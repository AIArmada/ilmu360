<?php

declare(strict_types=1);

namespace App\Support\Engagement;

use AIArmada\Engagement\Models\Bookmark;
use AIArmada\Engagement\Models\Follow;
use AIArmada\Engagement\Models\Response;
use App\Features\Engagement\Api\EventEngagement;
use App\Features\Engagement\Api\EventEngagementState;
use App\Features\Engagement\Api\FollowState;
use App\Features\Engagement\Api\SubjectFollows;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use App\Models\Series;
use App\Models\User;
use App\Models\Venue;
use App\Shared\Engagement\EngagementActivity;
use App\Shared\Engagement\EngagementReader;
use App\Shared\Engagement\EngagementSubjectType;
use BackedEnum;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Root read adapter over the package interaction tables.
 *
 * Discovery/engagement will own these reads once the feature is extracted.
 * Until then this adapter replaces the User reverse relations with the same
 * follow/bookmark/response queries and supplies the Engagement feature Api.
 */
final readonly class EloquentEngagementReader implements EngagementReader, EventEngagement, SubjectFollows
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

    /**
     * @return list<string>
     */
    public function followerAccountIds(EngagementSubjectType $subjectType, string $subjectId): array
    {
        return Follow::query()
            ->where('follower_type', (new User)->getMorphClass())
            ->where('followable_type', $this->subjectMorphClass($subjectType))
            ->where('followable_id', $subjectId)
            ->where('status', 'active')
            ->pluck('follower_id')
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

    public function stateFor(string $accountId, string $eventId): EventEngagementState
    {
        return $this->statesFor($accountId, [$eventId])[$eventId]
            ?? new EventEngagementState(false, false, 0, 0);
    }

    /**
     * @param  list<string>  $eventIds
     * @return array<string, EventEngagementState>
     */
    public function statesFor(string $accountId, array $eventIds): array
    {
        $eventIds = $this->uniqueIds($eventIds);

        if ($eventIds === []) {
            return [];
        }

        $eventMorphClass = (new Event)->getMorphClass();

        $savedIds = Bookmark::query()
            ->where('bookmarker_type', (new User)->getMorphClass())
            ->where('bookmarker_id', $accountId)
            ->where('bookmarkable_type', $eventMorphClass)
            ->whereIn('bookmarkable_id', $eventIds)
            ->where('status', 'active')
            ->pluck('bookmarkable_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        $goingIds = Response::query()
            ->where('responder_type', (new User)->getMorphClass())
            ->where('responder_id', $accountId)
            ->where('respondable_type', $eventMorphClass)
            ->whereIn('respondable_id', $eventIds)
            ->where('response_type', 'going')
            ->where('status', 'active')
            ->pluck('respondable_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        $savesCounts = Bookmark::query()
            ->where('bookmarkable_type', $eventMorphClass)
            ->whereIn('bookmarkable_id', $eventIds)
            ->where('status', 'active')
            ->selectRaw('bookmarkable_id, count(*) as aggregate')
            ->groupBy('bookmarkable_id')
            ->pluck('aggregate', 'bookmarkable_id')
            ->mapWithKeys(static fn (mixed $count, mixed $id): array => [(string) $id => (int) $count])
            ->all();

        $goingCounts = Response::query()
            ->where('respondable_type', $eventMorphClass)
            ->whereIn('respondable_id', $eventIds)
            ->where('response_type', 'going')
            ->where('status', 'active')
            ->selectRaw('respondable_id, count(*) as aggregate')
            ->groupBy('respondable_id')
            ->pluck('aggregate', 'respondable_id')
            ->mapWithKeys(static fn (mixed $count, mixed $id): array => [(string) $id => (int) $count])
            ->all();

        $savedLookup = array_fill_keys($savedIds, true);
        $goingLookup = array_fill_keys($goingIds, true);

        $states = [];

        foreach ($eventIds as $eventId) {
            $states[$eventId] = new EventEngagementState(
                isSaved: isset($savedLookup[$eventId]),
                isGoing: isset($goingLookup[$eventId]),
                savesCount: $savesCounts[$eventId] ?? 0,
                goingCount: $goingCounts[$eventId] ?? 0,
            );
        }

        return $states;
    }

    public function followStateFor(
        string $accountId,
        EngagementSubjectType $subjectType,
        string $subjectId,
    ): FollowState {
        return $this->followStatesFor($accountId, $subjectType, [$subjectId])[$subjectId]
            ?? new FollowState(false, 0);
    }

    /**
     * @param  list<string>  $subjectIds
     * @return array<string, FollowState>
     */
    public function followStatesFor(
        string $accountId,
        EngagementSubjectType $subjectType,
        array $subjectIds,
    ): array {
        $subjectIds = $this->uniqueIds($subjectIds);

        if ($subjectIds === []) {
            return [];
        }

        $subjectMorphClass = $this->subjectMorphClass($subjectType);

        $followedIds = Follow::query()
            ->where('follower_type', (new User)->getMorphClass())
            ->where('follower_id', $accountId)
            ->where('followable_type', $subjectMorphClass)
            ->whereIn('followable_id', $subjectIds)
            ->where('status', 'active')
            ->pluck('followable_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        $followerCounts = Follow::query()
            ->where('followable_type', $subjectMorphClass)
            ->whereIn('followable_id', $subjectIds)
            ->where('status', 'active')
            ->selectRaw('followable_id, count(*) as aggregate')
            ->groupBy('followable_id')
            ->pluck('aggregate', 'followable_id')
            ->mapWithKeys(static fn (mixed $count, mixed $id): array => [(string) $id => (int) $count])
            ->all();

        $followedLookup = array_fill_keys($followedIds, true);

        $states = [];

        foreach ($subjectIds as $subjectId) {
            $states[$subjectId] = new FollowState(
                isFollowing: isset($followedLookup[$subjectId]),
                followersCount: $followerCounts[$subjectId] ?? 0,
            );
        }

        return $states;
    }

    public function followerCount(EngagementSubjectType $subjectType, string $subjectId): int
    {
        return Follow::query()
            ->where('followable_type', $this->subjectMorphClass($subjectType))
            ->where('followable_id', $subjectId)
            ->where('status', 'active')
            ->count();
    }

    public function bookmarkActivity(string $bookmarkId): ?EngagementActivity
    {
        return $this->activity(
            Bookmark::query()->whereKey($bookmarkId)->first(),
            'bookmarker_type',
            'bookmarker_id',
            'bookmarked_at',
        );
    }

    public function responseActivity(string $responseId): ?EngagementActivity
    {
        return $this->activity(
            Response::query()->whereKey($responseId)->first(),
            'responder_type',
            'responder_id',
            'responded_at',
        );
    }

    public function followActivity(string $followId): ?EngagementActivity
    {
        return $this->activity(
            Follow::query()->whereKey($followId)->first(),
            'follower_type',
            'follower_id',
            'followed_at',
        );
    }

    public function hasEarlierBookmarkActivity(
        string $actorType,
        string $actorId,
        CarbonImmutable $before,
        string $excludingId,
    ): bool {
        return $this->hasEarlierActivity(
            Bookmark::class,
            'bookmarker_type',
            'bookmarker_id',
            'bookmarked_at',
            $actorType,
            $actorId,
            $before,
            $excludingId,
        );
    }

    public function hasEarlierResponseActivity(
        string $actorType,
        string $actorId,
        CarbonImmutable $before,
        string $excludingId,
    ): bool {
        return $this->hasEarlierActivity(
            Response::class,
            'responder_type',
            'responder_id',
            'responded_at',
            $actorType,
            $actorId,
            $before,
            $excludingId,
        );
    }

    public function hasEarlierFollowActivity(
        string $actorType,
        string $actorId,
        CarbonImmutable $before,
        string $excludingId,
    ): bool {
        return $this->hasEarlierActivity(
            Follow::class,
            'follower_type',
            'follower_id',
            'followed_at',
            $actorType,
            $actorId,
            $before,
            $excludingId,
        );
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private function hasEarlierActivity(
        string $modelClass,
        string $actorTypeColumn,
        string $actorIdColumn,
        string $occurredColumn,
        string $actorType,
        string $actorId,
        CarbonImmutable $before,
        string $excludingId,
    ): bool {
        return $modelClass::query()
            ->where($actorTypeColumn, $actorType)
            ->where($actorIdColumn, $actorId)
            ->where($occurredColumn, '<', $before->setTimezone(config('app.timezone', 'UTC')))
            ->whereKeyNot($excludingId)
            ->exists();
    }

    private function activity(
        ?Model $source,
        string $actorTypeColumn,
        string $actorIdColumn,
        string $occurredColumn,
    ): ?EngagementActivity {
        if (! $source instanceof Model) {
            return null;
        }

        $actorType = $this->scalarString($source->getAttribute($actorTypeColumn));
        $actorId = $this->scalarString($source->getAttribute($actorIdColumn));

        if ($actorType === null || $actorId === null) {
            return null;
        }

        $status = $source->getAttribute('status');
        $status = $status instanceof BackedEnum
            ? (string) $status->value
            : $this->scalarString($status);

        $occurredAt = $source->getAttribute($occurredColumn) ?? $source->getAttribute('created_at');
        $occurredAt = $occurredAt instanceof DateTimeInterface
            ? CarbonImmutable::instance($occurredAt)->utc()
            : null;

        $metadata = $source->getAttribute('metadata');
        $metadata = is_array($metadata) ? $metadata : [];

        return new EngagementActivity(
            id: (string) $source->getKey(),
            actorType: $actorType,
            actorId: $actorId,
            status: $status,
            occurredAt: $occurredAt,
            metadata: $metadata,
        );
    }

    /**
     * @param  list<string>  $ids
     * @return list<string>
     */
    private function uniqueIds(array $ids): array
    {
        return array_values(array_unique(array_filter(
            $ids,
            static fn (string $id): bool => $id !== '',
        )));
    }

    private function scalarString(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_object($value) && property_exists($value, 'value') && is_string($value->value)) {
            return $value->value;
        }

        return null;
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
