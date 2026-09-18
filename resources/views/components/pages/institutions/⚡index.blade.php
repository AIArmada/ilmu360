<?php

use App\Forms\SharedFormSchema;
use App\Support\Location\LocationSlugResolver;
use App\Support\Location\VisitorCountryResolver;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new
#[Title('Institutions - ilmu360°')]
class extends Component
{
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

    public function updatedSearch(): void
    {
        $this->syncResults();
    }

    public function updatedCountry(): void
    {
        $this->state = null;
        $this->city = null;
        $this->locality = null;
        $this->district = null;
        $this->subdivision = null;
        $this->memoizedLocationIds = null;
        $this->syncResults();
    }

    public function updatedState(): void
    {
        $this->city = null;
        $this->locality = null;
        $this->district = null;
        $this->subdivision = null;
        $this->memoizedLocationIds = null;
        $this->autoSelectSingleLocationChildren();
        $this->syncResults();
    }

    public function updatedDistrict(): void
    {
        $this->subdivision = null;
        $this->memoizedLocationIds = null;
        $this->autoSelectSingleLocationChildren();
        $this->syncResults();
    }

    public function updatedSubdivision(): void
    {
        $this->memoizedLocationIds = null;
        $this->syncResults();
    }

    public function updatedCity(): void
    {
        $this->memoizedLocationIds = null;
        $this->syncResults();
    }

    public function updatedLocality(): void
    {
        $this->memoizedLocationIds = null;
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
        $this->country = null;
        $this->state = null;
        $this->city = null;
        $this->locality = null;
        $this->district = null;
        $this->subdivision = null;
        $this->memoizedLocationIds = null;
        $this->syncResults();
    }

    /**
     * @return array{country_id: ?string, state_id: ?string, city_id: ?string, locality_id: ?string, district_id: ?string, subdivision_id: ?string}
     */
    private function locationIds(): array
    {
        if ($this->memoizedLocationIds !== null) {
            return $this->memoizedLocationIds;
        }

        return $this->memoizedLocationIds = $this->locationSlugResolver()->idsForSlugs($this->filterPayload());
    }

    /**
     * The filter snapshot shared with the results child: same shape for the
     * initial mount params and every later sync dispatch.
     *
     * @return array{search: ?string, country: ?string, state: ?string, city: ?string, locality: ?string, district: ?string, subdivision: ?string}
     */
    public function filterPayload(): array
    {
        return [
            'search' => $this->search,
            'country' => $this->country,
            'state' => $this->state,
            'city' => $this->city,
            'locality' => $this->locality,
            'district' => $this->district,
            'subdivision' => $this->subdivision,
        ];
    }

    private function syncResults(): void
    {
        $this->dispatch('institution-filters-updated', filters: $this->filterPayload());
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
            <livewire:pages.institutions.results :filters="$this->filterPayload()" />


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
