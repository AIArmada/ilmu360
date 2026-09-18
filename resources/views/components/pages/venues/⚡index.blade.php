<?php

use App\Forms\SharedFormSchema;
use App\Support\Location\LocationSlugResolver;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new
    #[Title('Venues - ilmu360°')]
    class extends Component
    {
        #[Url]
        public ?string $search = null;

        #[Url]
        public ?string $country_id = null;

        #[Url]
        public ?string $state_id = null;

        #[Url]
        public ?string $district_id = null;

        #[Url]
        public ?string $subdivision_id = null;

        #[Computed]
        public function states(): array
        {
            return $this->locationSlugResolver()->stateOptionsForCountry($this->normalizedLocationId($this->country_id));
        }

        #[Computed]
        public function districts(): array
        {
            $countryId = $this->normalizedLocationId($this->country_id);
            $role = $this->locationSlugResolver()->districtRoleForCountry($countryId);

            if ($role === null) {
                return [];
            }

            return SharedFormSchema::areaOptionsForRole($countryId, $role, $this->normalizedLocationId($this->state_id));
        }

        #[Computed]
        public function subdistricts(): array
        {
            $countryId = $this->normalizedLocationId($this->country_id);
            $role = $this->locationSlugResolver()->subdivisionRoleForCountry($countryId);

            if ($role === null) {
                return [];
            }

            $parentId = $this->normalizedLocationId($this->district_id)
                ?? ($this->isParentlessAreaProfileSelection() ? $this->normalizedLocationId($this->state_id) : null);

            if ($parentId === null) {
                return [];
            }

            return SharedFormSchema::areaOptionsForRole($countryId, $role, $parentId);
        }

        public function isParentlessAreaProfileSelection(): bool
        {
            if ($this->districts() !== []) {
                return false;
            }

            $countryId = $this->normalizedLocationId($this->country_id);
            $role = $this->locationSlugResolver()->subdivisionRoleForCountry($countryId);
            $stateId = $this->normalizedLocationId($this->state_id);

            return $role !== null && $stateId !== null
                && SharedFormSchema::areaOptionsForRole($countryId, $role, $stateId) !== [];
        }

        public function stateLabel(): string
        {
            return SharedFormSchema::locationLevelLabel($this->normalizedLocationId($this->country_id), 'state_id', __('State / Province'));
        }

        public function districtLabel(): string
        {
            $countryId = $this->normalizedLocationId($this->country_id);
            $role = $this->locationSlugResolver()->districtRoleForCountry($countryId);

            return $role === null
                ? __('District')
                : SharedFormSchema::locationLevelLabel($countryId, $role, __('District'));
        }

        public function subdistrictLabel(): string
        {
            $countryId = $this->normalizedLocationId($this->country_id);
            $role = $this->locationSlugResolver()->subdivisionRoleForCountry($countryId);

            return $role === null
                ? __('Subdivision')
                : SharedFormSchema::locationLevelLabel($countryId, $role, __('Subdivision'));
        }

        public function updatedSearch(): void
        {
            $this->syncResults();
        }

        public function updatedCountryId(): void
        {
            $this->state_id = null;
            $this->district_id = null;
            $this->subdivision_id = null;
            $this->syncResults();
        }

        public function updatedStateId(): void
        {
            $this->district_id = null;
            $this->subdivision_id = null;
            $this->syncResults();
        }

        public function updatedDistrictId(): void
        {
            $this->subdivision_id = null;
            $this->syncResults();
        }

        public function updatedSubdivisionId(): void
        {
            $this->syncResults();
        }

        public function clearSearch(): void
        {
            $this->search = null;
            $this->syncResults();
        }

        public function clearFilters(): void
        {
            $this->search = null;
            $this->country_id = null;
            $this->state_id = null;
            $this->district_id = null;
            $this->subdivision_id = null;
            $this->syncResults();
        }

        /**
         * The filter snapshot shared with the results child: same shape for the
         * initial mount params and every later sync dispatch.
         *
         * @return array{search: ?string, country_id: ?string, state_id: ?string, district_id: ?string, subdivision_id: ?string}
         */
        public function filterPayload(): array
        {
            return [
                'search' => $this->search,
                'country_id' => $this->country_id,
                'state_id' => $this->state_id,
                'district_id' => $this->district_id,
                'subdivision_id' => $this->subdivision_id,
            ];
        }

        private function syncResults(): void
        {
            $this->dispatch('venue-filters-updated', filters: $this->filterPayload());
        }

        private function locationSlugResolver(): LocationSlugResolver
        {
            return app(LocationSlugResolver::class);
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
    $search = $this->search;
    $states = $this->states;
    $districts = $this->districts;
    $subdistricts = $this->subdistricts;
    $countryId = $this->country_id;
    $stateId = $this->state_id;
    $adminArea1Id = $this->district_id;
    $adminArea2Id = $this->subdivision_id;
    $stateLabel = $this->stateLabel();
    $districtLabel = $this->districtLabel();
    $subdistrictLabel = $this->subdistrictLabel();
    $isParentlessAreaProfile = $this->isParentlessAreaProfileSelection();
    $hasScopedFilters = filled($countryId) || filled($stateId) || filled($adminArea1Id) || filled($adminArea2Id);
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
                    @if(! filled($countryId) || $states !== [])
                        <div>
                            <label for="venue-state-filter" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">
                                {{ $stateLabel }}
                            </label>
                            <select
                                id="venue-state-filter"
                                wire:model.live="state_id"
                                @disabled(! filled($countryId))
                                class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/10 disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-400"
                            >
                                <option value="">{{ __('All :level', ['level' => $stateLabel]) }}</option>
                                @foreach($states as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif

                    @unless($isParentlessAreaProfile)
                        <div>
                            <label for="venue-district-filter" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">
                                {{ $districtLabel }}
                            </label>
                            <select
                                id="venue-district-filter"
                                    wire:model.live="district_id"
                                @disabled($districts === [])
                                class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/10 disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-400"
                            >
                                <option value="">{{ __('All :level', ['level' => $districtLabel]) }}</option>
                                @foreach($districts as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endunless

                    <div>
                        <label for="venue-subdistrict-filter" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">
                            {{ $subdistrictLabel }}
                        </label>
                        <select
                            id="venue-subdistrict-filter"
                            wire:model.live="subdivision_id"
                            @disabled($subdistricts === [])
                            class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/10 disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-400"
                        >
                            <option value="">{{ __('All :level', ['level' => $subdistrictLabel]) }}</option>
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

    <livewire:pages.venues.results :filters="$this->filterPayload()" />
</div>
