<?php

declare(strict_types=1);

namespace App\Features\Events\Api\Orm;

use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventSession;
use App\Features\Events\Models\Event;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Narrow Events-owned read seam for the public schedule.
 *
 * This is the only place where public-schedule business predicates, status and
 * visibility rules, occurrence/session matching and the card eager-load graph
 * are composed. Consumers may render the hydrated models and read their loaded
 * relations; they must not add event-domain predicates, joins or writes, and
 * must not build their own Event queries.
 *
 * @internal Model transport exists solely for Livewire page identity, relation
 *           rendering (media, institution, references, persons) and the
 *           Discovery schedule leaf conversion. Ordinary cross-owner reads use
 *           Events scalar/card APIs instead.
 *
 * Consumers: Discovery PublicScheduleDiscoveryService and the Events,
 * Institution, Person and Reference public schedule pages.
 */
interface PublicScheduleEventReads
{
    /**
     * Public events matching the schedule filters, hydrated with the supplied
     * card relations.
     *
     * Parent-projected time filters are applied by the owner exactly as the
     * historical discovery query did; callers pass the same filter array.
     *
     * @param  array<string, mixed>  $filters
     * @param  array<int|string, mixed>  $relations
     * @return EloquentCollection<int, Event>
     */
    public function publicScheduleEvents(?string $query, array $filters, array $relations): EloquentCollection;

    /**
     * Homepage candidate events: featured occurrences plus events followed by
     * the supplied person, institution or reference ids.
     *
     * @param  list<string>  $personIds
     * @param  list<string>  $institutionIds
     * @param  list<string>  $referenceIds
     * @param  array<int|string, mixed>  $relations
     * @return EloquentCollection<int, Event>
     */
    public function homepageScheduleEvents(
        array $personIds,
        array $institutionIds,
        array $referenceIds,
        array $relations,
    ): EloquentCollection;

    /**
     * Event ids matched by the homepage follow predicates, used to order the
     * non-featured homepage selection.
     *
     * @param  list<string>  $personIds
     * @param  list<string>  $institutionIds
     * @param  list<string>  $referenceIds
     * @return list<string>
     */
    public function homepageFollowedEventIds(
        array $personIds,
        array $institutionIds,
        array $referenceIds,
    ): array;

    /**
     * Card rendering relations for the public schedule.
     *
     * @return array<int|string, mixed>
     */
    public function publicScheduleCardRelations(): array;

    /**
     * Load the schedule graph on already-hydrated events.
     *
     * @param  EloquentCollection<int, Event>  $events
     */
    public function loadScheduleGraph(EloquentCollection $events, bool $withOccurrenceReferences = false): void;

    /**
     * Rehydrate search-page ids into schedule models, preserving the search
     * result order and dropping ids that no longer resolve.
     *
     * @param  list<string>  $eventIds
     * @return EloquentCollection<int, Event>
     */
    public function hydrateScheduleEvents(array $eventIds): EloquentCollection;

    /**
     * Resolve the public occurrence for an event by its public slug.
     */
    public function findPublicOccurrence(Event $event, string $slug): ?EventOccurrence;

    /**
     * Resolve the public session for an occurrence by its public slug.
     */
    public function findPublicSession(EventOccurrence $occurrence, string $slug): ?EventSession;
}
