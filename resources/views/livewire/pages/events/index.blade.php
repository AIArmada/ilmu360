@section('title', __('Kuliah & Majlis Ilmu Akan Datang di Malaysia') . ' - ' . config('app.name'))
@section('meta_description', __('Terokai kuliah, ceramah, kelas, dan majlis ilmu akan datang di seluruh Malaysia. Tapis mengikut lokasi, tarikh, penceramah, dan topik.'))
@section('og_url', route('events.index'))
@section('og_image', asset('images/default-mosque-hero.png'))
@section('og_image_alt', __('Kuliah dan majlis ilmu akan datang di Malaysia'))
@section('og_image_width', '1024')
@section('og_image_height', '1024')

@include('partials.filament-assets', [
    'scripts' => ['filament/support', 'filament/schemas', 'filament/forms'],
])

@once
    @push('styles')
        <style>
            .mi-filter-shell .mi-advanced-filter-group.fi-section {
                border-radius: 0.95rem;
                border: 1px solid rgb(226 232 240 / 0.9);
                background: rgb(255 255 255 / 0.92);
                box-shadow: none;
            }

            .mi-filter-shell .mi-advanced-filter-group+.mi-advanced-filter-group {
                margin-top: 0.85rem;
            }

            .mi-filter-shell .mi-advanced-filter-group .fi-section-content {
                gap: 0.75rem;
            }

            .mi-filter-shell .mi-advanced-filter-group .fi-input,
            .mi-filter-shell .mi-advanced-filter-group .fi-select-input,
            .mi-filter-shell .mi-advanced-filter-group .fi-select-control {
                border-radius: 0.8rem;
            }

            .mi-filter-shell .mi-advanced-filter-group .fi-fo-field-wrp-label {
                font-size: 0.72rem;
                letter-spacing: 0.08em;
                text-transform: uppercase;
                color: rgb(100 116 139);
            }

            .mi-sort-form .fi-fo-field-wrp {
                margin: 0;
            }

            .mi-sort-form .fi-input-wrp {
                border: 0;
                background: transparent;
                box-shadow: none;
            }

            .mi-sort-form .fi-input {
                border: 0;
                background: transparent;
                padding-top: 0;
                padding-bottom: 0;
                font-weight: 700;
                box-shadow: none;
            }
        </style>
    @endpush
@endonce

@php
    $events = $this->scheduleItems;
    $search = $this->search;
    $defaultCountryId = $this->defaultCountryId();
    // The country is scoped automatically; only an explicit change is a filter.
    $hasCountryScope = filled($this->country_id) && $this->country_id !== $defaultCountryId;
    $countryId = $this->country_id;
    $stateId = $this->state_id;
    $areaAssignments = $this->area_assignments;
    $districtAreaId = $areaAssignments['administrative_district'] ?? null;
    $subdivisionAreaId = $areaAssignments['administrative_subdivision'] ?? null;
    $institutionId = $this->institution_id;
    $venueId = $this->venue_id;
    $gender = $this->gender;
    $childrenAllowed = $this->children_allowed;
    $isMuslimOnly = $this->is_muslim_only;
    $startsAfter = $this->starts_after;
    $startsBefore = $this->starts_before;
    $prayerTime = $this->prayer_time;
    $timingMode = $this->timing_mode;
    $startsTimeFrom = $this->starts_time_from;
    $startsTimeUntil = $this->starts_time_until;
    $timeScope = $this->time_scope ?? 'upcoming';
    $lat = $this->lat;
    $lng = $this->lng;
    $sort = $this->sort;
    $countries = $this->countries;
    $states = $this->states;
    $cities = $this->cities;
    $divisions = $this->divisions;
    $postalLocalities = $this->postalLocalities;
    $districts = $this->districts;
    $subdistricts = $this->subdistricts;
    $languageOptions = $this->languageOptions();
    $selectedAgeGroups = array_values(array_filter((array) $this->age_group));
    $selectedDisciplineTagIds = array_values(array_filter((array) $this->discipline_tag_ids));
    $selectedDomainTagIds = array_values(array_filter((array) $this->domain_tag_ids));
    $selectedSourceTagIds = array_values(array_filter((array) $this->source_tag_ids));
    $selectedIssueTagIds = array_values(array_filter((array) $this->issue_tag_ids));
    $selectedReferenceIds = array_values(array_filter((array) $this->reference_ids));
    $selectedPersonIds = array_values(array_filter((array) $this->person_ids));
    $selectedKeyPersonRoles = array_values(array_filter((array) $this->key_person_roles));
    $selectedPersonInChargeIds = array_values(array_filter((array) $this->person_in_charge_ids));
    $personInChargeSearch = filled($this->person_in_charge_search) ? trim((string) $this->person_in_charge_search) : null;
    $personNameSearch = filled($this->person_name_search) ? trim((string) $this->person_name_search) : null;
    $selectedModeratorIds = array_values(array_filter((array) $this->moderator_ids));
    $selectedImamIds = array_values(array_filter((array) $this->imam_ids));
    $selectedKhatibIds = array_values(array_filter((array) $this->khatib_ids));
    $selectedBilalIds = array_values(array_filter((array) $this->bilal_ids));
    $selectedEventCategories = array_values(array_filter((array) $this->event_category_ids));
    $selectedEventFormats = array_values(array_filter((array) $this->event_format));
    $selectedLanguageCodes = array_values(array_filter((array) $this->language_codes));
    $selectedPersonLabelIds = collect([
        $selectedPersonIds,
        $selectedPersonInChargeIds,
        $selectedModeratorIds,
        $selectedImamIds,
        $selectedKhatibIds,
        $selectedBilalIds,
    ])->flatten()
        ->filter()
        ->map(fn (mixed $personId): string => (string) $personId)
        ->unique()
        ->values()
        ->all();
    $personLabels = $this->personOptionLabels($selectedPersonLabelIds);
    $referenceLabels = $this->referenceOptionLabels($selectedReferenceIds);
    $referenceAuthorLabels = $this->referenceAuthorOptionLabels($this->reference_author_search);
    $keyPersonRoleLabels = \App\Enums\EventKeyPersonRole::nonSpeakerOptions();
    $searchScopeLabels = collect([
        $this->search_include_institutions ? __('Institusi') : null,
        $this->search_include_persons ? __('Penceramah') : null,
        $this->search_include_references ? __('Rujukan') : null,
    ])->filter()->values()->all();
    $eventCategoryLabels = $this->eventCategoryOptions;
    $eventFormatLabels = collect(\App\Enums\EventFormat::cases())
        ->mapWithKeys(fn (\App\Enums\EventFormat $format): array => [$format->value => $format->getLabel()])
        ->all();
    $ageGroupLabels = collect(\App\Enums\EventAgeGroup::cases())
        ->mapWithKeys(fn (\App\Enums\EventAgeGroup $group): array => [$group->value => $group->getLabel()])
        ->all();
    $genderLabels = collect(\App\Enums\EventGenderRestriction::cases())
        ->mapWithKeys(fn (\App\Enums\EventGenderRestriction $restriction): array => [$restriction->value => $restriction->getLabel()])
        ->all();
    $domainLabels = $this->termOptionLabels('domain', $selectedDomainTagIds);
    $disciplineLabels = $this->termOptionLabels('discipline', $selectedDisciplineTagIds);
    $sourceLabels = $this->termOptionLabels('source', $selectedSourceTagIds);
    $issueLabels = $this->termOptionLabels('issue', $selectedIssueTagIds);
    $institutionLabel = filled($institutionId) ? $this->institutionOptionLabel((string) $institutionId) : null;
    $venueLabel = filled($venueId) ? $this->venueOptionLabel((string) $venueId) : null;
    $prayerTimeLabel = \App\Enums\EventPrayerTime::tryFrom((string) $prayerTime)?->getLabel() ?? $prayerTime;
    $timingModeLabel = \App\Enums\TimingMode::tryFrom((string) $timingMode)?->label();
    $activeFilterCount = collect([
        filled($search),
        $hasCountryScope,
        filled($stateId),
        filled($this->city_id),
        filled($areaAssignments['administrative_division'] ?? null),
        filled($districtAreaId),
        filled($subdivisionAreaId),
        filled($areaAssignments['postal_locality'] ?? null),
        filled($institutionId),
        filled($venueId),
        count($selectedLanguageCodes) > 0,
        count($selectedEventCategories) > 0,
        count($selectedEventFormats) > 0,
        filled($gender),
        count($selectedAgeGroups) > 0,
        $childrenAllowed !== null,
        $isMuslimOnly !== null,
        count($selectedPersonIds) > 0,
        count($selectedKeyPersonRoles) > 0,
        count($selectedPersonInChargeIds) > 0,
        filled($personInChargeSearch),
        filled($personNameSearch),
        count($selectedModeratorIds) > 0,
        count($selectedImamIds) > 0,
        count($selectedKhatibIds) > 0,
        count($selectedBilalIds) > 0,
        count($selectedDisciplineTagIds) > 0,
        count($selectedDomainTagIds) > 0,
        count($selectedSourceTagIds) > 0,
        count($selectedIssueTagIds) > 0,
        count($selectedReferenceIds) > 0,
        count($this->reference_author_search) > 0,
        filled($startsAfter),
        filled($startsBefore),
        filled($prayerTime),
        filled($timingMode),
        filled($startsTimeFrom),
        filled($startsTimeUntil),
        $this->has_event_url !== null,
        $this->has_live_url !== null,
        $this->has_end_time !== null,
        $timeScope !== 'upcoming',
        filled($lat),
        ! $this->search_include_institutions,
        ! $this->search_include_persons,
        ! $this->search_include_references,
    ])->filter()->count();
    $hasActiveFilters = $activeFilterCount > 0;
    $savedSearchQuery = array_filter([
        'search' => $search,
        'country_id' => $countryId,
        'state_id' => $stateId,
        'city_id' => $this->city_id,
        'area_assignments' => $areaAssignments,
        'institution_id' => $institutionId,
        'venue_id' => $venueId,
        'person_ids' => $selectedPersonIds,
        'key_person_roles' => $selectedKeyPersonRoles,
        'person_in_charge_ids' => $selectedPersonInChargeIds,
        'person_in_charge_search' => $personInChargeSearch,
        'person_name_search' => $personNameSearch,
        'moderator_ids' => $selectedModeratorIds,
        'imam_ids' => $selectedImamIds,
        'khatib_ids' => $selectedKhatibIds,
        'bilal_ids' => $selectedBilalIds,
        'language_codes' => $selectedLanguageCodes,
        'event_category_ids' => $selectedEventCategories,
        'event_format' => $selectedEventFormats,
        'gender' => $gender,
        'age_group' => $selectedAgeGroups,
        'children_allowed' => $childrenAllowed,
        'is_muslim_only' => $isMuslimOnly,
        'starts_after' => $startsAfter,
        'starts_before' => $startsBefore,
        'prayer_time' => $prayerTime,
        'timing_mode' => $timingMode,
        'starts_time_from' => $startsTimeFrom,
        'starts_time_until' => $startsTimeUntil,
        'has_event_url' => $this->has_event_url,
        'has_live_url' => $this->has_live_url,
        'has_end_time' => $this->has_end_time,
        'discipline_tag_ids' => $selectedDisciplineTagIds,
        'domain_tag_ids' => $selectedDomainTagIds,
        'source_tag_ids' => $selectedSourceTagIds,
        'issue_tag_ids' => $selectedIssueTagIds,
        'reference_ids' => $selectedReferenceIds,
        'lat' => filled($lat) && filled($lng) ? $lat : null,
        'lng' => filled($lat) && filled($lng) ? $lng : null,
        'radius_km' => filled($lat) && filled($lng) ? $this->radius_km : null,
        'sort' => $sort !== 'time' ? $sort : null,
        'time_scope' => $timeScope !== 'upcoming' ? $timeScope : null,
        'search_include_institutions' => $this->search_include_institutions ? null : false,
        'search_include_persons' => $this->search_include_persons ? null : false,
        'search_include_references' => $this->search_include_references ? null : false,
        'reference_author_search' => $this->reference_author_search,
    ], function (mixed $value): bool {
        if (is_array($value)) {
            return $value !== [];
        }

        return $value !== null && $value !== '';
    });
    $todayQuery = array_replace($savedSearchQuery, [
        'starts_after' => now()->toDateString(),
        'starts_before' => now()->toDateString(),
        'time_scope' => 'all',
    ]);
    $searchShareUrl = $hasActiveFilters ? route('events.index', $savedSearchQuery) : null;
    $searchShareText = __('Explore these ilmu360° search results on :app', ['app' => config('app.name')]);
    $searchShareData = $searchShareUrl !== null
        ? [
            'title' => __('Search Results'),
            'text' => __('Share these filtered results with others.'),
            'url' => $searchShareUrl,
            'sourceUrl' => $searchShareUrl,
            'shareText' => $searchShareText,
            'fallbackTitle' => __('Search Results'),
            'payloadEndpoint' => route('dawah-share.payload'),
        ]
        : null;
    $showsGeolocationControls = $this->showsGeolocationControls();
@endphp

<div
    class="min-h-screen bg-[#fbfaf6] text-slate-900"
    x-data="{
        ...window.ilmu360.geolocationPermission({
            initiallyGranted: @js($showsGeolocationControls),
            cookieName: @js(\App\Support\Location\PublicGeolocationPermission::COOKIE_NAME),
        }),
        locating: false,
        locationNotice: null,
        copiedShareLink: false,
        copiedEventId: null,
        shareData: @js($searchShareData),
        trackEndpoint: @js(route('dawah-share.track')),
        providerQueryParameter: @js(config('dawah-share.provider_query_parameter', 'channel')),
        attributedShareData: null,
        setLocationNotice(message) {
            this.locationNotice = message;
        },
        clearLocationNotice() {
            this.locationNotice = null;
        },
        async locate() {
            if (this.locating) return;
            this.clearLocationNotice();
            if (! navigator.geolocation) {
                this.setGeolocationPermission(false);
                this.setLocationNotice('{{ __("Geolocation is not supported by your browser.") }}');
                return;
            }

            if (navigator.permissions && typeof navigator.permissions.query === 'function') {
                try {
                    const permissionStatus = await navigator.permissions.query({ name: 'geolocation' });

                    if (permissionStatus.state === 'denied') {
                        this.setGeolocationPermission(false);
                    }
                } catch (error) {
                }
            }

            this.locating = true;
            navigator.geolocation.getCurrentPosition((position) => {
                this.clearLocationNotice();
                this.setGeolocationPermission(true);
                this.$wire.setLocation(position.coords.latitude, position.coords.longitude);
                this.locating = false;
            }, (error) => {
                this.locating = false;
                if (error?.code === 1) {
                    this.setGeolocationPermission(false);
                    this.setLocationNotice('{{ __("Allow location access in your browser settings to use nearby search.") }}');

                    return;
                }

                this.setLocationNotice('{{ __("Unable to get your location. Please enable location services.") }}');
            });
        },
        async resolveShareData() {
            if (! this.shareData) {
                return null;
            }

            if (this.attributedShareData) {
                return this.attributedShareData;
            }

            const params = new URLSearchParams({
                url: this.shareData.sourceUrl,
                text: this.shareData.shareText,
                title: this.shareData.fallbackTitle,
            });
            const response = await fetch(`${this.shareData.payloadEndpoint}?${params.toString()}`, {
                headers: {
                    Accept: 'application/json',
                },
            });

            if (! response.ok) {
                return this.shareData;
            }

            const payload = await response.json();
            this.attributedShareData = {
                ...this.shareData,
                url: payload.url,
                tracking_token: payload.tracking_token ?? null,
            };

            return this.attributedShareData;
        },
        async sharePayloadForChannel(provider = null) {
            const shareData = await this.resolveShareData();

            if (! shareData || ! provider || ! shareData.tracking_token) {
                return shareData;
            }

            try {
                const shareUrl = new URL(shareData.url, window.location.origin);
                shareUrl.searchParams.set(this.providerQueryParameter, provider);

                return {
                    ...shareData,
                    url: shareUrl.toString(),
                };
            } catch (error) {
                return shareData;
            }
        },
        async trackShare(provider) {
            const shareData = await this.resolveShareData();

            if (! shareData?.tracking_token) {
                return;
            }

            const csrfToken = document.querySelector('meta[name=csrf-token]')?.content;

            if (! csrfToken) {
                return;
            }

            await fetch(this.trackEndpoint, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify({
                    provider,
                    tracking_token: shareData.tracking_token,
                }),
            });
        },
        async shareResults() {
            const shareData = await this.sharePayloadForChannel('native_share');
            if (! shareData) {
                return;
            }

            if (navigator.share) {
                try {
                    await navigator.share(shareData);
                    await this.trackShare('native_share');
                } catch (error) {
                }

                return;
            }

            await this.copyShareLink();
        },
        async copyShareLink(shouldTrack = true, provider = 'copy_link') {
            const shareData = await this.sharePayloadForChannel(provider);
            if (! shareData) {
                return;
            }

            await this.copyUrl(shareData.url);

            if (shouldTrack) {
                await this.trackShare(provider);
            }

            this.copiedShareLink = true;
            setTimeout(() => this.copiedShareLink = false, 2200);
        },
        async copyUrl(url) {
            if (! navigator.clipboard) {
                window.prompt('{{ __("Copy this link:") }}', url);

                return;
            }

            await navigator.clipboard.writeText(url);
        },
        async copyEventLink(eventId, url) {
            await this.copyUrl(url);
            this.copiedEventId = eventId;
            setTimeout(() => this.copiedEventId = null, 1800);
        },
        async shareEvent(eventId, url, title) {
            const payload = { url, title, text: title };

            if (navigator.share) {
                try {
                    await navigator.share(payload);
                    return;
                } catch (error) {
                }
            }

            await this.copyEventLink(eventId, url);
        },
    }">
    <section class="relative pt-12 pb-16 bg-white border-b border-slate-100 overflow-hidden">
        <div class="absolute inset-0 bg-[radial-gradient(circle_at_20%_20%,rgba(245,158,11,0.12),transparent_32%),linear-gradient(90deg,#fffdf8_0%,#fffaf0_52%,#f2f8f4_100%)]"></div>
        <div class="absolute inset-y-0 right-0 hidden w-[48%] overflow-hidden lg:block">
            <div class="absolute inset-0 rounded-bl-[11rem] bg-slate-200">
                <img src="{{ asset('images/hero-bg.png') }}" alt="{{ __('Masjid pada waktu senja') }}" class="h-full w-full object-cover">
                <div class="absolute inset-0 bg-gradient-to-l from-transparent via-white/5 to-white/60"></div>
            </div>
        </div>
        <div class="pointer-events-none absolute right-[35%] top-6 hidden h-72 w-72 rounded-full border border-amber-200/50 opacity-40 lg:block"></div>
        <div class="pointer-events-none absolute right-[38%] top-14 hidden h-52 w-52 rounded-full border border-emerald-200/60 opacity-40 lg:block"></div>

        <div class="container relative mx-auto px-6 lg:px-12">
            <div class="max-w-4xl">
                <p class="text-xs font-bold uppercase tracking-[0.26em] text-emerald-700">{{ __('Majlis Ilmu') }}</p>
                <h1 class="mt-4 font-heading text-5xl font-bold leading-none tracking-normal text-emerald-950 md:text-7xl">
                    {{ __('Cari Majlis Ilmu') }}
                    <span class="sr-only">{{ __('Circle of Knowledge') }}</span>
                </h1>
                <p class="mt-5 max-w-2xl text-lg leading-8 text-slate-700">
                    {{ __('Cari ikut lokasi, masa, topik, penceramah atau institusi.') }}
                </p>

                <form wire:submit.prevent
                    data-signal-change-event="filter.changed"
                    data-signal-category="filter"
                    data-signal-component="events_index_filters"
                    data-signal-control="filter_form"
                    data-signal-props='@json(['surface' => 'events_index'])'
                    class="mt-8 max-w-3xl">
                    <x-ui.search-bar
                        input-id="event-search"
                        model="filterData.search"
                        :value="$search"
                        :placeholder="__('Cari tajuk, ustaz, masjid, topik...')"
                        maxlength="255"
                        :label="__('Carian')"
                        :hint="__('Cari mengikut tajuk, penceramah, institusi atau lokasi.')"
                        :clear-attributes="[
                            'data-signal-event' => 'search.cleared',
                            'data-signal-category' => 'search',
                            'data-signal-component' => 'events_index_filters',
                            'data-signal-control' => 'clear_search',
                        ]"
                        data-signal-control="search"
                        data-signal-include-value="true"
                    />
                </form>
            </div>
        </div>
    </section>

    <main class="container relative z-10 mx-auto -mt-8 px-6 pb-20 lg:px-12">
        <form wire:submit.prevent class="grid gap-6 lg:grid-cols-[18rem_minmax(0,1fr)] 2xl:grid-cols-[20rem_minmax(0,1fr)]">
            <aside class="h-fit rounded-2xl border border-amber-100/80 bg-white/95 p-4 shadow-[0_20px_50px_-35px_rgba(15,23,42,0.55)] lg:sticky lg:top-24">
                <div wire:loading.delay.short
                    wire:target="filterData,setLocation,clearLocation,clearAllFilters,toggleSave"
                    class="mb-4 inline-flex items-center gap-2 rounded-lg border border-emerald-100 bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-700">
                    <svg class="size-4 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke-width="4"></circle>
                        <path class="opacity-75" stroke-width="4" d="M22 12a10 10 0 0 0-10-10"></path>
                    </svg>
                    {{ __('Updating results...') }}
                </div>

                <div class="divide-y divide-slate-100">
                    <section class="pb-4">
                        <div class="flex items-center justify-between gap-3">
                            <h2 class="inline-flex items-center gap-2 font-heading text-base font-bold text-emerald-950">
                                <svg class="size-5 text-emerald-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 21s7-4.438 7-11a7 7 0 1 0-14 0c0 6.562 7 11 7 11Z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 10.5h.01" />
                                </svg>
                                {{ __('Lokasi') }}
                            </h2>
                            @if($lat)
                                <button type="button" wire:click="clearLocation" class="text-xs font-semibold text-rose-600 hover:text-rose-700">
                                    {{ __('Clear') }}
                                </button>
                            @endif
                        </div>

                        <button type="button" @click="locate" :disabled="locating"
                            data-testid="near-me-button"
                            data-signal-event="search.nearby_requested"
                            data-signal-category="search"
                            data-signal-component="events_index_filters"
                            data-signal-control="near_me"
                            class="mt-3 flex h-11 w-full items-center justify-center gap-2 rounded-xl border border-emerald-100 bg-emerald-50 text-sm font-bold text-emerald-800 transition hover:border-emerald-200 hover:bg-emerald-100">
                            <svg class="size-4" :class="locating ? 'animate-spin' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path x-show="! locating" stroke-linecap="round" stroke-linejoin="round" d="M12 21s7-4.438 7-11a7 7 0 1 0-14 0c0 6.562 7 11 7 11Z" />
                                <path x-show="! locating" stroke-linecap="round" stroke-linejoin="round" d="M12 10.5h.01" />
                                <circle x-show="locating" class="opacity-25" cx="12" cy="12" r="10" stroke-width="4"></circle>
                                <path x-show="locating" class="opacity-75" stroke-width="4" d="M22 12a10 10 0 0 0-10-10"></path>
                            </svg>
                            <span x-text="locating ? '{{ __('Locating...') }}' : '{{ __('Dekat saya') }}'"></span>
                        </button>

                        <div x-show="locationNotice" x-cloak x-text="locationNotice" class="mt-3 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold leading-5 text-amber-800"></div>

                    </section>

                    <section class="pt-4">
                        <div class="mi-filter-shell space-y-3">
                            {{ $this->form }}
                        </div>

                        <div class="mt-4 flex items-center justify-between gap-3">
                            <button type="button" wire:click="clearAllFilters"
                                data-signal-event="filter.cleared"
                                data-signal-category="filter"
                                data-signal-component="events_index_filters"
                                data-signal-control="clear_all"
                                aria-label="{{ __('Clear All Filters') }}"
                                class="text-xs font-semibold text-amber-700 transition hover:text-amber-800">
                                {{ __('Set semula semua') }}
                            </button>
                            <span class="text-right text-[11px] font-medium leading-4 text-slate-400">{{ __('Keputusan dikemas kini secara automatik.') }}</span>
                        </div>
                    </section>
                </div>
            </aside>

            <section class="min-w-0">
                <div class="rounded-2xl border border-amber-100/80 bg-white/95 p-4 shadow-[0_20px_50px_-35px_rgba(15,23,42,0.55)] md:p-6">
                    <div class="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
                        <div>
                            <h2 class="font-heading text-2xl font-bold text-emerald-950">
                                {{ trans_choice(':count majlis dijumpai', $events->total(), ['count' => number_format($events->total())]) }}
                            </h2>
                            <p class="mt-1 text-sm text-slate-500">
                                {{ match ($this->time_scope ?? 'upcoming') {
                                    'past' => __('Past Gatherings'),
                                    'all' => __('All Gatherings'),
                                    default => __('Upcoming Gatherings'),
                                } }}
                                ·
                                {{ filled($lat) ? __('Menunjukkan hasil dalam :radius km', ['radius' => $this->radius_km]) : __('Menunjukkan majlis yang sepadan dengan carian anda') }}
                            </p>
                        </div>

                        <div class="flex flex-wrap items-center gap-3">
                            <div class="inline-flex overflow-hidden rounded-xl border border-slate-200 bg-white p-1">
                                <a href="#majlis-map-preview"
                                    data-signal-event="navigation.map_preview_clicked"
                                    data-signal-category="navigation"
                                    data-signal-component="events_index_toolbar"
                                    data-signal-control="map_preview"
                                    class="inline-flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold text-slate-600 transition hover:bg-slate-50 hover:text-emerald-800">
                                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 18.75 3.75 21V6.75L9 4.5m0 14.25 6-2.25m-6 2.25V4.5m6 12 5.25 2.25V4.5L15 6.75m0 9.75V6.75m0 0L9 4.5" />
                                    </svg>
                                    {{ __('Peta') }}
                                </a>
                                <span class="inline-flex items-center gap-2 rounded-lg bg-emerald-800 px-3 py-2 text-sm font-bold text-white">
                                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5M3.75 17.25h16.5" />
                                    </svg>
                                    {{ __('Senarai') }}
                                </span>
                            </div>

                            <div class="mi-sort-form inline-flex h-11 items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-600"
                                data-signal-category="filter"
                                data-signal-component="events_index_toolbar"
                                data-signal-control="sort">
                                <span>{{ __('Susun:') }}</span>
                                {{ $this->sortForm }}
                            </div>
                        </div>
                    </div>

                    @if($hasActiveFilters)
                        <div class="mt-5 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-4">
                            @if($search)
                                <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-semibold text-slate-700">{{ __('Carian') }}: "{{ $search }}"</span>
                            @endif
                            @if(count($searchScopeLabels) < 3)
                                <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-semibold text-slate-700">{{ __('Cari dalam') }}: {{ implode(', ', $searchScopeLabels) ?: __('Tiada') }}</span>
                            @endif
                            @if($lat)
                                <span class="inline-flex items-center gap-1 rounded-full border border-emerald-100 bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-800">{{ __('Dekat saya') }} · {{ $this->radius_km }} km</span>
                            @endif
                            @if($hasCountryScope)
                                <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-semibold text-slate-700">{{ __('Negara') }}: {{ $countries->firstWhere('id', $countryId)?->name ?? $countryId }}</span>
                            @endif
                            @if($stateId)
                                <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-semibold text-slate-700">{{ __('Negeri') }}: {{ $states->firstWhere('id', $stateId)?->name ?? $stateId }}</span>
                            @endif
                            @if($this->city_id)
                                <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-semibold text-slate-700">{{ __('Bandar') }}: {{ $cities->firstWhere('id', $this->city_id)?->name ?? $this->city_id }}</span>
                            @endif
                            @if($areaAssignments['administrative_division'] ?? null)
                                <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-semibold text-slate-700">{{ __('Bahagian') }}: {{ $divisions->firstWhere('id', $areaAssignments['administrative_division'])?->name ?? $areaAssignments['administrative_division'] }}</span>
                            @endif
                            @if($areaAssignments['postal_locality'] ?? null)
                                <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-semibold text-slate-700">{{ __('Lokaliti / Kampung') }}: {{ $postalLocalities->firstWhere('id', $areaAssignments['postal_locality'])?->name ?? $areaAssignments['postal_locality'] }}</span>
                            @endif
                            @if($districtAreaId)
                                <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-semibold text-slate-700">{{ __('Daerah') }}: {{ $districts->firstWhere('id', $districtAreaId)?->name ?? $districtAreaId }}</span>
                            @endif
                            @if($subdivisionAreaId)
                                <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-semibold text-slate-700">{{ __('Bandar / Mukim / Zon') }}: {{ $subdistricts->firstWhere('id', $subdivisionAreaId)?->name ?? $subdivisionAreaId }}</span>
                            @endif
                            @if($institutionId)
                                <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-semibold text-slate-700">{{ __('Institusi') }}: {{ $institutionLabel ?? $institutionId }}</span>
                            @endif
                            @if($venueId)
                                <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-semibold text-slate-700">{{ __('Tempat') }}: {{ $venueLabel ?? $venueId }}</span>
                            @endif
                            @foreach($selectedEventCategories as $categoryId)
                                <span class="inline-flex items-center rounded-full border border-emerald-100 bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-800">{{ __('Jenis majlis') }}: {{ $eventCategoryLabels[$categoryId] ?? $categoryId }}</span>
                            @endforeach
                            @foreach($selectedEventFormats as $eventFormat)
                                <span class="inline-flex items-center rounded-full border border-sky-100 bg-sky-50 px-3 py-1.5 text-xs font-semibold text-sky-800">{{ __('Format') }}: {{ $eventFormatLabels[$eventFormat] ?? str((string) $eventFormat)->headline() }}</span>
                            @endforeach
                            @foreach($selectedLanguageCodes as $languageCode)
                                <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-semibold text-slate-700">{{ __('Bahasa') }}: {{ $languageOptions[$languageCode] ?? strtoupper((string) $languageCode) }}</span>
                            @endforeach
                            @foreach($selectedPersonIds as $personId)
                                <span class="inline-flex items-center rounded-full border border-orange-100 bg-orange-50 px-3 py-1.5 text-xs font-semibold text-orange-800">{{ __('Penceramah') }}: {{ $personLabels[(string) $personId] ?? $personId }}</span>
                            @endforeach
                            @if($personNameSearch)
                                <span class="inline-flex items-center rounded-full border border-orange-100 bg-orange-50 px-3 py-1.5 text-xs font-semibold text-orange-800">{{ __('Nama penceramah') }}: {{ $personNameSearch }}</span>
                            @endif
                            @foreach($selectedKeyPersonRoles as $role)
                                <span class="inline-flex items-center rounded-full border border-orange-100 bg-orange-50 px-3 py-1.5 text-xs font-semibold text-orange-800">{{ __('Peranan Lain Dalam Majlis') }}: {{ $keyPersonRoleLabels[$role] ?? $role }}</span>
                            @endforeach
                            @foreach($selectedDomainTagIds as $domainTagId)
                                <span class="inline-flex items-center rounded-full border border-violet-100 bg-violet-50 px-3 py-1.5 text-xs font-semibold text-violet-800">{{ __('Topik / bidang') }}: {{ $domainLabels[$domainTagId] ?? $domainTagId }}</span>
                            @endforeach
                            @foreach($selectedDisciplineTagIds as $disciplineTagId)
                                <span class="inline-flex items-center rounded-full border border-violet-100 bg-violet-50 px-3 py-1.5 text-xs font-semibold text-violet-800">{{ __('Topik lebih khusus') }}: {{ $disciplineLabels[$disciplineTagId] ?? $disciplineTagId }}</span>
                            @endforeach
                            @foreach($selectedSourceTagIds as $sourceTagId)
                                <span class="inline-flex items-center rounded-full border border-violet-100 bg-violet-50 px-3 py-1.5 text-xs font-semibold text-violet-800">{{ __('Sumber Rujukan Utama') }}: {{ $sourceLabels[$sourceTagId] ?? $sourceTagId }}</span>
                            @endforeach
                            @foreach($selectedIssueTagIds as $issueTagId)
                                <span class="inline-flex items-center rounded-full border border-violet-100 bg-violet-50 px-3 py-1.5 text-xs font-semibold text-violet-800">{{ __('Tema / Isu') }}: {{ $issueLabels[$issueTagId] ?? $issueTagId }}</span>
                            @endforeach
                            @foreach($selectedReferenceIds as $referenceId)
                                <span class="inline-flex items-center rounded-full border border-violet-100 bg-violet-50 px-3 py-1.5 text-xs font-semibold text-violet-800">{{ __('Rujukan Kitab/Buku') }}: {{ $referenceLabels[$referenceId] ?? $referenceId }}</span>
                            @endforeach
                            @foreach($this->reference_author_search as $referenceAuthor)
                                <span class="inline-flex items-center rounded-full border border-violet-100 bg-violet-50 px-3 py-1.5 text-xs font-semibold text-violet-800">{{ __('Pengarang Rujukan') }}: {{ $referenceAuthorLabels[$referenceAuthor] ?? $referenceAuthor }}</span>
                            @endforeach
                            @if($gender)
                                <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-semibold text-slate-700">{{ __('Jantina') }}: {{ $genderLabels[$gender] ?? str((string) $gender)->replace('_', ' ')->headline() }}</span>
                            @endif
                            @foreach($selectedAgeGroups as $ageGroup)
                                <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-semibold text-slate-700">{{ __('Kumpulan umur') }}: {{ $ageGroupLabels[$ageGroup] ?? str((string) $ageGroup)->replace('_', ' ')->headline() }}</span>
                            @endforeach
                            @if($childrenAllowed !== null)
                                <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-semibold text-slate-700">{{ __('Kanak-kanak Dibenarkan Hadir') }}: {{ $childrenAllowed ? __('Ya') : __('Tidak') }}</span>
                            @endif
                            @if($isMuslimOnly !== null)
                                <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-semibold text-slate-700">{{ __('Muslim Sahaja') }}: {{ $isMuslimOnly ? __('Ya') : __('Tidak') }}</span>
                            @endif
                            @if($prayerTime)
                                <span class="inline-flex items-center rounded-full border border-amber-100 bg-amber-50 px-3 py-1.5 text-xs font-semibold text-amber-800">{{ __('Waktu solat') }}: {{ $prayerTimeLabel }}</span>
                            @endif
                            @if($timingModeLabel)
                                <span class="inline-flex items-center rounded-full border border-amber-100 bg-amber-50 px-3 py-1.5 text-xs font-semibold text-amber-800">{{ __('Mod masa') }}: {{ $timingModeLabel }}</span>
                            @endif
                            @foreach($selectedPersonInChargeIds as $personInChargeId)
                                <span class="inline-flex items-center rounded-full border border-orange-100 bg-orange-50 px-3 py-1.5 text-xs font-semibold text-orange-800">{{ __('PIC / Penyelaras') }}: {{ $personLabels[(string) $personInChargeId] ?? $personInChargeId }}</span>
                            @endforeach
                            @foreach($selectedModeratorIds as $moderatorId)
                                <span class="inline-flex items-center rounded-full border border-orange-100 bg-orange-50 px-3 py-1.5 text-xs font-semibold text-orange-800">{{ __('Moderator') }}: {{ $personLabels[(string) $moderatorId] ?? $moderatorId }}</span>
                            @endforeach
                            @foreach($selectedImamIds as $imamId)
                                <span class="inline-flex items-center rounded-full border border-orange-100 bg-orange-50 px-3 py-1.5 text-xs font-semibold text-orange-800">{{ __('Imam') }}: {{ $personLabels[(string) $imamId] ?? $imamId }}</span>
                            @endforeach
                            @foreach($selectedKhatibIds as $khatibId)
                                <span class="inline-flex items-center rounded-full border border-orange-100 bg-orange-50 px-3 py-1.5 text-xs font-semibold text-orange-800">{{ __('Khatib') }}: {{ $personLabels[(string) $khatibId] ?? $khatibId }}</span>
                            @endforeach
                            @foreach($selectedBilalIds as $bilalId)
                                <span class="inline-flex items-center rounded-full border border-orange-100 bg-orange-50 px-3 py-1.5 text-xs font-semibold text-orange-800">{{ __('Bilal') }}: {{ $personLabels[(string) $bilalId] ?? $bilalId }}</span>
                            @endforeach
                            @if($personInChargeSearch)
                                <span class="inline-flex items-center rounded-full border border-orange-100 bg-orange-50 px-3 py-1.5 text-xs font-semibold text-orange-800">{{ __('Nama PIC / Penyelaras') }}: {{ $personInChargeSearch }}</span>
                            @endif
                            @if($this->has_event_url !== null)
                                <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-semibold text-slate-700">{{ $this->has_event_url ? __('Has Event URL') : __('No Event URL') }}</span>
                            @endif
                            @if($this->has_live_url !== null)
                                <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-semibold text-slate-700">{{ $this->has_live_url ? __('Has Live URL') : __('No Live URL') }}</span>
                            @endif
                            @if($this->has_end_time !== null)
                                <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-semibold text-slate-700">{{ $this->has_end_time ? __('Has End Time') : __('No End Time') }}</span>
                            @endif
                            @if($startsAfter)
                                <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-semibold text-slate-700">{{ __('Held from') }} {{ \Illuminate\Support\Carbon::make($startsAfter)?->format('d M Y') ?? $startsAfter }}</span>
                            @endif
                            @if($startsBefore)
                                <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-semibold text-slate-700">{{ __('Held until') }} {{ \Illuminate\Support\Carbon::make($startsBefore)?->format('d M Y') ?? $startsBefore }}</span>
                            @endif
                            @if($timeScope === 'past')
                                <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-semibold text-slate-700">{{ __('Past') }}</span>
                            @endif
                            @if($timeScope === 'all')
                                <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-semibold text-slate-700">{{ __('All Time') }}</span>
                            @endif

                            <div class="ml-auto flex flex-wrap items-center gap-3">
                                <button type="button" @click="shareResults()"
                                    data-signal-event="share.results_native_clicked"
                                    data-signal-category="share"
                                    data-signal-component="events_index_filters"
                                    data-signal-control="share_results"
                                    class="text-xs font-bold text-slate-600 transition hover:text-emerald-800">
                                    {{ __('Share These Results') }}
                                </button>
                                <button type="button" @click="copyShareLink()"
                                    data-signal-event="share.results_copy_clicked"
                                    data-signal-category="share"
                                    data-signal-component="events_index_filters"
                                    data-signal-control="copy_share_link"
                                    class="text-xs font-bold text-slate-600 transition hover:text-emerald-800">
                                    {{ __('Copy Share Link') }}
                                </button>
                                <a href="{{ route('saved-searches.index', $savedSearchQuery) }}" wire:navigate
                                    data-signal-event="saved_search.create_intent"
                                    data-signal-category="retention"
                                    data-signal-component="events_index_filters"
                                    data-signal-control="save_search"
                                    class="text-xs font-bold text-emerald-700 transition hover:text-emerald-900">
                                    {{ __('Save This Search') }}
                                </a>
                            </div>
                        </div>

                        <div x-show="copiedShareLink" x-cloak x-transition class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">
                            {{ __('Link copied to clipboard!') }}
                        </div>
                    @else
                        @auth
                            <div class="mt-5 flex flex-col gap-3 rounded-xl border border-sky-100 bg-sky-50/60 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                                <p class="text-sm text-slate-600">{{ __('Keep the filters you use often for quick access.') }}</p>
                                <a href="{{ route('saved-searches.index') }}" wire:navigate
                                    data-signal-event="navigation.saved_searches_clicked"
                                    data-signal-category="navigation"
                                    data-signal-component="events_index_filters"
                                    data-signal-control="saved_searches"
                                    class="text-sm font-semibold text-sky-700 transition hover:text-sky-800">
                                    {{ __('Saved Searches') }}
                                </a>
                            </div>
                        @endauth
                    @endif
                </div>

                @island(name: 'event-results', always: true)
                    @php
                        $events = $this->scheduleItems;
                        $savedEventIds = $this->savedEventIds;
                        $showPendingStatusNote = $events->contains(fn (\App\Data\PublicScheduleLeaf $leaf): bool => $leaf->event->status instanceof \App\States\EventStatus\Pending);
                        $showCancelledStatusNote = $events->contains(fn (\App\Data\PublicScheduleLeaf $leaf): bool => $leaf->event->status instanceof \App\States\EventStatus\Cancelled);
                        $eventLoadingTarget = 'filterData,setLocation,clearLocation,clearAllFilters,toggleSave,gotoPage,setPage';
                    @endphp

                <div class="mt-5 min-h-[42rem]" wire:transition="event-results">
                    @if($showPendingStatusNote || $showCancelledStatusNote)
                        <x-public.moderation-status-note
                            :show-pending="$showPendingStatusNote"
                            :show-cancelled="$showCancelledStatusNote"
                            class="mb-5"
                        />
                    @endif

                    <div wire:loading.delay.short wire:target="{{ $eventLoadingTarget }}" class="space-y-4">
                        @foreach(range(1, 4) as $index)
                            <article class="animate-pulse rounded-2xl border border-slate-200 bg-white p-3 sm:p-4">
                                <div class="grid items-start gap-4 md:grid-cols-[16rem_minmax(0,1fr)] lg:grid-cols-[18rem_minmax(0,1fr)_8.5rem] xl:grid-cols-[21rem_minmax(0,1fr)_9rem] 2xl:grid-cols-[24rem_minmax(0,1fr)_9rem]">
                                    <div class="aspect-[16/9] w-full rounded-xl bg-slate-200"></div>
                                    <div class="space-y-4 py-1">
                                        <div class="h-6 w-2/3 rounded-full bg-slate-200"></div>
                                        <div class="h-4 w-1/2 rounded-full bg-slate-100"></div>
                                        <div class="h-4 w-5/6 rounded-full bg-slate-100"></div>
                                        <div class="flex gap-2">
                                            <div class="h-8 w-24 rounded-lg bg-slate-100"></div>
                                            <div class="h-8 w-24 rounded-lg bg-slate-100"></div>
                                        </div>
                                    </div>
                                </div>
                            </article>
                        @endforeach
                    </div>

                    <div wire:loading.remove wire:target="{{ $eventLoadingTarget }}">
                        @if($events->isEmpty())
                            <div class="rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-16 text-center">
                                <div class="mx-auto flex size-20 items-center justify-center rounded-full bg-slate-50">
                                    <svg class="size-9 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3.75 8.25h16.5M5.25 5.25h13.5A1.5 1.5 0 0 1 20.25 6.75v12A1.5 1.5 0 0 1 18.75 20.25H5.25A1.5 1.5 0 0 1 3.75 18.75v-12A1.5 1.5 0 0 1 5.25 5.25Z" />
                                    </svg>
                                </div>
                                <h3 class="mt-5 font-heading text-2xl font-bold text-slate-900">{{ __('No events found') }}</h3>
                                <p class="mx-auto mt-2 max-w-md text-sm leading-6 text-slate-600">{{ __('Try adjusting your search terms or filters to find what you\'re looking for.') }}</p>
                                <button type="button" wire:click="clearAllFilters"
                                    data-signal-event="filter.cleared"
                                    data-signal-category="filter"
                                    data-signal-component="events_index_empty_state"
                                    data-signal-control="view_all_events"
                                    class="mt-6 rounded-xl bg-emerald-800 px-5 py-3 text-sm font-bold text-white transition hover:bg-emerald-900">
                                    {{ __('View all events') }}
                                </button>
                            </div>
                        @else
                            <div class="space-y-4">
                                @foreach($events as $scheduleLeaf)
                                    @php
                                        $event = $scheduleLeaf->event;
                                        $primaryOccurrence = $scheduleLeaf->occurrence;
                                        $primarySession = $scheduleLeaf->session;
                                        $scheduleTitle = $scheduleLeaf->title();
                                        $coverMedia = $primarySession?->getFirstMedia('cover')
                                            ?? $primaryOccurrence->getFirstMedia('cover')
                                            ?? $event->getFirstMedia('cover');
                                        $eventCardImageUrl = $coverMedia?->getAvailableUrl(['thumb']) ?: $event->card_image_url;
                                        $eventChangeBadgeLabel = $event->public_change_badge_label;
                                        $eventCategory = $event->classifications
                                            ->where('taxonomy_code', 'event_category')
                                            ->sortBy('sort_order')
                                            ->first();
                                        $eventCategoryLabel = $eventCategory?->term?->name ?? __('Event');
                                        $eventFormat = $event->delivery_mode instanceof \App\Enums\EventFormat
                                            ? $event->delivery_mode
                                            : \App\Enums\EventFormat::tryFrom((string) $event->delivery_mode);
                                        $formatValue = $eventFormat?->value ?? \App\Enums\EventFormat::Physical->value;
                                        $formatLabel = $eventFormat?->getLabel() ?? __('Physical');
                                        $formatBadgeClass = match ($formatValue) {
                                            \App\Enums\EventFormat::Online->value => 'bg-sky-700 text-white',
                                            \App\Enums\EventFormat::Hybrid->value => 'bg-teal-700 text-white',
                                            default => 'bg-emerald-800 text-white',
                                        };
                                        $scheduleLocation = $primarySession?->locations->first()
                                            ?? $primaryOccurrence->locations->first()
                                            ?? $event->primaryLocation;
                                        $primaryLocationName = $scheduleLocation?->venue?->name
                                            ?? $scheduleLocation?->label
                                            ?? $event->institution?->name
                                            ?? $event->venue?->name;
                                        $locationSpaceName = \App\Support\Spaces\SpaceLocationPresenter::name($scheduleLocation);
                                        $scheduleVenue = $scheduleLocation?->venue;
                                        $addressModel = $scheduleVenue instanceof \App\Models\Venue
                                            ? $scheduleVenue->primaryAddress()
                                            : null;
                                        $addressModel ??= $event->institution?->primaryAddress()
                                            ?? $event->venue?->primaryAddress();
                                        if (is_string($locationSpaceName) && trim($locationSpaceName) !== '') {
                                            $primaryLocationName = collect([$primaryLocationName, $locationSpaceName])
                                                ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
                                                ->implode(' · ');
                                        }
                                        $locationPrimaryText = is_string($primaryLocationName) && $primaryLocationName !== '' ? $primaryLocationName : null;
                                        $explicitCity = trim((string) ($addressModel?->city ?? ''));
                                        $stateText = \App\Support\Location\AddressHierarchyFormatter::format($addressModel, ['state']);
                                        $fallbackHierarchyText = \App\Support\Location\AddressHierarchyFormatter::format($addressModel, ['city', 'state']);

                                        if ($explicitCity !== '') {
                                            $locationSecondaryText = collect([$explicitCity, $stateText !== '' ? $stateText : null])
                                                ->filter()
                                                ->implode(', ');
                                        } else {
                                            $locationSecondaryText = $fallbackHierarchyText !== '' ? $fallbackHierarchyText : null;
                                        }

                                        if ($locationPrimaryText === null && $locationSecondaryText === null) {
                                            $locationPrimaryText = $formatValue === \App\Enums\EventFormat::Online->value ? __('Online') : __('Location pending');
                                        }

                                        $schedulePersonNames = $primarySession?->involvements
                                            ?->map(fn ($involvement): string => $involvement->involveable instanceof \App\Models\Person
                                                ? (string) ($involvement->involveable->formatted_name ?? $involvement->involveable->name)
                                                : (string) ($involvement->display_name ?? ''))
                                            ->filter()
                                            ->values() ?? collect();
                                        $personText = $schedulePersonNames->isNotEmpty()
                                            ? $schedulePersonNames->take(2)->implode(', ')
                                            : $event->persons
                                                ->take(2)
                                                ->map(fn (\App\Models\Person $person): string => (string) ($person->formatted_name ?? $person->name))
                                                ->filter()
                                                ->implode(', ');
                                        $personText = $personText !== '' ? $personText : __('Penceramah akan diumumkan');
                                        $cardStart = $scheduleLeaf->startsAt();
                                        $cardTimingExpression = $primarySession?->timeExpressions->firstWhere('anchor_type', 'prayer')
                                            ?? $primaryOccurrence?->timeExpressions->firstWhere('anchor_type', 'prayer')
                                            ?? $event->timeExpressions->firstWhere('anchor_type', 'prayer');
                                        $cardTimingText = $cardTimingExpression?->display_label
                                            ?: (($primarySession === null && $event->isPrayerRelative())
                                                ? (string) $event->timing_display
                                                : ($cardStart ? \App\Support\Timezone\UserDateTimeFormatter::format($cardStart, 'g:i A') : __('TBC')));
                                        $languageChips = $event->languageRecords
                                            ->pluck('language_code')
                                            ->take(1)
                                            ->map(fn (mixed $code): string => (string) ($code === 'ms' ? 'BM' : strtoupper((string) $code)))
                                            ->filter()
                                            ->values();
                                        $tagChips = $event->classifications
                                            ->take(2)
                                            ->map(fn ($classification): string => (string) ($classification->term?->name ?? $classification->term_code))
                                            ->filter()
                                            ->values();
                                        $scheduleStatus = $primarySession?->status ?? $primaryOccurrence->status;
                                        $statusBadgeLabel = $event->status instanceof \App\States\EventStatus\Pending
                                            ? __('Menunggu Kelulusan')
                                            : ($eventChangeBadgeLabel ?? __('Confirmed'));
                                        $statusBadgeClass = $event->status instanceof \App\States\EventStatus\Pending
                                            ? 'border-amber-100 bg-amber-50 text-amber-700'
                                            : (in_array((string) $scheduleStatus, ['postponed', 'rescheduled'], true) || $event->status instanceof \App\States\EventStatus\Cancelled
                                                ? 'border-rose-100 bg-rose-50 text-rose-700'
                                                : ($eventChangeBadgeLabel ? 'border-sky-100 bg-sky-50 text-sky-700' : 'border-emerald-100 bg-emerald-50 text-emerald-700'));
                                        $statusTimeLabel = $eventChangeBadgeLabel
                                            ? $event->updated_at?->diffForHumans()
                                            : ($primarySession?->published_at?->diffForHumans() ?? $primaryOccurrence->published_at?->diffForHumans() ?? $event->published_at?->diffForHumans());
                                        $mapUrl = filled($addressModel?->google_maps_url)
                                            ? (string) $addressModel->google_maps_url
                                            : (filled($addressModel?->latitude) && filled($addressModel?->longitude)
                                                ? 'https://www.google.com/maps/dir/?api=1&destination='.$addressModel->latitude.','.$addressModel->longitude
                                                : null);
                                        $eventUrl = $scheduleLeaf->url();
                                        $programmeUrl = route('events.show', $event);
                                        $signalEntityType = $scheduleLeaf->entityType();
                                        $signalEntityId = $scheduleLeaf->id();
                                        $isSaved = in_array((string) $event->getKey(), $savedEventIds, true);
                                    @endphp

                                    <article wire:key="schedule-{{ $signalEntityType }}-{{ $signalEntityId }}" class="group rounded-2xl border border-slate-200 bg-white p-3 shadow-sm transition hover:border-emerald-100 hover:shadow-[0_20px_55px_-38px_rgba(6,95,70,0.55)] sm:p-4">
                                        <div class="grid items-start gap-4 md:grid-cols-[16rem_minmax(0,1fr)] lg:grid-cols-[18rem_minmax(0,1fr)_8.5rem] xl:grid-cols-[21rem_minmax(0,1fr)_9rem] 2xl:grid-cols-[24rem_minmax(0,1fr)_9rem]">
                                            <a href="{{ $eventUrl }}" wire:navigate
                                                data-signal-event="navigation.result_clicked"
                                                data-signal-category="navigation"
                                                data-signal-component="events_index_results"
                                                data-signal-control="event_card_image"
                                                data-signal-entity-type="{{ $signalEntityType }}"
                                                data-signal-entity-id="{{ $signalEntityId }}"
                                                class="relative block aspect-[16/9] w-full overflow-hidden rounded-xl bg-slate-100 shadow-sm ring-1 ring-slate-900/5"
                                                data-cover-aspect="16:9">
                                                <img src="{{ $eventCardImageUrl }}" alt="{{ $scheduleTitle }}" loading="lazy" class="h-full w-full object-cover transition duration-500 group-hover:scale-105">
                                                <span class="absolute bottom-3 left-3 rounded-lg px-2.5 py-1.5 text-[11px] font-bold shadow-sm {{ $formatBadgeClass }}">{{ $formatLabel }}</span>
                                                @if(isset($event->distance_km))
                                                    <span class="absolute right-3 top-3 rounded-full bg-white/90 px-2.5 py-1 text-xs font-bold text-emerald-800 shadow-sm backdrop-blur">{{ number_format($event->distance_km, 1) }} km</span>
                                                @endif
                                            </a>

                                            <div class="min-w-0">
                                                <div class="mb-2.5 flex flex-wrap items-center gap-2" data-testid="event-card-badge-row">
                                                    <span class="inline-flex items-center rounded-full border border-emerald-100 bg-emerald-50 px-2.5 py-1 text-[11px] font-semibold text-emerald-800" data-testid="event-card-type-badge">
                                                        {{ $eventCategoryLabel }}
                                                    </span>
                                                    <span class="inline-flex items-center rounded-full border border-amber-100 bg-amber-50 px-2.5 py-1 text-[11px] font-semibold text-amber-800" data-testid="event-card-date-badge">
                                                        {{ $cardStart ? \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($cardStart, 'j M') : __('TBC') }}
                                                    </span>
                                                </div>

                                                <p class="mb-1 text-[11px] font-bold uppercase tracking-[0.18em] text-slate-500">
                                                    <a href="{{ $programmeUrl }}" wire:navigate class="transition hover:text-emerald-800">{{ __('Program') }} · {{ $event->title }}</a>
                                                </p>

                                                <a href="{{ $eventUrl }}" wire:navigate
                                                    data-signal-event="navigation.result_clicked"
                                                    data-signal-category="navigation"
                                                    data-signal-component="events_index_results"
                                                    data-signal-control="event_card_title"
                                                    data-signal-entity-type="{{ $signalEntityType }}"
                                                    data-signal-entity-id="{{ $signalEntityId }}"
                                                    class="block" data-testid="event-card-title-link">
                                                    <h3 class="font-heading text-xl font-bold leading-tight text-emerald-950 transition group-hover:text-emerald-800 lg:text-[1.35rem]">
                                                        {{ $scheduleTitle }}
                                                    </h3>
                                                </a>

                                                @if($event->reference_study_subtitle)
                                                    <p class="mt-1 text-xs font-semibold italic text-slate-500">{{ $event->reference_study_subtitle }}</p>
                                                @endif

                                                <dl class="mt-2.5 space-y-1.5 text-xs leading-5 text-slate-600">
                                                    <div class="flex gap-2">
                                                        <dt class="mt-0.5 text-slate-500">
                                                            <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.5 20.25a8.25 8.25 0 1 1 15 0" />
                                                            </svg>
                                                        </dt>
                                                        <dd class="min-w-0 truncate">{{ $personText }}</dd>
                                                    </div>
                                                    <div class="flex gap-2">
                                                        <dt class="mt-0.5 text-slate-500">
                                                            <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 21s7-4.438 7-11a7 7 0 1 0-14 0c0 6.562 7 11 7 11Z" />
                                                            </svg>
                                                        </dt>
                                                        <dd class="min-w-0">
                                                            <span class="block truncate">{{ $locationPrimaryText }}</span>
                                                            @if($locationSecondaryText)
                                                                <span class="block truncate text-xs text-slate-500">{{ $locationSecondaryText }}</span>
                                                            @endif
                                                        </dd>
                                                    </div>
                                                    <div class="flex gap-2">
                                                        <dt class="mt-0.5 text-slate-500">
                                                            <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2m5-2a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                                                            </svg>
                                                        </dt>
                                                        <dd>{{ $cardTimingText }}</dd>
                                                    </div>
                                                </dl>

                                                <div class="mt-2.5 flex flex-wrap gap-2">
                                                    @foreach($languageChips as $languageChip)
                                                        <span class="rounded-full bg-emerald-50 px-2.5 py-1 text-[11px] font-semibold text-emerald-800">{{ $languageChip }}</span>
                                                    @endforeach
                                                    @foreach($tagChips as $tagChip)
                                                        <span class="rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-semibold text-slate-700">{{ $tagChip }}</span>
                                                    @endforeach
                                                </div>

                                            </div>

                                            <div class="grid gap-2 md:col-span-2 md:grid-cols-4 lg:col-span-1 lg:flex lg:flex-col lg:border-l lg:border-slate-100 lg:pl-4">
                                                    <span class="rounded-lg border px-2.5 py-1.5 text-center text-[11px] font-semibold leading-4 md:col-span-4 {{ $statusBadgeClass }}">
                                                        <span class="block">{{ $statusBadgeLabel }}</span>
                                                        @if($statusTimeLabel)
                                                            <span class="mt-0.5 block font-normal opacity-75">{{ $statusTimeLabel }}</span>
                                                        @endif
                                                    </span>
                                                    <a href="{{ $eventUrl }}" wire:navigate class="inline-flex h-9 items-center justify-center rounded-xl border border-emerald-700 bg-white px-2 text-[11px] font-bold leading-tight text-emerald-800 transition hover:bg-emerald-50 lg:w-full">
                                                        {{ __('Lihat Detail') }}
                                                    </a>
                                                    <a href="{{ $programmeUrl }}" wire:navigate class="inline-flex h-9 items-center justify-center rounded-xl border border-slate-200 bg-white px-2 text-[11px] font-semibold leading-tight text-slate-600 transition hover:border-emerald-200 hover:bg-emerald-50 hover:text-emerald-800 lg:w-full">
                                                        {{ __('Program') }}
                                                    </a>
                                                    <button type="button" wire:click="toggleSave('{{ $event->getKey() }}')"
                                                        data-signal-event="engagement.event_save_clicked"
                                                        data-signal-category="engagement"
                                                        data-signal-component="events_index_results"
                                                        data-signal-control="save"
                                                        data-signal-entity-type="event"
                                                        data-signal-entity-id="{{ $event->id }}"
                                                        data-signal-props='@json(['currently_saved' => $isSaved])'
                                                        class="inline-flex h-9 items-center justify-center gap-1.5 rounded-xl border border-slate-200 bg-white px-2 text-[11px] font-semibold leading-tight text-slate-600 transition hover:border-sky-200 hover:bg-sky-50 hover:text-sky-700 lg:w-full">
                                                        <svg class="size-4" fill="{{ $isSaved ? 'currentColor' : 'none' }}" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16l-7-3.5L5 21V5Z" />
                                                        </svg>
                                                        {{ $isSaved ? __('Disimpan') : __('Simpan') }}
                                                    </button>
                                                    <button type="button" @click="shareEvent(@js($signalEntityId), @js($eventUrl), @js($scheduleTitle))"
                                                        data-signal-event="share.event_clicked"
                                                        data-signal-category="share"
                                                        data-signal-component="events_index_results"
                                                        data-signal-control="share_event"
                                                        data-signal-entity-type="{{ $signalEntityType }}"
                                                        data-signal-entity-id="{{ $signalEntityId }}"
                                                        class="inline-flex h-9 items-center justify-center gap-1.5 rounded-xl border border-slate-200 bg-white px-2 text-[11px] font-semibold leading-tight text-slate-600 transition hover:border-emerald-200 hover:bg-emerald-50 hover:text-emerald-800 lg:w-full">
                                                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M7.217 10.907a2.25 2.25 0 1 0 0 2.186m0-2.186 9.566-5.314m-9.566 7.5 9.566 5.314" />
                                                        </svg>
                                                        <span x-text="copiedEventId === @js($signalEntityId) ? '{{ __('Disalin') }}' : '{{ __('Kongsi') }}'"></span>
                                                    </button>
                                                    @if($mapUrl)
                                                        <a href="{{ $mapUrl }}" target="_blank" rel="noopener noreferrer"
                                                            data-signal-event="navigation.event_map_opened"
                                                            data-signal-category="navigation"
                                                            data-signal-component="events_index_results"
                                                            data-signal-control="open_maps"
                                                            data-signal-entity-type="{{ $signalEntityType }}"
                                                            data-signal-entity-id="{{ $signalEntityId }}"
                                                            class="inline-flex h-9 items-center justify-center gap-1.5 rounded-xl border border-slate-200 bg-white px-2 text-[11px] font-semibold leading-tight text-slate-600 transition hover:border-emerald-200 hover:bg-emerald-50 hover:text-emerald-800 lg:w-full">
                                                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                                <path stroke-linecap="round" stroke-linejoin="round" d="m12 19.5 7.5-4.125V4.875L12 9 4.5 4.875v10.5L12 19.5Zm0 0V9" />
                                                            </svg>
                                                            {{ __('Buka Maps') }}
                                                        </a>
                                                    @else
                                                        <span class="hidden sm:block"></span>
                                                    @endif
                                            </div>
                                        </div>
                                    </article>
                                @endforeach
                            </div>

                            <div class="mt-6">
                                {{ $events->withQueryString()->links() }}
                            </div>
                        @endif
                    </div>
                </div>
                @endisland

                <section id="majlis-map-preview" class="mt-6 overflow-hidden rounded-2xl border border-amber-100 bg-white shadow-sm">
                    <div class="grid gap-0 lg:grid-cols-[18rem_minmax(0,1fr)]">
                        <div class="flex items-center gap-4 p-6">
                            <div class="flex size-20 shrink-0 items-center justify-center rounded-2xl border border-emerald-100 bg-emerald-50">
                                <svg class="size-10 text-emerald-800" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 18.75 3.75 21V6.75L9 4.5m0 14.25 6-2.25m-6 2.25V4.5m6 12 5.25 2.25V4.5L15 6.75m0 9.75V6.75m0 0L9 4.5" />
                                </svg>
                            </div>
                            <div>
                                <h2 class="font-heading text-xl font-bold text-emerald-950">{{ __('Lihat majlis di peta') }}</h2>
                                <p class="mt-1 text-sm leading-6 text-slate-600">{{ __('Terokai majlis berdekatan anda dengan paparan peta interaktif.') }}</p>
                                <button type="button" @click="locate" class="mt-3 inline-flex items-center gap-2 rounded-lg bg-emerald-800 px-4 py-2 text-xs font-bold text-white transition hover:bg-emerald-900">
                                    {{ __('Lihat di peta penuh') }}
                                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                        <div class="relative min-h-44 overflow-hidden bg-[#eaf2ec]">
                            <div class="absolute inset-0 opacity-70" style="background-image: linear-gradient(30deg, transparent 45%, rgba(148, 163, 184, .45) 46%, rgba(148, 163, 184, .45) 48%, transparent 49%), linear-gradient(120deg, transparent 42%, rgba(148, 163, 184, .35) 43%, rgba(148, 163, 184, .35) 46%, transparent 47%); background-size: 120px 80px, 160px 100px;"></div>
                            <div class="absolute left-[18%] top-[38%] size-8 rounded-full bg-emerald-800 p-1 text-white shadow-lg"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21s6-4.5 6-10a6 6 0 1 0-12 0c0 5.5 6 10 6 10Z" /></svg></div>
                            <div class="absolute left-[52%] top-[26%] size-8 rounded-full bg-sky-600 p-1 text-white shadow-lg"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21s6-4.5 6-10a6 6 0 1 0-12 0c0 5.5 6 10 6 10Z" /></svg></div>
                            <div class="absolute right-[18%] top-[42%] size-8 rounded-full bg-emerald-800 p-1 text-white shadow-lg"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21s6-4.5 6-10a6 6 0 1 0-12 0c0 5.5 6 10 6 10Z" /></svg></div>
                            <div class="absolute bottom-3 left-1/2 -translate-x-1/2 rounded-full bg-white/90 px-4 py-1.5 text-xs font-semibold text-slate-600 shadow-sm backdrop-blur">{{ __('Shah Alam') }}</div>
                        </div>
                    </div>
                </section>
            </section>
        </form>

        <section class="mt-6 overflow-hidden rounded-2xl bg-[#062b49] px-6 py-8 text-center text-white shadow-[0_24px_70px_-45px_rgba(6,43,73,0.75)] md:px-10">
            <div class="mx-auto max-w-3xl">
                <h2 class="font-heading text-2xl font-bold leading-tight text-amber-100 md:text-3xl">
                    {{ __('Ilmu dah ada. Masjid dah terbuka. Surau dah hidup.') }}
                    <span class="block text-amber-200">{{ __('Sekarang, mari bantu lebih ramai orang sampai.') }}</span>
                </h2>
                <div class="mt-6 flex flex-col justify-center gap-3 sm:flex-row">
                    <a href="{{ route('events.index', $todayQuery) }}" wire:navigate class="inline-flex items-center justify-center gap-2 rounded-xl bg-emerald-700 px-5 py-3 text-sm font-bold text-white transition hover:bg-emerald-600">
                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                        </svg>
                        {{ __('Cari Majlis Hari Ini') }}
                    </a>
                    <a href="{{ route('home') }}" wire:navigate class="inline-flex items-center justify-center gap-2 rounded-xl border border-white/25 bg-white px-5 py-3 text-sm font-bold text-slate-900 transition hover:bg-amber-50">
                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V3.75m0 12.75 3.75-3.75M12 16.5l-3.75-3.75M4.5 20.25h15" />
                        </svg>
                        {{ __('Download Ilmu360') }}
                    </a>
                    <a href="{{ route('submit-event.create') }}" wire:navigate class="inline-flex items-center justify-center gap-2 rounded-xl border border-amber-200/60 px-5 py-3 text-sm font-bold text-amber-100 transition hover:bg-white/10">
                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0 3 3m-3-3-3 3M4.5 19.5h15a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5h-15A1.5 1.5 0 0 0 3 6v12a1.5 1.5 0 0 0 1.5 1.5Z" />
                        </svg>
                        {{ __('Hantar Majlis') }}
                    </a>
                </div>
            </div>
        </section>
    </main>
</div>
