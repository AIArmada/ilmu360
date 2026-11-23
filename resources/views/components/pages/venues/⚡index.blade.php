<?php

use AIArmada\Addressing\Support\LocationLevelLabels;
use App\Platform\Location\LocationSlugResolver;
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

        /**
         * @var array<string, ?string>
         */
        #[Url]
        public array $areas = [];

        #[Computed]
        public function states(): array
        {
            return $this->locationSlugResolver()->stateOptionsForCountry($this->normalizedLocationId($this->country_id));
        }

        #[Computed]
        public function firstAreaOptions(): array
        {
            $countryId = $this->normalizedLocationId($this->country_id);
            $role = $this->firstAreaRole();

            if ($countryId === null || $role === null) {
                return [];
            }

            $parentId = $this->locationSlugResolver()->areaParentIdForRole(
                $countryId,
                $role,
                $this->normalizedLocationId($this->state_id),
                $this->normalizedAreas(),
            );

            return $this->locationSlugResolver()->areaMapsForRole($countryId, $role, $parentId)['options'];
        }

        #[Computed]
        public function secondAreaOptions(): array
        {
            $countryId = $this->normalizedLocationId($this->country_id);
            $role = $this->secondAreaRole();

            if ($countryId === null || $role === null) {
                return [];
            }

            $parentId = $this->locationSlugResolver()->areaParentIdForRole(
                $countryId,
                $role,
                $this->normalizedLocationId($this->state_id),
                $this->normalizedAreas(),
            );

            return $this->locationSlugResolver()->areaMapsForRole($countryId, $role, $parentId)['options'];
        }

        public function isParentlessAreaProfileSelection(): bool
        {
            if ($this->firstAreaOptions() !== []) {
                return false;
            }

            return $this->secondAreaRole() !== null && $this->secondAreaOptions() !== [];
        }

        public function stateLabel(): string
        {
            return LocationLevelLabels::label($this->normalizedLocationId($this->country_id), 'state_id', __('State / Province'));
        }

        public function firstAreaLabel(): string
        {
            $countryId = $this->normalizedLocationId($this->country_id);
            $role = $this->firstAreaRole();

            return $role === null
                ? __('Administrative area')
                : LocationLevelLabels::label($countryId, $role, __('Administrative area'), $this->normalizedLocationId($this->state_id), $this->normalizedAreas());
        }

        public function secondAreaLabel(): string
        {
            $countryId = $this->normalizedLocationId($this->country_id);
            $role = $this->secondAreaRole();

            if ($role === null) {
                return __('Administrative area');
            }

            return LocationLevelLabels::label($countryId, $role, __('Administrative area'), $this->normalizedLocationId($this->state_id), $this->normalizedAreas());
        }

        public function firstAreaRole(): ?string
        {
            $countryId = $this->normalizedLocationId($this->country_id);

            return $this->locationSlugResolver()->districtRoleForCountry($countryId);
        }

        public function secondAreaRole(): ?string
        {
            $countryId = $this->normalizedLocationId($this->country_id);

            return $this->locationSlugResolver()->subdivisionRoleForCountry($countryId);
        }

        public function updatedSearch(): void
        {
            $this->syncResults();
        }

        public function updatedCountryId(): void
        {
            $this->state_id = null;
            $this->areas = [];
            $this->syncResults();
        }

        public function updatedStateId(): void
        {
            $this->areas = [];
            $this->syncResults();
        }

        public function updatedAreas(mixed $value, ?string $key = null): void
        {
            $countryId = $this->normalizedLocationId($this->country_id);

            if ($countryId !== null && $key !== null) {
                foreach ($this->locationSlugResolver()->areaSuccessorRolesForCountry($countryId, $key) as $successorRole) {
                    unset($this->areas[$successorRole]);
                }
            }

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
            $this->areas = [];
            $this->syncResults();
        }

        /**
         * The filter snapshot shared with the results child: same shape for the
         * initial mount params and every later sync dispatch.
         *
         * @return array{search: ?string, country_id: ?string, state_id: ?string, areas: array<string, string>}
         */
        public function filterPayload(): array
        {
            return [
                'search' => $this->search,
                'country_id' => $this->country_id,
                'state_id' => $this->state_id,
                'areas' => $this->normalizedAreas(),
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

        /**
         * @return array<string, string>
         */
        private function normalizedAreas(): array
        {
            $countryId = $this->normalizedLocationId($this->country_id);

            if ($countryId === null) {
                return [];
            }

            $roles = $this->locationSlugResolver()->areaRolesForCountry($countryId);
            $normalized = [];

            foreach ($this->areas as $role => $areaId) {
                if (! is_string($role) || ! in_array($role, $roles, true) || ! is_string($areaId)) {
                    continue;
                }

                $normalizedAreaId = $this->normalizedLocationId($areaId);

                if ($normalizedAreaId !== null) {
                    $normalized[$role] = $normalizedAreaId;
                }
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
    $firstAreaOptions = $this->firstAreaOptions;
    $secondAreaOptions = $this->secondAreaOptions;
    $countryId = $this->country_id;
    $stateId = $this->state_id;
    $firstAreaRole = $this->firstAreaRole();
    $secondAreaRole = $this->secondAreaRole();
    $areas = $this->areas;
    $stateLabel = $this->stateLabel();
    $firstAreaLabel = $this->firstAreaLabel();
    $secondAreaLabel = $this->secondAreaLabel();
    $isParentlessAreaProfile = $this->isParentlessAreaProfileSelection();
    $hasScopedFilters = filled($countryId) || filled($stateId) || count($areas) > 0;
@endphp

<div class="relative min-h-screen">
    <div class="relative pt-12 pb-16 bg-white border-b border-slate-100 overflow-hidden">
        <div class="absolute inset-0 bg-emerald-50/50"></div>
        <div class="absolute inset-0 opacity-5" style="background-image: url('{{ asset('images/pattern-bg.jpg') }}');"></div>

        <div class="container relative mx-auto px-6 text-center lg:px-12">
            <h1 class="mb-6 text-balance font-heading text-4xl font-extrabold tracking-tight text-slate-900 md:text-5xl">
                {{ __('Places for') }} <br class="hidden md:block" />
                <span class="bg-gradient-to-r from-emerald-600 to-teal-500 bg-clip-text text-transparent">{{ __('Knowledge & Community') }}</span>
            </h1>
            <p class="mx-auto max-w-2xl text-balance text-lg text-slate-600 md:text-xl">
                {{ __('Find halls, auditoriums, libraries, and trusted spaces where Majlis Ilmu happens.') }}
            </p>

            <div class="mx-auto mt-8 max-w-xl">
                <x-ui.search-bar
                    input-id="venue-search"
                    model="search"
                    :value="$search"
                    :placeholder="__('Search venues...')"
                    :label="__('Search venues')"
                    aria-controls="venue-results"
                />

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

                    @if($firstAreaRole !== null && ! $isParentlessAreaProfile)
                        <div>
                            <label for="venue-first-area-filter" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">
                                {{ $firstAreaLabel }}
                            </label>
                            <select
                                id="venue-first-area-filter"
                                wire:model.live="areas.{{ $firstAreaRole }}"
                                @disabled($firstAreaOptions === [])
                                class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/10 disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-400"
                            >
                                <option value="">{{ __('All :level', ['level' => $firstAreaLabel]) }}</option>
                                @foreach($firstAreaOptions as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif

                    @if($secondAreaRole !== null)
                        <div>
                            <label for="venue-second-area-filter" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">
                                {{ $secondAreaLabel }}
                            </label>
                            <select
                                id="venue-second-area-filter"
                                wire:model.live="areas.{{ $secondAreaRole }}"
                                @disabled($secondAreaOptions === [])
                                class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/10 disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-400"
                            >
                                <option value="">{{ __('All :level', ['level' => $secondAreaLabel]) }}</option>
                                @foreach($secondAreaOptions as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
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
