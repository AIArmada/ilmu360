<?php

declare(strict_types=1);

namespace App\Features\Rewards\Actions\GratitudeDigests;

use App\Features\Communications\Api\AccountNotificationPreferences;
use App\Features\Communications\Api\ManagedCommunicationSnapshot;
use App\Features\Communications\Api\ManagedNotificationDelivery;
use App\Features\Communications\Api\ManagedNotificationDispatchRequest;
use App\Features\Communications\Api\NotificationCadence;
use App\Features\Communications\Api\NotificationTrigger;
use App\Features\Rewards\Api\GratitudeDigest;
use App\Features\Rewards\Services\ShareGratitudeDigestMessages;
use App\Features\Rewards\Support\RewardsModel;
use App\Shared\Auth\AccountDirectory;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
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
        private AccountNotificationPreferences $policies,
        private ShareGratitudeDigestMessages $messages,
        private AccountDirectory $accounts,
        private ManagedNotificationDelivery $communications,
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

            $recipientId = (string) $slot->getAttribute('recipient_id');

            if (! $this->accounts->exists($recipientId)) {
                $this->markCancelled($slot, 'recipient-unknown', $now);

                return 'cancelled';
            }

            $policy = $this->policies->resolvePolicy($recipientId, NotificationTrigger::ShareGratitudeDigest);

            if (! $policy->enabled || ! in_array($policy->cadence, [NotificationCadence::Daily, NotificationCadence::Weekly], true)) {
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

            $summary = [
                'text' => (string) ($recomputed['text'] ?? ''),
                'counts' => (array) ($recomputed['counts'] ?? []),
                'sections' => (array) ($recomputed['sections'] ?? []),
                'window' => (array) ($recomputed['window'] ?? []),
                'plan_key' => (string) $slot->getAttribute('plan_key'),
                'slot_id' => (string) $slot->getKey(),
            ];
            $redriveSummary = $summary;
            unset($redriveSummary['slot_id']);

            $communication = $this->communications->adoptOrDispatch(new ManagedNotificationDispatchRequest(
                recipientId: $recipientId,
                idempotencyKey: (string) $slot->getAttribute('plan_key'),
                category: 'digest',
                priority: 'low',
                timezone: $policy->timezone,
                metadata: [
                    'plan_key' => (string) $slot->getAttribute('plan_key'),
                    'slot_id' => (string) $slot->getKey(),
                    'trigger' => $policy->trigger->value,
                ],
                message: $this->messages->build($summary),
                redriveMessage: $this->messages->build($redriveSummary),
                now: $now,
                stalledMinutes: self::STALLED_MINUTES,
            ));

            if (! $communication instanceof ManagedCommunicationSnapshot) {
                $this->markCancelled($slot, 'no-eligible-channel', $now);

                return 'cancelled';
            }

            $slot->forceFill([
                'status' => RewardsModel::SLOT_SENDING,
                'communication_id' => $communication->communicationId,
            ])->save();

            return 'dispatched';
        });
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
                : $this->communications->snapshot((string) $communicationId);

            if (! $communication instanceof ManagedCommunicationSnapshot) {
                // Defensive: the bound aggregate vanished; requeue so the
                // next claim adopts-or-creates afresh.
                $slot->forceFill(['status' => RewardsModel::SLOT_PLANNED])->save();

                return 'skipped';
            }

            $outcomes = $communication->channelStatuses;
            $delivered = $communication->hasDelivered;
            $inFlight = $communication->hasInFlight;
            $ambiguous = $communication->hasAmbiguous;

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
                $queuedAt = $communication->queuedAt;

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
