<?php

declare(strict_types=1);

namespace App\Features\Events\Queries;

use App\Features\Events\Models\Event;
use App\Features\Events\Models\EventSubmission;
use App\Features\Registration\Models\EventCheckin;
use App\Features\Registration\Models\Registration;
use App\Models\User;
use App\Platform\Moderation\Models\ModerationReview;
use App\Shared\Events\AccountEventFacts;

/**
 * Root account-scoped Events fact reader used by account and reward projections.
 *
 * Events will expose this boundary from its own feature once the authoring
 * slice is extracted. Until then this adapter replaces the removed User
 * reverse relations without adding a second write path.
 */
final readonly class EloquentAccountEventFacts implements AccountEventFacts
{
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
        return Registration::query()
            ->where('registrant_type', (new User)->getMorphClass())
            ->where('registrant_id', $accountId)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }

    public function checkinIds(string $accountId): array
    {
        return EventCheckin::query()
            ->where('attendee_id', $accountId)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }

    public function verifiedCheckinIds(string $accountId): array
    {
        return EventCheckin::query()
            ->where('verified_by_user_id', $accountId)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }

    public function moderationReviewIds(string $accountId): array
    {
        return ModerationReview::query()
            ->where('actioned_by_id', $accountId)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }
}
