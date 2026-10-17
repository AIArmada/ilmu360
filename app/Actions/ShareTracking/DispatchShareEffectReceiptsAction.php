<?php

declare(strict_types=1);

namespace App\Actions\ShareTracking;

use AIArmada\Affiliates\Models\AffiliateConversion;
use AIArmada\Affiliates\Models\AffiliateLink;
use AIArmada\Affiliates\States\ConversionStatus;
use AIArmada\Affiliates\States\ReversedConversion;
use AIArmada\CommerceSupport\Support\OwnerScope;
use AIArmada\Events\Models\EventRegistration;
use App\Actions\Events\ReverseTicketAffiliateCommissionAction;
use App\Enums\ShareEffectStatus;
use App\Models\ShareEffectReceipt;
use App\Models\ShareOutcomeCorrection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class DispatchShareEffectReceiptsAction
{
    use AsAction;

    /**
     * Effect types this dispatcher owns: downstream outcome projection
     * effects (conversion/link payloads). Every other effect type
     * belongs to its own named consumer and is never selected here —
     * the generic dispatcher cannot steal foreign receipts.
     *
     * @var list<string>
     */
    public const array OWNED_EFFECT_TYPES = ['projection', 'ticket_affiliate_reversal'];

    public function __construct(
        private ReverseTicketAffiliateCommissionAction $reverseTicketAffiliateCommission,
    ) {}

    public function handle(int $limit = 50, ?string $workerId = null): int
    {
        $limit = max(1, min($limit, 200));
        $workerId ??= 'dispatch-'.gethostname().'-'.getmypid();
        $processed = 0;

        $receipts = DB::transaction(function () use ($limit) {
            return ShareEffectReceipt::query()
                ->whereIn('effect_type', self::OWNED_EFFECT_TYPES)
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
                ->get();
        });

        foreach ($receipts as $receipt) {
            if ($this->processReceipt($receipt, $workerId)) {
                $processed++;
            }
        }

        return $processed;
    }

    private function processReceipt(ShareEffectReceipt $receipt, string $workerId): bool
    {
        $leaseToken = (string) Str::uuid();

        $claimed = DB::transaction(function () use ($receipt, $workerId, $leaseToken): bool {
            $locked = ShareEffectReceipt::query()->whereKey($receipt->getKey())->lockForUpdate()->first();

            if (! $locked instanceof ShareEffectReceipt) {
                return false;
            }

            $status = $locked->status instanceof ShareEffectStatus
                ? $locked->status
                : ShareEffectStatus::tryFrom((string) $locked->status);

            if (! in_array((string) $locked->effect_type, self::OWNED_EFFECT_TYPES, true)) {
                return false;
            }

            $reclaimable = $status === ShareEffectStatus::Pending
                || ($status === ShareEffectStatus::Leased && $locked->isLeaseExpired());

            if (! $reclaimable || $locked->isTerminal()) {
                return false;
            }

            $locked->forceFill([
                'status' => ShareEffectStatus::Leased,
                'lease_token' => $leaseToken,
                'leased_by' => mb_substr($workerId, 0, 64),
                'lease_expires_at' => now()->addMinutes(5),
                'attempts' => $locked->attempts + 1,
            ])->save();

            return true;
        });

        if (! $claimed) {
            return false;
        }

        return DB::transaction(function () use ($receipt, $workerId, $leaseToken): bool {
            $locked = ShareEffectReceipt::query()->whereKey($receipt->getKey())->lockForUpdate()->first();

            if (! $locked instanceof ShareEffectReceipt) {
                return false;
            }

            if ($locked->lease_token !== $leaseToken || $locked->leased_by !== mb_substr($workerId, 0, 64)) {
                return false;
            }

            if ($locked->isLeaseExpired()) {
                return false;
            }

            $payload = is_array($locked->payload) ? $locked->payload : [];

            if ((string) $locked->effect_type === 'ticket_affiliate_reversal') {
                return $this->processDeferredTicketReversal($locked, $payload);
            }

            $conversionId = $payload['conversion_id'] ?? null;
            $linkId = $payload['link_id'] ?? null;

            $conversion = is_string($conversionId) && $conversionId !== ''
                ? AffiliateConversion::query()->whereKey($conversionId)->lockForUpdate()->first()
                : null;

            if (! $conversion instanceof AffiliateConversion) {
                $locked->forceFill([
                    'status' => ShareEffectStatus::Failed,
                    'lease_token' => null,
                    'leased_by' => null,
                    'lease_expires_at' => null,
                    'failed_at' => now(),
                ])->save();

                return true;
            }

            $status = $conversion->status;
            $value = $status instanceof ConversionStatus
                ? $status->getValue()
                : (string) $status;

            $correctionExists = ShareOutcomeCorrection::query()
                ->where('business_key', (string) $locked->source_id)
                ->lockForUpdate()
                ->exists();

            if (in_array($value, ['rejected', 'reversed'], true) || $correctionExists) {
                $locked->forceFill([
                    'status' => ShareEffectStatus::Cancelled,
                    'lease_token' => null,
                    'leased_by' => null,
                    'lease_expires_at' => null,
                    'cancelled_at' => now(),
                ])->save();

                return true;
            }

            if (! is_string($linkId) || $linkId === '') {
                $locked->forceFill([
                    'status' => ShareEffectStatus::Cancelled,
                    'lease_token' => null,
                    'leased_by' => null,
                    'lease_expires_at' => null,
                    'cancelled_at' => now(),
                ])->save();

                return true;
            }

            $link = AffiliateLink::query()->whereKey($linkId)->lockForUpdate()->first();

            if (! $link instanceof AffiliateLink) {
                $locked->forceFill([
                    'status' => ShareEffectStatus::Cancelled,
                    'lease_token' => null,
                    'leased_by' => null,
                    'lease_expires_at' => null,
                    'cancelled_at' => now(),
                ])->save();

                return true;
            }

            $link->incrementConversions();

            $locked->forceFill([
                'status' => ShareEffectStatus::Succeeded,
                'lease_token' => null,
                'leased_by' => null,
                'lease_expires_at' => null,
                'succeeded_at' => now(),
            ])->save();

            return true;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function processDeferredTicketReversal(ShareEffectReceipt $receipt, array $payload): bool
    {
        $registrationId = $payload['registration_id'] ?? null;
        $conversionId = $payload['conversion_id'] ?? null;
        $reason = $payload['reason'] ?? null;

        $registration = is_string($registrationId) && $registrationId !== ''
            ? EventRegistration::query()->whereKey($registrationId)->first()
            : null;

        if (! $registration instanceof EventRegistration || ! is_string($reason) || $reason === '') {
            $receipt->forceFill([
                'status' => ShareEffectStatus::Failed,
                'lease_token' => null,
                'leased_by' => null,
                'lease_expires_at' => null,
                'failed_at' => now(),
            ])->save();

            return true;
        }

        $this->reverseTicketAffiliateCommission->handle($registration, $reason);

        $conversion = is_string($conversionId) && $conversionId !== ''
            ? AffiliateConversion::query()->withoutGlobalScope(OwnerScope::class)->whereKey($conversionId)->lockForUpdate()->first()
            : null;

        if ($conversion instanceof AffiliateConversion
            && ($conversion->status instanceof ConversionStatus
                ? $conversion->status->equals(ReversedConversion::class)
                : (string) $conversion->status === 'reversed')) {
            $receipt->forceFill([
                'status' => ShareEffectStatus::Succeeded,
                'lease_token' => null,
                'leased_by' => null,
                'lease_expires_at' => null,
                'succeeded_at' => now(),
            ])->save();

            return true;
        }

        if ($conversion instanceof AffiliateConversion
            && $this->reverseTicketAffiliateCommission->hasOpenPayoutReservation($conversion)) {
            $receipt->forceFill([
                'status' => ShareEffectStatus::Pending,
                'lease_token' => null,
                'leased_by' => null,
                'lease_expires_at' => null,
            ])->save();

            return true;
        }

        $receipt->forceFill([
            'status' => ShareEffectStatus::Failed,
            'lease_token' => null,
            'leased_by' => null,
            'lease_expires_at' => null,
            'failed_at' => now(),
        ])->save();

        return true;
    }
}
