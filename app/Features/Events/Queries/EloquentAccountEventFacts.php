<?php

declare(strict_types=1);

namespace App\Features\Events\Queries;

use App\Features\Events\Api\AccountEventFacts;
use App\Features\Events\Models\Event;
use App\Features\Events\Models\EventSubmission;
use App\Features\Moderation\Api\ModerationActionHistory;
use App\Features\Registration\Api\Orm\RegistrationLookup;
use App\Models\User;

/**
 * Root account-scoped Events fact reader used by account and reward projections.
 *
 * Events will expose this boundary from its own feature once the authoring
 * slice is extracted. Until then this adapter replaces the removed User
 * reverse relations without adding a second write path.
 */
final readonly class EloquentAccountEventFacts implements AccountEventFacts
{
    public function __construct(
        private ModerationActionHistory $moderationActions,
        private RegistrationLookup $registrations,
    ) {}

    public function ownedEventIds(string $accountId): array
    {
        return Event::query()
            ->where('owner_type', (new User)->getMorphClass())
            ->where('owner_id', $accountId)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }

    public function submittedEventIds(string $accountId): array
    {
        return EventSubmission::query()
            ->where('submitter_type', (new User)->getMorphClass())
            ->where('submitter_id', $accountId)
            ->pluck('event_id')
            ->filter()
            ->map(static fn (mixed $id): string => (string) $id)
            ->unique()
            ->values()
            ->all();
    }

    public function eventSubmissionIds(string $accountId): array
    {
        return EventSubmission::query()
            ->where('submitter_type', (new User)->getMorphClass())
            ->where('submitter_id', $accountId)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }

    public function registrationIds(string $accountId): array
    {
        return $this->registrations->registrationIdsForAccount($accountId);
    }

    public function checkinIds(string $accountId): array
    {
        return $this->registrations->checkInIdsForAccount($accountId);
    }

    public function verifiedCheckinIds(string $accountId): array
    {
        return $this->registrations->verifiedCheckInIdsForAccount($accountId);
    }

    public function moderationReviewIds(string $accountId): array
    {
        return $this->moderationActions->idsForAccount($accountId);
    }
}
