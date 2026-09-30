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
    $keyPersonRoleLabels = \App\Enums\EventKeyPersonRole::nonSpeakerOptions();
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
    $divisionLabel = filled($areaAssignments['administrative_division'] ?? null) ? $this->areaOptionLabel((string) $areaAssignments['administrative_division']) : null;
    $postalLocalityLabel = filled($areaAssignments['postal_locality'] ?? null) ? $this->areaOptionLabel((string) $areaAssignments['postal_locality']) : null;
    $districtLabel = filled($districtAreaId) ? $this->areaOptionLabel((string) $districtAreaId) : null;
    $subdivisionLabel = filled($subdivisionAreaId) ? $this->areaOptionLabel((string) $subdivisionAreaId) : null;
    $prayerTimeLabels = collect((array) $prayerTime)
        ->filter(static fn (mixed $value): bool => is_string($value) && $value !== '')
        ->map(static fn (string $value): string => \App\Enums\EventPrayerTime::tryFrom($value)?->getLabel() ?? $value)
        ->all();
    $timingModeLabel = \App\Enums\TimingMode::tryFrom((string) $timingMode)?->label();
    $activeFilterCount = $this->activeFilterCount();
    $hasActiveFilters = $activeFilterCount > 0;
    $savedSearchQuery = array_filter([
        'search' => $search,
        'country_id' => $countryId,
        'state_id' => $stateId,
        'area_assignments' => $areaAssignments,
        'institution_id' => $institutionId,
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
    ], function (mixed $value): bool {
        if (is_array($value)) {
            return $value !== [];
        }

        return $value !== null && $value !== '';
    });
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
    data-art-direction="living-majlis"
    class="living-majlis-field min-h-screen overflow-x-clip text-slate-900"
    x-data="{
        ...window.ilmu360.geolocationPermission({
            initiallyGranted: @js($showsGeolocationControls),
            cookieName: @js(\App\Support\Location\PublicGeolocationPermission::COOKIE_NAME),
        }),
        filtersOpen: $wire.entangle('filtersPanelOpen'),
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
    <section class="relative isolate overflow-hidden border-b border-emerald-900/[0.06]">
        <div class="absolute inset-0 bg-[#f7f3e8]">
            <img
                src="{{ asset('images/events/majlis-hero-background-v1.png') }}"
                alt="{{ __('Laman masjid pada waktu keemasan') }}"
                class="h-full w-full object-cover object-[35%_center] lg:object-center"
            >
            <div
                class="absolute inset-0"
                style="background: linear-gradient(90deg, rgba(255,253,248,.94) 0%, rgba(255,253,248,.88) 25%, rgba(255,253,248,.60) 44%, rgba(255,253,248,.15) 58%, transparent 68%);"
            ></div>
        </div>

        <div class="relative mx-auto max-w-7xl px-5 pt-48 pb-14 sm:px-6 sm:pt-52 sm:pb-20 lg:px-8 lg:pt-52 lg:pb-16">
            <div class="max-w-3xl lg:max-w-[44rem]">
                <h1 class="max-w-2xl text-balance font-heading text-4xl font-bold leading-[1.06] tracking-[-0.035em] text-emerald-950 sm:text-5xl lg:text-6xl">
                    {{ __('Temui majlis ilmu yang') }} <br class="hidden md:block" />
                    <span class="relative inline-block text-emerald-700">
                        {{ __('dekat dengan anda') }}
                        <svg class="absolute -bottom-2 left-0 h-3.5 w-full text-amber-500/80" viewBox="0 0 320 18" preserveAspectRatio="none" aria-hidden="true">
                            <path d="M4 13C79 5 218 4 316 10" fill="none" stroke="currentColor" stroke-width="5" stroke-linecap="round" />
                        </svg>
                    </span>
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
                    class="mt-8 max-w-2xl">
                    <x-ui.search-bar
                        input-id="event-search"
                        model="filterData.search"
                        :value="$search"
                        :placeholder="__('Cari tajuk majlis...')"
                        maxlength="255"
                        :label="__('Carian')"
                        :hint="__('Cari mengikut tajuk majlis.')"
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

    <main class="relative z-10 mx-auto max-w-7xl px-5 pt-10 pb-20 sm:px-6 lg:px-8 lg:pt-12">
        <form wire:submit.prevent class="space-y-5">
            @include('partials.event-filter-panel', ['showNearbyButton' => true])

            <section class="min-w-0">
                <div class="living-majlis-veil rounded-[1.5rem] border p-4 shadow-[0_20px_50px_-35px_rgba(15,23,42,0.55)] md:p-6">
                    <div class="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
                        <div>
                            <h2 aria-live="polite" class="font-heading text-2xl font-bold text-emerald-950">
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

                    </div>

                    @if($hasActiveFilters)
                        <div class="mt-5 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-4">
                            @if($search)
                                <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-semibold text-slate-700">{{ __('Carian') }}: "{{ $search }}"</span>
                            @endif
                            @if($lat)
                                <span class="inline-flex items-center gap-1 rounded-full border border-emerald-100 bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-800">{{ __('Dekat saya') }} · {{ $this->radius_km }} km</span>
                            @endif
                            @if($hasCountryScope)
                                <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-semibold text-slate-700">{{ __('Negara') }}: {{ $countries->firstWhere('id', $countryId)?->name ?? $countryId }}</span>
                            @endif
                            @if($stateId)
                                <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-semibold text-slate-700">{{ $this->stateChipLabel() }}: {{ $states->firstWhere('id', $stateId)?->name ?? $stateId }}</span>
                            @endif
                            @if($areaAssignments['administrative_division'] ?? null)
                                <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-semibold text-slate-700">{{ $this->divisionLabel() }}: {{ $divisionLabel ?? $areaAssignments['administrative_division'] }}</span>
                            @endif
                            @if($areaAssignments['postal_locality'] ?? null)
                                <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-semibold text-slate-700">{{ $this->localityLabel() }}: {{ $postalLocalityLabel ?? $areaAssignments['postal_locality'] }}</span>
                            @endif
                            @if($districtAreaId)
                                <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-semibold text-slate-700">{{ $this->districtLabel() }}: {{ $districtLabel ?? $districtAreaId }}</span>
                            @endif
                            @if($subdivisionAreaId)
                                <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-semibold text-slate-700">{{ $this->subdistrictLabel() }}: {{ $subdivisionLabel ?? $subdivisionAreaId }}</span>
                            @endif
                            @if($institutionId)
                                <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-semibold text-slate-700">{{ __('Institusi') }}: {{ $institutionLabel ?? $institutionId }}</span>
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
                            @foreach($prayerTimeLabels as $prayerTimeLabel)
                                <span class="inline-flex items-center rounded-full border border-amber-100 bg-amber-50 px-3 py-1.5 text-xs font-semibold text-amber-800">{{ __('Waktu solat') }}: {{ $prayerTimeLabel }}</span>
                            @endforeach
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
                        $eventLoadingTarget = 'filterData,setLocation,clearLocation,clearAllFilters,toggleSave,gotoPage,setPage';
                    @endphp

                <div class="mt-5 min-h-[42rem]" wire:transition="event-results">
                    <div wire:loading.delay.short wire:target="{{ $eventLoadingTarget }}" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach(range(1, 6) as $index)
                            <article class="animate-pulse overflow-hidden rounded-2xl border border-emerald-200 bg-white shadow-[0_12px_30px_-18px_rgba(24,53,43,0.35)]">
                                <div class="aspect-video w-full bg-slate-200"></div>
                                <div class="px-4 pt-4 pb-4">
                                    <div class="flex items-center gap-3">
                                        <div class="h-14 w-12 shrink-0 rounded-xl bg-slate-100"></div>
                                        <div class="h-5 w-4/5 rounded-full bg-slate-200"></div>
                                    </div>
                                    <div class="mt-3 flex gap-1.5">
                                        <div class="h-4 w-20 rounded-full bg-slate-100"></div>
                                        <div class="h-4 w-16 rounded-full bg-slate-100"></div>
                                    </div>
                                    <div class="mt-4 space-y-2">
                                        <div class="h-4 w-4/5 rounded-full bg-slate-100"></div>
                                        <div class="h-4 w-3/5 rounded-full bg-slate-100"></div>
                                        <div class="h-4 w-1/2 rounded-full bg-slate-100"></div>
                                    </div>
                                </div>
                                <div class="mt-auto flex items-center justify-between gap-3 border-t border-slate-100 px-4 pb-4 pt-4">
                                    <div class="h-4 w-24 rounded-full bg-slate-100"></div>
                                    <div class="flex gap-2">
                                        <div class="size-9 rounded-xl bg-slate-100"></div>
                                        <div class="size-9 rounded-xl bg-slate-100"></div>
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
                            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                                @foreach($events as $scheduleLeaf)
                                    @php
                                        $event = $scheduleLeaf->event;
                                        $primaryOccurrence = $scheduleLeaf->occurrence;
                                        $primarySession = $scheduleLeaf->session;
                                        $scheduleTitle = $scheduleLeaf->title();
                                        $eventChangeBadgeLabel = $event->public_change_badge_label;
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

                                        $cardStart = $scheduleLeaf->startsAt();
                                        $cardTimingExpression = $primarySession?->timeExpressions->firstWhere('anchor_type', 'prayer')
                                            ?? $primaryOccurrence?->timeExpressions->firstWhere('anchor_type', 'prayer')
                                            ?? $event->timeExpressions->firstWhere('anchor_type', 'prayer');
                                        $cardTimingText = $cardTimingExpression?->display_label
                                            ?: (($primarySession === null && $event->isPrayerRelative())
                                                ? (string) $event->timing_display
                                                : ($cardStart ? \App\Support\Timezone\UserDateTimeFormatter::format($cardStart, 'g:i A') : __('TBC')));
                                        $scheduleStatus = $primarySession?->status ?? $primaryOccurrence->status;
                                        $statusBadgeLabel = $event->status instanceof \App\States\EventStatus\Pending
                                            ? __('Menunggu Kelulusan')
                                            : ($eventChangeBadgeLabel ?? __('Confirmed'));
                                        $statusBadgeClass = $event->status instanceof \App\States\EventStatus\Pending
                                            ? 'border-amber-100 bg-amber-50 text-amber-700'
                                            : (in_array((string) $scheduleStatus, ['postponed', 'rescheduled'], true) || $event->status instanceof \App\States\EventStatus\Cancelled
                                                ? 'border-rose-100 bg-rose-50 text-rose-700'
                                                : ($eventChangeBadgeLabel ? 'border-sky-100 bg-sky-50 text-sky-700' : 'border-emerald-100 bg-emerald-50 text-emerald-700'));
                                        $eventUrl = $scheduleLeaf->url();
                                        $signalEntityType = $scheduleLeaf->entityType();
                                        $signalEntityId = $scheduleLeaf->id();
                                        $isSaved = in_array((string) $event->getKey(), $savedEventIds, true);
                                    @endphp

                                    <x-events.card
                                        :event="$event"
                                        :url="$eventUrl"
                                        :title="$scheduleTitle"
                                        :starts-at="$cardStart"
                                        :timing-text="$cardTimingText"
                                        :is-saved="$isSaved"
                                        :status-label="$statusBadgeLabel"
                                        :status-class="$statusBadgeClass"
                                        :format-label="$formatLabel"
                                        :format-class="$formatBadgeClass"
                                        :location-primary="$locationPrimaryText"
                                        :location-secondary="$locationSecondaryText"
                                        :distance-km="isset($event->distance_km) ? $event->distance_km : null"
                                        signal-component="events_index_results"
                                        :signal-entity-type="$signalEntityType"
                                        :signal-entity-id="$signalEntityId"
                                        testid-prefix="events-index-card"
                                        :wire-key="'schedule-'.$signalEntityType.'-'.$signalEntityId"
                                    />
                                @endforeach
                            </div>

                            <div class="mt-6">
                                {{ $events->withQueryString()->links('vendor.livewire.directory-pagination') }}
                            </div>
                        @endif
                    </div>
                </div>
                @endisland

                            </section>
        </form>

    </main>
</div>
