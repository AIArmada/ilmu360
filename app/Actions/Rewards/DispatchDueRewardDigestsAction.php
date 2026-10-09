<?php

declare(strict_types=1);

namespace App\Actions\Rewards;

use AIArmada\Communications\Actions\DispatchManagedNotificationAction;
use AIArmada\Communications\Data\CommunicationContextData;
use AIArmada\Communications\Enums\CommunicationCategory;
use AIArmada\Communications\Enums\CommunicationPriority;
use AIArmada\Communications\Enums\CommunicationStatus;
use AIArmada\Communications\Enums\DeliveryStatus;
use AIArmada\Communications\Jobs\DispatchManagedNotificationJob;
use AIArmada\Communications\Models\Communication;
use AIArmada\Communications\Models\CommunicationDelivery;
use App\Enums\NotificationCadence;
use App\Enums\NotificationTrigger;
use App\Models\RewardDigestSlot;
use App\Models\User;
use App\Notifications\ShareGratitudeDigestNotification;
use App\Services\Notifications\NotificationSettingsManager;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Dispatch due gratitude digests and reconcile sending ones.
 *
 * Each hourly run does two passes: claim due planned slots (exactly
 * once, under the slot lock) and reconcile sending slots against
 * observed delivery. The slot lifecycle reflects actual delivery —
 * planned → sending → sent only when at least one channel observably
 * sent — never a sent mark on a merely queued transport.
 *
 * Claim (single transaction): due + consent/cadence/quiet-hour rechecks,
 * payload recompute over the frozen window and merged categories (zero
 * material cancels, changed material recalculates), then adopt-or-create
 * the managed communication and mark sending. An adopted transport is
 * redriven only when provably jobless (pre-send and stalled), so crash
 * recovery applies once and a live job is never doubled.
 *
 * Reconcile: any sent-or-beyond delivery marks sent with per-channel
 * outcomes; in-flight deliveries keep waiting (ambiguous sending is
 * never released or re-sent); all-terminal failures requeue up to three
 * attempts, then cancel as delivery-failed; pre-send work stalled past
 * two hours (lost job) requeues for redelivery, bounded, then cancels
 * as stalled. The next planned event always starts a fresh day.
 */
final readonly class DispatchDueRewardDigestsAction
{
    use AsAction;

    public const int MAX_DELIVERY_ATTEMPTS = 3;

    public const int STALLED_MINUTES = 120;

    public const int MAX_STALLED_ESCAPES = 2;

    /**
     * @return array{dispatched: int, reconciled: int, cancelled: int, skipped: int}
     */
    public function handle(int $batch = 50, ?CarbonInterface $now = null): array
    {
        $at = $now === null
            ? CarbonImmutable::now('UTC')
            : CarbonImmutable::parse($now)->setTimezone('UTC');

        $outcome = ['dispatched' => 0, 'reconciled' => 0, 'cancelled' => 0, 'skipped' => 0];

        $due = RewardDigestSlot::query()
            ->where('status', RewardDigestSlot::STATUS_PLANNED)
            ->where('due_at', '<=', $at)
            ->orderBy('due_at')
            ->limit(max(1, $batch))
            ->pluck('id')
            ->all();

        foreach ($due as $slotId) {
            $result = $this->dispatchOne((string) $slotId, $at);
            $outcome[$result]++;
        }

        $sending = RewardDigestSlot::query()
            ->where('status', RewardDigestSlot::STATUS_SENDING)
            ->orderBy('updated_at')
            ->limit(max(1, $batch))
            ->pluck('id')
            ->all();

        foreach ($sending as $slotId) {
            $result = $this->reconcileOne((string) $slotId, $at);
            $outcome[$result]++;
        }

        return $outcome;
    }

    /**
     * @return 'dispatched'|'cancelled'|'skipped'
     */
    private function dispatchOne(string $slotId, CarbonImmutable $now): string
    {
        return DB::transaction(function () use ($slotId, $now): string {
            $slot = RewardDigestSlot::query()->whereKey($slotId)->lockForUpdate()->first();

            if (! $slot instanceof RewardDigestSlot
                || (string) $slot->status !== RewardDigestSlot::STATUS_PLANNED
                || $slot->due_at === null
                || $slot->due_at->greaterThan($now)
            ) {
                return 'skipped';
            }

            $user = User::query()->whereKey((string) $slot->recipient_id)->first();

            if (! $user instanceof User) {
                self::markCancelled($slot, 'recipient-unknown', $now);

                return 'cancelled';
            }

            $policy = app(NotificationSettingsManager::class)
                ->resolvePolicy($user, NotificationTrigger::ShareGratitudeDigest);

            if (! $policy->enabled || ! in_array($policy->cadence, [NotificationCadence::Daily, NotificationCadence::Weekly], true)) {
                self::markCancelled($slot, 'opted-out', $now);

                return 'cancelled';
            }

            if (self::inQuietHours($now, $policy->quietHoursStart, $policy->quietHoursEnd, $policy->timezone)) {
                return 'skipped';
            }

            $window = (array) (($slot->payload ?? [])['window'] ?? []);
            $from = CarbonImmutable::parse($window['from'] ?? $now->toIso8601String())->setTimezone('UTC');
            $to = CarbonImmutable::parse($window['to'] ?? $now->toIso8601String())->setTimezone('UTC');
            $categories = array_values(array_intersect(
                (array) ($slot->categories ?? []),
                PlanRewardGratitudeDigestAction::CATEGORIES,
            ));

            $recomputed = PlanRewardGratitudeDigestAction::digestPayload(
                (string) $slot->recipient_id, $categories === [] ? ['awards'] : $categories, $from, $to,
            );

            if (($recomputed['total'] ?? 0) <= 0) {
                self::markCancelled($slot, 'nothing-to-thank', $now);

                return 'cancelled';
            }

            // Delivery bookkeeping (attempts, escapes, channel
            // outcomes) survives recomputes; only the material
            // sections refresh.
            $delivery = ($slot->payload ?? [])['delivery'] ?? null;

            if ($delivery !== null) {
                $recomputed['delivery'] = $delivery;
            }

            $slot->forceFill(['payload' => $recomputed])->save();

            $communication = $this->adoptOrCreateCommunication($slot, $user, $recomputed, $policy->timezone, $now);

            if ($communication === null) {
                self::markCancelled($slot, 'no-eligible-channel', $now);

                return 'cancelled';
            }

            $slot->forceFill([
                'status' => RewardDigestSlot::STATUS_SENDING,
                'communication_id' => (string) $communication->getKey(),
            ])->save();

            return 'dispatched';
        });
    }

    /**
     * Adopt the latest sendable communication for the plan key, else
     * create a fresh one. Returns null when no channel can carry the
     * digest. An adopted transport is redriven through the real job only
     * when it is provably jobless — every delivery pre-send and stalled
     * past the threshold — so recovery applies once and a live job is
     * never doubled.
     *
     * @param  array<string, mixed>  $payload
     */
    private function adoptOrCreateCommunication(
        RewardDigestSlot $slot,
        User $user,
        array $payload,
        string $timezone,
        CarbonImmutable $now,
    ): ?Communication {
        $planKey = (string) $slot->plan_key;

        $latest = Communication::query()
            ->where('idempotency_key', $planKey)
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        if ($latest instanceof Communication && self::isSendable($latest)) {
            $deliveries = CommunicationDelivery::query()
                ->where('communication_id', $latest->getKey())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($deliveries->isNotEmpty()) {
                $queuedAt = $latest->queued_at ?? $latest->created_at;
                $allPreSend = $deliveries->every(
                    static fn (CommunicationDelivery $delivery): bool => self::isPreSend($delivery->status)
                );

                if ($allPreSend
                    && $queuedAt instanceof CarbonImmutable
                    && $queuedAt->addMinutes(self::STALLED_MINUTES)->lessThan($now)
                ) {
                    $this->redriveJob($latest, $user, $payload, $deliveries->all());
                }

                return $latest->refresh();
            }
        }

        $summary = [
            'text' => (string) ($payload['text'] ?? ''),
            'counts' => (array) ($payload['counts'] ?? []),
            'sections' => (array) ($payload['sections'] ?? []),
            'window' => (array) ($payload['window'] ?? []),
            'plan_key' => $planKey,
            'slot_id' => (string) $slot->getKey(),
        ];

        $communication = app(DispatchManagedNotificationAction::class)->handle(
            $user,
            new ShareGratitudeDigestNotification($summary),
            new CommunicationContextData(
                category: CommunicationCategory::Digest,
                priority: CommunicationPriority::Low,
                idempotencyKey: $planKey,
                timezone: $timezone,
                metadata: [
                    'plan_key' => $planKey,
                    'slot_id' => (string) $slot->getKey(),
                    'trigger' => NotificationTrigger::ShareGratitudeDigest->value,
                ],
            ),
        );

        $fresh = CommunicationDelivery::query()
            ->where('communication_id', $communication->getKey())
            ->exists();

        return $fresh ? $communication : null;
    }

    /**
     * @param  list<CommunicationDelivery>  $deliveries
     * @param  array<string, mixed>  $payload
     */
    private function redriveJob(
        Communication $communication,
        User $user,
        array $payload,
        array $deliveries,
    ): void {
        $byChannel = [];

        foreach ($deliveries as $delivery) {
            $byChannel[(string) $delivery->channel] = (string) $delivery->getKey();
        }

        $notification = (new ShareGratitudeDigestNotification([
            'text' => (string) ($payload['text'] ?? ''),
            'counts' => (array) ($payload['counts'] ?? []),
            'sections' => (array) ($payload['sections'] ?? []),
            'window' => (array) ($payload['window'] ?? []),
            'plan_key' => (string) $communication->idempotency_key,
        ]))->withCommunicationContext((string) $communication->getKey(), $byChannel);

        DispatchManagedNotificationJob::dispatch(
            notifiable: $user,
            notification: $notification,
            channels: array_keys($byChannel),
        )->afterCommit();
    }

    /**
     * @return 'reconciled'|'cancelled'|'skipped'
     */
    private function reconcileOne(string $slotId, CarbonImmutable $now): string
    {
        return DB::transaction(function () use ($slotId, $now): string {
            $slot = RewardDigestSlot::query()->whereKey($slotId)->lockForUpdate()->first();

            if (! $slot instanceof RewardDigestSlot
                || (string) $slot->status !== RewardDigestSlot::STATUS_SENDING
            ) {
                return 'skipped';
            }

            $communication = $slot->communication_id === null
                ? null
                : Communication::query()->whereKey((string) $slot->communication_id)->lockForUpdate()->first();

            if (! $communication instanceof Communication) {
                // Defensive: the bound aggregate vanished; requeue so the
                // next claim adopts-or-creates afresh.
                $slot->forceFill(['status' => RewardDigestSlot::STATUS_PLANNED])->save();

                return 'skipped';
            }

            $deliveries = CommunicationDelivery::query()
                ->where('communication_id', $communication->getKey())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $outcomes = [];
            $delivered = false;
            $inFlight = false;
            $ambiguous = false;

            foreach ($deliveries as $delivery) {
                $status = $delivery->status instanceof DeliveryStatus
                    ? $delivery->status->value
                    : (string) $delivery->status;
                $outcomes[(string) $delivery->channel] = $status;

                if (self::isDelivered($delivery->status)) {
                    $delivered = true;
                }

                if (self::isInFlight($delivery->status)) {
                    $inFlight = true;
                }

                if (self::isAmbiguous($delivery->status)) {
                    $ambiguous = true;
                }
            }

            if ($delivered) {
                $payload = $slot->payload ?? [];
                $payload['delivery'] = array_merge($payload['delivery'] ?? [], [
                    'at' => $now->toIso8601String(),
                    'channels' => $outcomes,
                ]);

                $slot->forceFill([
                    'status' => RewardDigestSlot::STATUS_SENT,
                    'payload' => $payload,
                    'sent_at' => $now,
                ])->save();

                return 'reconciled';
            }

            if ($inFlight) {
                // Stalled escape: pre-send work with no job behind it
                // (lost dispatch) requeues for redelivery, bounded.
                // Ambiguous in-flight work never escapes.
                $queuedAt = $communication->queued_at ?? $communication->created_at;

                if (! $ambiguous
                    && $queuedAt instanceof CarbonImmutable
                    && $queuedAt->addMinutes(self::STALLED_MINUTES)->lessThan($now)
                ) {
                    $payload = $slot->payload ?? [];
                    $escapes = ((int) ($payload['delivery']['stalled_escapes'] ?? 0)) + 1;
                    $payload['delivery']['stalled_escapes'] = $escapes;

                    if ($escapes > self::MAX_STALLED_ESCAPES) {
                        $payload['cancellation'] = ['at' => $now->toIso8601String(), 'reason' => 'delivery-stalled'];
                        $slot->forceFill([
                            'status' => RewardDigestSlot::STATUS_CANCELLED,
                            'payload' => $payload,
                            'cancelled_at' => $now,
                        ])->save();

                        return 'cancelled';
                    }

                    $slot->forceFill([
                        'status' => RewardDigestSlot::STATUS_PLANNED,
                        'payload' => $payload,
                    ])->save();
                }

                return 'skipped';
            }

            // Every delivery terminal without a single send: requeue for
            // redelivery up to three attempts, then cancel truthfully.
            $payload = $slot->payload ?? [];
            $attempts = ((int) ($payload['delivery']['attempts'] ?? 0)) + 1;
            $payload['delivery']['attempts'] = $attempts;
            $payload['delivery']['channels'] = $outcomes;

            if ($attempts >= self::MAX_DELIVERY_ATTEMPTS) {
                $payload['cancellation'] = ['at' => $now->toIso8601String(), 'reason' => 'delivery-failed'];
                $slot->forceFill([
                    'status' => RewardDigestSlot::STATUS_CANCELLED,
                    'payload' => $payload,
                    'cancelled_at' => $now,
                ])->save();

                return 'cancelled';
            }

            $slot->forceFill([
                'status' => RewardDigestSlot::STATUS_PLANNED,
                'payload' => $payload,
            ])->save();

            return 'skipped';
        });
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

    private static function isSendable(Communication $communication): bool
    {
        return $communication->status instanceof CommunicationStatus
            && in_array($communication->status, [
                CommunicationStatus::Draft,
                CommunicationStatus::Scheduled,
                CommunicationStatus::Queued,
                CommunicationStatus::Processing,
                CommunicationStatus::PartiallyCompleted,
            ], true);
    }

    private static function isPreSend(mixed $status): bool
    {
        return $status instanceof DeliveryStatus
            && in_array($status, [
                DeliveryStatus::Pending,
                DeliveryStatus::Scheduled,
                DeliveryStatus::Queued,
            ], true);
    }

    private static function isDelivered(mixed $status): bool
    {
        return $status instanceof DeliveryStatus
            && in_array($status, [
                DeliveryStatus::Sent,
                DeliveryStatus::Received,
                DeliveryStatus::Delivered,
                DeliveryStatus::Opened,
                DeliveryStatus::Read,
                DeliveryStatus::Clicked,
                DeliveryStatus::Replied,
            ], true);
    }

    private static function isInFlight(mixed $status): bool
    {
        return $status instanceof DeliveryStatus
            && in_array($status, [
                DeliveryStatus::Pending,
                DeliveryStatus::Scheduled,
                DeliveryStatus::Queued,
                DeliveryStatus::Sending,
                DeliveryStatus::Accepted,
            ], true);
    }

    private static function isAmbiguous(mixed $status): bool
    {
        return $status instanceof DeliveryStatus
            && in_array($status, [DeliveryStatus::Sending, DeliveryStatus::Accepted], true);
    }

    private static function inQuietHours(
        CarbonImmutable $now,
        ?string $start,
        ?string $end,
        string $timezone,
    ): bool {
        if ($start === null || $end === null || $start === '' || $end === '') {
            return false;
        }

        $local = $now->setTimezone($timezone)->format('H:i');

        return $start <= $end
            ? ($local >= $start && $local < $end)
            : ($local >= $start || $local < $end);
    }
}
