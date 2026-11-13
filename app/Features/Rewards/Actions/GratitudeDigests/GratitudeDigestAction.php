<?php

declare(strict_types=1);

namespace App\Features\Rewards\Actions\GratitudeDigests;

use App\Features\Rewards\Api\GratitudeDigest;
use App\Features\Rewards\Api\GratitudeDigestSnapshot;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Scalar gratitude digest boundary.
 *
 * Converts the feature's date-time and model surfaces into the immutable
 * `GratitudeDigest` contract while the three named writers keep their existing
 * transactions, locks, retries, merge/replay rules and payload shape.
 */
final readonly class GratitudeDigestAction implements GratitudeDigest
{
    public function __construct(
        private PlanRewardGratitudeDigestAction $planner,
        private CancelRewardDigestAction $canceller,
        private DispatchDueRewardDigestsAction $dispatcher,
    ) {}

    public function plan(
        string $recipientType,
        string $recipientId,
        array $categories = ['awards'],
        ?DateTimeImmutable $decidedAt = null,
    ): GratitudeDigestSnapshot {
        return $this->snapshot(
            $this->planner->handle($recipientType, $recipientId, $categories, $this->at($decidedAt)),
        );
    }

    public function cancel(
        string $slotId,
        string $reason,
        string $actorType,
        ?string $actorId,
        string $actorRole,
        bool $force = false,
        ?DateTimeImmutable $decidedAt = null,
    ): GratitudeDigestSnapshot {
        return $this->snapshot(
            $this->canceller->handle(
                $slotId, $reason, $actorType, $actorId, $actorRole, $force, $this->at($decidedAt),
            ),
        );
    }

    public function cancelPendingForRecipient(
        string $recipientType,
        string $recipientId,
        string $reason,
        ?DateTimeImmutable $decidedAt = null,
    ): int {
        return CancelRewardDigestAction::cancelPendingForRecipient(
            $recipientType,
            $recipientId,
            $reason,
            $this->at($decidedAt) ?? CarbonImmutable::now('UTC'),
        );
    }

    public function dispatchDue(int $batch = 50, ?DateTimeImmutable $now = null): array
    {
        return $this->dispatcher->handle($batch, $this->at($now));
    }

    private function at(?DateTimeImmutable $at): ?CarbonImmutable
    {
        return $at === null ? null : CarbonImmutable::createFromInterface($at)->setTimezone('UTC');
    }

    private function snapshot(Model $slot): GratitudeDigestSnapshot
    {
        $communicationId = $slot->getAttribute('communication_id');

        return new GratitudeDigestSnapshot(
            id: (string) $slot->getKey(),
            recipientType: (string) $slot->getAttribute('recipient_type'),
            recipientId: (string) $slot->getAttribute('recipient_id'),
            localDay: (string) $slot->getAttribute('local_day'),
            planKey: (string) $slot->getAttribute('plan_key'),
            status: (string) $slot->getAttribute('status'),
            categories: array_values((array) ($slot->getAttribute('categories') ?? [])),
            payload: (array) ($slot->getAttribute('payload') ?? []),
            timezone: (string) $slot->getAttribute('timezone'),
            dueAt: $this->timestamp($slot->getAttribute('due_at')),
            sentAt: $this->timestamp($slot->getAttribute('sent_at')),
            cancelledAt: $this->timestamp($slot->getAttribute('cancelled_at')),
            communicationId: $communicationId === null ? null : (string) $communicationId,
        );
    }

    private function timestamp(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface ? $value->format(DATE_ATOM) : null;
    }
}
