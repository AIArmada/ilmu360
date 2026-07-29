<?php

use App\Forms\SharedFormSchema;
use App\Models\Venue;
use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new
    #[Title('Venues - ilmu360°')]
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
        public ?string $administrative_district_id = null;

        #[Url]
        public ?string $administrative_subdivision_id = null;

        #[Computed]
        public function venues(): LengthAwarePaginatorContract
        {
            $query = $this->baseVenuesQuery();
            $search = $this->normalizedSearch();

            if ($search !== null) {
                $this->applySearchScope($query, $search);
            }

            return $query
                ->orderBy('venues.name')
                ->paginate(12)
                ->withQueryString();
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
        public function subdistricts(): array
        {
            return SharedFormSchema::subdistrictOptionsForSelection($this->state_id, $this->administrative_district_id, $this->country_id);
        }

        public function isParentlessAreaProfileSelection(): bool
        {
            return SharedFormSchema::shouldShowSubdistrictField($this->state_id, null, $this->country_id)
                && ! SharedFormSchema::shouldShowDistrictField($this->state_id, $this->country_id);
        }

        public function updatedSearch(): void
        {
            $this->resetPage();
        }

        public function updatedCountryId(): void
        {
            $this->state_id = null;
            $this->administrative_district_id = null;
            $this->administrative_subdivision_id = null;
            $this->resetPage();
        }

        public function updatedStateId(): void
        {
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
            $this->administrative_district_id = null;
            $this->administrative_subdivision_id = null;
            $this->resetPage();
        }

        private function baseVenuesQuery(): Builder
        {
            $query = Venue::query()
                ->active()
                ->where('status', 'verified')
                ->withCount(['events' => function (Builder $query): void {
                    $query->active();
                }])
                ->with([
                    'addresses',
                    'media',
                ]);

            return $this->applyLocationScope($query);
        }

        private function applySearchScope(Builder $query, string $search): void
        {
            $operator = DB::connection($query->getModel()->getConnectionName())->getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE';
            $collapsedWildcardSearch = '%'.str_replace(' ', '%', $search).'%';

            $query->where(function (Builder $innerQuery) use ($operator, $search, $collapsedWildcardSearch): void {
                $innerQuery
                    ->where('venues.name', $operator, "%{$search}%")
                    ->orWhere('venues.name', $operator, $collapsedWildcardSearch)
                    ->orWhere('venues.slug', $operator, "%{$search}%")
                    ->orWhere('venues.description', $operator, "%{$search}%")
                    ->orWhere('venues.description', $operator, $collapsedWildcardSearch)
                    ->orWhereHas('addresses', function (Builder $addressQuery) use ($operator, $search, $collapsedWildcardSearch): void {
                        $addressQuery
                            ->where('line1', $operator, "%{$search}%")
                            ->orWhere('line1', $operator, $collapsedWildcardSearch)
                            ->orWhere('line2', $operator, "%{$search}%")
                            ->orWhere('postcode', $operator, "%{$search}%")
                            ->orWhere('city', $operator, "%{$search}%")
                            ->orWhere('state', $operator, "%{$search}%")
                            ->orWhere('country', $operator, "%{$search}%");
                    });
            });
        }

        private function applyLocationScope(Builder $query): Builder
        {
            $countryId = $this->normalizedLocationId($this->country_id);
            $stateId = $this->normalizedLocationId($this->state_id);
            $adminArea1Id = $this->normalizedLocationId($this->administrative_district_id);
            $adminArea2Id = $this->normalizedLocationId($this->administrative_subdivision_id);

            if ($countryId === null && $stateId === null && $adminArea1Id === null && $adminArea2Id === null) {
                return $query;
            }

            return $query->whereHas('addresses', function (Builder $addressQuery) use ($countryId, $stateId, $adminArea1Id, $adminArea2Id): void {
                if ($countryId !== null) {
                    $addressQuery->where('country_id', $countryId);
                }

                if ($stateId !== null) {
                    $addressQuery->where('state_id', $stateId);
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

        private function normalizedSearch(): ?string
        {
            if (! is_string($this->search)) {
                return null;
            }

            $search = trim($this->search);

            return $search === '' ? null : $search;
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

@section('title', __('Venue Directory') . ' - ' . config('app.name'))
@section('meta_description', __('Explore halls, auditoriums, libraries, and community venues used for public knowledge events.'))
@section('og_url', route('venues.index'))
@section('og_image', asset('images/placeholders/venue.png'))
@section('og_image_alt', __('Venue directory'))
@section('og_image_width', '1024')
@section('og_image_height', '1024')

@php
    $venues = $this->venues;
    $search = $this->search;
    $states = $this->states;
    $districts = $this->districts;
    $subdistricts = $this->subdistricts;
    $countryId = $this->country_id;
    $stateId = $this->state_id;
    $adminArea1Id = $this->administrative_district_id;
    $adminArea2Id = $this->administrative_subdivision_id;
    $isParentlessAreaProfile = $this->isParentlessAreaProfileSelection();
    $hasScopedFilters = filled($countryId) || filled($stateId) || filled($adminArea1Id) || filled($adminArea2Id);
    $venueTotal = $venues->total();
    $venueLoadingTarget = 'search,country_id,state_id,administrative_district_id,administrative_subdivision_id,clearSearch,clearFilters';
    $formatVenueLocation = static function ($addressModel): string {
        $parts = \App\Support\Location\AddressHierarchyFormatter::parts($addressModel);

        return $parts === [] ? '-' : implode(', ', $parts);
    };
@endphp

<div class="relative min-h-screen">
    <div class="relative pt-12 pb-16 bg-white border-b border-slate-100 overflow-hidden">
        <div class="absolute inset-0 bg-emerald-50/50"></div>
        <div class="absolute inset-0 opacity-5" style="background-image: url('{{ asset('images/pattern-bg.png') }}');"></div>

        <div class="container relative mx-auto px-6 text-center lg:px-12">
            <h1 class="mb-6 text-balance font-heading text-4xl font-extrabold tracking-tight text-slate-900 md:text-5xl">
                {{ __('Places for') }} <br class="hidden md:block" />
                <span class="bg-gradient-to-r from-emerald-600 to-teal-500 bg-clip-text text-transparent">{{ __('Knowledge & Community') }}</span>
            </h1>
            <p class="mx-auto max-w-2xl text-balance text-lg text-slate-600 md:text-xl">
                {{ __('Find halls, auditoriums, libraries, and trusted spaces where Majlis Ilmu happens.') }}
            </p>

            <div class="mx-auto mt-8 max-w-xl">
                <div class="group relative">
                    <label for="venue-search" class="sr-only">{{ __('Search venues') }}</label>
                    <input
                        type="text"
                        id="venue-search"
                        wire:model.live.debounce.300ms="search"
                        wire:keydown.escape="clearSearch"
                        placeholder="{{ __('Search venues...') }}"
                        class="h-14 w-full rounded-2xl border-2 border-slate-200 bg-white py-0 pl-12 pr-4 font-medium text-slate-900 shadow-lg shadow-slate-200/60 transition-all placeholder:text-slate-400 focus:border-emerald-500 focus:outline-none focus:ring-4 focus:ring-emerald-500/10"
                    >
                    <svg class="absolute left-4 top-1/2 h-6 w-6 -translate-y-1/2 text-slate-400 transition-colors group-focus-within:text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                    @if(filled($search))
                        <button
                            type="button"
                            wire:click="clearSearch"
                            aria-label="{{ __('Clear search') }}"
                            class="absolute right-3 top-1/2 inline-flex h-9 w-9 -translate-y-1/2 items-center justify-center rounded-full border border-red-200 bg-red-50 text-red-500 shadow-sm transition hover:border-red-300 hover:bg-red-100 hover:text-red-600 focus:outline-none focus:ring-4 focus:ring-red-500/10"
                        >
                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 6l8 8M14 6l-8 8" />
                            </svg>
                            <span class="sr-only">{{ __('Clear search') }}</span>
                        </button>
                    @endif
                </div>

                <div class="mt-4 grid grid-cols-1 gap-3 text-left md:grid-cols-2 xl:grid-cols-3">
                    <div>
                        <label for="venue-state-filter" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">
                            {{ __('Negeri') }}
                        </label>
                        <select
                            id="venue-state-filter"
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

                    @unless($isParentlessAreaProfile)
                        <div>
                            <label for="venue-district-filter" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">
                                {{ __('Daerah') }}
                            </label>
                            <select
                                id="venue-district-filter"
                                    wire:model.live="administrative_district_id"
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
                        <label for="venue-subdistrict-filter" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">
                            {{ __('Bandar / Mukim / Zon') }}
                        </label>
                        <select
                            id="venue-subdistrict-filter"
                            wire:model.live="administrative_subdivision_id"
                            @disabled($isParentlessAreaProfile ? ! filled($stateId) : ! filled($adminArea1Id))
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
                        <button type="button" wire:click="clearFilters" class="text-xs font-bold text-red-500 hover:underline">
                            {{ __('Clear Location Scope') }}
                        </button>
                    </div>
                @endif
            </div>
        </div>
    </div>

    <div class="container mx-auto mt-12 px-6 lg:px-12">
        <div wire:loading.delay.short wire:target="{{ $venueLoadingTarget }}">
            <x-ui.skeleton.institution-card-grid />
        </div>

        <div wire:loading.remove wire:target="{{ $venueLoadingTarget }}">
            @if($venues->isEmpty())
                <div class="rounded-3xl border border-dashed border-slate-200 bg-slate-50/50 py-24 text-center">
                    <div class="mb-6 inline-flex h-20 w-20 items-center justify-center rounded-full bg-white text-slate-300 shadow-sm">
                        <svg class="h-10 w-10" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                    </div>
                    <h3 class="text-xl font-bold text-slate-900">{{ __('No venues found') }}</h3>
                    <p class="mx-auto mt-2 max-w-md text-slate-500">
                        {{ __('We couldn\'t find any venues matching your search or location filters.') }}
                    </p>
                    <div class="mt-6 flex flex-wrap items-center justify-center gap-3">
                        <button type="button" wire:click="clearFilters" class="font-semibold text-emerald-600 hover:text-emerald-700">
                            {{ __('Clear Filters') }} &rarr;
                        </button>
                    </div>
                </div>
            @else
                <div class="grid gap-8 md:grid-cols-2 lg:grid-cols-3">
                    @foreach($venues as $venue)
                        @php
                            $coverUrl = $venue->getFirstMediaUrl('cover', 'banner') ?: asset('images/placeholders/venue.png');
                            $address = $venue->primaryAddress();
                            $locationDisplay = $formatVenueLocation($address);
                            $venueType = $venue->venue_type;
                            $typeLabel = $venueType instanceof \App\Enums\VenueType
                                ? $venueType->getLabel()
                                : (filled($venueType) ? \Illuminate\Support\Str::headline((string) $venueType) : __('Venue'));
                        @endphp

                        <a
                            href="{{ route('venues.show', $venue) }}"
                            wire:navigate
                            class="group relative flex flex-col overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-md transition-all duration-300 hover:-translate-y-1 hover:shadow-xl hover:shadow-emerald-900/8"
                        >
                            <div class="relative aspect-video overflow-hidden bg-slate-50">
                                <img src="{{ $coverUrl }}" alt="{{ $venue->name }}" class="h-full w-full object-cover transition-transform duration-700 group-hover:scale-105" loading="lazy">
                                <div class="absolute inset-0 bg-gradient-to-t from-slate-900/45 via-slate-900/10 to-transparent"></div>
                                <span class="absolute left-4 top-4 rounded-full bg-white/95 px-3 py-1 text-xs font-bold text-emerald-700 shadow-sm ring-1 ring-emerald-100">
                                    {{ $typeLabel }}
                                </span>
                            </div>

                            <div class="flex flex-1 flex-col p-6">
                                <h3 class="mb-2 font-heading text-lg font-bold leading-tight text-slate-900 transition-colors group-hover:text-emerald-700">
                                    {{ $venue->name }}
                                </h3>

                                <p class="mb-4 flex items-start gap-1.5 text-sm font-medium text-slate-600">
                                    <svg class="mt-0.5 h-4 w-4 shrink-0 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                                    </svg>
                                    <span class="line-clamp-2">{{ $locationDisplay }}</span>
                                </p>

                                <div class="mt-auto flex items-center justify-center border-t border-slate-100 pt-5">
                                    <span class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-200">
                                        <svg class="h-3.5 w-3.5 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                        </svg>
                                        {{ $venue->events_count }} {{ __('Events') }}
                                    </span>
                                </div>
                            </div>
                        </a>
                    @endforeach
                </div>

                <div class="mt-16">
                    {{ $venues->links() }}
                </div>

                <div class="mt-6 rounded-2xl border border-slate-200 bg-slate-50/80 px-4 py-4 text-center shadow-sm">
                    <p class="text-[11px] font-black uppercase tracking-[0.2em] text-slate-400">{{ __('Direktori Tempat') }}</p>
                    <p class="mt-2 text-sm font-semibold text-slate-600">
                        {{ __('Jumlah tempat: :count', ['count' => number_format($venueTotal)]) }}
                    </p>
                </div>
            @endif
        </div>
    </div>
</div>
