<?php

declare(strict_types=1);

namespace Tests\Feature\Rewards\Host\Support;

use App\Features\Rewards\Api\GratitudeDigest;
use App\Features\Rewards\Api\GratitudeDigestSnapshot;
use DateTimeImmutable;

final class Rw1FakeGratitudeDigest implements GratitudeDigest
{
    /** @var list<array<int, mixed>> */
    public array $calls = [];

    public function plan(
        string $recipientType,
        string $recipientId,
        array $categories = ['awards'],
        ?DateTimeImmutable $decidedAt = null,
    ): GratitudeDigestSnapshot {
        $this->calls[] = ['plan', $recipientType, $recipientId, $categories, $decidedAt?->format(DATE_ATOM)];

        return $this->snapshot();
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
        $this->calls[] = ['cancel', $slotId, $reason, $actorType, $actorId, $actorRole, $force, $decidedAt?->format(DATE_ATOM)];

        return $this->snapshot('cancelled');
    }

    public function cancelPendingForRecipient(
        string $recipientType,
        string $recipientId,
        string $reason,
        ?DateTimeImmutable $decidedAt = null,
    ): int {
        $this->calls[] = ['cancelPending', $recipientType, $recipientId, $reason, $decidedAt?->format(DATE_ATOM)];

        return 1;
    }

    public function dispatchDue(int $batch = 50, ?DateTimeImmutable $now = null): array
    {
        $this->calls[] = ['dispatchDue', $batch, $now?->format(DATE_ATOM)];

        return ['dispatched' => 1, 'reconciled' => 0, 'cancelled' => 0, 'skipped' => 0];
    }

    private function snapshot(string $status = 'planned'): GratitudeDigestSnapshot
    {
        return new GratitudeDigestSnapshot(
            id: 'slot-1',
            recipientType: 'user',
            recipientId: 'recipient-1',
            localDay: '2026-06-16',
            planKey: 'gratitude:recipient-1:2026-06-16',
            status: $status,
            categories: ['awards'],
            payload: ['total' => 0],
            timezone: 'UTC',
            dueAt: '2026-06-16T08:00:00+00:00',
            sentAt: null,
            cancelledAt: null,
            communicationId: null,
        );
    }
}
