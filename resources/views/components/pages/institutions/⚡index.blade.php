<?php

use App\Enums\EventVisibility;
use App\Forms\SharedFormSchema;
use App\Models\Event;
use App\Models\Institution;
use App\Support\Cache\SelectionCatalogCache;
use App\Support\Location\VisitorCountryResolver;
use App\Support\Search\InstitutionSearchService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Illuminate\Database\Eloquent\Builder;
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

    private bool $defaultCountryIdResolved = false;

    private ?string $resolvedDefaultCountryId = null;

    public function mount(): void
    {
        if ($this->country_id === null) {
            $this->country_id = $this->defaultCountryId();
        }
    }

    #[Url]
    public ?string $search = null;

    #[Url]
    public ?string $country_id = null;

    #[Url]
    public ?string $state_id = null;

    #[Url]
    public ?string $city_id = null;

    #[Url]
    public ?string $administrative_district_id = null;

    #[Url]
    public ?string $administrative_subdivision_id = null;

    #[Computed]
    public function institutions(): LengthAwarePaginatorContract
    {
        $search = $this->normalizedSearch();
        $baseQuery = $this->baseInstitutionsQuery();

        if ($search === null) {
            return $baseQuery
                ->publicDirectoryOrder()
                ->paginate(12)
                ->withQueryString();
        }

        $directMatches = $this->directSearch($search);

        if ($directMatches->total() > 0 || mb_strlen($search) < 3) {
            return $directMatches;
        }

        return $this->fuzzySearch($search);
    }

    private function baseInstitutionsQuery(): Builder
    {
        return $this->scopedInstitutionsQuery()
            ->select('institutions.*')
            ->selectSub($this->publicEventCountSubquery(), 'events_count')
            ->with([
                'addresses.state',
                'addresses.city',
                'addresses.areaAssignments.area',
                'media',
            ]);
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
        return Event::query()
            ->selectRaw('count(*)')
            ->whereColumn('events.institution_id', 'institutions.id')
            ->whereNotNull('events.published_at')
            ->whereIn('events.status', Event::PUBLIC_STATUSES)
            ->where('events.visibility', EventVisibility::Public->value)
            ->whereHas('occurrences');
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
        return app(SelectionCatalogCache::class)->countryOptions();
    }

    #[Computed]
    public function states(): array
    {
        $countryId = $this->normalizedLocationId($this->country_id);

        return SharedFormSchema::stateOptionsForCountry($countryId);
    }

    #[Computed]
    public function districts(): array
    {
        return SharedFormSchema::districtOptionsForState($this->state_id, $this->country_id);
    }

    #[Computed]
    public function cities(): array
    {
        // A city is never a valid first step in the cascade. The admin form
        // only exposes it after a state is selected, and Malaysia does not
        // expose it at all once its district/local-area profile applies.
        if (! filled($this->state_id)) {
            return [];
        }

        // Follow the admin address form: once a country's profile exposes
        // district/local-area hierarchy, City is redundant and must not be
        // offered as a parallel filter. Malaysia uses this path.
        if (
            SharedFormSchema::shouldShowDistrictField($this->state_id, $this->country_id)
            || SharedFormSchema::shouldShowSubdistrictField($this->state_id, null, $this->country_id)
        ) {
            return [];
        }

        return SharedFormSchema::cityOptionsForState($this->state_id, $this->country_id);
    }

    #[Computed]
    public function subdistricts(): array
    {
        if (! filled($this->state_id)) {
            return [];
        }

        // For states with a district hierarchy, mukim is the child filter and
        // must not be offered until a district has been selected. Federal
        // territories intentionally skip district and expose local areas
        // directly under the selected state.
        if (
            SharedFormSchema::shouldShowDistrictField($this->state_id, $this->country_id)
            && ! filled($this->administrative_district_id)
        ) {
            return [];
        }

        return SharedFormSchema::subdistrictOptionsForSelection($this->state_id, $this->administrative_district_id, $this->country_id);
    }

    public function stateLabel(): string
    {
        return SharedFormSchema::locationLevelLabel($this->country_id, 'state_id', __('State / Federal Territory'));
    }

    public function districtLabel(): string
    {
        return SharedFormSchema::locationLevelLabel($this->country_id, 'administrative_district', __('District'));
    }

    public function subdistrictLabel(): string
    {
        return SharedFormSchema::locationLevelLabel($this->country_id, 'administrative_subdivision', __('Subdivision'));
    }

    public function isParentlessAreaProfileSelection(): bool
    {
        return SharedFormSchema::shouldShowSubdistrictField($this->state_id, null, $this->country_id)
            && ! SharedFormSchema::shouldShowDistrictField($this->state_id, $this->country_id);
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

    public function updatedCountryId(): void
    {
        $this->state_id = null;
        $this->city_id = null;
        $this->administrative_district_id = null;
        $this->administrative_subdivision_id = null;
        $this->resetPage();
    }

    public function updatedStateId(): void
    {
        $this->city_id = null;
        $this->administrative_district_id = null;
        $this->administrative_subdivision_id = null;
        $this->resetPage();
    }

    public function updatedAdministrativeDistrictId(): void
    {
        $this->administrative_subdivision_id = null;
        $this->resetPage();
    }

    public function updatedAdministrativeSubdivisionId(): void
    {
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
        $this->country_id = null;
        $this->state_id = null;
        $this->city_id = null;
        $this->administrative_district_id = null;
        $this->administrative_subdivision_id = null;
        $this->resetPage();
    }

    private function applyLocationScope(Builder $query): Builder
    {
        $countryId = $this->normalizedLocationId($this->country_id);
        $stateId = $this->normalizedLocationId($this->state_id);
        $cityId = $this->normalizedLocationId($this->city_id);
        $adminArea1Id = $this->normalizedLocationId($this->administrative_district_id);
        $adminArea2Id = $this->normalizedLocationId($this->administrative_subdivision_id);

        if ($countryId === null && $stateId === null && $cityId === null && $adminArea1Id === null && $adminArea2Id === null) {
            return $query;
        }

        return $query->whereHas('addresses', function (Builder $addressQuery) use ($countryId, $stateId, $cityId, $adminArea1Id, $adminArea2Id): void {
            if ($countryId !== null) {
                $addressQuery->where('country_id', $countryId);
            }

            if ($stateId !== null) {
                $addressQuery->where('state_id', $stateId);
            }

            if ($cityId !== null) {
                $addressQuery->where('city_id', $cityId);
            }

            if ($adminArea1Id !== null) {
                $addressQuery->whereHas('areaAssignments', fn (Builder $assignmentQuery) => $assignmentQuery
                    ->where('role', 'administrative_district')->where('address_area_id', $adminArea1Id));
            }

            if ($adminArea2Id !== null) {
                $addressQuery->whereHas('areaAssignments', fn (Builder $assignmentQuery) => $assignmentQuery
                    ->where('role', 'administrative_subdivision')->where('address_area_id', $adminArea2Id));
            }
        });
    }

    private function normalizedLocationId(?string $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        if ($normalized === '' || ! Str::isUuid($normalized)) {
            return null;
        }

        return $normalized;
    }

    private function defaultCountryId(): ?string
    {
        if ($this->defaultCountryIdResolved) {
            return $this->resolvedDefaultCountryId;
        }

        $this->defaultCountryIdResolved = true;

        // Shared with the /majlis directory: the edge geo header (Cloudflare
        // CF-IPCountry) is the cheapest GeoIP, no mmdb/api needed when behind CF.
        return $this->resolvedDefaultCountryId = app(VisitorCountryResolver::class)->resolve();
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
    $districts = $this->districts;
    $subdistricts = $this->subdistricts;
    $countryId = $this->country_id;
    $stateId = $this->state_id;
    $cityId = $this->city_id;
    $adminArea1Id = $this->administrative_district_id;
    $adminArea2Id = $this->administrative_subdivision_id;
    $isParentlessAreaProfile = $this->isParentlessAreaProfileSelection();
    $stateLabel = $this->stateLabel();
    $districtLabel = $this->districtLabel();
    $subdistrictLabel = $this->subdistrictLabel();
    $defaultCountryId = $this->defaultCountryId();
    $hasScopedFilters = ($countryId !== $defaultCountryId && filled($countryId)) || filled($stateId) || filled($cityId) || filled($adminArea1Id) || filled($adminArea2Id);
    $activeLocationFilterCount = collect([
        $countryId !== $defaultCountryId ? $countryId : null,
        $stateId,
        $cityId,
        $adminArea1Id,
        $adminArea2Id,
    ])->filter(static fn (mixed $value): bool => filled($value))->count();
    $submitInstitutionUrl = route('contributions.submit-institution');
    $institutionTotal = $institutions->total();
@endphp

<div class="relative min-h-screen">
        <!-- Hero Section -->
        <div class="relative pt-12 pb-16 bg-white border-b border-slate-100 overflow-hidden">
             <div class="absolute inset-0 bg-emerald-50/50"></div>
        <div class="absolute inset-0 opacity-5" style="background-image: url('{{ asset('images/pattern-bg.png') }}');"></div>

            <div class="container relative mx-auto px-6 lg:px-12 text-center">
                 <h1 class="font-heading text-4xl md:text-5xl font-extrabold text-slate-900 tracking-tight text-balance mb-6">
                    {{ __('Centers of') }} <br class="hidden md:block" />
                    <span class="text-transparent bg-clip-text bg-gradient-to-r from-emerald-600 to-teal-500">{{ __('Knowledge & Community') }}</span>
                </h1>
                <p class="text-slate-600 text-lg md:text-xl max-w-2xl mx-auto text-balance">
                    {{ __('Connect with the mosques, suraus, and educational centers hosting Majlis Ilmu and nurturing our community.') }}
                </p>
                
                 <!-- Search and filter controls -->
                 <div class="mx-auto mt-8 max-w-5xl">
                    <x-ui.search-bar
                        input-id="institution-search"
                        model="search"
                        :value="$search"
                        :placeholder="__('Search institutions...')"
                        :label="__('Search institutions')"
                        :label-visible="true"
                        :count="$institutionTotal"
                        :count-label="__('institutions')"
                        :hint="__('Search by institution name or location.')"
                        width="mx-auto max-w-2xl"
                    />

                    <div data-institution-filters class="mx-auto mt-8 max-w-4xl border-t border-emerald-200/80 pt-5 text-left sm:pt-6">
                        <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
                            <div class="flex items-center gap-2">
                                <span class="flex h-7 w-7 items-center justify-center rounded-full bg-emerald-50 text-emerald-600 ring-1 ring-emerald-100">
                                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 21a9 9 0 100-18 9 9 0 000 18Zm0 0c2.25-2.15 3.5-5.15 3.5-9S14.25 5.15 12 3m0 18c-2.25-2.15-3.5-5.15-3.5-9S9.75 5.15 12 3m-8.5 9h17" /></svg>
                                </span>
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-emerald-800/70">{{ __('Filter by location') }}</p>
                                </div>
                            </div>
                            @if($activeLocationFilterCount > 0)
                                <span class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-bold text-emerald-700 ring-1 ring-emerald-100">{{ $activeLocationFilterCount }} {{ __('active') }}</span>
                            @endif
                        </div>
                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                        <div>
                            <label for="institution-country-filter" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">
                                {{ __('Country') }}
                            </label>
                            <flux:select
                                id="institution-country-filter"
                                wire:model.live="country_id"
                                size="sm"
                                class="w-full rounded-xl border-slate-300 bg-white text-sm text-slate-800 shadow-sm transition-[border-color,box-shadow,background-color] hover:border-emerald-300 focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/10 disabled:cursor-not-allowed disabled:border-slate-200 disabled:bg-slate-100 disabled:text-slate-400"
                            >
                                <flux:select.option value="" :selected="$countryId === null">{{ __('All countries') }}</flux:select.option>
                                @foreach($countries as $id => $name)
                                    <flux:select.option value="{{ $id }}" :selected="(string) $id === $countryId">{{ $name }}</flux:select.option>
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
                                wire:model.live="state_id"
                                :disabled="! filled($countryId)"
                                size="sm"
                                class="w-full rounded-xl border-slate-300 bg-white text-sm text-slate-800 shadow-sm transition-[border-color,box-shadow,background-color] hover:border-emerald-300 focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/10 disabled:cursor-not-allowed disabled:border-slate-200 disabled:bg-slate-100 disabled:text-slate-400"
                            >
                                <flux:select.option value="">{{ __('All :level', ['level' => $stateLabel]) }}</flux:select.option>
                                @foreach($states as $id => $name)
                                    <flux:select.option value="{{ $id }}">{{ $name }}</flux:select.option>
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
                                wire:model.live="city_id"
                                :disabled="! filled($stateId)"
                                size="sm"
                                class="w-full rounded-xl border-slate-300 bg-white text-sm text-slate-800 shadow-sm transition-[border-color,box-shadow,background-color] hover:border-emerald-300 focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/10 disabled:cursor-not-allowed disabled:border-slate-200 disabled:bg-slate-100 disabled:text-slate-400"
                            >
                                <flux:select.option value="">{{ __('All cities') }}</flux:select.option>
                                @foreach($cities as $id => $name)
                                    <flux:select.option value="{{ $id }}">{{ $name }}</flux:select.option>
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
                                    wire:model.live="administrative_district_id"
                                    :disabled="! filled($stateId)"
                                    size="sm"
                                    class="w-full rounded-xl border-slate-300 bg-white text-sm text-slate-800 shadow-sm transition-[border-color,box-shadow,background-color] hover:border-emerald-300 focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/10 disabled:cursor-not-allowed disabled:border-slate-200 disabled:bg-slate-100 disabled:text-slate-400"
                                >
                                    <flux:select.option value="">{{ __('All :level', ['level' => $districtLabel]) }}</flux:select.option>
                                    @foreach($districts as $id => $name)
                                        <flux:select.option value="{{ $id }}">{{ $name }}</flux:select.option>
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
                                wire:model.live="administrative_subdivision_id"
                                :disabled="$isParentlessAreaProfile ? ! filled($stateId) : ! filled($adminArea1Id)"
                                size="sm"
                                class="w-full rounded-xl border-slate-300 bg-white text-sm text-slate-800 shadow-sm transition-[border-color,box-shadow,background-color] hover:border-emerald-300 focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/10 disabled:cursor-not-allowed disabled:border-slate-200 disabled:bg-slate-100 disabled:text-slate-400"
                            >
                                <flux:select.option value="">{{ __('All :level', ['level' => $subdistrictLabel]) }}</flux:select.option>
                                @foreach($subdistricts as $id => $name)
                                    <flux:select.option value="{{ $id }}">{{ $name }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        </div>
                        @endif
                        </div>

	                    <div class="mt-5 flex justify-end border-t border-slate-200/80 pt-4">
	                        @if($hasScopedFilters)
	                            <button
	                                type="button"
	                                wire:click="clearFilters"
	                                class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1.5 text-xs font-bold text-red-500 transition hover:bg-red-50 hover:text-red-600 focus:outline-none focus:ring-4 focus:ring-red-500/10"
	                            >
	                                <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" d="M6 6l8 8M14 6l-8 8" /></svg>
	                                {{ __('Clear Location Scope') }}
	                            </button>
	                        @endif
	                    </div>
	                    </div>

		                 </div>
		            </div>
		        </div>

	        <div class="container mx-auto mt-12 px-6 lg:px-12">
            @island(name: 'institution-results', always: true)
                @php
                    $institutions = $this->institutions;
                    $institutionLoadingTarget = 'search,country_id,state_id,city_id,administrative_district_id,administrative_subdivision_id,clearSearch,clearFilters';
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
                <div class="grid gap-8 md:grid-cols-2 lg:grid-cols-3">
                    @foreach($institutions as $institution)
                        @php
                            $cardInstitutionImageUrl = $institution->public_image_url;
                        @endphp
                        <a wire:key="institution-{{ $institution->id }}" href="{{ route('institutions.show', $institution) }}" wire:navigate class="group relative flex flex-col overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-md transition-all duration-300 hover:-translate-y-1 hover:shadow-xl hover:shadow-emerald-900/8">
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
                                    <div class="absolute inset-0 bg-gradient-to-br from-emerald-50 to-teal-50 opacity-100 group-hover:opacity-90 transition-opacity"></div>
                                    <svg class="absolute right-0 bottom-0 text-emerald-100/50 w-32 h-32 transform translate-x-8 translate-y-8" fill="currentColor" viewBox="0 0 24 24">
                                         <path d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                                    </svg>
                                @endif
                            </div>
                            
                            <div class="p-6 pt-6 relative flex-1 flex flex-col">
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
                                
                                <div class="mt-auto pt-5 border-t border-slate-100 flex items-center justify-center">
                                    <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-emerald-700 bg-emerald-50 px-2.5 py-1 rounded-lg ring-1 ring-emerald-200">
                                        <svg class="w-3.5 h-3.5 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
                                        {{ $institution->events_count }} {{ __('Events') }}
                                    </span>
                                </div>
                            </div>
                        </a>
                    @endforeach
                </div>

		                <div class="mt-16">
		                    {{ $institutions->withQueryString()->links() }}
		                </div>

                        <div class="mt-6 rounded-2xl border border-slate-200 bg-slate-50/80 px-4 py-4 text-center shadow-sm">
                            <p class="text-[11px] font-black uppercase tracking-[0.2em] text-slate-400">{{ __('Direktori Institusi') }}</p>
                            <p class="mt-2 text-sm font-semibold text-slate-600">
                                {{ __('Jumlah institusi: :count', ['count' => number_format($institutions->total())]) }}
                            </p>
                        </div>
	            @endif

                    </div>
                </div>
            @endisland

                    <section class="mt-16">
                        <div class="relative overflow-hidden rounded-[2rem] border border-emerald-200/70 bg-gradient-to-br from-emerald-600 via-teal-600 to-cyan-600 px-6 py-8 text-white shadow-[0_30px_90px_-40px_rgba(5,150,105,0.85)] md:px-10 md:py-10">
                            <div class="absolute -right-16 -top-16 h-44 w-44 rounded-full bg-white/10 blur-2xl"></div>
                            <div class="absolute -bottom-20 left-0 h-48 w-48 rounded-full bg-emerald-300/20 blur-3xl"></div>

                            <div class="relative flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between">
                                <div class="max-w-2xl">
                                    <span class="inline-flex items-center rounded-full border border-white/20 bg-white/10 px-3 py-1 text-[11px] font-black uppercase tracking-[0.22em] text-emerald-50">
                                        {{ __('Sumbangan Komuniti') }}
                                    </span>
                                    <h2 class="mt-4 font-heading text-2xl font-bold tracking-tight text-balance md:text-3xl">
                                        {{ __('Tak jumpa institusi yang anda cari? Cadangkan institusi baharu.') }}
                                    </h2>
                                    <p class="mt-3 max-w-2xl text-sm leading-6 text-emerald-50/90 md:text-base">
                                        {{ __('Bantu kami tambah masjid, surau, pusat pengajian, dan komuniti ilmu yang patut ditemui ramai. Hantaran anda akan disemak dahulu sebelum dipaparkan kepada umum.') }}
                                    </p>
                                </div>

                                <div class="flex flex-col items-start gap-3 lg:items-end">
                                    <a
                                        href="{{ $submitInstitutionUrl }}"
                                        wire:navigate
                                        class="group inline-flex w-full min-w-0 items-center justify-between gap-4 rounded-[1.5rem] bg-white px-5 py-4 text-left text-emerald-700 shadow-xl shadow-emerald-950/20 transition duration-200 hover:-translate-y-0.5 hover:bg-emerald-50 sm:w-auto sm:min-w-[18rem]"
                                    >
                                        <span class="block">
                                            <span class="block text-[11px] font-black uppercase tracking-[0.2em] text-emerald-500">{{ __('Tambah ke direktori') }}</span>
                                            <span class="mt-1 block text-base font-bold text-emerald-900">{{ __('Cadangkan institusi baharu') }}</span>
                                        </span>
                                        <svg class="h-5 w-5 shrink-0 transition group-hover:translate-x-1" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M4.167 10h11.666m0 0-4.166-4.167M15.833 10l-4.166 4.167" />
                                        </svg>
                                    </a>

                                </div>
                            </div>
                        </div>
                    </section>
	                </div>
		        </div>
		    </div>
