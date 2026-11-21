<?php

declare(strict_types=1);

namespace App\Features\Events\Api\Orm;

use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventSession;
use App\Features\Events\Api\EventScheduleFactSet;
use App\Features\Events\Models\Builders\EventBuilder;
use App\Features\Events\Models\Event;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Narrow Events-owned read seam for the public schedule.
 *
 * This is the only place where public-schedule business predicates, status and
 * visibility rules, occurrence/session eligibility and schedule facts are
 * composed. Immutable facts cross into Discovery for filtering and ordering;
 * model hydration is limited to selected rows needed by page rendering.
 *
 * @internal Model transport exists solely for Livewire page identity and
 *           relation rendering (media, institution, references, persons).
 *
 * Consumers: Discovery PublicScheduleDiscoveryService and the Events,
 * Institution, Person and Reference public schedule pages.
 */
interface PublicScheduleEventReads
{
    /**
     * Public event candidates matching the owner-applied parent filters, with
     * immutable eligible schedule facts for Discovery's leaf-level filters.
     *
     * @param  array<string, mixed>  $filters
     */
    public function publicScheduleFactSet(?string $query, array $filters): EventScheduleFactSet;

    /**
     * Homepage candidate events: featured occurrences plus events followed by
     * the supplied person, institution or reference ids.
     *
     * @param  list<string>  $personIds
     * @param  list<string>  $institutionIds
     * @param  list<string>  $referenceIds
     */
    public function homepageScheduleFacts(
        array $personIds,
        array $institutionIds,
        array $referenceIds,
    ): EventScheduleFactSet;

    /**
     * Schedule facts for page-owned event ids. Discovery uses only ids from
     * its input models; Events owns the query and eligibility policy.
     *
     * @param  list<string>  $eventIds
     * @param  array<string, float>  $distanceByEventId
     */
    public function scheduleFactsForEventIds(array $eventIds, array $distanceByEventId = []): EventScheduleFactSet;

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
     * Rehydrate search-page ids into schedule models, preserving the search
     * result order and dropping ids that no longer resolve.
     *
     * @param  list<string>  $eventIds
     * @param  array<string, float>  $distanceByEventId
     * @return EloquentCollection<int, Event>
     */
    public function hydrateScheduleEvents(
        array $eventIds,
        bool $withOccurrenceReferences = false,
        array $distanceByEventId = [],
    ): EloquentCollection;

    /**
     * Resolve the public occurrence for an event by its public slug.
     */
    public function findPublicOccurrence(Event $event, string $slug): ?EventOccurrence;

    /**
     * Resolve the public session for an occurrence by its public slug.
     */
    public function findPublicSession(EventOccurrence $occurrence, string $slug): ?EventSession;

    /**
     * Base event query for an institution detail page schedule section.
     *
     * The owner applies active/public state, the reference visibility rule and
     * the supplied presenter relations; the page only splits and renders the
     * result.
     *
     * @param  array<int|string, mixed>  $relations
     */
    public function institutionScheduleQuery(string $institutionId, array $relations): EventBuilder;
}
