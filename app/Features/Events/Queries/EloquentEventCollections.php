<?php

declare(strict_types=1);

namespace App\Features\Events\Queries;

use App\Features\Discovery\Api\EventListPayloads;
use App\Features\Events\Api\Enums\EventKeyPersonRole;
use App\Features\Events\Api\EventCollections;
use App\Features\Events\Api\EventScheduleSql;
use App\Features\Events\Models\Event;
use App\Features\Events\Models\EventKeyPerson;
use App\Features\References\Api\ReferenceQueries;
use App\Platform\Api\Frontend\SearchPayloadTransformer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final readonly class EloquentEventCollections implements EventCollections
{
    public function __construct(
        private EventListPayloads $eventListPayloads,
        private EventScheduleSql $eventScheduleSql,
        private ReferenceQueries $referenceQueries,
        private SearchPayloadTransformer $searchPayloadTransformer,
    ) {}

    public function forInstitution(
        string $institutionId,
        bool $past,
        int $perPage,
        ?\DateTimeInterface $now = null,
    ): array {
        return $this->eventCards(
            Event::query()
                ->where('institution_id', $institutionId)
                ->active()
                ->whereRaw($this->directionSql($past), [$now ?? now()])
                ->with([
                    'institution.media',
                    'venue.addresses.country',
                    'persons.media',
                    'keyPeople.person',
                    'media',
                    'references' => fn (BelongsToMany $query) => $query->active(),
                    'primaryOccurrence',
                    'classifications.term',
                    'timeExpressions',
                ])
                ->orderBy('starts_at', $past ? 'desc' : 'asc'),
            $perPage,
        );
    }

    public function forPerson(
        string $personId,
        bool $past,
        int $perPage,
        ?\DateTimeInterface $now = null,
    ): array {
        $involvementsTable = (new EventKeyPerson)->getTable();

        return $this->eventCards(
            Event::query()
                ->join($involvementsTable.' as person_involvements', 'person_involvements.event_id', '=', 'events.id')
                ->where('person_involvements.involveable_type', 'person')
                ->where('person_involvements.involveable_id', $personId)
                ->active()
                ->whereRaw($this->directionSql($past), [$now ?? now()])
                ->with([
                    'institution',
                    'institution.media',
                    'institution.addresses.country',
                    'venue.addresses.country',
                    'persons.media',
                    'media',
                    'references' => fn (BelongsToMany $query) => $query->active(),
                    'primaryOccurrence',
                    'classifications.term',
                    'timeExpressions',
                ])
                ->orderBy('person_involvements.sort_order')
                ->orderBy('starts_at', $past ? 'desc' : 'asc'),
            $perPage,
        );
    }

    public function forVenue(
        string $venueId,
        bool $past,
        int $perPage,
        ?\DateTimeInterface $now = null,
    ): array {
        return $this->eventCards(
            Event::query()
                ->where('default_venue_id', $venueId)
                ->active()
                ->whereRaw($this->directionSql($past), [$now ?? now()])
                ->with([
                    'institution.media',
                    'institution.addresses.country',
                    'persons.media',
                    'keyPeople.person.media',
                    'media',
                    'references' => fn (BelongsToMany $query) => $query->active(),
                    'primaryOccurrence',
                    'classifications.term',
                    'timeExpressions',
                ])
                ->orderBy('starts_at', $past ? 'desc' : 'asc'),
            $perPage,
        );
    }

    public function forReferences(
        array $referenceIds,
        bool $past,
        int $perPage,
        ?\DateTimeInterface $now = null,
    ): array {
        if ($referenceIds === []) {
            return ['items' => [], 'total' => 0];
        }

        return $this->eventCards(
            Event::query()
                ->active()
                ->whereHas('references', function (Builder $referenceQuery) use ($referenceIds): void {
                    $this->referenceQueries->applyPublicVisibility($referenceQuery)
                        ->whereIn('references.id', $referenceIds);
                })
                ->whereRaw($this->directionSql($past), [$now ?? now()])
                ->with([
                    'institution',
                    'institution.media',
                    'institution.addresses.country',
                    'persons.media',
                    'venue.addresses.country',
                    'media',
                    'references' => fn (BelongsToMany $query) => $query->active(),
                    'primaryOccurrence',
                    'classifications.term',
                    'timeExpressions',
                ])
                ->orderBy('starts_at', $past ? 'desc' : 'asc'),
            $perPage,
        );
    }

    public function forSeries(
        string $seriesId,
        bool $past,
        int $perPage,
        ?\DateTimeInterface $now = null,
    ): array {
        $seriesItemsTable = (string) config('events.database.tables.event_series_items', 'event_series_items');

        return $this->eventCards(
            Event::query()
                ->join($seriesItemsTable.' as series_items', 'series_items.event_id', '=', 'events.id')
                ->where('series_items.event_series_id', $seriesId)
                ->where('series_items.seriesable_type', Event::class)
                ->active()
                ->whereRaw($this->directionSql($past), [$now ?? now()])
                ->with([
                    'institution',
                    'institution.addresses.country',
                    'venue.addresses.country',
                    'persons.media',
                    'media',
                    'references' => fn (BelongsToMany $query) => $query->active(),
                    'primaryOccurrence',
                    'classifications.term',
                    'timeExpressions',
                ])
                ->orderBy('series_items.sort_order')
                ->orderBy('starts_at', $past ? 'desc' : 'asc'),
            $perPage,
        );
    }

    public function personParticipations(
        string $personId,
        bool $past,
        int $perPage,
        ?\DateTimeInterface $now = null,
    ): array {
        $now ??= now();
        $involvementsTable = (new EventKeyPerson)->getTable();
        $occurrencesTable = (string) config('events.database.tables.event_occurrences', 'event_occurrences');

        $query = EventKeyPerson::query()
            ->where('involveable_type', 'person')
            ->where('involveable_id', $personId)
            ->where('role_code', '!=', EventKeyPersonRole::Speaker->value)
            ->where('visibility', 'public')
            ->whereHas('event', function (Builder $query) use ($now, $past): void {
                $query
                    ->whereNotNull('events.published_at')
                    ->whereIn('events.status', Event::PUBLIC_STATUSES)
                    ->where('events.visibility', \App\Features\Events\Api\Enums\EventVisibility::Public)
                    ->whereHas('occurrences')
                    ->whereRaw($this->directionSql($past), [$now]);
            });

        $total = (clone $query)->count();

        /** @var Collection<int, EventKeyPerson> $matches */
        $matches = $query
            ->select("{$involvementsTable}.*")
            ->selectSub(function ($query) use ($occurrencesTable, $involvementsTable): void {
                $query->select("{$occurrencesTable}.starts_at")
                    ->from($occurrencesTable)
                    ->whereColumn("{$occurrencesTable}.event_id", "{$involvementsTable}.event_id")
                    ->orderBy("{$occurrencesTable}.starts_at")
                    ->orderBy("{$occurrencesTable}.created_at")
                    ->orderBy("{$occurrencesTable}.id")
                    ->limit(1);
            }, '__ordered_starts_at')
            ->with([
                'event' => fn ($query) => $query->with([
                    'primaryOccurrence',
                    'displayOccurrence.media',
                    'classifications.term',
                    'timeExpressions',
                    'institution.media',
                    'institution.addresses.country',
                    'venue.addresses.country',
                    'persons.media',
                    'media',
                    'references' => function (BelongsToMany $references): void {
                        $this->referenceQueries->applyPublicVisibility($references->getQuery());
                    },
                ]),
            ])
            ->reorder()
            ->orderBy('__ordered_starts_at', $past ? 'desc' : 'asc')
            ->orderBy("{$involvementsTable}.sort_order")
            ->orderBy("{$involvementsTable}.id")
            ->take($perPage)
            ->get();

        return [
            'items' => $matches
                ->map(fn (EventKeyPerson $keyPerson): array => $this->participationData($keyPerson))
                ->all(),
            'total' => $total,
        ];
    }

    /**
     * @param  Builder<Event>  $query
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    private function eventCards(Builder $query, int $perPage): array
    {
        /** @var \Illuminate\Database\Eloquent\Collection<int, Event> $limitedEvents */
        $limitedEvents = (clone $query)
            ->select('events.*')
            ->addSelect(DB::raw('COUNT(*) OVER () AS __total_count'))
            ->take($perPage + 1)
            ->get();

        $visibleEvents = $limitedEvents
            ->take($perPage)
            ->values();

        $visibleEvents->loadMissing('displayOccurrence.media');

        return [
            'items' => $visibleEvents
                ->map(fn (Event $event): array => $this->eventListPayloads->forEvent($event)->toArray())
                ->all(),
            'total' => (int) ($limitedEvents->first()?->getAttribute('__total_count') ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function participationData(EventKeyPerson $keyPerson): array
    {
        return [
            'id' => $keyPerson->id,
            'role' => (string) $keyPerson->role_code,
            'role_label' => $this->searchPayloadTransformer->keyPersonRoleLabel($keyPerson->role_code),
            'display_name' => $keyPerson->display_name,
            'event' => $keyPerson->event instanceof Event
                ? $this->eventListPayloads->forEvent($keyPerson->event)->toArray()
                : null,
        ];
    }

    private function directionSql(bool $past): string
    {
        return $this->eventScheduleSql->endInstantSql((new Event)->getTable()).($past ? ' < ?' : ' >= ?');
    }
}
