<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Persistence;

use App\Features\ShareTracking\Api\EffectReceiptSnapshot;
use App\Features\ShareTracking\Api\EffectReceipts;
use App\Features\ShareTracking\Support\ShareTrackingModel;
use App\Shared\ShareTracking\ShareEffectStatus;
use App\Shared\ShareTracking\ShareOutcomeBusinessKey;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Persistence and lease boundary for share effect receipts.
 *
 * The effect row is a durable outbox entry. Creation is freeze-once per
 * effect key, claiming is a five-minute worker lease with fencing, and every
 * terminal write clears the lease it settles.
 */
final class DatabaseEffectReceipts implements EffectReceipts
{
    private const int LEASE_MINUTES = 5;

    public function ensureProjection(
        string $businessKey,
        string $conversionId,
        string $affiliateId,
        string $linkId,
    ): EffectReceiptSnapshot {
        $effectKey = $businessKey.':projection';

        try {
            $receipt = DB::transaction(static fn (): Model => ShareTrackingModel::effectReceiptQuery()->create([
                'effect_key' => $effectKey,
                'source_type' => ShareOutcomeBusinessKey::NAMESPACE,
                'source_id' => $businessKey,
                'effect_type' => 'projection',
                'status' => ShareEffectStatus::Pending,
                'payload' => [
                    'conversion_id' => $conversionId,
                    'affiliate_id' => $affiliateId,
                    'link_id' => $linkId,
                ],
                'attempts' => 0,
                'lease_token' => null,
                'leased_by' => null,
                'lease_expires_at' => null,
                'succeeded_at' => null,
                'failed_at' => null,
                'cancelled_at' => null,
            ]), 1);
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            $receipt = ShareTrackingModel::effectReceiptQuery()
                ->where('effect_key', $effectKey)
                ->firstOrFail();
        }

        return $this->snapshot($receipt);
    }

    public function ensureTicketReversal(
        string $effectKey,
        string $registrationId,
        string $conversionId,
        string $orderId,
        string $reason,
    ): EffectReceiptSnapshot {
        $receipt = ShareTrackingModel::effectReceiptQuery()->firstOrCreate(
            ['effect_key' => $effectKey],
            [
                'source_type' => 'event_registration',
                'source_id' => $registrationId,
                'effect_type' => 'ticket_affiliate_reversal',
                'status' => ShareEffectStatus::Pending,
                'payload' => [
                    'conversion_id' => $conversionId,
                    'registration_id' => $registrationId,
                    'order_id' => $orderId,
                    'reason' => $reason,
                ],
            ],
        );

        return $this->snapshot($receipt);
    }

    public function claimableBatch(array $ownedEffectTypes, int $limit): array
    {
        $receipts = DB::transaction(static fn (): Collection => ShareTrackingModel::effectReceiptQuery()
            ->whereIn('effect_type', $ownedEffectTypes)
            ->where(function ($query): void {
                $query->where('status', ShareEffectStatus::Pending->value)
                    ->orWhere(function ($expired): void {
                        $expired->where('status', ShareEffectStatus::Leased->value)
                            ->where('lease_expires_at', '<=', now());
                    });
            })
            ->orderBy('created_at')
            ->limit($limit)
            ->lockForUpdate()
            ->get());

        return $receipts
            ->map(fn (Model $receipt): EffectReceiptSnapshot => $this->snapshot($receipt))
            ->values()
            ->all();
    }

    public function claim(
        string $receiptId,
        array $ownedEffectTypes,
        string $workerId,
        string $leaseToken,
    ): ?EffectReceiptSnapshot {
        return DB::transaction(function () use ($receiptId, $ownedEffectTypes, $workerId, $leaseToken): ?EffectReceiptSnapshot {
            $locked = ShareTrackingModel::effectReceiptQuery()
                ->whereKey($receiptId)
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof Model) {
                return null;
            }

            if (! in_array((string) $locked->getAttribute('effect_type'), $ownedEffectTypes, true)) {
                return null;
            }

            $status = $this->status($locked);

            $reclaimable = $status === ShareEffectStatus::Pending
                || ($status === ShareEffectStatus::Leased && $this->isLeaseExpired($locked));

            if (! $reclaimable || $this->isTerminal($status)) {
                return null;
            }

            $locked->forceFill([
                'status' => ShareEffectStatus::Leased,
                'lease_token' => $leaseToken,
                'leased_by' => mb_substr($workerId, 0, 64),
                'lease_expires_at' => now()->addMinutes(self::LEASE_MINUTES),
                'attempts' => ((int) $locked->getAttribute('attempts')) + 1,
            ])->save();

            return $this->snapshot($locked);
        });
    }

    public function lockLease(
        string $receiptId,
        string $workerId,
        string $leaseToken,
    ): ?EffectReceiptSnapshot {
        return DB::transaction(function () use ($receiptId, $workerId, $leaseToken): ?EffectReceiptSnapshot {
            $locked = ShareTrackingModel::effectReceiptQuery()
                ->whereKey($receiptId)
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof Model) {
                return null;
            }

            if ($locked->getAttribute('lease_token') !== $leaseToken
                || $locked->getAttribute('leased_by') !== mb_substr($workerId, 0, 64)
                || $this->isLeaseExpired($locked)) {
                return null;
            }

            return $this->snapshot($locked);
        });
    }

    public function markSucceeded(string $receiptId, string $workerId, string $leaseToken): void
    {
        $this->settle($receiptId, $workerId, $leaseToken, ShareEffectStatus::Succeeded, 'succeeded_at');
    }

    public function markFailed(string $receiptId, string $workerId, string $leaseToken): void
    {
        $this->settle($receiptId, $workerId, $leaseToken, ShareEffectStatus::Failed, 'failed_at');
    }

    public function markCancelled(string $receiptId, string $workerId, string $leaseToken): void
    {
        $this->settle($receiptId, $workerId, $leaseToken, ShareEffectStatus::Cancelled, 'cancelled_at');
    }

    public function releaseToPending(string $receiptId, string $workerId, string $leaseToken): void
    {
        ShareTrackingModel::effectReceiptQuery()
            ->whereKey($receiptId)
            ->where('lease_token', $leaseToken)
            ->where('leased_by', mb_substr($workerId, 0, 64))
            ->update([
                'status' => ShareEffectStatus::Pending->value,
                'lease_token' => null,
                'leased_by' => null,
                'lease_expires_at' => null,
            ]);
    }

    public function lockCancelableBySource(string $sourceId): array
    {
        $receipts = DB::transaction(static fn (): Collection => ShareTrackingModel::effectReceiptQuery()
            ->where('source_id', $sourceId)
            ->whereIn('status', [ShareEffectStatus::Pending->value, ShareEffectStatus::Leased->value])
            ->lockForUpdate()
            ->get());

        return $receipts
            ->map(fn (Model $receipt): EffectReceiptSnapshot => $this->snapshot($receipt))
            ->values()
            ->all();
    }

    public function cancelPendingBySource(string $sourceId): void
    {
        DB::transaction(function () use ($sourceId): void {
            $effects = ShareTrackingModel::effectReceiptQuery()
                ->where('source_id', $sourceId)
                ->whereIn('status', [ShareEffectStatus::Pending->value, ShareEffectStatus::Leased->value])
                ->lockForUpdate()
                ->get();

            $effects->each(function (Model $effect): void {
                if ($this->isTerminal($this->status($effect))) {
                    return;
                }

                $effect->forceFill([
                    'status' => ShareEffectStatus::Cancelled,
                    'lease_token' => null,
                    'leased_by' => null,
                    'lease_expires_at' => null,
                    'cancelled_at' => now(),
                ])->save();
            });
        });
    }

    private function settle(
        string $receiptId,
        string $workerId,
        string $leaseToken,
        ShareEffectStatus $status,
        string $timestampColumn,
    ): void {
        ShareTrackingModel::effectReceiptQuery()
            ->whereKey($receiptId)
            ->where('lease_token', $leaseToken)
            ->where('leased_by', mb_substr($workerId, 0, 64))
            ->update([
                'status' => $status->value,
                'lease_token' => null,
                'leased_by' => null,
                'lease_expires_at' => null,
                $timestampColumn => now(),
            ]);
    }

    private function snapshot(Model $receipt): EffectReceiptSnapshot
    {
        $payload = $receipt->getAttribute('payload');
        $status = $this->status($receipt);

        return new EffectReceiptSnapshot(
            id: (string) $receipt->getKey(),
            effectKey: (string) $receipt->getAttribute('effect_key'),
            sourceType: (string) $receipt->getAttribute('source_type'),
            sourceId: (string) $receipt->getAttribute('source_id'),
            effectType: (string) $receipt->getAttribute('effect_type'),
            status: $status instanceof ShareEffectStatus ? $status->value : (string) $receipt->getAttribute('status'),
            payload: is_array($payload) ? $payload : [],
            attempts: (int) $receipt->getAttribute('attempts'),
            leaseToken: $this->nullableString($receipt->getAttribute('lease_token')),
            leasedBy: $this->nullableString($receipt->getAttribute('leased_by')),
            leaseExpiresAt: $this->timestamp($receipt->getAttribute('lease_expires_at')),
            succeededAt: $this->timestamp($receipt->getAttribute('succeeded_at')),
            failedAt: $this->timestamp($receipt->getAttribute('failed_at')),
            cancelledAt: $this->timestamp($receipt->getAttribute('cancelled_at')),
        );
    }

    private function status(Model $receipt): ?ShareEffectStatus
    {
        $status = $receipt->getAttribute('status');

        return $status instanceof ShareEffectStatus
            ? $status
            : ShareEffectStatus::tryFrom((string) $status);
    }

    private function isTerminal(?ShareEffectStatus $status): bool
    {
        return $status !== null && in_array($status, [
            ShareEffectStatus::Succeeded,
            ShareEffectStatus::Failed,
            ShareEffectStatus::Cancelled,
        ], true);
    }

    private function isLeaseExpired(Model $receipt): bool
    {
        $expiresAt = $receipt->getAttribute('lease_expires_at');

        if (! $expiresAt instanceof CarbonInterface) {
            return true;
        }

        return $expiresAt->lte(now());
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function timestamp(mixed $value): ?string
    {
        return $value instanceof CarbonInterface ? $value->toIso8601String() : $this->nullableString($value);
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return in_array((string) ($exception->errorInfo[0] ?? $exception->getCode()), ['23000', '23505'], true);
    }
}
