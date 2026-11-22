<?php

declare(strict_types=1);

namespace App\Features\Events\Services;

use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Models\State;
use App\Features\Discovery\Api\Orm\PublicScheduleModelLeaf;
use App\Features\Discovery\Api\Orm\PublicScheduleOrmPort;
use App\Features\Events\Api\EventSchedulePageData;
use App\Features\Events\Api\Data\PublicScheduleRenderedCard;
use App\Features\Events\Models\Builders\EventBuilder;
use App\Features\Events\Queries\PostgresEventDiscovery;
use App\Platform\Location\Ui\Concerns\JoinsHierarchyAreaName;
use App\Platform\Models\MorphModelResolver;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Collection;

/**
 * Owner implementation of the upcoming/past event-page schedule split.
 */
final readonly class EventSchedulePageDataService implements EventSchedulePageData
{
    use JoinsHierarchyAreaName;

    public function __construct(private PublicScheduleOrmPort $schedule, private PostgresEventDiscovery $events) {}

    /**
     * Reference family schedule selection is owned entirely by Events:
     * no Eloquent builder, model query or relation callback crosses into References.
     *
     * @param  list<string>  $referenceIds
     */
    public function forInstitution(
        string $institutionId,
        int $upcomingLimit,
        int $pastLimit,
        ?CarbonInterface $rangeFrom = null,
        ?CarbonInterface $rangeTo = null,
        array $savedEventIds = [],
        array $goingEventIds = [],
    ): array {
        $query = $this->events->institutionScheduleQuery($institutionId, [
            'venue.media',
            'venue.addresses' => $this->joinAddressStateName(...),
            'venue.addresses.city',
            'venue.addresses.areaAssignments' => $this->joinAreaName(...),
        ]);

        return $this->renderSchedulePage(
            $this->forQuery($query, $upcomingLimit, $pastLimit, $rangeFrom, $rangeTo),
            $savedEventIds, $goingEventIds, true, false,
            'institution_detail_upcoming_events', 'institution-detail-card',
        );
    }

    public function forReferenceFamily(
        array $referenceIds,
        int $upcomingLimit,
        int $pastLimit,
        ?CarbonInterface $rangeFrom = null,
        ?CarbonInterface $rangeTo = null,
        array $savedEventIds = [],
        array $goingEventIds = [],
    ): array {
        $query = $this->events->referenceEventsQuery($referenceIds, [
            'venue.media',
            'venue.addresses' => $this->joinAddressStateName(...),
            'venue.addresses.city',
            'venue.addresses.areaAssignments' => $this->joinAreaName(...),
        ]);

        return $this->renderSchedulePage(
            $this->forQuery(
                $query, $upcomingLimit, $pastLimit, $rangeFrom, $rangeTo,
                fn (Collection $leaves): Collection => $this->filterReferenceLeaves($leaves, $referenceIds),
            ),
            $savedEventIds, $goingEventIds, false, true,
            'reference_detail_upcoming_events', 'reference-detail-card',
        );
    }

    /**
     * @param  Collection<int, PublicScheduleModelLeaf>  $leaves
     * @param  list<string>  $referenceIds
     * @return Collection<int, PublicScheduleModelLeaf>
     */
    /**
     * Server-render inside Events so no mutable models cross feature boundaries.
     *
     * @param array{upcoming: Collection<int, PublicScheduleModelLeaf>, past: Collection<int, PublicScheduleModelLeaf>, upcoming_total: int, past_total: int, upcoming_overall_total: int, past_overall_total: int} $page
     * @param list<string> $savedEventIds
     * @param list<string> $goingEventIds
     * @return array{upcoming: Collection<int, PublicScheduleRenderedCard>, past: Collection<int, PublicScheduleRenderedCard>, upcoming_total: int, past_total: int, upcoming_overall_total: int, past_overall_total: int}
     */
    private function renderSchedulePage(
        array $page,
        array $savedEventIds,
        array $goingEventIds,
        bool $hideLocation,
        bool $hideReference,
        string $signalComponent,
        string $testIdPrefix,
    ): array {
        $render = static function (PublicScheduleModelLeaf $leaf, string $section) use (
            $savedEventIds, $goingEventIds, $hideLocation, $hideReference, $signalComponent, $testIdPrefix,
        ): PublicScheduleRenderedCard {
            $eventId = $leaf->eventId();

            return new PublicScheduleRenderedCard(
                id: $leaf->id(),
                eventId: $eventId,
                eventStatus: $leaf->eventStatus(),
                entityType: $leaf->entityType(),
                html: view('components.events.render-schedule-card', [
                    'scheduleLeaf' => $leaf,
                    'savedEventIds' => $savedEventIds,
                    'isGoing' => in_array($eventId, $goingEventIds, true),
                    'hideLocation' => $hideLocation,
                    'hideReference' => $hideReference,
                    'signalComponent' => $signalComponent,
                    'testidPrefix' => $testIdPrefix,
                    'wireKey' => $section.'-'.$leaf->entityType().'-'.$leaf->id(),
                ])->render(),
            );
        };

        return [
            ...$page,
            'upcoming' => $page['upcoming']->map(static fn (PublicScheduleModelLeaf $leaf): PublicScheduleRenderedCard => $render($leaf, 'upcoming')),
            'past' => $page['past']->map(static fn (PublicScheduleModelLeaf $leaf): PublicScheduleRenderedCard => $render($leaf, 'past')),
        ];
    }

    private function filterReferenceLeaves(Collection $leaves, array $referenceIds): Collection
    {
        $referenceClass = MorphModelResolver::resolve('reference');
        $referenceMorphType = (new $referenceClass)->getMorphClass();

        return $leaves
            ->map(static function (PublicScheduleModelLeaf $leaf) use ($referenceIds, $referenceMorphType): ?PublicScheduleModelLeaf {
                $event = $leaf->event();
                $occurrence = $leaf->occurrence();

                if ($event->references->contains(static fn ($reference): bool => in_array((string) $reference->getKey(), $referenceIds, true))) {
                    return $leaf;
                }

                $matchingReference = static fn ($reference, ?string $sessionId): bool => $reference->visibility === 'public'
                    && $reference->referenceable_type === $referenceMorphType
                    && in_array((string) $reference->referenceable_id, $referenceIds, true)
                    && $reference->event_session_id === $sessionId;

                if ($occurrence->references->contains(static fn ($reference): bool => $matchingReference($reference, null))) {
                    return $leaf;
                }

                $sessions = collect($leaf->sessions())
                    ->filter(static fn ($session): bool => $occurrence->references
                        ->contains(static fn ($reference): bool => $matchingReference($reference, (string) $session->getKey())))
                    ->values();

                return $sessions->isEmpty()
                    ? null
                    : $leaf->withSessions($sessions->map(static fn ($session): string => (string) $session->getKey())->all());
            })
            ->filter()
            ->values();
    }

    /**
     * @param  MorphToMany<Address, Model, Pivot, 'pivot'>  $relation
     * @return MorphToMany<Address, Model, Pivot, 'pivot'>
     */
    private function joinAddressStateName(MorphToMany $relation): MorphToMany
    {
        $addressesTable = (new Address)->getTable();
        $statesTable = (new State)->getTable();

        return $relation
            ->addSelect("{$addressesTable}.*")
            ->leftJoin($statesTable, "{$addressesTable}.state_id", '=', "{$statesTable}.id")
            ->addSelect("{$statesTable}.name as hierarchy_state_name");
    }

    private function forQuery(
        EventBuilder $baseQuery,
        int $upcomingLimit,
        int $pastLimit,
        ?CarbonInterface $rangeFrom = null,
        ?CarbonInterface $rangeTo = null,
        ?callable $filterLeaves = null,
    ): array {
        $eventIds = (clone $baseQuery)->reorder()->pluck('events.id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
        $leaves = $this->schedule->leavesForEventIds($eventIds);

        if ($filterLeaves !== null) {
            /** @var Collection<int, PublicScheduleModelLeaf> $leaves */
            $leaves = $filterLeaves($leaves);
        }

        $now = now();
        $endInstant = static fn (PublicScheduleModelLeaf $leaf): ?CarbonInterface => $leaf->endsAt() ?? $leaf->startsAt();

        $upcomingOverall = $leaves
            ->filter(fn (PublicScheduleModelLeaf $leaf): bool => ($endInstant($leaf)?->greaterThanOrEqualTo($now)) ?? false)
            ->values();

        $upcoming = $upcomingOverall
            ->filter(static function (PublicScheduleModelLeaf $leaf) use ($rangeFrom, $rangeTo): bool {
                if (! $rangeFrom instanceof CarbonInterface || ! $rangeTo instanceof CarbonInterface) {
                    return true;
                }

                $startsAt = $leaf->startsAt();

                return $startsAt instanceof CarbonInterface
                    && $startsAt >= $rangeFrom
                    && $startsAt < $rangeTo;
            })
            ->values();

        $past = $leaves
            ->filter(fn (PublicScheduleModelLeaf $leaf): bool => ($endInstant($leaf)?->lessThan($now)) ?? false)
            ->reverse()
            ->values();

        return [
            'upcoming' => $upcoming->take($upcomingLimit),
            'past' => $past->take($pastLimit),
            'upcoming_total' => $upcoming->count(),
            'past_total' => $past->count(),
            'upcoming_overall_total' => $upcomingOverall->count(),
            'past_overall_total' => $past->count(),
        ];
    }
}
