<?php

declare(strict_types=1);

namespace App\Features\Rewards\Actions\GratitudeDigests;

use AIArmada\Communications\Actions\DispatchManagedNotificationAction;
use AIArmada\Communications\Data\CommunicationContextData;
use AIArmada\Communications\Enums\CommunicationCategory;
use AIArmada\Communications\Enums\CommunicationPriority;
use AIArmada\Communications\Enums\CommunicationStatus;
use AIArmada\Communications\Enums\DeliveryStatus;
use AIArmada\Communications\Jobs\DispatchManagedNotificationJob;
use AIArmada\Communications\Models\Communication;
use AIArmada\Communications\Models\CommunicationDelivery;
use App\Features\Rewards\Api\GratitudeDigest;
use App\Features\Rewards\Support\RewardsModel;
use App\Shared\Communications\DigestMessages;
use App\Shared\Communications\DigestPolicy;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
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

    public function __construct(
        private DigestPolicy $policies,
        private DigestMessages $messages,
    ) {}

    /**
     * @return array{dispatched: int, reconciled: int, cancelled: int, skipped: int}
     */
    public function handle(int $batch = 50, ?CarbonInterface $now = null): array
    {
        $at = $now instanceof CarbonInterface
            ? CarbonImmutable::parse($now)->setTimezone('UTC')
            : CarbonImmutable::now('UTC');

        $outcome = ['dispatched' => 0, 'reconciled' => 0, 'cancelled' => 0, 'skipped' => 0];

        $due = RewardsModel::digestSlotQuery()
            ->where('status', RewardsModel::SLOT_PLANNED)
            ->where('due_at', '<=', $at)
            ->orderBy('due_at')
            ->limit(max(1, $batch))
            ->pluck('id')
            ->all();

        foreach ($due as $slotId) {
            $result = $this->dispatchOne((string) $slotId, $at);
            $outcome[$result]++;
        }

        $sending = RewardsModel::digestSlotQuery()
            ->where('status', RewardsModel::SLOT_SENDING)
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
            $slot = RewardsModel::digestSlotQuery()->whereKey($slotId)->lockForUpdate()->first();
            $dueAt = $slot?->getAttribute('due_at');

            if (! $slot instanceof Model
                || (string) $slot->getAttribute('status') !== RewardsModel::SLOT_PLANNED
                || ! $dueAt instanceof CarbonInterface
                || $dueAt->greaterThan($now)
            ) {
                return 'skipped';
            }

            $recipient = $this->messages->notifiable((string) $slot->getAttribute('recipient_id'));

            if ($recipient === null) {
                $this->markCancelled($slot, 'recipient-unknown', $now);

                return 'cancelled';
            }

            $policy = $this->policies->resolve($recipient, GratitudeDigest::TRIGGER);

            if (! $policy->enabled || ! in_array($policy->cadence, ['daily', 'weekly'], true)) {
                $this->markCancelled($slot, 'opted-out', $now);

                return 'cancelled';
            }

            if ($this->inQuietHours($now, $policy->quietHoursStart, $policy->quietHoursEnd, $policy->timezone)) {
                return 'skipped';
            }

            $slotPayload = (array) ($slot->getAttribute('payload') ?? []);
            $window = (array) ($slotPayload['window'] ?? []);
            $from = CarbonImmutable::parse($window['from'] ?? $now->toIso8601String())->setTimezone('UTC');
            $to = CarbonImmutable::parse($window['to'] ?? $now->toIso8601String())->setTimezone('UTC');
            $categories = array_values(array_intersect(
                (array) ($slot->getAttribute('categories') ?? []),
                PlanRewardGratitudeDigestAction::CATEGORIES,
            ));

            $recomputed = PlanRewardGratitudeDigestAction::digestPayload(
                (string) $slot->getAttribute('recipient_id'), $categories === [] ? ['awards'] : $categories, $from, $to,
            );

            if (($recomputed['total'] ?? 0) <= 0) {
                $this->markCancelled($slot, 'nothing-to-thank', $now);

                return 'cancelled';
            }

            // Delivery bookkeeping (attempts, escapes, channel
            // outcomes) survives recomputes; only the material
            // sections refresh.
            $delivery = $slotPayload['delivery'] ?? null;

            if ($delivery !== null) {
                $recomputed['delivery'] = $delivery;
            }

            $slot->forceFill(['payload' => $recomputed])->save();

            $communication = $this->adoptOrCreateCommunication(
                $slot, $recipient, $recomputed, $policy->timezone, $policy->trigger, $now,
            );

            if (! $communication instanceof Communication) {
                $this->markCancelled($slot, 'no-eligible-channel', $now);

                return 'cancelled';
            }

            $slot->forceFill([
                'status' => RewardsModel::SLOT_SENDING,
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
        Model $slot,
        Authenticatable $recipient,
        array $payload,
        string $timezone,
        string $trigger,
        CarbonImmutable $now,
    ): ?Communication {
        $planKey = (string) $slot->getAttribute('plan_key');

        $latest = Communication::query()
            ->where('idempotency_key', $planKey)
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        if ($latest instanceof Communication && $this->isSendable($latest)) {
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
                    $this->redriveJob($latest, $recipient, $payload, $deliveries->all());
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
            $recipient,
            $this->messages->digest((string) $slot->getAttribute('recipient_id'), $summary),
            new CommunicationContextData(
                category: CommunicationCategory::Digest,
                priority: CommunicationPriority::Low,
                timezone: $timezone,
                idempotencyKey: $planKey,
                metadata: [
                    'plan_key' => $planKey,
                    'slot_id' => (string) $slot->getKey(),
                    'trigger' => $trigger,
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
        Authenticatable $recipient,
        array $payload,
        array $deliveries,
    ): void {
        $byChannel = [];

        foreach ($deliveries as $delivery) {
            $byChannel[(string) $delivery->channel] = (string) $delivery->getKey();
        }

        $summary = [
            'text' => (string) ($payload['text'] ?? ''),
            'counts' => (array) ($payload['counts'] ?? []),
            'sections' => (array) ($payload['sections'] ?? []),
            'window' => (array) ($payload['window'] ?? []),
            'plan_key' => (string) $communication->idempotency_key,
        ];

        $notification = $this->messages->redrive(
            (string) $recipient->getAuthIdentifier(),
            (string) $communication->getKey(),
            $summary,
            $byChannel,
        );

        DispatchManagedNotificationJob::dispatch(
            notifiable: $recipient,
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
            $slot = RewardsModel::digestSlotQuery()->whereKey($slotId)->lockForUpdate()->first();

            if (! $slot instanceof Model
                || (string) $slot->getAttribute('status') !== RewardsModel::SLOT_SENDING
            ) {
                return 'skipped';
            }

            $communicationId = $slot->getAttribute('communication_id');
            $communication = $communicationId === null
                ? null
                : Communication::query()->whereKey((string) $communicationId)->lockForUpdate()->first();

            if (! $communication instanceof Communication) {
                // Defensive: the bound aggregate vanished; requeue so the
                // next claim adopts-or-creates afresh.
                $slot->forceFill(['status' => RewardsModel::SLOT_PLANNED])->save();

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

                if ($this->isDelivered($delivery->status)) {
                    $delivered = true;
                }

                if ($this->isInFlight($delivery->status)) {
                    $inFlight = true;
                }

                if ($this->isAmbiguous($delivery->status)) {
                    $ambiguous = true;
                }
            }

            if ($delivered) {
                $payload = (array) ($slot->getAttribute('payload') ?? []);
                $payload['delivery'] = array_merge($payload['delivery'] ?? [], [
                    'at' => $now->toIso8601String(),
                    'channels' => $outcomes,
                ]);

                $slot->forceFill([
                    'status' => RewardsModel::SLOT_SENT,
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
                    $payload = (array) ($slot->getAttribute('payload') ?? []);
                    $escapes = ((int) ($payload['delivery']['stalled_escapes'] ?? 0)) + 1;
                    $payload['delivery']['stalled_escapes'] = $escapes;

                    if ($escapes > self::MAX_STALLED_ESCAPES) {
                        $payload['cancellation'] = ['at' => $now->toIso8601String(), 'reason' => 'delivery-stalled'];
                        $slot->forceFill([
                            'status' => RewardsModel::SLOT_CANCELLED,
                            'payload' => $payload,
                            'cancelled_at' => $now,
                        ])->save();

                        return 'cancelled';
                    }

                    $slot->forceFill([
                        'status' => RewardsModel::SLOT_PLANNED,
                        'payload' => $payload,
                    ])->save();
                }

                return 'skipped';
            }

            // Every delivery terminal without a single send: requeue for
            // redelivery up to three attempts, then cancel truthfully.
            $payload = (array) ($slot->getAttribute('payload') ?? []);
            $attempts = ((int) ($payload['delivery']['attempts'] ?? 0)) + 1;
            $payload['delivery']['attempts'] = $attempts;
            $payload['delivery']['channels'] = $outcomes;

            if ($attempts >= self::MAX_DELIVERY_ATTEMPTS) {
                $payload['cancellation'] = ['at' => $now->toIso8601String(), 'reason' => 'delivery-failed'];
                $slot->forceFill([
                    'status' => RewardsModel::SLOT_CANCELLED,
                    'payload' => $payload,
                    'cancelled_at' => $now,
                ])->save();

                return 'cancelled';
            }

            $slot->forceFill([
                'status' => RewardsModel::SLOT_PLANNED,
                'payload' => $payload,
            ])->save();

            return 'skipped';
        });
    }

    private function markCancelled(Model $slot, string $reason, CarbonImmutable $now): void
    {
        $payload = (array) ($slot->getAttribute('payload') ?? []);
        $payload['cancellation'] = ['at' => $now->toIso8601String(), 'reason' => $reason];

        $slot->forceFill([
            'status' => RewardsModel::SLOT_CANCELLED,
            'payload' => $payload,
            'cancelled_at' => $now,
        ])->save();
    }

    private function isSendable(Communication $communication): bool
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

    private function isDelivered(mixed $status): bool
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

    private function isInFlight(mixed $status): bool
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

    private function isAmbiguous(mixed $status): bool
    {
        return $status instanceof DeliveryStatus
            && in_array($status, [DeliveryStatus::Sending, DeliveryStatus::Accepted], true);
    }

    private function inQuietHours(
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
