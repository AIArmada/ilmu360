<?php

declare(strict_types=1);

namespace App\Actions\Rewards;

use AIArmada\Communications\Actions\CancelCommunicationAction;
use AIArmada\Communications\Enums\DeliveryStatus;
use AIArmada\Communications\Models\Communication;
use AIArmada\Communications\Models\CommunicationDelivery;
use AIArmada\Rewards\Support\RewardLedgerEntries;
use App\Models\RewardDigestSlot;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Cancel or recalculate pending digest material.
 *
 * Corrections call this with force=false: planned slots recompute over
 * their frozen window and categories — nothing left cancels, changed
 * material recalculates in place. Sending slots whose transport is
 * still pre-send are cancelled through the real communication cancel
 * contract, then likewise cancel or requeue with a fresh payload.
 * Sending slots with a delivered channel refuse (already delivered);
 * sending slots with ambiguous in-flight work (sending/accepted)
 * refuse (delivery in flight — reconcile first, never release
 * ambiguity as success or silently re-send).
 *
 * Operators call with force=true to cancel pending material outright
 * (still refusing delivered or ambiguous sends). Sent and cancelled
 * slots always refuse: history is never rewritten.
 */
final readonly class CancelRewardDigestAction
{
    use AsAction;

    public function handle(
        RewardDigestSlot|string $slot,
        string $reason,
        string $actorType,
        ?string $actorId,
        string $actorRole,
        bool $force = false,
        ?CarbonInterface $decidedAt = null,
    ): RewardDigestSlot {
        RewardLedgerEntries::assertActor($actorType, $actorId, $actorRole);

        $isSystem = $actorType === 'system' && $actorId === null && $actorRole === 'system';
        $isStaff = $actorType === 'user' && in_array($actorRole, ['staff', 'admin'], true);

        if (! $isSystem && ! $isStaff) {
            throw new InvalidArgumentException('Digest cancellation requires a staff/admin user or the system identity.');
        }

        if ($reason === '' || mb_strlen($reason) > 191) {
            throw new InvalidArgumentException('Cancellation reason must be 1..191 characters.');
        }

        $slotId = $slot instanceof RewardDigestSlot ? (string) $slot->getKey() : $slot;
        $decided = $decidedAt === null
            ? CarbonImmutable::now('UTC')
            : CarbonImmutable::parse($decidedAt)->setTimezone('UTC');

        return DB::transaction(function () use ($slotId, $reason, $force, $decided): RewardDigestSlot {
            $locked = RewardDigestSlot::query()->whereKey($slotId)->lockForUpdate()->first();

            if (! $locked instanceof RewardDigestSlot) {
                throw new InvalidArgumentException('Unknown reward digest slot.');
            }

            $status = (string) $locked->status;

            if ($status === RewardDigestSlot::STATUS_SENT) {
                throw new InvalidArgumentException('Digest already sent; cancellation is closed.');
            }

            if ($status === RewardDigestSlot::STATUS_CANCELLED) {
                throw new InvalidArgumentException('Digest already cancelled.');
            }

            if ($status === RewardDigestSlot::STATUS_SENDING) {
                $this->cancelSendingTransport($locked);
            }

            if ($force) {
                self::markCancelled($locked, $reason, $decided);

                return $locked->refresh();
            }

            $window = (array) (($locked->payload ?? [])['window'] ?? []);
            $from = CarbonImmutable::parse($window['from'] ?? $decided->toIso8601String())->setTimezone('UTC');
            $to = CarbonImmutable::parse($window['to'] ?? $decided->toIso8601String())->setTimezone('UTC');
            $categories = array_values(array_intersect(
                (array) ($locked->categories ?? []),
                PlanRewardGratitudeDigestAction::CATEGORIES,
            ));

            $recomputed = PlanRewardGratitudeDigestAction::digestPayload(
                (string) $locked->recipient_id, $categories === [] ? ['awards'] : $categories, $from, $to,
            );

            if (($recomputed['total'] ?? 0) <= 0) {
                self::markCancelled($locked, $reason, $decided);

                return $locked->refresh();
            }

            // Material remains: recalculate in place (planned) or requeue
            // for redelivery with the fresh payload (sending transport
            // was cancelled above). Delivery bookkeeping survives.
            $delivery = ($locked->payload ?? [])['delivery'] ?? null;

            if ($delivery !== null) {
                $recomputed['delivery'] = $delivery;
            }

            $locked->forceFill([
                'status' => RewardDigestSlot::STATUS_PLANNED,
                'payload' => $recomputed,
            ])->save();

            return $locked->refresh();
        });
    }

    /**
     * Cancel one recipient's pending digest material after a correction.
     * Ambiguous or finished slots are left for reconcile; every other
     * pending slot cancels or recalculates. Returns the cancelled-or-
     * recalculated count.
     */
    public static function cancelPendingForRecipient(
        string $recipientType,
        string $recipientId,
        string $reason,
        CarbonImmutable $decided,
    ): int {
        $ids = RewardDigestSlot::query()
            ->where('recipient_type', $recipientType)
            ->where('recipient_id', $recipientId)
            ->whereIn('status', [RewardDigestSlot::STATUS_PLANNED, RewardDigestSlot::STATUS_SENDING])
            ->orderBy('due_at')
            ->pluck('id')
            ->all();

        $count = 0;

        foreach ($ids as $id) {
            try {
                self::run((string) $id, $reason, 'system', null, 'system', false, $decided);
                $count++;
            } catch (InvalidArgumentException) {
                // Delivered, ambiguous, or already finished: reconcile
                // arbitrates; the correction itself never fails.
            }
        }

        return $count;
    }

    private function cancelSendingTransport(RewardDigestSlot $slot): void
    {
        $communication = $slot->communication_id === null
            ? null
            : Communication::query()->whereKey((string) $slot->communication_id)->lockForUpdate()->first();

        if (! $communication instanceof Communication) {
            return;
        }

        $deliveries = CommunicationDelivery::query()
            ->where('communication_id', $communication->getKey())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($deliveries as $delivery) {
            if ($delivery->status instanceof DeliveryStatus && in_array($delivery->status, [
                DeliveryStatus::Sent,
                DeliveryStatus::Received,
                DeliveryStatus::Delivered,
                DeliveryStatus::Opened,
                DeliveryStatus::Read,
                DeliveryStatus::Clicked,
                DeliveryStatus::Replied,
            ], true)) {
                throw new InvalidArgumentException('Digest already delivered on at least one channel; cancellation is closed.');
            }

            if ($delivery->status instanceof DeliveryStatus && in_array($delivery->status, [
                DeliveryStatus::Sending,
                DeliveryStatus::Accepted,
            ], true)) {
                throw new InvalidArgumentException('Digest delivery is in flight; reconcile first.');
            }
        }

        app(CancelCommunicationAction::class)->handle((string) $communication->getKey(), 'digest-correction');
    }

    private static function markCancelled(RewardDigestSlot $slot, string $reason, CarbonImmutable $now): void
    {
        $payload = $slot->payload ?? [];
        $payload['cancellation'] = ['at' => $now->toIso8601String(), 'reason' => $reason];

        $slot->forceFill([
            'status' => RewardDigestSlot::STATUS_CANCELLED,
            'payload' => $payload,
            'cancelled_at' => $now,
        ])->save();
    }
}
