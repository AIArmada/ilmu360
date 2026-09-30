<?php

use App\Enums\DawahShareOutcomeType;
use App\Enums\EventVisibility;
use App\Models\Event;
use App\Models\Institution;
use App\Models\User;
use App\Services\ShareTrackingService;
use App\Support\Auth\IntendedRedirect;
use App\Support\Location\LocationSlugResolver;
use App\Support\Search\InstitutionSearchService;
use App\Support\Timezone\UserDateTimeFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    /**
     * @var array{country_id: ?string, state_id: ?string, city_id: ?string, district_id: ?string, subdivision_id: ?string, locality_id: ?string, area_ids: array<string, ?string>}|null
     */
    private ?array $memoizedLocationIds = null;

    /**
     * Mirror of the parent filter state. Plain props on purpose: the parent
     * owns the URL, this component only consumes filter snapshots via mount
     * (first paint) and syncFilters (later updates, each its own request so
     * the filter dropdowns never wait for this list query).
     */
    public ?string $search = null;

    public ?string $country = null;

    public ?string $state = null;

    public ?string $city = null;

    public ?string $locality = null;

    public ?string $district = null;

    public ?string $subdivision = null;

    /**
     * @var array<string, ?string>
     */
    public array $areas = [];

    /**
     * @var list<string>
     */
    public array $followingInstitutionIds = [];

    /**
     * @param  array{search?: ?string, country?: ?string, state?: ?string, city?: ?string, locality?: ?string, district?: ?string, subdivision?: ?string, areas?: array<string, ?string>}  $filters
     */
    public function mount(array $filters = []): void
    {
        $this->applyFilters($filters);

        // Preserve ?page= deep links: page state lives here, not in the URL.
        $page = (int) request('page', 1);

        if ($page > 1) {
            $this->setPage($page);
        }

        $user = auth()->user();

        if ($user instanceof User) {
            $this->followingInstitutionIds = $user->followingInstitutions()
                ->pluck((new Institution)->qualifyColumn('id'))
                ->map(static fn (mixed $id): string => (string) $id)
                ->values()
                ->all();
        }
    }

    /**
     * @param  array{search?: ?string, country?: ?string, state?: ?string, city?: ?string, locality?: ?string, district?: ?string, subdivision?: ?string, areas?: array<string, ?string>}  $filters
     */
    #[On('institution-filters-updated')]
    public function syncFilters(array $filters): void
    {
        $this->applyFilters($filters);
        $this->memoizedLocationIds = null;
        $this->resetPage();
    }

    /**
     * @param  array{search?: ?string, country?: ?string, state?: ?string, city?: ?string, locality?: ?string, district?: ?string, subdivision?: ?string, areas?: array<string, ?string>}  $filters
     */
    private function applyFilters(array $filters): void
    {
        // syncFilters is client-reachable: only strings survive, anything
        // else degrades to null (filter ignored) instead of throwing.
        $this->search = LocationSlugResolver::cleanSlug($this->filterString($filters, 'search'));
        $this->country = LocationSlugResolver::cleanSlug($this->filterString($filters, 'country'));
        $this->state = LocationSlugResolver::cleanSlug($this->filterString($filters, 'state'));
        $this->city = LocationSlugResolver::cleanSlug($this->filterString($filters, 'city'));
        $this->locality = LocationSlugResolver::cleanSlug($this->filterString($filters, 'locality'));
        $this->district = LocationSlugResolver::cleanSlug($this->filterString($filters, 'district'));
        $this->subdivision = LocationSlugResolver::cleanSlug($this->filterString($filters, 'subdivision'));
        $this->areas = array_filter(
            array_map(
                static fn (mixed $value): ?string => LocationSlugResolver::cleanSlug(is_string($value) ? $value : null),
                is_array($filters['areas'] ?? null) ? $filters['areas'] : [],
            ),
            static fn (?string $value): bool => $value !== null,
        );
    }

    private function filterString(array $filters, string $key): ?string
    {
        $value = $filters[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    #[Computed]
    public function institutions(): LengthAwarePaginatorContract
    {
        $search = $this->normalizedSearch();
        $baseQuery = $this->baseInstitutionsQuery();

        if ($search === null) {
            $paginator = $baseQuery
                ->publicDirectoryOrder()
                ->paginate(12)
                ->withQueryString();
        } else {
            $directMatches = $this->directSearch($search);

            $paginator = $directMatches->total() > 0 || mb_strlen($search) < 3
                ? $directMatches
                : $this->fuzzySearch($search);
        }

        $this->attachNextPublicEvents($paginator->items());

        return $paginator;
    }

    private function baseInstitutionsQuery(): Builder
    {
        return $this->scopedInstitutionsQuery()
            ->select('institutions.*')
            ->selectSub($this->publicEventCountSubquery(), 'events_count')
            ->selectSub($this->nextPublicEventQuery()->select('events.id'), 'next_event_id')
            ->with([
                'addresses.state',
                'addresses.city',
                'addresses.areaAssignments.area',
                'media',
            ]);
    }

    /**
     * Hydrate the next-event card fields for one page of institutions in a
     * single batched query. The listing only needs these three columns for
     * the visible page, so repeating the same correlated LIMIT 1 subquery
     * three times per row only triples that work — and each repetition
     * evaluates its own "now", which can mix columns from different events
     * at the boundary. Selecting the winning id per row and resolving its
     * columns once keeps every card internally consistent.
     *
     * @param  array<int, Institution>  $institutions
     */
    private function attachNextPublicEvents(array $institutions): void
    {
        if ($institutions === []) {
            return;
        }

        $nextEventIds = collect($institutions)
            ->map(static fn (Institution $institution): mixed => $institution->getAttribute('next_event_id'))
            ->filter()
            ->map(static fn (mixed $id): string => (string) $id)
            ->unique()
            ->values()
            ->all();

        /** @var EloquentCollection<int, Event> $details */
        $details = $nextEventIds === []
            ? new EloquentCollection
            : $this->nextPublicEventDetails($nextEventIds)->keyBy(static fn (Event $event): string => (string) $event->getKey());

        foreach ($institutions as $institution) {
            $detail = $details->get((string) $institution->getAttribute('next_event_id'));

            $institution->setAttribute('next_event_slug', $detail?->getAttribute('slug'));
            $institution->setAttribute('next_event_title', $detail?->getAttribute('title'));
            $institution->setAttribute('next_event_starts_at', $detail?->getAttribute('next_event_starts_at'));
            $institution->offsetUnset('next_event_id');
        }
    }

    /**
     * Resolve the card columns for already-selected next events. The id
     * already identifies the earliest future (event, occurrence) pair per
     * institution, so the event's minimum future occurrence start is exactly
     * that pair's start — no tie-break needed for the timestamp itself.
     *
     * @param  list<string>  $eventIds
     * @return EloquentCollection<int, Event>
     */
    private function nextPublicEventDetails(array $eventIds): EloquentCollection
    {
        $occurrencesTable = config('events.database.tables.event_occurrences', 'event_occurrences');

        return Event::query()
            ->select('events.id', 'events.slug', 'events.title')
            ->selectRaw('min("'.$occurrencesTable.'"."starts_at") as next_event_starts_at')
            ->join("{$occurrencesTable}", "{$occurrencesTable}.event_id", '=', 'events.id')
            ->whereIn('events.id', $eventIds)
            ->where("{$occurrencesTable}.starts_at", '>=', now())
            ->groupBy('events.id', 'events.slug', 'events.title')
            ->get();
    }

    private function scopedInstitutionsQuery(): Builder
    {
        return $this->applyLocationScope(
            Institution::query()
                ->whereIn('status', ['verified', 'pending']),
        );
    }

    /**
     * @return Builder<Event>
     */
    private function publicEventCountSubquery(): Builder
    {
        $occurrencesTable = config('events.database.tables.event_occurrences', 'event_occurrences');

        return Event::query()
            ->selectRaw('count(*)')
            ->whereColumn('events.institution_id', 'institutions.id')
            ->whereNotNull('events.published_at')
            ->whereIn('events.status', Event::PUBLIC_STATUSES)
            ->where('events.visibility', EventVisibility::Public->value)
            // A plain table EXISTS, not whereHas('occurrences'): the occurrence
            // relation carries the event-owner global scope, which degrades to
            // a tautological nested EXISTS in this public global context. The
            // next-event lookup below already bypasses it with a plain join.
            ->whereExists(function (QueryBuilder $query) use ($occurrencesTable): void {
                $query->selectRaw('1')
                    ->from($occurrencesTable)
                    ->whereColumn("{$occurrencesTable}.event_id", 'events.id');
            });
    }

    /**
     * @return Builder<Event>
     */
    private function nextPublicEventQuery(): Builder
    {
        $occurrencesTable = config('events.database.tables.event_occurrences', 'event_occurrences');

        return Event::query()
            ->join("{$occurrencesTable} as next_event_occurrences", 'next_event_occurrences.event_id', '=', 'events.id')
            ->whereColumn('events.institution_id', 'institutions.id')
            ->where('next_event_occurrences.starts_at', '>=', now())
            ->whereNotNull('events.published_at')
            ->whereIn('events.status', Event::PUBLIC_STATUSES)
            ->where('events.visibility', EventVisibility::Public->value)
            ->orderBy('next_event_occurrences.starts_at')
            ->orderBy('events.id')
            ->limit(1);
    }

    private function directSearch(string $search): LengthAwarePaginatorContract
    {
        $matchingIds = $this->institutionSearchService()->publicSearchIds($search);

        if ($matchingIds === []) {
            return $this->emptyPaginator();
        }

        return $this->paginateDirectMatches($matchingIds);
    }

    private function fuzzySearch(string $search): LengthAwarePaginatorContract
    {
        $orderedIds = $this->filterSearchIdsToCurrentScope(
            $this->institutionSearchService()->publicFuzzySearchIds($search),
        );

        if ($orderedIds === []) {
            return $this->emptyPaginator();
        }

        return $this->orderedIdPaginator($orderedIds);
    }

    /**
     * @param  list<string>  $orderedIds
     * @return list<string>
     */
    private function filterSearchIdsToCurrentScope(array $orderedIds, bool $useDirectoryOrder = false): array
    {
        if ($orderedIds === []) {
            return [];
        }

        $scopedQuery = $this->scopedInstitutionsQuery()
            ->whereIn('institutions.id', $orderedIds)
            ->when($useDirectoryOrder, fn (Builder $query): Builder => $query->publicDirectoryOrder());

        $scopedIds = $scopedQuery
            ->pluck('institutions.id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        if ($useDirectoryOrder) {
            return $scopedIds;
        }

        return collect($scopedIds)
            ->sortBy(static function (string $id) use ($orderedIds): int {
                $position = array_search($id, $orderedIds, true);

                return is_int($position) ? $position : PHP_INT_MAX;
            })
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $orderedIds
     */
    private function orderedIdPaginator(array $orderedIds): LengthAwarePaginatorContract
    {
        $currentPage = max(1, (int) $this->getPage());
        $perPage = 12;
        $paginationMeta = $this->paginationMeta();
        $paginatedIds = array_slice($orderedIds, ($currentPage - 1) * $perPage, $perPage);

        if ($paginatedIds === []) {
            return new LengthAwarePaginator(collect(), count($orderedIds), $perPage, $currentPage, $paginationMeta);
        }

        return $this->hydrateOrderedIds($paginatedIds, count($orderedIds), $currentPage, $paginationMeta);
    }

    /**
     * Apply the current directory scope, ordering, pagination, and total in
     * one database query. The search service already resolved the candidate
     * IDs, so a separate scoped-ID query only repeats the location work.
     *
     * @param  list<string>  $matchingIds
     */
    private function paginateDirectMatches(array $matchingIds): LengthAwarePaginatorContract
    {
        $currentPage = max(1, (int) $this->getPage());
        $perPage = 12;
        $paginationMeta = $this->paginationMeta();

        $institutions = $this->baseInstitutionsQuery()
            ->whereIn('institutions.id', $matchingIds)
            ->publicDirectoryOrder()
            ->selectRaw('count(*) over () as matching_total')
            ->forPage($currentPage, $perPage)
            ->get();

        $firstInstitution = $institutions->first();
        $total = $firstInstitution instanceof Institution
            ? (int) $firstInstitution->getAttribute('matching_total')
            : 0;

        // A page beyond the end has no row from which to read the window
        // total. This is rare and keeps pagination totals correct without
        // adding a count query to the normal request path.
        if ($institutions->isEmpty() && $currentPage > 1) {
            $total = $this->scopedInstitutionsQuery()
                ->whereIn('institutions.id', $matchingIds)
                ->count();
        }

        $institutions->each(static fn (Institution $institution): Institution => $institution->makeHidden('matching_total'));

        return new LengthAwarePaginator($institutions, $total, $perPage, $currentPage, $paginationMeta);
    }

    /**
     * @param  list<string>  $orderedIds
     */
    private function hydrateOrderedIds(array $orderedIds, int $total, int $currentPage, array $paginationMeta): LengthAwarePaginatorContract
    {
        $institutions = $this->baseInstitutionsQuery()
            ->whereIn('institutions.id', $orderedIds)
            ->get()
            ->sortBy(static function (Institution $institution) use ($orderedIds): int {
                $position = array_search((string) $institution->id, $orderedIds, true);

                return is_int($position) ? $position : PHP_INT_MAX;
            })
            ->values();

        return new LengthAwarePaginator($institutions, $total, 12, $currentPage, $paginationMeta);
    }

    private function emptyPaginator(): LengthAwarePaginatorContract
    {
        return new LengthAwarePaginator(collect(), 0, 12, max(1, (int) $this->getPage()), $this->paginationMeta());
    }

    /**
     * @return array{path: string, query: array<string, mixed>}
     */
    private function paginationMeta(): array
    {
        return [
            'path' => request()->url(),
            'query' => request()->query(),
        ];
    }

    private function institutionSearchService(): InstitutionSearchService
    {
        return app(InstitutionSearchService::class);
    }

    public function toggleFollow(string $institutionId): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            $this->redirect(
                IntendedRedirect::loginUrl(route('institutions.index', absolute: false)),
                navigate: true,
            );

            return;
        }

        $institution = $this->followableInstitution($institutionId);

        if ($institution === null) {
            return;
        }

        $institutionId = (string) $institution->getKey();

        if ($user->isFollowing($institution)) {
            $user->unfollow($institution);
            $this->followingInstitutionIds = array_values(array_filter(
                $this->followingInstitutionIds,
                static fn (mixed $id): bool => (string) $id !== $institutionId,
            ));

            return;
        }

        $user->follow($institution);

        if (! in_array($institutionId, $this->followingInstitutionIds, true)) {
            $this->followingInstitutionIds[] = $institutionId;
        }

        app(ShareTrackingService::class)->recordOutcome(
            type: DawahShareOutcomeType::InstitutionFollow,
            outcomeKey: 'institution_follow:user:'.$user->id.':institution:'.$institution->id,
            subject: $institution,
            actor: $user,
            request: request(),
            metadata: [
                'institution_id' => $institution->id,
            ],
        );
    }

    private function followableInstitution(string $institutionId): ?Institution
    {
        if (! Str::isUuid($institutionId)) {
            return null;
        }

        return Institution::query()
            ->whereIn('status', ['verified', 'pending'])
            ->whereKey($institutionId)
            ->first();
    }

    private function applyLocationScope(Builder $query): Builder
    {
        $ids = $this->locationIds();
        $countryId = $ids['country_id'];
        $stateId = $ids['state_id'];
        $cityId = $ids['city_id'];
        $localityId = $ids['locality_id'];
        $areaIds = $ids['area_ids'];

        if ($countryId === null && $stateId === null && $cityId === null && $localityId === null && ! array_filter($areaIds)) {
            return $query;
        }

        return $query->whereHas('addresses', function (Builder $addressQuery) use ($countryId, $stateId, $cityId, $localityId, $areaIds): void {
            if ($countryId !== null) {
                $addressQuery->where('country_id', $countryId);
            }

            if ($stateId !== null) {
                $addressQuery->where('state_id', $stateId);
            }

            if ($cityId !== null) {
                $addressQuery->where('city_id', $cityId);
            }

            if ($localityId !== null) {
                $addressQuery->whereHas('areaAssignments', fn (Builder $assignmentQuery) => $assignmentQuery
                    ->where('role', 'postal_locality')->where('address_area_id', $localityId));
            }

            foreach ($areaIds as $role => $areaId) {
                if ($areaId === null) {
                    continue;
                }

                $addressQuery->whereHas('areaAssignments', fn (Builder $assignmentQuery) => $assignmentQuery
                    ->where('role', $role)->where('address_area_id', $areaId));
            }
        });
    }

    /**
     * @return array{country_id: ?string, state_id: ?string, city_id: ?string, locality_id: ?string, district_id: ?string, subdivision_id: ?string, area_ids: array<string, ?string>}
     */
    private function locationIds(): array
    {
        if ($this->memoizedLocationIds !== null) {
            return $this->memoizedLocationIds;
        }

        return $this->memoizedLocationIds = $this->locationSlugResolver()->idsForSlugs([
            'search' => $this->search,
            'country' => $this->country,
            'state' => $this->state,
            'city' => $this->city,
            'locality' => $this->locality,
            'district' => $this->district,
            'subdivision' => $this->subdivision,
            'areas' => $this->areas,
        ]);
    }

    private function locationSlugResolver(): LocationSlugResolver
    {
        return app(LocationSlugResolver::class);
    }

    private function normalizedSearch(): ?string
    {
        if (! is_string($this->search)) {
            return null;
        }

        $normalizedSearch = trim($this->search);

        return $normalizedSearch === '' ? null : $normalizedSearch;
    }
};
?>

@php
    $institutions = $this->institutions;
    // Event listeners travel as __dispatch calls; wire:target matches the call method, not syncFilters.
    $institutionLoadingTarget = 'syncFilters,__dispatch,gotoPage,nextPage,previousPage';
    $formatInstitutionLocation = static function ($addressModel): string {
        $parts = \App\Support\Location\AddressHierarchyFormatter::parts($addressModel);

        return $parts === [] ? '-' : implode(', ', $parts);
    };
@endphp

<div class="min-h-[32rem]" wire:transition="institution-results">
    <div wire:loading.delay.short wire:target="{{ $institutionLoadingTarget }}">
        <x-ui.skeleton.institution-card-grid />
    </div>

    <div wire:loading.remove wire:target="{{ $institutionLoadingTarget }}">
            @if($institutions->isEmpty())
                <div class="text-center py-24 rounded-3xl bg-slate-50/50 border border-dashed border-slate-200">
                    <div class="inline-flex items-center justify-center w-20 h-20 rounded-full bg-white text-slate-300 shadow-sm mb-6">
                        <svg class="w-10 h-10" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" /></svg>
                    </div>
                    <h3 class="text-xl font-bold text-slate-900">{{ __('No institutions found') }}</h3>
                    <p class="text-slate-500 mt-2 max-w-md mx-auto">{{ __('We couldn\'t find any institutions matching your search.') }}</p>
                        <div class="mt-6 flex flex-wrap items-center justify-center gap-3">
                            <button type="button" wire:click="$parent.clearFilters()" class="font-semibold text-emerald-600 hover:text-emerald-700">
                                {{ __('Clear Filters') }} &rarr;
                            </button>
                        </div>
                </div>
            @else
                <div class="grid gap-5 md:grid-cols-2 lg:grid-cols-3">
                    @foreach($institutions as $institution)
                        @php
                            $cardInstitutionImageUrl = $institution->public_cover_url !== ''
                                ? $institution->public_cover_url
                                : ($institution->public_logo_url !== '' ? $institution->public_logo_url : null);
                            $isFollowing = in_array((string) $institution->getKey(), $followingInstitutionIds, true);
                        @endphp
                        <article wire:key="institution-{{ $institution->id }}" class="living-majlis-card group relative flex h-full flex-col overflow-hidden rounded-[1.5rem] border border-emerald-200 transition-[border-color,background-color,box-shadow,transform] duration-300 hover:-translate-y-1.5 hover:border-amber-200 hover:bg-amber-100 hover:shadow-[0_22px_50px_-28px_rgba(217,165,20,0.40)]">
                            <a href="{{ route('institutions.show', $institution) }}" wire:navigate class="flex flex-1 flex-col after:absolute after:inset-0">
                            <!-- Banner Area (16:9, cover-first) -->
                            <div class="institution-card-media aspect-video bg-slate-50 relative overflow-hidden">
                                @if((string) $institution->status === 'verified')
                                    <span class="absolute start-2.5 top-2.5 z-10 inline-flex items-center gap-1.5 rounded-full border border-white/70 bg-white/92 px-2.5 py-1 text-[10px] font-bold text-emerald-800 shadow-sm backdrop-blur">
                                        <flux:icon.check variant="mini" class="size-3.5 text-emerald-700" />
                                        {{ __('Disahkan') }}
                                    </span>
                                @elseif((string) $institution->status === 'pending')
                                    <span class="absolute start-2.5 top-2.5 z-10 inline-flex items-center gap-1.5 rounded-full border border-amber-300/70 bg-amber-50/92 px-2.5 py-1 text-[10px] font-bold text-amber-800 shadow-sm backdrop-blur">
                                        <flux:icon.exclamation-triangle variant="solid" class="size-3.5 text-amber-600" />
                                        {{ __('Belum disahkan') }}
                                    </span>
                                @endif

                                @if($cardInstitutionImageUrl)
                                    <img src="{{ $cardInstitutionImageUrl }}" alt="{{ $institution->name }}" class="h-full w-full object-cover" loading="lazy">
                                    <div class="absolute inset-0 bg-gradient-to-t from-slate-900/50 via-slate-900/15 to-transparent"></div>
                                @else
                                    <img
                                        src="{{ asset('images/placeholders/institution-v2.png') }}"
                                        alt=""
                                        aria-hidden="true"
                                        class="h-full w-full object-cover"
                                        loading="lazy"
                                    >
                                    <div class="absolute inset-0 bg-gradient-to-t from-emerald-950/35 via-transparent to-transparent"></div>
                                @endif
                            </div>

                            <div class="p-6 pb-0 pt-6 relative flex-1 flex flex-col">
                                <h3 class="font-heading text-lg font-bold text-slate-900 group-hover:text-emerald-700 transition-colors mb-2 leading-tight">
                                    {{ $institution->name }}
                                </h3>

                                @php
                                    $address = $institution->primaryAddress();
                                    $locationDisplay = $formatInstitutionLocation($address);
                                @endphp
                                <p class="text-sm text-slate-600 flex items-start gap-1.5 mb-4 font-medium">
                                    <flux:icon.map-pin class="mt-0.5 size-4 shrink-0 text-emerald-500" />
                                    <span class="line-clamp-2">{{ $locationDisplay }}</span>
                                </p>

                                @php
                                    $nextEventStartsAt = filled($institution->next_event_starts_at)
                                        ? CarbonImmutable::parse((string) $institution->next_event_starts_at, 'UTC')
                                        : null;
                                @endphp

                            </div>
                            </a>
                            @if($nextEventStartsAt instanceof CarbonImmutable && filled($institution->next_event_slug) && filled($institution->next_event_title))
                                <a
                                    data-next-event
                                    href="{{ route('events.show', ['event' => $institution->next_event_slug]) }}"
                                    wire:navigate
                                    class="group/next relative z-10 mx-6 mt-4 block min-w-0 border-t border-slate-100 pb-4 pt-4 transition-colors duration-200 group-hover:border-amber-200 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-600/15"
                                >
                                    <span class="min-w-0">
                                        <span class="block text-[10px] font-bold uppercase tracking-[0.12em] text-slate-400">{{ __('Next event') }}</span>
                                        <span class="mt-1 block truncate text-[11px] font-semibold text-slate-700 transition-colors group-hover/next:font-bold group-hover/next:text-emerald-800 sm:text-xs">
                                            {{ UserDateTimeFormatter::translatedFormat($nextEventStartsAt, 'j M') }}
                                            <span class="text-slate-300" aria-hidden="true">·</span>
                                            {{ $institution->next_event_title }}
                                        </span>
                                    </span>
                                </a>
                            @endif
                            <div class="flex items-center justify-between gap-3 border-t border-slate-100 px-6 pb-6 pt-5 group-hover:border-amber-200">
                                <span class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-200">
                                    <flux:icon.calendar class="size-3.5 text-emerald-500" />
                                    {{ $institution->events_count }} {{ __('Events') }}
                                </span>
                                <button
                                    type="button"
                                    wire:click.stop.prevent="toggleFollow('{{ $institution->id }}')"
                                    wire:loading.attr="disabled"
                                    data-follow-icon="institution"
                                    data-follow-state="{{ $isFollowing ? 'following' : 'not-following' }}"
                                    aria-label="{{ $isFollowing ? __('Nyahikut') : __('Ikuti') }}"
                                    aria-pressed="{{ $isFollowing ? 'true' : 'false' }}"
                                    class="relative z-10 grid h-9 w-9 shrink-0 place-items-center rounded-xl border transition-colors duration-200 disabled:cursor-wait disabled:opacity-60 {{ $isFollowing ? 'border-emerald-200 bg-emerald-50 text-emerald-700 group-hover:border-emerald-300 group-hover:bg-emerald-100' : 'border-slate-200 bg-white text-slate-400 group-hover:border-emerald-200 group-hover:text-[#087f59] hover:border-emerald-200 hover:bg-emerald-100 hover:text-[#087f59] hover:shadow-sm' }}"
                                >
                                    <flux:icon.bookmark class="size-4" :variant="$isFollowing ? 'solid' : 'outline'" />
                                </button>
                            </div>
                        </article>
                    @endforeach
                </div>

		                <div class="mt-16">
	                    {{ $institutions->withQueryString()->links('vendor.livewire.directory-pagination') }}
		                </div>

            @endif

    </div>
</div>
