<?php

use App\Enums\EventStructure;
use App\Enums\EventVisibility;
use App\Models\Event;
use AIArmada\Addressing\Models\AddressArea;
use App\Models\Institution;
use App\Support\Search\InstitutionSearchService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
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

    #[Url]
    public ?string $search = null;

    #[Url]
    public ?string $country_id = null;

    #[Url]
    public ?string $state_id = null;

    #[Url]
    public ?string $admin_area_1_id = null;

    #[Url]
    public ?string $admin_area_2_id = null;

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
        $query = Institution::query()
            ->select('institutions.*')
            ->active()
            ->where('status', 'verified')
            ->selectSub($this->publicEventCountSubquery(), 'events_count')
            ->with(['addresses', 'media']);

        return $this->applyLocationScope($query);
    }

    /**
     * @return Builder<Event>
     */
    private function publicEventCountSubquery(): Builder
    {
        return Event::query()
            ->selectRaw('count(*)')
            ->whereRaw("{$this->eventInstitutionIdSelector()} = institutions.id")
            ->where('events.is_active', true)
            ->whereIn('events.status', Event::PUBLIC_STATUSES)
            ->where('events.visibility', EventVisibility::Public->value)
            ->where('events.event_structure', '!=', EventStructure::ParentProgram->value);
    }

    private function eventInstitutionIdSelector(): string
    {
        return match ($this->databaseDriver()) {
            'pgsql' => "(events.metadata->>'institution_id')::uuid",
            default => $this->eventMetadataSqlSelector('institution_id'),
        };
    }

    private function eventMetadataSqlSelector(string $key): string
    {
        return match ($this->databaseDriver()) {
            'pgsql' => "events.metadata->>'{$key}'",
            'mysql', 'mariadb' => "json_unquote(json_extract(events.metadata, '$.\"{$key}\"'))",
            default => "json_extract(events.metadata, '$.\"{$key}\"')",
        };
    }

    private function databaseDriver(): string
    {
        return DB::connection()->getDriverName();
    }

    private function directSearch(string $search): LengthAwarePaginatorContract
    {
        $matchingIds = $this->institutionSearchService()->publicSearchIds($search);

        if ($matchingIds === []) {
            return $this->emptyPaginator();
        }

        return $this->baseInstitutionsQuery()
            ->whereIn('institutions.id', $matchingIds)
            ->publicDirectoryOrder()
            ->paginate(12)
            ->withQueryString();
    }

    private function fuzzySearch(string $search): LengthAwarePaginatorContract
    {
        $orderedIds = $this->filterSearchIdsToCurrentScope(
            $this->institutionSearchService()->publicFuzzySearchIds($search),
        );

        $currentPage = max(1, (int) $this->getPage());
        $perPage = 12;
        $paginationMeta = $this->paginationMeta();

        if ($orderedIds === []) {
            return $this->emptyPaginator();
        }

        $paginatedIds = array_slice($orderedIds, ($currentPage - 1) * $perPage, $perPage);

        if ($paginatedIds === []) {
            return new LengthAwarePaginator(collect(), count($orderedIds), $perPage, $currentPage, $paginationMeta);
        }

        $institutions = $this->baseInstitutionsQuery()
            ->whereIn('id', $paginatedIds)
            ->get()
            ->sortBy(static function (Institution $institution) use ($paginatedIds): int {
                $position = array_search($institution->id, $paginatedIds, true);

                return is_int($position) ? $position : PHP_INT_MAX;
            })
            ->values();

        return new LengthAwarePaginator($institutions, count($orderedIds), $perPage, $currentPage, $paginationMeta);
    }

    /**
     * @param  list<string>  $orderedIds
     * @return list<string>
     */
    private function filterSearchIdsToCurrentScope(array $orderedIds): array
    {
        if ($orderedIds === []) {
            return [];
        }

        $scopedIds = $this->baseInstitutionsQuery()
            ->whereIn('institutions.id', $orderedIds)
            ->pluck('institutions.id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        return collect($scopedIds)
            ->sortBy(static function (string $id) use ($orderedIds): int {
                $position = array_search($id, $orderedIds, true);

                return is_int($position) ? $position : PHP_INT_MAX;
            })
            ->values()
            ->all();
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
    public function states(): array
    {
        $countryId = $this->normalizedLocationId($this->country_id);

        if ($countryId === null) {
            return [];
        }

        return AddressArea::query()
            ->where('country_id', $countryId)
            ->where('level', 1)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    #[Computed]
    public function districts(): array
    {
        $stateId = $this->normalizedLocationId($this->state_id);

        if ($stateId === null) {
            return [];
        }

        return AddressArea::query()
            ->where('parent_id', $stateId)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    #[Computed]
    public function subdistricts(): array
    {
        $adminArea1Id = $this->normalizedLocationId($this->admin_area_1_id) ?? $this->normalizedLocationId($this->state_id);

        if ($adminArea1Id === null) {
            return [];
        }

        return AddressArea::query()
            ->where('parent_id', $adminArea1Id)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public function isFederalTerritoryStateSelected(): bool
    {
        return false;
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
        $this->admin_area_1_id = null;
        $this->admin_area_2_id = null;
        $this->resetPage();
    }

    public function updatedStateId(): void
    {
        $this->admin_area_1_id = null;
        $this->admin_area_2_id = null;
        $this->resetPage();
    }

    public function updatedDistrictId(): void
    {
        $this->admin_area_2_id = null;
        $this->resetPage();
    }

    public function updatedSubdistrictId(): void
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
        $this->admin_area_1_id = null;
        $this->admin_area_2_id = null;
        $this->resetPage();
    }

    private function applyLocationScope(Builder $query): Builder
    {
        $countryId = $this->normalizedLocationId($this->country_id);
        $stateId = $this->normalizedLocationId($this->state_id);
        $adminArea1Id = $this->normalizedLocationId($this->admin_area_1_id);
        $adminArea2Id = $this->normalizedLocationId($this->admin_area_2_id);

        if ($countryId === null && $stateId === null && $adminArea1Id === null && $adminArea2Id === null) {
            return $query;
        }

        return $query->whereHas('addresses', function (Builder $addressQuery) use ($countryId, $stateId, $adminArea1Id, $adminArea2Id): void {
            if ($countryId !== null) {
                $addressQuery->where('country_id', $countryId);
            }

            if ($stateId !== null) {
                $addressQuery->where('admin_area_1_id', $stateId);
            }

            if ($adminArea1Id !== null) {
                $addressQuery->where('admin_area_2_id', $adminArea1Id);
            }

            if ($adminArea2Id !== null) {
                $addressQuery->where('admin_area_3_id', $adminArea2Id);
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
    $states = $this->states;
    $districts = $this->districts;
    $subdistricts = $this->subdistricts;
    $countryId = $this->country_id;
    $stateId = $this->state_id;
    $adminArea1Id = $this->admin_area_1_id;
    $adminArea2Id = $this->admin_area_2_id;
    $isFederalTerritoryState = $this->isFederalTerritoryStateSelected();
    $hasScopedFilters = filled($countryId) || filled($stateId) || filled($adminArea1Id) || filled($adminArea2Id);
    $submitInstitutionUrl = route('contributions.submit-institution');
    $institutionTotal = $institutions->total();
    $formatInstitutionLocation = static function ($addressModel): string {
        $parts = \App\Support\Location\AddressHierarchyFormatter::parts($addressModel);

        return $parts === [] ? '-' : implode(', ', $parts);
    };
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
                
                 <!-- Search Box -->
                 <div class="max-w-xl mx-auto mt-8">
                    <div class="relative group">
                        <label for="institution-search" class="sr-only">{{ __('Search institutions') }}</label>
                        <input 
                            type="text" 
                            id="institution-search"
                            wire:model.live.debounce.300ms="search"
                            wire:keydown.escape="clearSearch"
                            placeholder="{{ __('Search institutions...') }}" 
                            class="w-full h-14 pl-12 pr-4 rounded-2xl border-2 border-slate-200 bg-white shadow-lg shadow-slate-200/60 font-medium text-slate-900 focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/10 focus:outline-none transition-all placeholder:text-slate-400"
                        >
                        <svg class="absolute left-4 top-1/2 -translate-y-1/2 h-6 w-6 text-slate-400 group-focus-within:text-emerald-500 transition-colors" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
                        @if(filled($search))
                            <button type="button" wire:click="clearSearch"
                                aria-label="{{ __('Clear search') }}"
                                class="absolute right-3 top-1/2 inline-flex h-9 w-9 -translate-y-1/2 items-center justify-center rounded-full border border-red-200 bg-red-50 text-red-500 shadow-sm transition hover:border-red-300 hover:bg-red-100 hover:text-red-600 focus:outline-none focus:ring-4 focus:ring-red-500/10">
                                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 6l8 8M14 6l-8 8" />
                                </svg>
                                <span class="sr-only">{{ __('Clear search') }}</span>
                            </button>
                        @endif
                    </div>

                    <div class="mt-4 grid grid-cols-1 gap-3 text-left md:grid-cols-2 xl:grid-cols-3">
                        <div>
                            <label for="institution-state-filter" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">
                                {{ __('Negeri') }}
                            </label>
                            <select
                                id="institution-state-filter"
                                wire:model.live="state_id"
                                @disabled(! filled($countryId))
                                class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/10 disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-400"
                            >
                                <option value="">{{ __('Semua Negeri') }}</option>
                                @foreach($states as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>

                        @unless($isFederalTerritoryState)
                            <div>
                                <label for="institution-district-filter" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">
                                    {{ __('Daerah') }}
                                </label>
                                <select
                                    id="institution-district-filter"
                                    wire:model.live="admin_area_1_id"
                                    @disabled(! filled($stateId))
                                    class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/10 disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-400"
                                >
                                    <option value="">{{ __('Semua Daerah') }}</option>
                                    @foreach($districts as $id => $name)
                                        <option value="{{ $id }}">{{ $name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endunless

                        <div>
                            <label for="institution-subdistrict-filter" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">
                                {{ __('Bandar / Mukim / Zon') }}
                            </label>
                            <select
                                id="institution-subdistrict-filter"
                                wire:model.live="admin_area_2_id"
                                @disabled($isFederalTerritoryState ? ! filled($stateId) : ! filled($adminArea1Id))
                                class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/10 disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-400"
                            >
                                <option value="">{{ __('Semua Bandar / Mukim / Zon') }}</option>
                                @foreach($subdistricts as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

	                    @if($hasScopedFilters)
	                        <div class="mt-3 flex justify-end">
	                            <button
	                                type="button"
	                                wire:click="clearFilters"
                                class="text-xs font-bold text-red-500 hover:underline"
	                            >
	                                {{ __('Clear Location Scope') }}
	                            </button>
	                        </div>
	                    @endif

		                 </div>
		            </div>
		        </div>

	        <div class="container mx-auto px-6 lg:px-12 mt-12">
                @php
                    $institutionLoadingTarget = 'search,country_id,state_id,admin_area_1_id,admin_area_2_id,clearSearch,clearFilters';
                @endphp

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
                <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-8">
                    @foreach($institutions as $institution)
                        @php
                            $cardInstitutionImageUrl = $institution->public_image_url;
                        @endphp
                        <a href="{{ route('institutions.show', $institution) }}" wire:navigate class="group relative bg-white rounded-3xl border border-slate-200 shadow-md hover:shadow-xl hover:shadow-emerald-900/8 hover:-translate-y-1 transition-all duration-300 flex flex-col overflow-hidden">
                            <!-- Banner Area (16:9, cover-first) -->
                            <div class="aspect-video bg-slate-50 relative overflow-hidden">
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
                                    $address = $institution->addressModel;
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
                                {{ __('Jumlah institusi: :count', ['count' => number_format($institutionTotal)]) }}
                            </p>
                        </div>
		            @endif

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
                                        class="group inline-flex min-w-[18rem] items-center justify-between gap-4 rounded-[1.5rem] bg-white px-5 py-4 text-left text-emerald-700 shadow-xl shadow-emerald-950/20 transition duration-200 hover:-translate-y-0.5 hover:bg-emerald-50"
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
