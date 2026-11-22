<?php

declare(strict_types=1);

namespace App\Features\Events\Services;

use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Models\State;
use App\Features\Discovery\Api\Orm\PublicScheduleModelLeaf;
use App\Features\Discovery\Api\Orm\PublicScheduleOrmPort;
use App\Features\Events\Api\Orm\EventSchedulePageData;
use App\Features\Events\Models\Builders\EventBuilder;
use App\Features\Events\Queries\PostgresEventDiscovery;
use App\Platform\Location\Ui\Concerns\JoinsHierarchyAreaName;
use App\Platform\Models\MorphModelResolver;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

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
     * @param list<string> $referenceIds
     */
    public function forReferenceFamily(
        array $referenceIds,
        int $upcomingLimit,
        int $pastLimit,
        ?CarbonInterface $rangeFrom = null,
        ?CarbonInterface $rangeTo = null,
    ): array {
        $query = $this->events->referenceEventsQuery($referenceIds, [
            'venue.media',
            'venue.addresses' => $this->joinAddressStateName(...),
            'venue.addresses.city',
            'venue.addresses.areaAssignments' => $this->joinAreaName(...),
        ]);

        return $this->forQuery(
            $query,
            $upcomingLimit,
            $pastLimit,
            $rangeFrom,
            $rangeTo,
            fn (Collection $leaves): Collection => $this->filterReferenceLeaves($leaves, $referenceIds),
        );
    }

    /**
     * @param Collection<int, PublicScheduleModelLeaf> $leaves
     * @param list<string> $referenceIds
     * @return Collection<int, PublicScheduleModelLeaf>
     */
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
     * @param MorphToMany<Address, Model, Pivot, 'pivot'> $relation
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

    public function forQuery(
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
