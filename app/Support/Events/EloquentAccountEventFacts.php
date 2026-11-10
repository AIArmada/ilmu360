<?php

declare(strict_types=1);

namespace App\Support\Events;

use App\Models\Event;
use App\Models\EventCheckin;
use App\Models\EventSubmission;
use App\Models\ModerationReview;
use App\Models\Registration;
use App\Models\User;
use App\Shared\Events\AccountEventFacts;

/**
 * Interim account-scoped Events reader over the current tables.
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
