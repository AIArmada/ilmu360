<?php

declare(strict_types=1);

namespace App\Support\Engagement;

use AIArmada\Engagement\Models\Bookmark;
use AIArmada\Engagement\Models\Follow;
use AIArmada\Engagement\Models\Response;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use App\Models\Series;
use App\Models\User;
use App\Models\Venue;
use App\Shared\Engagement\EngagementReader;
use App\Shared\Engagement\EngagementSubjectType;

/**
 * Interim engagement read adapter over the package interaction tables.
 *
 * Discovery/engagement will own these reads once the feature is extracted.
 * Until then this adapter replaces the User reverse relations with the same
 * follow/bookmark/response queries.
 */
final readonly class EloquentEngagementReader implements EngagementReader
{
    public function followedSubjectIds(string $accountId, EngagementSubjectType $subjectType): array
    {
        return Follow::query()
            ->where('follower_type', (new User)->getMorphClass())
            ->where('follower_id', $accountId)
            ->where('followable_type', $this->subjectMorphClass($subjectType))
            ->where('status', 'active')
            ->pluck('followable_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }

    public function savedEventIds(string $accountId): array
    {
        return Bookmark::query()
            ->where('bookmarker_type', (new User)->getMorphClass())
            ->where('bookmarker_id', $accountId)
            ->where('bookmarkable_type', (new Event)->getMorphClass())
            ->where('status', 'active')
            ->pluck('bookmarkable_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }

    public function goingEventIds(string $accountId): array
    {
        return Response::query()
            ->where('responder_type', (new User)->getMorphClass())
            ->where('responder_id', $accountId)
            ->where('respondable_type', (new Event)->getMorphClass())
            ->where('response_type', 'going')
            ->where('status', 'active')
            ->pluck('respondable_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }

    public function hasSavedEvent(string $accountId, string $eventId): bool
    {
        return Bookmark::query()
            ->where('bookmarker_type', (new User)->getMorphClass())
            ->where('bookmarker_id', $accountId)
            ->where('bookmarkable_type', (new Event)->getMorphClass())
            ->where('bookmarkable_id', $eventId)
            ->where('status', 'active')
            ->exists();
    }

    public function isGoingToEvent(string $accountId, string $eventId): bool
    {
        return Response::query()
            ->where('responder_type', (new User)->getMorphClass())
            ->where('responder_id', $accountId)
            ->where('respondable_type', (new Event)->getMorphClass())
            ->where('respondable_id', $eventId)
            ->where('response_type', 'going')
            ->where('status', 'active')
            ->exists();
    }

    /**
     * @return class-string<Event|Person|Institution|Reference|Series|Venue>
     */
    private function subjectMorphClass(EngagementSubjectType $subjectType): string
    {
        return match ($subjectType) {
            EngagementSubjectType::Venue => (new Venue)->getMorphClass(),
            EngagementSubjectType::Person => (new Person)->getMorphClass(),
            EngagementSubjectType::Institution => (new Institution)->getMorphClass(),
            EngagementSubjectType::Reference => (new Reference)->getMorphClass(),
            EngagementSubjectType::Series => (new Series)->getMorphClass(),
        };
    }
}
