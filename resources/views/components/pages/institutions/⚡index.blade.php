<?php

use App\Enums\DawahShareOutcomeType;
use App\Enums\EventVisibility;
use App\Forms\SharedFormSchema;
use App\Models\Event;
use App\Models\Institution;
use App\Models\User;
use App\Services\ShareTrackingService;
use App\Support\Auth\IntendedRedirect;
use App\Support\Location\LocationSlugResolver;
use App\Support\Location\VisitorCountryResolver;
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
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new
#[Title('Institutions - ilmu360°')]
class extends Component
{
    use WithPagination;

    private bool $defaultCountrySlugResolved = false;

    private ?string $resolvedDefaultCountrySlug = null;

    /**
     * @var array{country_id: ?string, state_id: ?string, city_id: ?string, district_id: ?string, subdivision_id: ?string, locality_id: ?string}|null
     */
    private ?array $memoizedLocationIds = null;

    public function mount(): void
    {
        if ($this->country === null) {
            $this->country = $this->defaultCountrySlug();
        }

        $this->normalizeLocationSlugs();
        $this->autoSelectSingleLocationChildren();

        $user = auth()->user();

        if ($user instanceof User) {
            $this->followingInstitutionIds = $user->followingInstitutions()
                ->pluck((new Institution)->qualifyColumn('id'))
                ->map(static fn (mixed $id): string => (string) $id)
                ->values()
                ->all();
        }
    }

    #[Url]
    public ?string $search = null;

    /**
     * Location filters as URL slugs (country=malaysia&state=johor). Queries
     * still filter by IDs via locationIds(); slugs resolve through cached
     * maps scoped exactly like the dropdowns that offer them.
     */
    #[Url]
    public ?string $country = null;

    #[Url]
    public ?string $state = null;

    #[Url]
    public ?string $city = null;

    #[Url]
    public ?string $locality = null;

    #[Url]
    public ?string $district = null;

    #[Url]
    public ?string $subdivision = null;

    /**
     * @var list<string>
     */
    public array $followingInstitutionIds = [];

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

    #[Computed]
    public function countries(): array
    {
        return $this->locationSlugResolver()->countryMaps()['options'];
    }

    #[Computed]
    public function states(): array
    {
        $countryId = $this->locationIds()['country_id'];

        if ($countryId === null) {
            return [];
        }

        return $this->locationSlugResolver()->stateMaps($countryId)['options'];
    }

    #[Computed]
    public function districts(): array
    {
        $ids = $this->locationIds();

        return $this->locationSlugResolver()->districtMaps($ids['state_id'], $ids['country_id'])['options'];
    }

    #[Computed]
    public function cities(): array
    {
        // A city is never a valid first step in the cascade. The admin form
        // only exposes it after a state is selected, and Malaysia does not
        // expose it at all once its district/local-area profile applies.
        if (! filled($this->state)) {
            return [];
        }

        $ids = $this->locationIds();

        if ($this->localities() !== []) {
            return [];
        }

        // Follow the admin address form: once a country's profile exposes
        // district/local-area hierarchy, City is redundant and must not be
        // offered as a parallel filter. Malaysia uses the role-specific path;
        // other provider countries resolve through their own area slots.
        if (
            SharedFormSchema::shouldShowDistrictField($ids['state_id'], $ids['country_id'])
            || SharedFormSchema::shouldShowSubdistrictField($ids['state_id'], null, $ids['country_id'])
            || $this->districts() !== []
        ) {
            return [];
        }

        return $this->locationSlugResolver()->cityMaps($ids['state_id'], $ids['country_id'])['options'];
    }

    #[Computed]
    public function localities(): array
    {
        if (! filled($this->state)) {
            return [];
        }

        $ids = $this->locationIds();

        return $this->locationSlugResolver()->localityMaps($ids['state_id'], $ids['country_id'])['options'];
    }

    #[Computed]
    public function subdistricts(): array
    {
        // Slot 2 needs its parent: the slot-1 selection when the first slot
        // exists, otherwise the state (federal-territory-style profiles, and
        // provider countries whose cascade skips State entirely).
        if ($this->districts() !== []) {
            if (! filled($this->district)) {
                return [];
            }
        } elseif (! filled($this->state)) {
            return [];
        }

        $ids = $this->locationIds();

        return $this->locationSlugResolver()->subdivisionMaps($ids['state_id'], $ids['district_id'], $ids['country_id'])['options'];
    }

    public function stateLabel(): string
    {
        return SharedFormSchema::locationLevelLabel($this->locationIds()['country_id'], 'state_id', __('State / Province'));
    }

    public function districtLabel(): string
    {
        $countryId = $this->locationIds()['country_id'];
        $role = $this->locationSlugResolver()->districtRoleForCountry($countryId);

        return $role === null
            ? __('District')
            : SharedFormSchema::locationLevelLabel($countryId, $role, __('District'));
    }

    public function subdistrictLabel(): string
    {
        $countryId = $this->locationIds()['country_id'];
        $role = $this->locationSlugResolver()->subdivisionRoleForCountry($countryId);

        return $role === null
            ? __('Subdivision')
            : SharedFormSchema::locationLevelLabel($countryId, $role, __('Subdivision'));
    }

    public function localityLabel(): string
    {
        return SharedFormSchema::locationLevelLabel($this->locationIds()['country_id'], 'postal_locality', __('Locality / Precinct / Kampung'));
    }

    public function isParentlessAreaProfileSelection(): bool
    {
        $ids = $this->locationIds();

        return SharedFormSchema::shouldShowSubdistrictField($ids['state_id'], null, $ids['country_id'])
            && ! SharedFormSchema::shouldShowDistrictField($ids['state_id'], $ids['country_id']);
    }

    public function isDistrictFilterDisabled(): bool
    {
        if ($this->districts() === []) {
            return true;
        }

        // Slot 1 hangs off the state dropdown when the country has a state
        // level, otherwise directly off the country (stateless profiles like
        // Singapore, where $state can never be filled).
        return $this->states() !== []
            ? ! filled($this->state)
            : ! filled($this->country);
    }

    private function autoSelectSingleLocationChildren(): void
    {
        if (! filled($this->state)) {
            return;
        }

        $localities = $this->localities();

        if ($localities !== []) {
            $this->autoSelectSingleSlug('locality', $localities);

            return;
        }

        $districts = $this->districts();

        if ($districts !== [] && ! $this->isParentlessAreaProfileSelection()) {
            if (! filled($this->district)) {
                $this->autoSelectSingleSlug('district', $districts);
            }

            if (filled($this->district)) {
                $this->autoSelectSingleSlug('subdivision', $this->subdistricts());
            }

            return;
        }

        $subdistricts = $this->subdistricts();

        if ($subdistricts !== []) {
            $this->autoSelectSingleSlug('subdivision', $subdistricts);

            return;
        }

        $this->autoSelectSingleSlug('city', $this->cities());
    }

    /**
     * @param  array<string, string>  $options
     */
    private function autoSelectSingleSlug(string $property, array $options): void
    {
        if (count($options) !== 1 || filled($this->{$property})) {
            return;
        }

        $value = array_key_first($options);

        if (! is_string($value)) {
            return;
        }

        match ($property) {
            'city' => $this->city = $value,
            'district' => $this->district = $value,
            'subdivision' => $this->subdivision = $value,
            'locality' => $this->locality = $value,
            default => null,
        };

        $this->memoizedLocationIds = null;
    }

    private function normalizedSearch(): ?string
    {
        if (! is_string($this->search)) {
            return null;
        }

        $normalizedSearch = trim($this->search);

        return $normalizedSearch === '' ? null : $normalizedSearch;
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedCountry(): void
    {
        $this->state = null;
        $this->city = null;
        $this->locality = null;
        $this->district = null;
        $this->subdivision = null;
        $this->memoizedLocationIds = null;
        $this->resetPage();
    }

    public function updatedState(): void
    {
        $this->city = null;
        $this->locality = null;
        $this->district = null;
        $this->subdivision = null;
        $this->memoizedLocationIds = null;
        $this->autoSelectSingleLocationChildren();
        $this->resetPage();
    }

    public function updatedDistrict(): void
    {
        $this->subdivision = null;
        $this->memoizedLocationIds = null;
        $this->autoSelectSingleLocationChildren();
        $this->resetPage();
    }

    public function updatedSubdivision(): void
    {
        $this->memoizedLocationIds = null;
        $this->resetPage();
    }

    public function updatedCity(): void
    {
        $this->memoizedLocationIds = null;
        $this->resetPage();
    }

    public function updatedLocality(): void
    {
        $this->memoizedLocationIds = null;
        $this->resetPage();
    }

    public function clearSearch(): void
    {
        $this->search = null;
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = null;
        $this->country = null;
        $this->state = null;
        $this->city = null;
        $this->locality = null;
        $this->district = null;
        $this->subdivision = null;
        $this->memoizedLocationIds = null;
        $this->resetPage();
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
        $adminArea1Id = $ids['district_id'];
        $adminArea2Id = $ids['subdivision_id'];

        if ($countryId === null && $stateId === null && $cityId === null && $localityId === null && $adminArea1Id === null && $adminArea2Id === null) {
            return $query;
        }

        return $query->whereHas('addresses', function (Builder $addressQuery) use ($countryId, $stateId, $cityId, $localityId, $adminArea1Id, $adminArea2Id): void {
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

            $districtRole = $this->locationSlugResolver()->districtRoleForCountry($countryId);
            $subdivisionRole = $this->locationSlugResolver()->subdivisionRoleForCountry($countryId);

            if ($adminArea1Id !== null && $districtRole !== null) {
                $addressQuery->whereHas('areaAssignments', fn (Builder $assignmentQuery) => $assignmentQuery
                    ->where('role', $districtRole)->where('address_area_id', $adminArea1Id));
            }

            if ($adminArea2Id !== null && $subdivisionRole !== null) {
                $addressQuery->whereHas('areaAssignments', fn (Builder $assignmentQuery) => $assignmentQuery
                    ->where('role', $subdivisionRole)->where('address_area_id', $adminArea2Id));
            }
        });
    }

    /**
     * Resolve the URL slugs to package IDs, following the dropdown cascade:
     * each level resolves within its parent's scope, and anything unknown
     * resolves to null (filter ignored), like the old unknown UUIDs.
     *
     * @return array{country_id: ?string, state_id: ?string, city_id: ?string, locality_id: ?string, district_id: ?string, subdivision_id: ?string}
     */
    private function locationIds(): array
    {
        if ($this->memoizedLocationIds !== null) {
            return $this->memoizedLocationIds;
        }

        $resolver = $this->locationSlugResolver();

        $countrySlug = LocationSlugResolver::cleanSlug($this->country);
        $countryId = $countrySlug !== null
            ? ($resolver->countryMaps()['slugToId'][$countrySlug] ?? null)
            : null;

        $stateSlug = LocationSlugResolver::cleanSlug($this->state);
        $stateId = $countryId !== null && $stateSlug !== null
            ? ($resolver->stateMaps($countryId)['slugToId'][$stateSlug] ?? null)
            : null;

        $citySlug = LocationSlugResolver::cleanSlug($this->city);
        $cityId = $citySlug !== null
            ? ($resolver->cityMaps($stateId, $countryId)['slugToId'][$citySlug] ?? null)
            : null;

        $localitySlug = LocationSlugResolver::cleanSlug($this->locality);
        $localityId = $localitySlug !== null
            ? ($resolver->localityMaps($stateId, $countryId)['slugToId'][$localitySlug] ?? null)
            : null;

        $districtSlug = LocationSlugResolver::cleanSlug($this->district);
        $districtId = $districtSlug !== null
            ? ($resolver->districtMaps($stateId, $countryId)['slugToId'][$districtSlug] ?? null)
            : null;

        $subdivisionSlug = LocationSlugResolver::cleanSlug($this->subdivision);
        $subdivisionId = $subdivisionSlug !== null
            ? ($resolver->subdivisionMaps($stateId, $districtId, $countryId)['slugToId'][$subdivisionSlug] ?? null)
            : null;

        return $this->memoizedLocationIds = [
            'country_id' => $countryId,
            'state_id' => $stateId,
            'city_id' => $cityId,
            'locality_id' => $localityId,
            'district_id' => $districtId,
            'subdivision_id' => $subdivisionId,
        ];
    }

    /**
     * Null out slugs that resolve to nothing so the dropdown selections stay
     * consistent with the applied scope. Runs on the
     * initial request only; later updates always carry valid dropdown slugs.
     */
    private function normalizeLocationSlugs(): void
    {
        $this->country = LocationSlugResolver::cleanSlug($this->country);
        $this->state = LocationSlugResolver::cleanSlug($this->state);
        $this->city = LocationSlugResolver::cleanSlug($this->city);
        $this->locality = LocationSlugResolver::cleanSlug($this->locality);
        $this->district = LocationSlugResolver::cleanSlug($this->district);
        $this->subdivision = LocationSlugResolver::cleanSlug($this->subdivision);

        $ids = $this->locationIds();

        if ($this->country !== null && $ids['country_id'] === null) {
            $this->country = null;
        }

        if ($this->state !== null && $ids['state_id'] === null) {
            $this->state = null;
        }

        if ($this->city !== null && $ids['city_id'] === null) {
            $this->city = null;
        }

        if ($this->locality !== null && $ids['locality_id'] === null) {
            $this->locality = null;
        }

        if ($this->district !== null && $ids['district_id'] === null) {
            $this->district = null;
        }

        if ($this->subdivision !== null && $ids['subdivision_id'] === null) {
            $this->subdivision = null;
        }

        $this->memoizedLocationIds = null;
    }

    private function locationSlugResolver(): LocationSlugResolver
    {
        return app(LocationSlugResolver::class);
    }

    private function defaultCountrySlug(): ?string
    {
        if ($this->defaultCountrySlugResolved) {
            return $this->resolvedDefaultCountrySlug;
        }

        $this->defaultCountrySlugResolved = true;

        // Shared with the /majlis directory: the edge geo header (Cloudflare
        // CF-IPCountry) is the cheapest GeoIP, no mmdb/api needed when behind CF.
        $countryId = app(VisitorCountryResolver::class)->resolve();

        if ($countryId === null) {
            return $this->resolvedDefaultCountrySlug = null;
        }

        return $this->resolvedDefaultCountrySlug = $this->locationSlugResolver()->countryMaps()['idToSlug'][$countryId] ?? null;
    }
};
?>

@section('title', __('Direktori Institusi Islam di Malaysia') . ' - ' . config('app.name'))
@section('meta_description', __('Terokai masjid, surau, pusat pengajian, dan institusi penganjur majlis ilmu di seluruh Malaysia. Cari mengikut nama dan lokasi.'))
@section('og_url', route('institutions.index'))
@section('og_image', asset('images/placeholders/institution.png'))
@section('og_image_alt', __('Direktori institusi Islam di Malaysia'))
@section('og_image_width', '1024')
@section('og_image_height', '1024')

@php
    $institutions = $this->institutions;
    $search = $this->search;
    $countries = $this->countries;
    $states = $this->states;
    $cities = $this->cities;
    $localities = $this->localities;
    $districts = $this->districts;
    $subdistricts = $this->subdistricts;
    $country = $this->country;
    $state = $this->state;
    $city = $this->city;
    $locality = $this->locality;
    $district = $this->district;
    $subdivision = $this->subdivision;
    $isParentlessAreaProfile = $this->isParentlessAreaProfileSelection();
    $stateLabel = $this->stateLabel();
    $districtLabel = $this->districtLabel();
    $subdistrictLabel = $this->subdistrictLabel();
    $localityLabel = $this->localityLabel();
    $defaultCountry = $this->defaultCountrySlug();
    $submitInstitutionUrl = route('contributions.submit-institution');
@endphp

<div data-art-direction="living-majlis" class="living-majlis-field relative min-h-screen overflow-x-clip text-slate-800">
        <!-- Hero Section -->
        <div class="relative isolate overflow-hidden border-b border-emerald-900/[0.06]">
            <div data-material="hero-field" class="absolute inset-0 overflow-hidden bg-[#f7f3e8]">
                <img
                    src="{{ asset('images/institutions/pusat-ilmu-hero-background-v1.png') }}"
                    alt=""
                    aria-hidden="true"
                    class="absolute inset-0 h-full w-full object-cover object-[35%_center] sm:object-[42%_center] lg:object-center"
                    width="1672"
                    height="941"
                    loading="eager"
                    decoding="async"
                >
                <div class="absolute inset-0 bg-gradient-to-r from-[#fffdf8]/78 via-[#fffdf8]/24 to-transparent"></div>
            </div>

            <div class="relative mx-auto max-w-7xl px-5 py-14 sm:px-6 sm:py-20 lg:px-8 lg:py-16">
                 <h1 class="max-w-2xl font-heading text-4xl font-bold leading-[1.06] tracking-[-0.035em] text-emerald-950 text-balance sm:text-5xl lg:text-6xl">
                    {{ __('Centers of') }} <br class="hidden md:block" />
                    <span class="relative inline-block text-emerald-700">
                        {{ __('Knowledge & Community') }}
                        <svg class="absolute -bottom-2 left-0 h-3.5 w-full text-amber-500/80" viewBox="0 0 320 18" preserveAspectRatio="none" aria-hidden="true">
                            <path d="M4 13C79 5 218 4 316 10" fill="none" stroke="currentColor" stroke-width="5" stroke-linecap="round" />
                        </svg>
                    </span>
                </h1>
                <p class="mt-6 max-w-xl text-base leading-7 text-slate-600 text-balance sm:mt-7 sm:text-lg">
                    {{ __('Connect with the mosques, suraus, and educational centers nurturing our community.') }}
                </p>
                
                 <!-- Search and filter controls -->
                 <div class="mt-9 max-w-3xl">
                    <x-ui.search-bar
                        input-id="institution-search"
                        model="search"
                        :value="$search"
                        :placeholder="__('Search institutions...')"
                        :label="__('Search institutions')"
                        :hint="__('Search by institution name or location.')"
                        width="max-w-xl"
                    />
                </div>
            </div>
        </div>

        <div class="relative z-10 mx-auto max-w-7xl px-5 py-5 sm:px-6 sm:py-6 lg:px-8">
            <div data-institution-filters class="mx-auto max-w-4xl text-left">
                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                        <div>
                            <label for="institution-country-filter" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">
                                {{ __('Country') }}
                            </label>
                            <flux:select
                                id="institution-country-filter"
                                wire:model.live="country"
                                size="sm"
                                class="w-full rounded-xl border-slate-300 bg-white text-sm text-slate-800 shadow-sm transition-[border-color,box-shadow,background-color] hover:border-emerald-300 focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/10 disabled:cursor-not-allowed disabled:border-slate-200 disabled:bg-slate-100 disabled:text-slate-400"
                            >
                                <flux:select.option value="" :selected="$country === null">{{ __('All countries') }}</flux:select.option>
                                @foreach($countries as $slug => $name)
                                    <flux:select.option value="{{ $slug }}" :selected="(string) $slug === $country">{{ $name }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        </div>

                        @if($states !== [])
                        <div>
                            <label for="institution-state-filter" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">
                                {{ $stateLabel }}
                            </label>
                            <flux:select
                                id="institution-state-filter"
                                wire:model.live="state"
                                :disabled="! filled($country)"
                                size="sm"
                                class="w-full rounded-xl border-slate-300 bg-white text-sm text-slate-800 shadow-sm transition-[border-color,box-shadow,background-color] hover:border-emerald-300 focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/10 disabled:cursor-not-allowed disabled:border-slate-200 disabled:bg-slate-100 disabled:text-slate-400"
                            >
                                <flux:select.option value="">{{ __('All :level', ['level' => $stateLabel]) }}</flux:select.option>
                                @foreach($states as $slug => $name)
                                    <flux:select.option value="{{ $slug }}">{{ $name }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        </div>
                        @endif

                        @if($localities !== [])
                        <div>
                            <label for="institution-locality-filter" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">
                                {{ $localityLabel }}
                            </label>
                            <flux:select
                                id="institution-locality-filter"
                                wire:model.live="locality"
                                :disabled="! filled($state)"
                                size="sm"
                                class="w-full rounded-xl border-slate-300 bg-white text-sm text-slate-800 shadow-sm transition-[border-color,box-shadow,background-color] hover:border-emerald-300 focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/10 disabled:cursor-not-allowed disabled:border-slate-200 disabled:bg-slate-100 disabled:text-slate-400"
                            >
                                <flux:select.option value="">{{ __('All :level', ['level' => $localityLabel]) }}</flux:select.option>
                                @foreach($localities as $slug => $name)
                                    <flux:select.option value="{{ $slug }}">{{ $name }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        </div>
                        @endif

                        @if($cities !== [])
                        <div>
                            <label for="institution-city-filter" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">
                                {{ __('City') }}
                            </label>
                            <flux:select
                                id="institution-city-filter"
                                wire:model.live="city"
                                :disabled="! filled($state)"
                                size="sm"
                                class="w-full rounded-xl border-slate-300 bg-white text-sm text-slate-800 shadow-sm transition-[border-color,box-shadow,background-color] hover:border-emerald-300 focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/10 disabled:cursor-not-allowed disabled:border-slate-200 disabled:bg-slate-100 disabled:text-slate-400"
                            >
                                <flux:select.option value="">{{ __('All cities') }}</flux:select.option>
                                @foreach($cities as $slug => $name)
                                    <flux:select.option value="{{ $slug }}">{{ $name }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        </div>
                        @endif

                    @if($districts !== [] && ! $isParentlessAreaProfile)
                            <div>
                                <label for="institution-district-filter" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">
                                    {{ $districtLabel }}
                                </label>
                                <flux:select
                                    id="institution-district-filter"
                                    wire:model.live="district"
                                    :disabled="$this->isDistrictFilterDisabled()"
                                    size="sm"
                                    class="w-full rounded-xl border-slate-300 bg-white text-sm text-slate-800 shadow-sm transition-[border-color,box-shadow,background-color] hover:border-emerald-300 focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/10 disabled:cursor-not-allowed disabled:border-slate-200 disabled:bg-slate-100 disabled:text-slate-400"
                                >
                                    <flux:select.option value="">{{ __('All :level', ['level' => $districtLabel]) }}</flux:select.option>
                                    @foreach($districts as $slug => $name)
                                        <flux:select.option value="{{ $slug }}">{{ $name }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            </div>
                        @endif

                        @if($subdistricts !== [])
                        <div>
                            <label for="institution-subdistrict-filter" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">
                                {{ $subdistrictLabel }}
                            </label>
                            <flux:select
                                id="institution-subdistrict-filter"
                                wire:model.live="subdivision"
                                :disabled="$isParentlessAreaProfile ? ! filled($state) : ! filled($district)"
                                size="sm"
                                class="w-full rounded-xl border-slate-300 bg-white text-sm text-slate-800 shadow-sm transition-[border-color,box-shadow,background-color] hover:border-emerald-300 focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/10 disabled:cursor-not-allowed disabled:border-slate-200 disabled:bg-slate-100 disabled:text-slate-400"
                            >
                                <flux:select.option value="">{{ __('All :level', ['level' => $subdistrictLabel]) }}</flux:select.option>
                                @foreach($subdistricts as $slug => $name)
                                    <flux:select.option value="{{ $slug }}">{{ $name }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        </div>
                        @endif
                        </div>

            </div>
        </div>

	        <div class="mx-auto max-w-7xl px-5 pt-10 pb-16 sm:px-6 lg:px-8 lg:pt-12 lg:pb-20">
            @island(name: 'institution-results', always: true)
                @php
                    $institutions = $this->institutions;
                    $institutionLoadingTarget = 'search,country,state,city,locality,district,subdivision,clearSearch,clearFilters';
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
                            <button type="button" wire:click="clearFilters" class="font-semibold text-emerald-600 hover:text-emerald-700">
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
	                        <article wire:key="institution-{{ $institution->id }}" class="living-majlis-card group relative flex h-full flex-col overflow-hidden rounded-[1.5rem] border transition-[border-color,box-shadow,transform] duration-300 hover:-translate-y-1.5 hover:border-emerald-300/80 hover:shadow-[0_22px_50px_-28px_rgba(6,78,59,0.40)]">
                            <a href="{{ route('institutions.show', $institution) }}" wire:navigate class="relative flex flex-1 flex-col">
                            <!-- Banner Area (16:9, cover-first) -->
                            <div class="institution-card-media aspect-video bg-slate-50 relative overflow-hidden">
                                @if((string) $institution->status === 'verified')
                                    <span class="absolute start-2.5 top-2.5 z-10 inline-flex items-center gap-1.5 rounded-full border border-white/70 bg-white/92 px-2.5 py-1 text-[10px] font-bold text-emerald-800 shadow-sm backdrop-blur">
                                        <svg class="h-3.5 w-3.5 text-emerald-700" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                            <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.051l-7.5 9.75a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.897 3.896 6.976-9.07a.75.75 0 0 1 1.051-.142Z" clip-rule="evenodd" />
                                        </svg>
                                        {{ __('Disahkan') }}
                                    </span>
                                @elseif((string) $institution->status === 'pending')
                                    <span class="absolute start-2.5 top-2.5 z-10 inline-flex items-center gap-1.5 rounded-full border border-amber-300/70 bg-amber-50/92 px-2.5 py-1 text-[10px] font-bold text-amber-800 shadow-sm backdrop-blur">
                                        <svg class="h-3.5 w-3.5 text-amber-600" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                            <path fill-rule="evenodd" d="M12 2.25a.75.75 0 0 1 .66.4l9 15.75a.75.75 0 0 1-.66 1.125H3a.75.75 0 0 1-.66-1.125l9-15.75a.75.75 0 0 1 .66-.4Zm0 6a.75.75 0 0 1 .75.75v3.75a.75.75 0 0 1-1.5 0V9a.75.75 0 0 1 .75-.75Zm0 7.5a.9.9 0 1 0 0 1.8.9.9 0 0 0 0-1.8Z" clip-rule="evenodd" />
                                        </svg>
                                        {{ __('Belum disahkan') }}
                                    </span>
                                @endif

                                @if($cardInstitutionImageUrl)
                                    <img src="{{ $cardInstitutionImageUrl }}" alt="{{ $institution->name }}" class="h-full w-full object-cover transition-transform duration-700 group-hover:scale-105" loading="lazy">
                                    <div class="absolute inset-0 bg-gradient-to-t from-slate-900/50 via-slate-900/15 to-transparent"></div>
                                @else
                                    <img
                                        src="{{ asset('images/placeholders/institution-v2.png') }}"
                                        alt=""
                                        aria-hidden="true"
                                        class="h-full w-full object-cover transition-transform duration-700 group-hover:scale-105"
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
                                    <svg class="w-4 h-4 text-emerald-500 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
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
                                    class="mx-6 mt-4 block min-w-0 border-t border-slate-100 pt-4 transition-colors duration-200 hover:border-emerald-200 hover:bg-emerald-50/30 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-600/15"
                                >
                                    <span class="min-w-0">
                                        <span class="block text-[10px] font-bold uppercase tracking-[0.12em] text-slate-400">{{ __('Next event') }}</span>
                                        <span class="mt-1 block truncate text-[11px] font-semibold text-slate-700 sm:text-xs">
                                            {{ UserDateTimeFormatter::translatedFormat($nextEventStartsAt, 'j M') }}
                                            <span class="text-slate-300" aria-hidden="true">·</span>
                                            {{ $institution->next_event_title }}
                                        </span>
                                    </span>
                                </a>
                            @endif
                            <div class="flex items-center justify-between gap-3 border-t border-slate-100 px-6 pb-6 pt-5">
                                <span class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-200">
                                    <svg class="h-3.5 w-3.5 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
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
                                    class="grid h-9 w-9 shrink-0 place-items-center rounded-xl border transition-colors duration-200 disabled:cursor-wait disabled:opacity-60 {{ $isFollowing ? 'border-emerald-200 bg-emerald-50 text-emerald-700 group-hover:border-emerald-300 group-hover:bg-emerald-100' : 'border-slate-200 bg-white text-slate-400 group-hover:border-emerald-200 group-hover:text-emerald-700' }}"
                                >
                                    @if($isFollowing)
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                            <path d="M6.75 4.5A2.25 2.25 0 0 1 9 2.25h6a2.25 2.25 0 0 1 2.25 2.25V21L12 17.25 6.75 21V4.5Z" />
                                        </svg>
                                    @else
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 4.5A2.25 2.25 0 0 1 9 2.25h6a2.25 2.25 0 0 1 2.25 2.25V21L12 17.25 6.75 21V4.5Z" />
                                        </svg>
                                    @endif
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
            @endisland


                    <section class="mt-16 sm:mt-20">
                        <div data-material="opaque-cta" class="living-majlis-cta relative overflow-hidden rounded-[1.5rem] border border-emerald-800/15 px-6 py-10 text-white sm:px-8 md:px-10 md:py-11">
                            <div class="absolute inset-0 opacity-[0.08]" style="background-image: radial-gradient(circle at 1.5px 1.5px, rgba(255,255,255,.70) 1px, transparent 0); background-size: 22px 22px;"></div>
                            <div class="absolute -bottom-16 -left-16 h-48 w-48 rounded-full bg-emerald-700/[0.15] blur-3xl"></div>

                            <div class="relative flex flex-col gap-8 lg:flex-row lg:items-center lg:justify-between">
                                <div class="flex flex-col gap-5 sm:flex-row sm:items-center sm:gap-6">
                                    <span class="relative grid h-20 w-20 shrink-0 place-items-center rounded-[1.35rem] bg-emerald-800/35 text-[#f5d98f] shadow-[0_18px_34px_-22px_rgba(0,0,0,0.9)]">
                                        <span class="pointer-events-none absolute inset-3 rounded-full bg-gold-300/10 blur-xl"></span>
                                        <svg class="relative h-14 w-14 drop-shadow-[0_6px_8px_rgba(0,0,0,0.18)]" viewBox="0 0 64 64" fill="none" aria-hidden="true">
                                            <defs>
                                                <linearGradient id="institution-gold" x1="13" y1="10" x2="51" y2="55" gradientUnits="userSpaceOnUse">
                                                    <stop stop-color="#FFF0B1" />
                                                    <stop offset="1" stop-color="#E8BA55" />
                                                </linearGradient>
                                            </defs>
                                            <path d="M11 51h42M16 51V31h32v20M22 51V37h20v14" stroke="url(#institution-gold)" stroke-linecap="round" stroke-linejoin="round" stroke-width="3" />
                                            <path d="M18 31c2.6-7.4 7.5-11.1 14-11.1S43.4 23.6 46 31" fill="url(#institution-gold)" />
                                            <path d="M12 31V20h5v11m30 0V20h5v11M14.5 20h0M49.5 20h0M32 19V9" stroke="url(#institution-gold)" stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" />
                                            <path d="M29 51V39h6v12" stroke="#063b27" stroke-linejoin="round" stroke-width="2" />
                                            <path d="M32 6v3m-2-1.5h4" stroke="#FFF0B1" stroke-linecap="round" stroke-width="2" />
                                        </svg>
                                    </span>

                                    <div class="max-w-2xl">
                                        <p class="text-[11px] font-black uppercase tracking-[0.22em] text-gold-300">
                                            {{ __('Community Contribution') }}
                                        </p>
                                        <h2 class="mt-2 max-w-xl font-heading text-2xl font-bold leading-snug tracking-tight text-balance sm:text-3xl">
                                            {{ __('Kenal institusi yang belum tersenarai?') }}
                                        </h2>
                                        <p class="mt-3 max-w-2xl text-sm leading-6 text-emerald-100/75 sm:text-base">
                                            {{ __('Bantu kami menambah masjid, surau, madrasah, dan pusat ilmu yang patut ditemui ramai. Setiap cadangan akan disemak sebelum diterbitkan.') }}
                                        </p>
                                    </div>
                                </div>

                                <a
                                    href="{{ $submitInstitutionUrl }}"
                                    wire:navigate
                                    class="living-majlis-cta-button group inline-flex min-h-14 w-full items-center justify-between gap-5 rounded-[1.25rem] px-5 py-3.5 text-left text-[#063b27] focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-gold-400/40 sm:w-auto sm:min-w-[18rem]"
                                >
                                    <span class="relative z-10 text-sm font-bold sm:text-base">{{ __('Cadangkan institusi') }}</span>
                                    <svg class="relative z-10 h-5 w-5 shrink-0" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.167 10h11.666m0 0-4.166-4.167M15.833 10l-4.166 4.167" />
                                    </svg>
                                </a>
                            </div>
                        </div>
                    </section>
	                </div>
		        </div>
		    </div>
