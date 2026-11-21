<?php

declare(strict_types=1);

namespace App\Features\Events\Api\Orm;

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
