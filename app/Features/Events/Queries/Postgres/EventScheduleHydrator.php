<?php

declare(strict_types=1);

namespace App\Features\Events\Queries\Postgres;

use AIArmada\Events\Contracts\EventSearchRelationProvider;
use AIArmada\Persons\Enums\AssignmentStatus;
use App\Features\Events\Api\Enums\EventVisibility;
use App\Features\Events\Models\Builders\EventBuilder;
use App\Features\Events\Models\Event;
use App\Platform\Models\MorphModelResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Internal hydrator for public schedule events and card relation graphs.
 */
final readonly class EventScheduleHydrator
{
    public function __construct(
        private EventSearchRelationProvider $relationProvider,
    ) {}

    /**
     * @param  list<string>  $eventIds
     * @param  array<string, float>  $distanceByEventId
     * @param  list<string>|null  $occurrenceIds
     * @return EloquentCollection<int, Event>
     */
    public function hydrateScheduleEvents(
        array $eventIds,
        bool $withOccurrenceReferences = false,
        array $distanceByEventId = [],
        ?array $occurrenceIds = null,
    ): EloquentCollection {
        if ($eventIds === []) {
            return new EloquentCollection;
        }

        $relations = $this->scheduleCardRelations();

        if ($withOccurrenceReferences) {
            $relations[] = 'occurrences.references';
        }

        if ($occurrenceIds !== null && $occurrenceIds !== []) {
            $relations['occurrences'] = function (Relation $query) use ($occurrenceIds): void {
                $this->constrainPublicSchedule($query);
                $query
                    ->whereIn('id', $occurrenceIds)
                    ->orderBy('starts_at')
                    ->orderBy('created_at')
                    ->orderBy('id');
            };
        }

        /** @var EloquentCollection<string, Event> $events */
        $events = $this->hydrateEventsForIds($eventIds, $relations);

        if ($occurrenceIds !== null) {
            $groupedEvents = $events->filter(fn (Event $event): bool => is_array($event->metadata['public_submission_group'] ?? null)
                && is_string($event->metadata['public_submission_group']['key'] ?? null));

            if ($groupedEvents->isNotEmpty()) {
                $groupedEvents->loadMissing('goingWindowOccurrences');
            }
        }

        $orderedEvents = collect($eventIds)
            ->map(static fn (string $eventId): ?Event => $events->get($eventId))
            ->filter()
            ->values();

        foreach ($orderedEvents as $event) {
            $eventId = (string) $event->getKey();

            if (array_key_exists($eventId, $distanceByEventId)) {
                $event->setAttribute('distance_km', $distanceByEventId[$eventId]);
            }
        }

        return new EloquentCollection($orderedEvents->all());
    }

    /**
     * Reapply current public parent and occurrence state to an id rehydration
     * query. Search engines filter against indexed values, which may be stale.
     */
    public function constrainCurrentPublicEventState(EventBuilder $query): EventBuilder
    {
        return $query
            ->whereNotNull('events.published_at')
            ->whereIn('events.status', Event::PUBLIC_STATUSES)
            ->where('events.visibility', EventVisibility::Public->value)
            ->whereHas('occurrences', function (Builder $occurrences): void {
                $occurrences
                    ->whereIn('status', Event::PUBLIC_SCHEDULE_STATUSES)
                    ->whereIn('visibility', Event::PUBLIC_SCHEDULE_VISIBILITIES);
            });
    }

    /**
     * @template TRelatedModel of \Illuminate\Database\Eloquent\Model
     * @template TDeclaringModel of \Illuminate\Database\Eloquent\Model
     * @template TResult
     *
     * @param  Relation<TRelatedModel, TDeclaringModel, TResult>  $query
     */
    public function constrainPublicSchedule(Relation $query): void
    {
        $query
            ->whereIn('status', Event::PUBLIC_SCHEDULE_STATUSES)
            ->whereIn('visibility', Event::PUBLIC_SCHEDULE_VISIBILITIES);
    }

    /**
     * @return array<int|string, string|\Closure>
     */
    public function scheduleFactRelations(): array
    {
        $publicScheduleScope = $this->constrainPublicSchedule(...);

        return [
            'timeExpressions',
            'occurrences' => function (Relation $query) use ($publicScheduleScope): void {
                $publicScheduleScope($query);
                $query
                    ->orderBy('starts_at')
                    ->orderBy('created_at')
                    ->orderBy('id');
            },
            'occurrences.timeExpressions',
            'occurrences.sessions' => function (Relation $query) use ($publicScheduleScope): void {
                $publicScheduleScope($query);
                $query
                    ->orderBy('sort_order')
                    ->orderBy('starts_at')
                    ->orderBy('created_at')
                    ->orderBy('id');
            },
            'occurrences.sessions.timeExpressions',
        ];
    }

    /**
     * @return array<int|string, mixed>
     */
    private function scheduleCardRelations(): array
    {
        $dropped = [
            'goingWindowOccurrences',
            'languageRecords',
            'displayOccurrence.media',
            'occurrences.media',
            'occurrences.sessions.media',
            'occurrences.sessions.involvements',
            'occurrences.sessions.involvements.involveable',
            'primaryLocation.venue',
            'occurrences.locations.venue',
            'occurrences.sessions.locations.venue',
            'venue',
            'venue.addresses.country',
            'venue.addresses.state',
            'venue.addresses.city',
            'venue.addresses.areaAssignments.area',
            'primaryLocationVenue',
            'primaryLocationVenue.addresses.country',
            'primaryLocationVenue.addresses.state',
            'primaryLocationVenue.addresses.city',
            'primaryLocationVenue.addresses.areaAssignments.area',
            'persons.titleAssignments.title.category',
        ];

        $relations = collect($this->scheduleRelations())
            // Keys are strings for constrained relations, but bare nested paths
            // arrive with numeric keys — match those by value, or Eloquent
            // resurrects the dropped parents unconstrained to satisfy them.
            ->reject(function (mixed $constraints, int|string $key) use ($dropped): bool {
                $path = is_string($key) ? $key : (is_string($constraints) ? $constraints : null);

                return is_string($path)
                    && ($path === 'primaryOccurrence'
                        || str_starts_with($path, 'primaryOccurrence.')
                        || in_array($path, $dropped, true));
            })
            ->all();

        $relations['occurrences.media'] = static function (Relation $query): void {
            $query->whereIn('collection_name', ['cover', 'poster']);
        };
        $relations['persons.titleAssignments'] = static function (Relation $query): void {
            $query
                ->where('status', AssignmentStatus::Active)
                ->with('title.category');
        };

        return $relations;
    }

    /**
     * @return array<string, mixed>
     */
    private function scheduleRelations(): array
    {
        $publicScheduleScope = $this->constrainPublicSchedule(...);

        return [
            ...$this->relationProvider->relations(),
            'primaryLocation.venue',
            'occurrences' => function (Relation $query) use ($publicScheduleScope): void {
                $publicScheduleScope($query);
                $query
                    ->orderBy('starts_at')
                    ->orderBy('created_at')
                    ->orderBy('id');
            },
            'occurrences.media',
            'occurrences.timeExpressions',
            'occurrences.locations' => static function (Relation $query): void {
                $query->whereNull('event_session_id')->where('status', 'active')->where('visibility', 'public');
            },
            'occurrences.locations.venue',
            'occurrences.locations.venueSpace',
            'occurrences.sessions' => function (Relation $query) use ($publicScheduleScope): void {
                $publicScheduleScope($query);
                $query
                    ->orderBy('sort_order')
                    ->orderBy('starts_at')
                    ->orderBy('created_at')
                    ->orderBy('id');
            },
            'occurrences.sessions.media',
            'occurrences.sessions.timeExpressions',
            'occurrences.sessions.locations.venue',
            'occurrences.sessions.locations.venueSpace',
            'occurrences.sessions.involvements' => fn (Relation $query) => $query
                ->where('status', 'active')
                ->where('visibility', 'public'),
            'occurrences.sessions.involvements.involveable' => function (MorphTo $relation): void {
                // Cards render formatted_name per involveable; preload the full
                // title graph so the accessor never falls back to per-person queries.
                $relation->morphWith([
                    MorphModelResolver::resolve('person') => [
                        'titleAssignments' => fn (Relation $query) => $query
                            ->where('status', AssignmentStatus::Active)
                            ->with('title.category'),
                    ],
                ]);
            },
        ];
    }

    /**
     * Hydrate current public parent events for selected public schedule ids.
     *
     * @param  list<string>  $eventIds
     * @param  array<int|string, mixed>  $relations
     * @return EloquentCollection<string, Event>
     */
    private function hydrateEventsForIds(array $eventIds, array $relations): EloquentCollection
    {
        return $this->constrainCurrentPublicEventState(
            Event::query()
                ->with($relations)
                ->whereKey($eventIds),
        )
            ->get()
            ->keyBy('id');
    }
}
