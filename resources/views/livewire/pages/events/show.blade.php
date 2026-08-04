@section('title', $event->title . ' - ' . config('app.name'))
@section('meta_description', Str::limit($event->description_text !== '' ? $event->description_text : __('Lihat masa, lokasi, penceramah, dan maklumat pendaftaran untuk majlis ilmu ini di :app.', ['app' => config('app.name')]), 160))
@section('meta_og_type', 'event')
@section('meta_robots', $this->metaRobots)
@section('og_url', route('events.show', $event))
@section('og_image', $event->card_image_url)
@section('og_image_alt', __('Poster untuk :title', ['title' => $event->title]))

@include('partials.filament-assets', [
    'scripts' => ['filament/support', 'filament/notifications'],
])

@push('head')
    <x-event-json-ld :event="$this->event" />
    <link rel="canonical" href="{{ route('events.show', $event) }}">
    <meta property="article:published_time" content="{{ $event->starts_at?->toIso8601String() }}">
@endpush

@php
    $venueAddress = $event->venue?->primaryAddress();
    $institutionAddress = $event->institution?->primaryAddress();
    $primaryAddress = $venueAddress ?? $institutionAddress;
    $lat = $venueAddress?->latitude ?? $venueAddress?->lat ?? $institutionAddress?->latitude ?? $institutionAddress?->lat;
    $lng = $venueAddress?->longitude ?? $venueAddress?->lng ?? $institutionAddress?->longitude ?? $institutionAddress?->lng;
    $addressDisplayLines = \App\Support\Location\AddressHierarchyFormatter::displayLines($primaryAddress);
    $locationParts = \App\Support\Location\AddressHierarchyFormatter::parts($primaryAddress);
    $locationState = $locationParts[1] ?? null;
    $locationDistrict = $locationParts[0] ?? null;
    $locationShortLabel = implode(', ', array_filter(
        $locationDistrict !== $locationState ? [$locationDistrict, $locationState] : [$locationState]
    ));
    $mapQuery = implode(', ', array_filter([
        $event->venue?->name ?? $event->institution?->name,
        $primaryAddress?->line1,
        $primaryAddress?->line2,
        $addressDisplayLines['locality'] ?? null,
        $addressDisplayLines['regional'] ?? null,
    ]));
    $normalizedMapQuery = null;
    if (filled($primaryAddress?->google_maps_url)) {
        $queryString = parse_url((string) $primaryAddress->google_maps_url, PHP_URL_QUERY);
        if (is_string($queryString) && $queryString !== '') {
            parse_str($queryString, $queryParameters);
            $normalizedMapQuery = $queryParameters['query'] ?? $queryParameters['q'] ?? null;
        }
    }
    if (! filled($normalizedMapQuery) && $lat !== null && $lng !== null) {
        $normalizedMapQuery = $lat . ',' . $lng;
    }
    if (! filled($normalizedMapQuery)) {
        $normalizedMapQuery = $mapQuery;
    }
    $mapEmbedUrl = filled($normalizedMapQuery)
        ? 'https://www.google.com/maps?q=' . urlencode((string) $normalizedMapQuery) . '&output=embed'
        : null;
    $wazeNavUrl = filled($primaryAddress?->waze_url)
        ? (string) $primaryAddress->waze_url
        : ($lat !== null && $lng !== null ? "https://www.waze.com/ul?ll={$lat},{$lng}&navigate=yes" : null);
    $googleMapsNavUrl = filled($primaryAddress?->google_maps_url)
        ? (string) $primaryAddress->google_maps_url
        : ($lat !== null && $lng !== null ? "https://www.google.com/maps/dir/?api=1&destination={$lat},{$lng}" : null);

    $galleryImages = $this->galleryImages;
    $keyPeopleByRole = $this->keyPeopleByRole;
    $relatedEvents = $this->relatedEvents;
    $registrationMode = $this->registrationMode();
    $descriptionHtml = $this->descriptionHtml;
    $hasAboutContent = $this->hasAboutContent;
    $eventActionsDisabled = $this->eventActionsDisabled;
    $isPostponedWithoutConfirmedTime = $this->isPostponedWithoutConfirmedTime;
    $isCancelledStatus = $event->status instanceof \App\States\EventStatus\Cancelled || (string) $event->status === 'cancelled';
    $checkInState = $this->checkInState;
    $checkInActionDisabled = auth()->check() && ! $checkInState['available'] && ! $this->isCheckedIn;
    $isOnlineFormat = (string) $event->delivery_mode === \App\Enums\EventFormat::Online->value;
    $isHybridFormat = (string) $event->delivery_mode === \App\Enums\EventFormat::Hybrid->value;
    $formatLabel = $event->delivery_mode instanceof \App\Enums\EventFormat
        ? $event->delivery_mode->getLabel()
        : ($isOnlineFormat ? __('Dalam talian') : ($isHybridFormat ? __('Hibrid') : __('Fizikal')));
    $scheduleKindLabel = $event->schedule_kind?->label();
    $eventStatus = (string) $event->status;
    $statusLabel = match ($eventStatus) {
        'pending' => __('Pending Approval'),
        'cancelled' => __('Majlis Dibatalkan'),
        default => $eventStatus === 'approved' ? __('Approved') : \Illuminate\Support\Str::headline($eventStatus),
    };
    $statusTone = match ($eventStatus) {
        'cancelled' => 'border-rose-300/40 bg-rose-400/15 text-rose-100',
        'pending' => 'border-amber-300/40 bg-amber-300/15 text-amber-100',
        default => 'border-lime-300/40 bg-lime-300/15 text-lime-100',
    };
    $eventHasPoster = $event->hasMedia('poster');
    $eventPosterPreviewUrl = $eventHasPoster ? $event->getFirstMedia('poster')?->getAvailableUrl(['poster_thumb', 'card']) : null;
    $eventPosterOriginalUrl = $eventHasPoster ? $event->getFirstMediaUrl('poster') : null;
    $eventPosterDisplayAspectRatio = $eventHasPoster ? $event->poster_display_aspect_ratio : '16:9';
    $posterAspectClass = $eventPosterDisplayAspectRatio === '3:4' ? 'aspect-[3/4]' : 'aspect-[16/9]';
    $eventCoverUrl = $event->getFirstMedia('cover')?->getAvailableUrl(['banner', 'thumb']) ?? '';
    $heroImage = $eventCoverUrl;
    if ($heroImage === '') {
        $heroImage = $event->institution?->getFirstMedia('cover')?->getAvailableUrl(['banner']) ?? '';
    }
    if ($heroImage === '') {
        $heroImage = $event->venue?->getFirstMedia('main')?->getAvailableUrl(['banner'])
            ?? $event->venue?->getFirstMedia('cover')?->getAvailableUrl(['banner'])
            ?? '';
    }
    $locationEntity = $event->venue ?? $event->institution;
    $locationHref = $locationEntity instanceof \App\Models\Institution
        ? route('institutions.show', $locationEntity)
        : ($locationEntity instanceof \App\Models\Venue ? route('venues.show', $locationEntity) : null);
    $locationName = $locationEntity?->name ?? ($isOnlineFormat ? __('Acara Dalam Talian') : __('Lokasi Akan Dikemaskini'));
    $locationContactMethods = $locationEntity?->relationLoaded('contactMethods') ? $locationEntity->contactMethods : collect();
    $locationPhone = $locationContactMethods->firstWhere('type', \AIArmada\Contacting\Enums\ContactMethodType::Phone->value)?->value;
    $locationEmail = $locationContactMethods->firstWhere('type', \AIArmada\Contacting\Enums\ContactMethodType::Email->value)?->value;
    $institutionSameAsLocation = $event->institution !== null
        && $locationEntity !== null
        && $event->institution->getMorphClass() === $locationEntity->getMorphClass()
        && (string) $event->institution->getKey() === (string) $locationEntity->getKey();
    $organizer = $event->organizer ?? $event->institution;
    $organizerHref = $organizer instanceof \App\Models\Institution
        ? route('institutions.show', $organizer)
        : ($organizer instanceof \App\Models\Person ? route('persons.show', $organizer) : null);
    $classifications = $event->classifications->loadMissing('term')
        ->filter(fn ($classification): bool => $classification->term !== null);
    $classificationLabels = $classifications->map(fn ($classification): string => (string) $classification->term->name)->unique()->values();
    $ageGroupLabels = collect($event->age_group ?? [])
        ->map(fn ($group): ?string => is_object($group) && method_exists($group, 'getLabel') ? $group->getLabel() : (is_string($group) ? $group : null))
        ->filter()
        ->values();
    $genderLabel = $event->gender instanceof \App\Enums\EventGenderRestriction
        ? $event->gender->getLabel()
        : null;
    $primaryLanguage = $event->languages->first();
    $languageLabel = $primaryLanguage?->name ?? __('Bahasa Melayu');
    $audienceLabels = $event->audiences
        ->map(fn ($audience): ?string => filled($audience->value) ? (string) $audience->value : null)
        ->filter()
        ->values();
    $audienceProfile = $event->audienceProfiles->first();
    $shareData = [
        'title' => $event->title,
        'text' => \Illuminate\Support\Str::limit($event->description_text, 100),
        'url' => route('events.show', $event),
        'sourceUrl' => route('events.show', $event),
        'shareText' => trim($event->title . ' - ' . config('app.name')),
        'fallbackTitle' => $event->title,
        'payloadEndpoint' => route('dawah-share.payload'),
    ];
@endphp

<div
    class="min-h-screen bg-[#f4f0e7] pb-24 text-slate-900 lg:pb-12"
    data-signal-event="navigation.external_link_clicked"
    data-signal-category="navigation"
    data-signal-component="event_detail_external_navigation"
    data-signal-control="external_link"
    x-data='{
        registerOpen: false,
        shareModalOpen: false,
        posterModalOpen: false,
        copied: false,
        shareData: @json($shareData),
        trackEndpoint: @json(route("dawah-share.track")),
        providerQueryParameter: @json(config("dawah-share.provider_query_parameter", "channel")),
        attributedShareData: null,
        async resolveShareData() {
            if (this.attributedShareData) return this.attributedShareData;
            const params = new URLSearchParams({
                url: this.shareData.sourceUrl,
                text: this.shareData.shareText,
                title: this.shareData.fallbackTitle
            });
            const response = await fetch(this.shareData.payloadEndpoint + "?" + params.toString(), {
                headers: { Accept: "application/json" }
            });
            if (!response.ok) return this.shareData;
            const payload = await response.json();
            this.attributedShareData = {
                ...this.shareData,
                url: payload.url,
                tracking_token: payload.tracking_token ?? null
            };
            return this.attributedShareData;
        },
        async sharePayloadForChannel(provider) {
            const shareData = await this.resolveShareData();
            if (!shareData?.tracking_token || !provider) return shareData;
            const shareUrl = new URL(shareData.url, window.location.origin);
            shareUrl.searchParams.set(this.providerQueryParameter, provider);
            return { ...shareData, url: shareUrl.toString() };
        },
        async trackShare(provider) {
            const shareData = await this.resolveShareData();
            const csrfToken = document.querySelector("meta[name=csrf-token]")?.content;
            if (!shareData?.tracking_token || !csrfToken) return;
            await fetch(this.trackEndpoint, {
                method: "POST",
                headers: {
                    Accept: "application/json",
                    "Content-Type": "application/json",
                    "X-CSRF-TOKEN": csrfToken
                },
                body: JSON.stringify({
                    provider,
                    tracking_token: shareData.tracking_token
                })
            });
        },
        async share(provider) {
            const shareData = await this.sharePayloadForChannel(provider);
            if (!shareData?.url) return;
            const encodedUrl = encodeURIComponent(shareData.url);
            const encodedText = encodeURIComponent(shareData.shareText ?? shareData.title);
            const providerUrls = {
                whatsapp: "https://wa.me/?text=" + encodedText + "%20" + encodedUrl,
                telegram: "https://t.me/share/url?url=" + encodedUrl + "&text=" + encodedText,
                facebook: "https://www.facebook.com/sharer/sharer.php?u=" + encodedUrl,
                x: "https://twitter.com/intent/tweet?url=" + encodedUrl + "&text=" + encodedText,
                email: "mailto:?subject=" + encodedText + "&body=" + encodedUrl
            };
            if (provider === "copy_link") {
                await navigator.clipboard?.writeText(shareData.url);
                this.copied = true;
                await this.trackShare(provider);
                setTimeout(() => { this.copied = false; }, 2200);
                return;
            }
            if (provider === "native_share" && navigator.share) {
                try {
                    await navigator.share({
                        title: shareData.title,
                        text: shareData.shareText ?? shareData.title,
                        url: shareData.url
                    });
                    await this.trackShare(provider);
                } catch (error) {}
                return;
            }
            if (providerUrls[provider]) {
                window.open(providerUrls[provider], "_blank", "noopener,noreferrer");
                await this.trackShare(provider);
            }
        },
        openShareModal() {
            this.shareModalOpen = true;
            this.copied = false;
        }
    }'
>
    <x-ui.breadcrumbs
        class="relative z-10 mx-auto max-w-7xl px-5 pt-6 sm:px-8 lg:px-12"
        :items="[
            ['label' => __('Laman Utama'), 'url' => route('home'), 'icon' => 'home'],
            ['label' => __('Majlis Ilmu'), 'url' => route('events.index'), 'icon' => 'calendar', 'show_label' => true],
        ]"
    />

    <header class="relative isolate mt-6 overflow-hidden border-y border-[#183c35]/15 bg-[#173c34] text-white">
        @if($heroImage)
            <div class="absolute inset-0" aria-hidden="true">
                <img src="{{ $heroImage }}" alt="" class="size-full object-cover opacity-65" loading="eager">
            </div>
        @endif
        <div class="absolute inset-0 bg-[radial-gradient(circle_at_80%_20%,rgba(217,164,65,0.24),transparent_30%),linear-gradient(120deg,#173c34,#102b28)]" aria-hidden="true"></div>
        <div class="absolute -right-24 -top-24 size-80 rounded-full border border-[#e1b24f]/20" aria-hidden="true"></div>
        <div class="absolute -right-10 -top-10 size-52 rounded-full border border-[#e1b24f]/15" aria-hidden="true"></div>
        <div class="relative mx-auto grid max-w-7xl gap-10 px-5 py-12 sm:px-8 lg:grid-cols-[minmax(0,1fr)_minmax(250px,360px)] lg:items-end lg:px-12 lg:py-16">
            <div class="max-w-3xl">
                <div class="flex flex-wrap items-center gap-2 text-[11px] font-bold uppercase tracking-[0.22em] text-[#f2c867]">
                    <span class="inline-flex items-center gap-2"><span class="size-2 rounded-full bg-[#f2c867]"></span>{{ __('Majlis Ilmu') }}</span>
                    <span class="text-white/35">/</span>
                    <span>{{ $formatLabel }}</span>
                    @if($scheduleKindLabel)<span class="text-white/35">/</span><span>{{ $scheduleKindLabel }}</span>@endif
                </div>
                <div class="mt-6 flex max-w-2xl items-start gap-4">
                    <span class="mt-1 hidden h-24 w-1 shrink-0 rounded-full bg-[#f2c867] sm:block" aria-hidden="true"></span>
                    <h1 class="font-heading text-4xl font-semibold leading-[0.98] tracking-[-0.04em] sm:text-5xl lg:text-7xl">{{ $event->title }}</h1>
                </div>
                <div class="mt-7 flex flex-wrap items-center gap-2">
                    <span class="inline-flex items-center rounded-full border px-3 py-1.5 text-xs font-bold {{ $statusTone }}">{{ $statusLabel }}</span>
                    @foreach($classificationLabels->take(4) as $classificationLabel)
                        <span class="rounded-full border border-white/15 bg-white/10 px-3 py-1.5 text-xs font-semibold text-white/80">{{ $classificationLabel }}</span>
                    @endforeach
                </div>
                <div class="mt-8 grid max-w-2xl gap-4 text-sm sm:grid-cols-2">
                    <div class="flex gap-3">
                        <span class="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-xl bg-[#f2c867] text-[#173c34]">
                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 4h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5v12a2 2 0 002 2z" /><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M8 16h.01M12 16h.01" /></svg>
                        </span>
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[0.16em] text-white/45">{{ __('Bila') }}</p>
                            <p class="mt-1 font-semibold text-white">@if($event->starts_at){{ \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($event->starts_at, 'l, j F Y') }}<span class="block font-normal text-white/65">{{ \App\Support\Timezone\UserDateTimeFormatter::format($event->starts_at, 'h:i A') }}</span>@else{{ __('Tarikh Akan Dikemaskini') }}@endif</p>
                        </div>
                    </div>
                    <div class="flex gap-3" data-testid="event-hero-reference">
                        <span class="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-xl border border-[#f2c867]/40 bg-[#f2c867]/10 text-[#f2c867]">
                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332 1.253-4.5 1.253" /></svg>
                        </span>
                        <div><p class="text-xs font-bold uppercase tracking-[0.16em] text-white/45">{{ __('Rujukan') }}</p><p class="mt-1 font-semibold text-white">{{ $event->reference_study_subtitle ?: __('Tiada rujukan dinyatakan') }}</p></div>
                    </div>
                    <div class="flex gap-3 sm:col-span-2" data-testid="event-hero-location">
                        <span class="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-xl border border-white/15 bg-white/10 text-[#f2c867]">
                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" /></svg>
                        </span>
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[0.16em] text-white/45">{{ __('Di mana') }}</p>
                            @if($locationHref)<a href="{{ $locationHref }}" class="mt-1 block font-semibold text-white underline decoration-[#f2c867]/50 underline-offset-4 transition hover:text-[#f2c867] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#f2c867]">{{ $locationName }}</a>@else<p class="mt-1 font-semibold text-white">{{ $locationName }}</p>@endif
                            @if($locationShortLabel !== '')<p class="mt-1 text-white/65">{{ $locationShortLabel }}</p>@endif
                        </div>
                    </div>
                </div>
            </div>
            <div class="relative mx-auto w-full max-w-[360px] lg:mx-0 lg:justify-self-end">
                @if($eventHasPoster && $eventPosterPreviewUrl)
                    <button type="button" @click="posterModalOpen = true" data-signal-event="engagement.poster_opened" data-signal-category="engagement" data-signal-component="event_detail_hero" data-signal-control="poster" data-signal-entity-type="event" data-signal-entity-id="{{ $event->id }}" class="group block w-full overflow-hidden rounded-2xl bg-[#f8f2e6] p-2 text-left shadow-2xl shadow-black/25 ring-1 ring-white/20 transition motion-safe:hover:-translate-y-1 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#f2c867]" aria-label="{{ __('Lihat poster penuh') }}">
                        <div class="relative overflow-hidden rounded-xl {{ $posterAspectClass }} bg-slate-900"><img src="{{ $eventPosterPreviewUrl }}" alt="{{ $event->title }}" class="size-full object-contain" loading="eager"><span class="absolute bottom-3 right-3 rounded-full bg-[#173c34]/85 px-3 py-1.5 text-xs font-bold text-white backdrop-blur-sm">{{ __('Lihat poster') }}</span></div>
                    </button>
                @else
                    <div class="relative overflow-hidden rounded-2xl border border-white/15 bg-white/[0.04] p-6">
                        <div class="absolute right-5 top-5 size-20 rounded-full border border-[#f2c867]/25" aria-hidden="true"></div>
                        <div class="absolute right-10 top-10 size-10 rounded-full bg-[#f2c867]/15" aria-hidden="true"></div>
                        <p class="text-xs font-bold uppercase tracking-[0.2em] text-[#f2c867]">{{ __('Program') }}</p>
                        <p class="mt-16 max-w-[12rem] font-heading text-3xl leading-none text-white/90">{{ __('Catatan majlis') }}</p>
                        <p class="mt-4 text-sm leading-6 text-white/55">{{ __('Satu ruang untuk masa, manusia, tempat dan ilmu yang akan dikongsi.') }}</p>
                        <div class="mt-10 h-px bg-white/15"></div><p class="mt-4 text-xs font-bold uppercase tracking-[0.15em] text-white/40">ilmu360°</p>
                    </div>
                @endif
            </div>
        </div>
    </header>

    <div class="relative z-20 mx-auto -mt-5 max-w-7xl px-5 sm:px-8 lg:px-12">
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white p-3 shadow-xl shadow-slate-900/10">
            <div class="flex flex-wrap items-center gap-2">
                @if(!$eventActionsDisabled && (!$event->starts_at || !$event->starts_at->isPast()))
                    <button type="button" wire:click="toggleGoing" wire:loading.attr="disabled" data-signal-event="engagement.event_going_clicked" data-signal-category="engagement" data-signal-component="event_detail_actions" data-signal-control="going" data-signal-entity-type="event" data-signal-entity-id="{{ $event->id }}" data-signal-props='@json(['currently_going' => $this->isGoing])' class="inline-flex items-center gap-2 rounded-xl bg-[#173c34] px-4 py-3 text-sm font-bold text-white transition hover:bg-[#21594c] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#173c34]">
                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        {{ $this->isGoing ? __('Hadir') : __('Akan Hadir') }} @if($this->goingCount > 0)<span class="rounded-full bg-white/15 px-2 py-0.5 text-xs">{{ $this->goingCount }}</span>@endif
                    </button>
                @elseif($eventActionsDisabled)
                    <span class="inline-flex items-center gap-2 rounded-xl border px-4 py-3 text-sm font-bold {{ $isPostponedWithoutConfirmedTime ? 'border-amber-200 bg-amber-50 text-amber-800' : 'border-rose-200 bg-rose-50 text-rose-800' }}">{{ $isPostponedWithoutConfirmedTime ? __('Tarikh Belum Disahkan') : __('Majlis Dibatalkan') }}</span>
                @endif
                @auth
                    <button type="button" wire:click="toggleSave" wire:loading.attr="disabled" data-signal-event="engagement.event_save_clicked" data-signal-category="engagement" data-signal-component="event_detail_actions" data-signal-control="save" data-signal-entity-type="event" data-signal-entity-id="{{ $event->id }}" data-signal-props='@json(['currently_saved' => $this->isSaved])' class="inline-flex items-center gap-2 rounded-xl border px-4 py-3 text-sm font-bold transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#173c34] {{ $this->isSaved ? 'border-blue-200 bg-blue-50 text-blue-700' : 'border-slate-200 bg-white text-slate-700 hover:border-blue-200 hover:bg-blue-50' }}">
                        <svg class="size-4 {{ $this->isSaved ? 'fill-current' : '' }}" viewBox="0 0 24 24" fill="{{ $this->isSaved ? 'currentColor' : 'none' }}" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 5a2 2 0 012-2h10a2 2 0 012 2v16l-7-3.5L5 21V5z" /></svg>{{ $this->isSaved ? __('Disimpan') : __('Simpan') }}
                    </button>
                    <button type="button" wire:click="checkIn" wire:loading.attr="disabled" @disabled($checkInActionDisabled) data-signal-event="engagement.event_check_in_clicked" data-signal-category="engagement" data-signal-component="event_detail_actions" data-signal-control="check_in" data-signal-entity-type="event" data-signal-entity-id="{{ $event->id }}" data-signal-props='@json(['available' => !$checkInActionDisabled, 'checked_in' => $this->isCheckedIn])' @if($checkInActionDisabled && filled($checkInState['reason'])) title="{{ $checkInState['reason'] }}" @endif class="inline-flex items-center gap-2 rounded-xl border px-4 py-3 text-sm font-bold transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#173c34] {{ $this->isCheckedIn ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : ($checkInActionDisabled ? 'cursor-not-allowed border-slate-200 bg-slate-100 text-slate-400' : 'border-emerald-200 bg-white text-emerald-700 hover:bg-emerald-50') }}">
                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>{{ $this->isCheckedIn ? __('Sudah Check-in') : __('Check-in') }}
                    </button>
                @else
                    <button type="button" wire:click="toggleSave" wire:loading.attr="disabled" data-signal-event="engagement.event_save_clicked" data-signal-category="engagement" data-signal-component="event_detail_actions" data-signal-control="save" data-signal-entity-type="event" data-signal-entity-id="{{ $event->id }}" data-signal-props='@json(['currently_saved' => false])' class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-bold text-slate-700 transition hover:border-blue-200 hover:bg-blue-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#173c34]">
                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 5a2 2 0 012-2h10a2 2 0 012 2v16l-7-3.5L5 21V5z" /></svg>{{ __('Simpan') }}
                    </button>
                    <button type="button" wire:click="checkIn" wire:loading.attr="disabled" data-signal-event="engagement.event_check_in_clicked" data-signal-category="engagement" data-signal-component="event_detail_actions" data-signal-control="check_in" data-signal-entity-type="event" data-signal-entity-id="{{ $event->id }}" data-signal-props='@json(['available' => false, 'checked_in' => false])' class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-bold text-slate-500 transition hover:bg-slate-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#173c34]">
                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>{{ __('Check-in') }}
                    </button>
                    <a href="{{ \App\Support\Auth\IntendedRedirect::registerUrl(route('events.show', $event)) }}" class="inline-flex items-center rounded-xl border border-slate-200 px-4 py-3 text-sm font-bold text-slate-700 transition hover:border-[#173c34] hover:text-[#173c34]">{{ __('Daftar Akaun') }}</a>
                    <a href="{{ \App\Support\Auth\IntendedRedirect::loginUrl(route('events.show', $event)) }}" class="inline-flex items-center rounded-xl border border-slate-200 px-4 py-3 text-sm font-bold text-slate-700 transition hover:border-[#173c34] hover:text-[#173c34]">{{ __('Log Masuk') }}</a>
                @endauth
            </div>
            <div class="flex items-center gap-2">
                @if(!$eventActionsDisabled)
                    <div class="relative" x-data="{ calendarOpen: false }">
                        <button type="button" @click="calendarOpen = !calendarOpen" data-signal-event="engagement.calendar_opened" data-signal-category="engagement" data-signal-component="event_detail_actions" data-signal-control="calendar" data-signal-entity-type="event" data-signal-entity-id="{{ $event->id }}" class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-bold text-slate-700 transition hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#173c34]">
                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 4h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5v12a2 2 0 002 2z" /></svg><span class="hidden sm:inline">{{ __('Tambah ke Kalendar') }}</span>
                        </button>
                        <div x-show="calendarOpen" x-cloak @click.away="calendarOpen = false" class="absolute right-0 top-full z-30 mt-2 w-64 overflow-hidden rounded-xl border border-slate-200 bg-white p-2 shadow-2xl">
                            @foreach(['google' => 'Google Calendar', 'ics' => 'Apple / iCal (.ics)', 'outlook' => 'Outlook.com', 'office365' => 'Office 365', 'yahoo' => 'Yahoo Calendar'] as $calendarKey => $calendarLabel)
                                <a href="{{ $this->calendarLinks[$calendarKey] ?? '#' }}" @if($calendarKey !== 'ics') target="_blank" rel="noopener" @else download @endif class="block rounded-lg px-3 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-100">{{ $calendarLabel }}</a>
                            @endforeach
                        </div>
                    </div>
                @else
                    <span class="hidden rounded-xl border border-rose-200 bg-rose-50 px-3 py-3 text-xs font-bold text-rose-700 sm:inline">{{ __('Kalendar tidak tersedia untuk majlis dibatalkan.') }}</span>
                @endif
                <button type="button" @click="openShareModal()" data-signal-event="share.modal_opened" data-signal-category="share" data-signal-component="event_detail_actions" data-signal-control="share" data-signal-entity-type="event" data-signal-entity-id="{{ $event->id }}" class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-bold text-slate-700 transition hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#173c34]">
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684zm0 9.316a3 3 0 105.368 2.684z" /></svg><span class="hidden sm:inline">{{ __('Kongsi') }}</span>
                </button>
                @if(auth()->user()?->hasAnyRole(['super_admin', 'admin']))
                    <a
                        href="{{ \AIArmada\FilamentEvents\Resources\EventResource::getUrl('edit', ['record' => $event], panel: 'admin') }}"
                        target="_blank"
                        data-testid="event-admin-edit-button"
                        class="inline-flex items-center gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-bold text-amber-800 transition hover:border-amber-300 hover:bg-amber-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-500"
                    >
                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" /></svg>
                        {{ __('Edit') }}
                    </a>
                @endif
            </div>
        </div>
    </div>

    @if($latestChangeNotice = $this->activeChangeNotice)
        <div class="mx-auto mt-8 max-w-7xl px-5 sm:px-8 lg:px-12">
            <div class="flex gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950">
                <svg class="mt-0.5 size-5 shrink-0 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m0 3.75h.008M10.29 3.86l-8.1 14A2 2 0 003.92 21h16.16a2 2 0 001.73-3.14l-8.1-14a2 2 0 00-3.42 0z" /></svg>
                <div><p class="font-bold">{{ $latestChangeNotice->public_title ?? __('Kemaskini majlis') }}</p>@if(filled($latestChangeNotice->public_message ?? null))<p class="mt-1 leading-6">{{ $latestChangeNotice->public_message }}</p>@endif</div>
            </div>
        </div>
    @endif

    <main class="mx-auto mt-10 grid max-w-7xl gap-10 px-5 sm:px-8 lg:grid-cols-[minmax(0,1fr)_320px] lg:px-12">
        <div class="min-w-0 space-y-12">
            @if($hasAboutContent)
                <section id="about" class="scroll-mt-8">
                    <div class="flex items-end justify-between gap-4 border-b border-[#173c34]/15 pb-4"><div><p class="text-xs font-bold uppercase tracking-[0.2em] text-[#b27b1b]">{{ __('Konteks') }}</p><h2 class="mt-2 font-heading text-3xl font-semibold tracking-tight text-[#173c34]">{{ __('About this Event') }}</h2></div><span class="hidden font-mono text-xs text-slate-400 sm:block">01 / 06</span></div>
                    @if($descriptionHtml !== '')<div class="prose prose-slate mt-6 max-w-none leading-8 prose-headings:font-heading prose-a:text-[#176b58] prose-a:underline">{!! $descriptionHtml !!}</div>@endif
                    @if($classificationLabels->isNotEmpty())<div class="mt-6 flex flex-wrap gap-2">@foreach($classificationLabels as $classificationLabel)<span class="rounded-full border border-[#173c34]/15 bg-white px-3 py-1.5 text-xs font-semibold text-[#173c34]">{{ $classificationLabel }}</span>@endforeach</div>@endif
                </section>
            @endif

            @if($event->occurrences->isNotEmpty())
                <section id="schedule" data-testid="event-schedule-section" class="scroll-mt-8">
                    <div class="flex items-end justify-between gap-4 border-b border-[#173c34]/15 pb-4">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[0.2em] text-[#b27b1b]">{{ __('Program') }}</p>
                            <h2 class="mt-2 font-heading text-3xl font-semibold tracking-tight text-[#173c34]">{{ __('Event Schedule') }}</h2>
                        </div>
                        <span class="hidden font-mono text-xs text-slate-400 sm:block">{{ str_pad((string) $event->occurrences->count(), 2, '0', STR_PAD_LEFT) }} {{ __('dates') }}</span>
                    </div>

                    <div class="mt-6 space-y-6">
                        @foreach($event->occurrences as $occurrence)
                            @php
                                $occurrenceOwnCover = $occurrence->getFirstMedia('cover');
                                $occurrenceOwnCoverUrl = $occurrenceOwnCover?->getAvailableUrl(['banner', 'thumb']) ?? '';
                                $occurrenceCoverUrl = $occurrenceOwnCoverUrl !== '' ? $occurrenceOwnCoverUrl : ($eventCoverUrl !== '' ? $eventCoverUrl : null);
                                $occurrenceCoverIsInherited = $occurrenceOwnCoverUrl === '' && $occurrenceCoverUrl !== null;
                                $occurrenceExpression = $occurrence->timeExpressions->first();
                                $occurrenceTime = $occurrence->starts_at
                                    ? \App\Support\Timezone\UserDateTimeFormatter::format($occurrence->starts_at, 'h:i A')
                                    : __('TBC');
                                if ($occurrence->ends_at) {
                                    $occurrenceTime .= ' — ' . \App\Support\Timezone\UserDateTimeFormatter::format($occurrence->ends_at, 'h:i A');
                                }
                            @endphp
                            <article data-testid="event-occurrence-{{ $occurrence->id }}" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                                <div class="grid md:grid-cols-[190px_minmax(0,1fr)]">
                                    <div class="relative min-h-44 overflow-hidden bg-[#173c34]">
                                        @if($occurrenceCoverUrl)
                                            <img src="{{ $occurrenceCoverUrl }}" alt="{{ $occurrence->title ?: $event->title }}" class="size-full min-h-44 object-cover" loading="lazy">
                                            <div class="absolute inset-0 bg-gradient-to-t from-[#0c211e]/80 via-transparent to-transparent"></div>
                                            <div class="absolute inset-x-4 bottom-4 flex items-end justify-between gap-3 text-white">
                                                <span class="text-xs font-bold uppercase tracking-[0.16em]">{{ __('Tarikh') }}</span>
                                                @if($occurrenceCoverIsInherited)<span class="rounded-full bg-black/25 px-2 py-1 text-[10px] font-bold uppercase tracking-[0.12em] backdrop-blur-sm">{{ __('Majlis') }}</span>@endif
                                            </div>
                                        @else
                                            <div class="flex min-h-44 h-full flex-col justify-between bg-[radial-gradient(circle_at_top_right,_rgba(242,200,103,0.35),_transparent_48%),linear-gradient(145deg,#173c34,#285b4e)] p-5 text-white">
                                                <div class="flex items-center justify-between"><span class="font-mono text-xs font-bold tracking-[0.18em] text-[#f2c867]">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span><span class="size-2 rounded-full bg-[#f2c867]"></span></div>
                                                <div><p class="text-xs font-bold uppercase tracking-[0.16em] text-[#f2c867]">{{ __('Tarikh') }}</p>@if($occurrence->starts_at)<p class="mt-2 font-heading text-3xl font-semibold leading-none">{{ \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($occurrence->starts_at, 'd M') }}</p><p class="mt-1 text-sm text-white/70">{{ \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($occurrence->starts_at, 'Y') }}</p>@else<p class="mt-2 font-heading text-2xl font-semibold">{{ __('TBC') }}</p>@endif</div>
                                            </div>
                                        @endif
                                    </div>

                                    <div class="p-5 sm:p-6">
                                        <div class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-100 pb-4">
                                            <div>
                                                <p class="text-xs font-bold uppercase tracking-[0.16em] text-[#b27b1b]">{{ __('Occurrence') }} {{ $loop->iteration }}</p>
                                                <h3 class="mt-2 font-heading text-2xl font-semibold tracking-tight text-[#173c34]">{{ $occurrence->title ?: __('Majlis ilmu') }}</h3>
                                                <p class="mt-2 text-sm font-semibold text-slate-600">{{ $occurrenceTime }}</p>
                                                @if($occurrenceExpression?->display_label)<p class="mt-1 text-sm font-semibold text-[#b27b1b]">{{ $occurrenceExpression->display_label }}</p>@endif
                                            </div>
                                            @if($occurrence->status)<span class="rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-bold text-slate-600">{{ \Illuminate\Support\Str::headline((string) $occurrence->status) }}</span>@endif
                                        </div>

                                        @if($occurrence->sessions->isNotEmpty())
                                            <div class="mt-5 space-y-3">
                                                <p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-400">{{ __('Sessions') }} <span class="font-mono">({{ $occurrence->sessions->count() }})</span></p>
                                                @foreach($occurrence->sessions as $session)
                                                    @php
                                                        $sessionOwnCover = $session->getFirstMedia('cover');
                                                        $sessionOwnCoverUrl = $sessionOwnCover?->getAvailableUrl(['banner', 'thumb']) ?? '';
                                                        $sessionCoverUrl = $sessionOwnCoverUrl !== '' ? $sessionOwnCoverUrl : ($occurrenceCoverUrl ?? $eventCoverUrl ?: null);
                                                        $sessionCoverIsInherited = $sessionOwnCoverUrl === '' && $sessionCoverUrl !== null;
                                                        $sessionExpression = $session->timeExpressions->first();
                                                        $sessionPeople = $session->involvements->map(fn ($involvement) => $involvement->display_name ?: $involvement->involveable?->name)->filter()->unique()->values();
                                                        $sessionTime = $session->starts_at
                                                            ? \App\Support\Timezone\UserDateTimeFormatter::format($session->starts_at, 'h:i A')
                                                            : __('TBC');
                                                        if ($session->ends_at) {
                                                            $sessionTime .= ' — ' . \App\Support\Timezone\UserDateTimeFormatter::format($session->ends_at, 'h:i A');
                                                        }
                                                    @endphp
                                                    <div data-testid="event-session-{{ $session->id }}" class="grid gap-4 rounded-xl border border-slate-200 bg-[#fbfaf7] p-3 sm:grid-cols-[96px_minmax(0,1fr)] sm:p-4">
                                                        <div class="relative min-h-24 overflow-hidden rounded-lg bg-[#173c34]">
                                                            @if($sessionCoverUrl)
                                                                <img src="{{ $sessionCoverUrl }}" alt="{{ $session->title ?: __('Sesi majlis') }}" class="size-full min-h-24 object-cover" loading="lazy">
                                                                <div class="absolute inset-0 bg-gradient-to-t from-[#0c211e]/70 to-transparent"></div>
                                                                @if($sessionCoverIsInherited)<span class="absolute bottom-2 left-2 rounded-full bg-black/25 px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-[0.1em] text-white backdrop-blur-sm">{{ __('Majlis') }}</span>@endif
                                                            @else
                                                                <div class="flex min-h-24 flex-col items-center justify-center bg-[radial-gradient(circle_at_30%_20%,_rgba(242,200,103,0.4),_transparent_42%),#173c34] text-center text-white"><span class="font-mono text-[10px] font-bold uppercase tracking-[0.18em] text-[#f2c867]">{{ __('Sesi') }}</span><span class="mt-1 font-heading text-2xl font-semibold">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span></div>
                                                            @endif
                                                        </div>
                                                        <div class="min-w-0 border-l-2 border-[#e1b24f] pl-4">
                                                            <div class="flex flex-wrap items-baseline justify-between gap-2"><h4 class="font-heading text-xl font-semibold text-[#173c34]">{{ $session->title ?: __('Sesi majlis') }}</h4><span class="font-mono text-xs font-bold text-slate-500">{{ $sessionTime }}</span></div>
                                                            @if($sessionExpression?->display_label)<p class="mt-1 text-sm font-semibold text-[#b27b1b]">{{ $sessionExpression->display_label }}</p>@endif
                                                            @if(filled($session->summary))<p class="mt-2 text-sm leading-6 text-slate-600">{{ $session->summary }}</p>@endif
                                                            @if($sessionPeople->isNotEmpty())<p class="mt-3 text-xs font-semibold text-slate-500">{{ $sessionPeople->implode(', ') }}</p>@endif
                                                        </div>
                                                    </div>
                                                @endforeach
                                            </div>
                                        @else
                                            <div class="mt-5 rounded-xl border border-dashed border-slate-200 bg-[#fbfaf7] p-4 text-sm text-slate-600">{{ __('Tiada sesi berasingan untuk tarikh ini.') }}</div>
                                        @endif
                                    </div>
                                </div>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endif

            @if($event->persons->isNotEmpty())
                <section id="speakers" class="scroll-mt-8">
                    <div class="flex items-end justify-between gap-4 border-b border-[#173c34]/15 pb-4"><div><p class="text-xs font-bold uppercase tracking-[0.2em] text-[#b27b1b]">{{ __('People') }}</p><h2 class="mt-2 font-heading text-3xl font-semibold tracking-tight text-[#173c34]">{{ __('Speakers') }}</h2></div><span class="hidden font-mono text-xs text-slate-400 sm:block">03 / 06</span></div>
                    <div class="mt-6 grid gap-3 md:grid-cols-2">
                        @foreach($event->persons as $person)
                            @php
                                $personAvatar = $person->getFirstMediaUrl('avatar', 'thumb');
                            @endphp
                            <a href="{{ route('persons.show', $person) }}" class="group flex items-center gap-4 rounded-2xl border border-slate-200 bg-white p-4 transition hover:-translate-y-0.5 hover:border-[#b27b1b]/50 hover:shadow-lg focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#173c34]">
                                @if($personAvatar)<img src="{{ $personAvatar }}" alt="{{ $person->name }}" class="size-14 rounded-xl object-cover" loading="lazy">@else<span class="flex size-14 shrink-0 items-center justify-center rounded-xl bg-[#173c34] font-heading text-xl text-[#f2c867]">{{ \Illuminate\Support\Str::substr($person->name, 0, 1) }}</span>@endif
                                <span class="min-w-0"><span class="block truncate font-heading text-lg font-semibold text-[#173c34] group-hover:text-[#b27b1b]">{{ $person->name }}</span>@if($person->titleAssignments->first()?->title?->name)<span class="mt-1 block truncate text-sm text-slate-500">{{ $person->titleAssignments->first()->title->name }}</span>@endif</span>
                                <svg class="ml-auto size-5 shrink-0 text-slate-300 transition group-hover:translate-x-1 group-hover:text-[#b27b1b]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" /></svg>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif

            @if($keyPeopleByRole->isNotEmpty())
                <section id="roles" class="scroll-mt-8">
                    <div class="flex items-end justify-between gap-4 border-b border-[#173c34]/15 pb-4"><div><p class="text-xs font-bold uppercase tracking-[0.2em] text-[#b27b1b]">{{ __('People') }}</p><h2 class="mt-2 font-heading text-3xl font-semibold tracking-tight text-[#173c34]">{{ __('Peranan') }}</h2></div></div>
                    <div class="mt-6 grid gap-3 sm:grid-cols-2">
                        @foreach($keyPeopleByRole as $role => $keyPeople)
                            @php
                                $roleLabel = \App\Enums\EventKeyPersonRole::tryFrom((string) $role)?->getLabel() ?? \Illuminate\Support\Str::headline((string) $role);
                            @endphp
                            <div class="rounded-2xl border border-slate-200 bg-white p-5"><p class="text-xs font-bold uppercase tracking-[0.16em] text-[#b27b1b]">{{ $roleLabel }}</p><div class="mt-3 space-y-3">@foreach($keyPeople as $keyPerson)@if($keyPerson->person)<a href="{{ route('persons.show', $keyPerson->person) }}" class="flex items-center gap-3 font-semibold text-[#173c34] hover:text-[#b27b1b]"><span class="flex size-9 items-center justify-center rounded-full bg-[#e8f0e8] text-sm font-bold text-[#173c34]">{{ \Illuminate\Support\Str::substr($keyPerson->person->name, 0, 1) }}</span><span>{{ $keyPerson->person->name }}</span></a>@endif @endforeach</div></div>
                        @endforeach
                    </div>
                </section>
            @endif

            @if($event->references->isNotEmpty())
                <section id="references" data-testid="event-detail-references-section" class="scroll-mt-8">
                    <div class="flex items-end justify-between gap-4 border-b border-[#173c34]/15 pb-4"><div><p class="text-xs font-bold uppercase tracking-[0.2em] text-[#b27b1b]">{{ __('Sources') }}</p><h2 class="mt-2 font-heading text-3xl font-semibold tracking-tight text-[#173c34]">{{ __('References') }}</h2></div></div>
                    <div class="grid gap-5">
                        @foreach($event->references as $reference)
                            <a href="{{ route('references.show', $reference) }}" class="group flex gap-4 rounded-2xl border border-slate-200 bg-white p-5 transition hover:border-[#b27b1b]/50 hover:shadow-lg focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#173c34]"><span class="flex size-12 shrink-0 items-center justify-center rounded-xl bg-[#173c34] text-[#f2c867]"><svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18.5 16.5 18c-1.746 0-3.332.477-4.5 1.253" /></svg></span><span class="min-w-0"><span class="block font-heading text-xl font-semibold text-[#173c34] group-hover:text-[#b27b1b]">{{ $reference->title }}</span>@if(filled($reference->author))<span class="mt-1 block text-sm text-slate-500">{{ $reference->author }}</span>@endif@if(filled($reference->publisher))<span class="mt-1 block text-xs text-slate-400">{{ $reference->publisher }}</span>@endif</span><svg class="ml-auto mt-1 size-5 shrink-0 text-slate-300 transition group-hover:translate-x-1 group-hover:text-[#b27b1b]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" /></svg></a>
                        @endforeach
                    </div>
                </section>
            @endif

            @if($locationEntity || $primaryAddress)
                <section id="location" data-testid="event-detail-location-section" class="scroll-mt-8">
                    <div class="flex items-end justify-between gap-4 border-b border-[#173c34]/15 pb-4"><div><p class="text-xs font-bold uppercase tracking-[0.2em] text-[#b27b1b]">{{ __('Practical details') }}</p><h2 class="mt-2 font-heading text-3xl font-semibold tracking-tight text-[#173c34]">{{ __('Location') }}</h2></div></div>
                    <div class="mt-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">@if($heroImage)<img src="{{ $heroImage }}" alt="" class="h-44 w-full object-cover" loading="lazy">@endif<div class="grid gap-6 p-5 sm:p-6 lg:grid-cols-[minmax(0,1fr)_minmax(240px,0.9fr)]"><div>@if($locationHref)<a href="{{ $locationHref }}" class="font-heading text-2xl font-semibold text-[#173c34] hover:text-[#b27b1b]">{{ $locationName }}</a>@else<h3 class="font-heading text-2xl font-semibold text-[#173c34]">{{ $locationName }}</h3>@endif@if($primaryAddress)<address class="mt-4 not-italic leading-7 text-slate-600">@if(filled($primaryAddress->line1))<span class="block">{{ $primaryAddress->line1 }}</span>@endif @if(filled($primaryAddress->line2))<span class="block">{{ $primaryAddress->line2 }}</span>@endif<span class="block">{{ implode(', ', array_filter([$primaryAddress->postcode, $addressDisplayLines['locality'] ?? null])) }}</span>@if(filled($addressDisplayLines['regional'] ?? null))<span class="block">{{ $addressDisplayLines['regional'] }}</span>@endif</address>@endif<div class="mt-5 flex flex-wrap gap-2">@if($wazeNavUrl)<a href="{{ $wazeNavUrl }}" target="_blank" rel="noopener" data-signal-event="navigation.external_link_clicked" data-signal-category="navigation" data-signal-component="event_detail_location" data-signal-control="waze" data-signal-entity-type="event" data-signal-entity-id="{{ $event->id }}" class="inline-flex items-center gap-2 rounded-xl bg-[#e8f7fa] px-3 py-2 text-sm font-bold text-cyan-800 transition hover:bg-cyan-100">Waze <span aria-hidden="true">↗</span></a>@endif @if($googleMapsNavUrl)<a href="{{ $googleMapsNavUrl }}" target="_blank" rel="noopener" data-signal-event="navigation.external_link_clicked" data-signal-category="navigation" data-signal-component="event_detail_location" data-signal-control="google_maps" data-signal-entity-type="event" data-signal-entity-id="{{ $event->id }}" class="inline-flex items-center gap-2 rounded-xl bg-[#eef2ff] px-3 py-2 text-sm font-bold text-indigo-800 transition hover:bg-indigo-100">Google Maps <span aria-hidden="true">↗</span></a>@endif</div>@if($locationPhone || $locationEmail)<div class="mt-6 space-y-2 border-t border-slate-100 pt-5 text-sm">@if($locationPhone)<a href="tel:{{ $locationPhone }}" class="block font-semibold text-[#173c34] hover:text-[#b27b1b]">{{ $locationPhone }}</a>@endif @if($locationEmail)<a href="mailto:{{ $locationEmail }}" class="block break-all font-semibold text-[#173c34] hover:text-[#b27b1b]">{{ $locationEmail }}</a>@endif</div>@endif</div>@if($mapEmbedUrl)<div class="overflow-hidden rounded-xl border border-slate-200 bg-slate-100"><iframe src="{{ $mapEmbedUrl }}" class="h-64 w-full" loading="lazy" referrerpolicy="no-referrer-when-downgrade" allowfullscreen title="{{ __('Peta Lokasi') }}"></iframe></div>@endif</div></div>
                </section>
            @endif

            @if($galleryImages !== [])
                <section id="gallery" class="scroll-mt-8">
                    <div class="flex items-end justify-between gap-4 border-b border-[#173c34]/15 pb-4"><div><p class="text-xs font-bold uppercase tracking-[0.2em] text-[#b27b1b]">{{ __('Archive') }}</p><h2 class="mt-2 font-heading text-3xl font-semibold tracking-tight text-[#173c34]">{{ __('Event Gallery') }}</h2></div></div>
                    <div class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">@foreach($galleryImages as $image)<a href="{{ $image['url'] }}" target="_blank" rel="noopener" class="group overflow-hidden rounded-2xl bg-slate-200 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#173c34]"><img src="{{ $image['thumb'] }}" alt="{{ $image['alt'] }}" class="aspect-[4/3] w-full object-cover transition duration-500 group-hover:scale-105" loading="lazy"></a>@endforeach</div>
                </section>
            @endif
        </div>

        <aside class="space-y-5 lg:sticky lg:top-6 lg:self-start">
            <section class="rounded-2xl bg-[#173c34] p-6 text-white shadow-xl shadow-[#173c34]/15">
                <p class="text-xs font-bold uppercase tracking-[0.2em] text-[#f2c867]">{{ __('At a glance') }}</p>
                <div class="mt-6 space-y-5"><div><p class="text-xs font-bold uppercase tracking-[0.14em] text-white/45">{{ __('Format') }}</p><p class="mt-1 font-semibold">{{ $formatLabel }}</p></div><div><p class="text-xs font-bold uppercase tracking-[0.14em] text-white/45">{{ __('Bahasa') }}</p><p class="mt-1 font-semibold">{{ $languageLabel }}</p></div>@if($genderLabel)<div><p class="text-xs font-bold uppercase tracking-[0.14em] text-white/45">{{ __('Audience') }}</p><p class="mt-1 font-semibold">{{ $genderLabel }}</p></div>@endif @if($ageGroupLabels->isNotEmpty())<div><p class="text-xs font-bold uppercase tracking-[0.14em] text-white/45">{{ __('Age group') }}</p><p class="mt-1 font-semibold">{{ $ageGroupLabels->implode(', ') }}</p></div>@endif</div>
                @if($audienceLabels->isNotEmpty())<div class="mt-6 border-t border-white/15 pt-5"><p class="text-xs font-bold uppercase tracking-[0.14em] text-white/45">{{ __('Sasaran') }}</p><div class="mt-3 flex flex-wrap gap-2">@foreach($audienceLabels as $audienceLabel)<span class="rounded-full bg-white/10 px-3 py-1.5 text-xs font-semibold text-white/80">{{ $audienceLabel }}</span>@endforeach</div></div>@endif
                @if($audienceProfile?->is_child_friendly)<p class="mt-5 rounded-xl bg-[#f2c867]/15 px-3 py-2 text-xs font-semibold text-[#f7d98b]">{{ __('Mesra kanak-kanak') }}</p>@endif
            </section>

            @if($event->accessPolicy?->registration_required)
                @php
                    $registrationClosed = $event->accessPolicy->closes_at && $event->accessPolicy->closes_at->isPast();
                    $registrationNotOpen = $event->accessPolicy->opens_at && $event->accessPolicy->opens_at->isFuture();
                    $atCapacity = $event->accessPolicy->capacity && $this->registrationsCount >= $event->accessPolicy->capacity;
                @endphp
                <section id="register" class="rounded-2xl border border-[#b27b1b]/35 bg-[#fff9ed] p-6 shadow-sm"><p class="text-xs font-bold uppercase tracking-[0.2em] text-[#b27b1b]">{{ __('Registration Required') }}</p><h2 class="mt-2 font-heading text-2xl font-semibold text-[#173c34]">{{ __('Register') }}</h2><p class="mt-2 text-sm leading-6 text-slate-600">{{ __('Reserve your place for this majlis.') }}</p>@if($event->accessPolicy->capacity)<p class="mt-4 text-xs font-semibold text-slate-500">{{ $this->registrationsCount }} / {{ $event->accessPolicy->capacity }} {{ __('places') }}</p>@endif @if($eventActionsDisabled)<p class="mt-5 rounded-xl bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">{{ __('Pendaftaran ditutup kerana status majlis.') }}</p>@elseif($registrationClosed || $atCapacity)<p class="mt-5 rounded-xl bg-slate-100 px-3 py-2 text-sm font-semibold text-slate-600">{{ $atCapacity ? __('Pendaftaran penuh') : __('Pendaftaran telah ditutup') }}</p>@elseif($registrationNotOpen)<p class="mt-5 rounded-xl bg-slate-100 px-3 py-2 text-sm font-semibold text-slate-600">{{ __('Dibuka') }} {{ \App\Support\Timezone\UserDateTimeFormatter::format($event->accessPolicy->opens_at, 'j M, h:i A') }}</p>@else<a href="#register" @click.prevent="registerOpen = true" data-signal-event="submission.event_registration_opened" data-signal-category="submission" data-signal-component="event_detail_registration" data-signal-control="register_open" data-signal-entity-type="event" data-signal-entity-id="{{ $event->id }}" data-signal-props='@json(['registration_mode' => $registrationMode->value])' class="mt-5 inline-flex w-full items-center justify-center rounded-xl bg-[#173c34] px-4 py-3 font-bold text-white transition hover:bg-[#21594c] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#173c34]">{{ __('Register') }}</a>@endif</section>
            @endif

            @if($event->ticketTypes->isNotEmpty())
                        <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"><p class="text-xs font-bold uppercase tracking-[0.2em] text-[#b27b1b]">{{ __('Admission') }}</p><h2 class="mt-2 font-heading text-2xl font-semibold text-[#173c34]">{{ __('Passes & tickets') }}</h2><div class="mt-5 space-y-3">@foreach($event->ticketTypes as $ticketType)@php
                                $ticketPrice = $ticketType->price === null ? null : \AIArmada\CommerceSupport\Support\MoneyFormatter::formatMinor((int) $ticketType->price, $ticketType->currency ?: 'MYR');
                            @endphp<div class="rounded-xl border border-slate-200 p-4"><div class="flex items-start justify-between gap-3"><p class="font-semibold text-[#173c34]">{{ $ticketType->name }}</p>@if($ticketPrice !== null)<span class="shrink-0 text-sm font-bold text-[#b27b1b]">{{ $ticketType->price === 0 ? __('Percuma') : $ticketPrice }}</span>@endif</div>@if(filled($ticketType->description))<p class="mt-2 text-sm leading-6 text-slate-500">{{ $ticketType->description }}</p>@endif @if($ticketType->seatingOptions->isNotEmpty())<p class="mt-3 text-xs font-semibold text-slate-500">{{ __('Tempat duduk tersedia') }}</p>@endif</div>@endforeach</div></section>
            @elseif($event->accessPolicy?->walk_in_allowed)
                <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"><p class="text-xs font-bold uppercase tracking-[0.2em] text-[#b27b1b]">{{ __('Admission') }}</p><h2 class="mt-2 font-heading text-2xl font-semibold text-[#173c34]">{{ __('Walk-in welcome') }}</h2><p class="mt-2 text-sm leading-6 text-slate-600">{{ __('No ticket is required for entry.') }}</p></section>
            @endif

            @if($event->seatMaps->isNotEmpty())
                <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"><p class="text-xs font-bold uppercase tracking-[0.2em] text-[#b27b1b]">{{ __('Seating') }}</p><h2 class="mt-2 font-heading text-2xl font-semibold text-[#173c34]">{{ __('Numbered seating') }}</h2><div class="mt-4 space-y-2 text-sm text-slate-600">@foreach($event->seatMaps as $seatMap)<p class="flex items-center justify-between gap-3"><span>{{ $seatMap->name }}</span><span class="font-mono text-xs text-slate-400">{{ $seatMap->sections->count() }} {{ __('sections') }}</span></p>@endforeach</div></section>
            @endif

            @if($organizer && (!$institutionSameAsLocation || $organizer->getKey() !== $locationEntity?->getKey()))
                <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"><p class="text-xs font-bold uppercase tracking-[0.2em] text-[#b27b1b]">{{ __('Host') }}</p>@if($organizerHref)<a href="{{ $organizerHref }}" class="mt-2 block font-heading text-xl font-semibold text-[#173c34] hover:text-[#b27b1b]">{{ $organizer->name }}</a>@else<p class="mt-2 font-heading text-xl font-semibold text-[#173c34]">{{ $organizer->name }}</p>@endif<p class="mt-2 text-sm leading-6 text-slate-600">{{ __('Penganjur majlis') }}</p></section>
            @endif
        </aside>
    </main>

    @if($relatedEvents->isNotEmpty())
        <section class="mx-auto mt-16 max-w-7xl border-t border-[#173c34]/15 px-5 pt-10 sm:px-8 lg:px-12"><div class="flex items-end justify-between gap-4"><div><p class="text-xs font-bold uppercase tracking-[0.2em] text-[#b27b1b]">{{ __('Continue exploring') }}</p><h2 class="mt-2 font-heading text-3xl font-semibold tracking-tight text-[#173c34]">{{ __('Related Events') }}</h2></div><a href="{{ route('events.index') }}" class="hidden text-sm font-bold text-[#173c34] underline decoration-[#b27b1b] underline-offset-4 sm:block">{{ __('Lihat semua') }}</a></div><div class="mt-6 grid gap-4 md:grid-cols-3">@foreach($relatedEvents as $relatedEvent)<a href="{{ route('events.show', $relatedEvent) }}" class="group overflow-hidden rounded-2xl border border-slate-200 bg-white transition hover:-translate-y-1 hover:shadow-xl focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#173c34]">@if($relatedEvent->card_image_url)<img src="{{ $relatedEvent->card_image_url }}" alt="{{ $relatedEvent->title }}" class="aspect-[16/9] w-full object-cover" loading="lazy">@else<div class="flex aspect-[16/9] items-end bg-[#173c34] p-5 text-[#f2c867]"><span class="font-heading text-2xl leading-none">{{ $relatedEvent->title }}</span></div>@endif<div class="p-5"><p class="font-heading text-xl font-semibold leading-tight text-[#173c34] group-hover:text-[#b27b1b]">{{ $relatedEvent->title }}</p>@if($relatedEvent->starts_at)<p class="mt-3 text-xs font-semibold text-slate-500">{{ \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($relatedEvent->starts_at, 'j M Y, h:i A') }}</p>@endif</div></a>@endforeach</div></section>
    @endif

    @if($eventHasPoster && $eventPosterOriginalUrl)
        <div x-show="posterModalOpen" x-cloak x-transition.opacity @keydown.escape.window="posterModalOpen = false" class="fixed inset-0 z-50 flex items-center justify-center bg-[#0c211e]/95 p-4" role="dialog" aria-modal="true" aria-label="{{ __('Poster majlis') }}"><button type="button" @click="posterModalOpen = false" class="absolute right-4 top-4 inline-flex size-11 items-center justify-center rounded-full bg-white/10 text-white transition hover:bg-white/20 focus-visible:outline focus-visible:outline-2 focus-visible:outline-white" aria-label="{{ __('Tutup') }}"><svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg></button><button type="button" @click="posterModalOpen = false" class="max-h-full max-w-full focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#f2c867]"><img src="{{ $eventPosterOriginalUrl }}" alt="{{ $event->title }}" class="max-h-[90vh] max-w-full rounded-xl object-contain shadow-2xl"></button></div>
    @endif

    @if($event->accessPolicy?->registration_required && !$eventActionsDisabled)
        <div x-show="registerOpen" x-cloak x-transition.opacity @keydown.escape.window="registerOpen = false" class="fixed inset-0 z-50 flex items-center justify-center bg-[#0c211e]/70 p-4 backdrop-blur-sm" role="dialog" aria-modal="true" aria-label="{{ __('Register') }}"><div @click.away="registerOpen = false" class="w-full max-w-lg overflow-hidden rounded-2xl bg-white shadow-2xl"><div class="flex items-start justify-between bg-[#173c34] p-6 text-white"><div><p class="text-xs font-bold uppercase tracking-[0.2em] text-[#f2c867]">{{ __('Registration') }}</p><h2 class="mt-2 font-heading text-2xl font-semibold">{{ __('Register') }}</h2></div><button type="button" @click="registerOpen = false" class="rounded-lg p-2 text-white/70 transition hover:bg-white/10 hover:text-white" aria-label="{{ __('Tutup') }}"><svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg></button></div><form action="{{ route('events.register', $event) }}" method="POST" data-signal-submit-event="submission.event_registration_submitted" data-signal-category="submission" data-signal-component="event_detail_registration" data-signal-control="registration_form" data-signal-entity-type="event" data-signal-entity-id="{{ $event->id }}" data-signal-props='@json(['registration_mode' => $registrationMode->value])' class="space-y-5 p-6">@csrf<div><label for="event-registration-name" class="block text-sm font-bold text-slate-700">{{ __('Name') }} <span class="text-rose-500">*</span></label><input id="event-registration-name" type="text" name="name" required class="mt-2 h-12 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 text-slate-900 outline-none transition focus:border-[#173c34] focus:bg-white focus:ring-4 focus:ring-[#173c34]/10"></div><div><label for="event-registration-email" class="block text-sm font-bold text-slate-700">{{ __('Email') }}</label><input id="event-registration-email" type="email" name="email" class="mt-2 h-12 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 text-slate-900 outline-none transition focus:border-[#173c34] focus:bg-white focus:ring-4 focus:ring-[#173c34]/10"></div><div><label for="event-registration-phone" class="block text-sm font-bold text-slate-700">{{ __('Phone') }}</label><input id="event-registration-phone" type="tel" name="phone" class="mt-2 h-12 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 text-slate-900 outline-none transition focus:border-[#173c34] focus:bg-white focus:ring-4 focus:ring-[#173c34]/10"></div><p class="rounded-xl bg-slate-50 p-3 text-xs leading-5 text-slate-600">{{ __('Please provide either email or phone number so we can send your registration confirmation.') }}</p><div class="flex gap-3"><button type="button" @click="registerOpen = false" class="flex-1 rounded-xl border border-slate-200 px-4 py-3 font-bold text-slate-700 transition hover:bg-slate-50">{{ __('Cancel') }}</button><button type="submit" class="flex-1 rounded-xl bg-[#173c34] px-4 py-3 font-bold text-white transition hover:bg-[#21594c]">{{ __('Register') }}</button></div></form></div></div>
    @endif

    <div x-show="shareModalOpen" x-cloak x-transition.opacity @keydown.escape.window="shareModalOpen = false" class="fixed inset-0 z-50 flex items-center justify-center bg-[#0c211e]/70 p-4 backdrop-blur-sm" role="dialog" aria-modal="true" aria-label="{{ __('Share Preview') }}"><div @click.away="shareModalOpen = false" class="w-full max-w-md overflow-hidden rounded-2xl bg-white shadow-2xl"><div class="bg-[#173c34] p-6 text-white"><p class="text-xs font-bold uppercase tracking-[0.2em] text-[#f2c867]">{{ __('Share') }}</p><h2 class="mt-2 font-heading text-2xl font-semibold">{{ __('Share Preview') }}</h2><p class="mt-2 line-clamp-2 text-sm text-white/65">{{ $event->title }}</p></div><div class="grid grid-cols-2 gap-3 p-6">@foreach(['whatsapp' => 'WhatsApp', 'telegram' => 'Telegram', 'facebook' => 'Facebook', 'x' => 'X', 'email' => __('Email')] as $shareProvider => $shareLabel)<button type="button" @click="share('{{ $shareProvider }}')" data-signal-event="share.provider_clicked" data-signal-category="share" data-signal-component="event_detail_share_modal" data-signal-control="{{ $shareProvider }}" data-signal-entity-type="event" data-signal-entity-id="{{ $event->id }}" class="rounded-xl border border-slate-200 px-4 py-3 text-sm font-bold text-slate-700 transition hover:border-[#173c34] hover:bg-slate-50">{{ $shareLabel }}</button>@endforeach<button type="button" @click="share('copy_link')" data-signal-event="share.provider_clicked" data-signal-category="share" data-signal-component="event_detail_share_modal" data-signal-control="copy_link" data-signal-entity-type="event" data-signal-entity-id="{{ $event->id }}" class="col-span-2 rounded-xl bg-[#173c34] px-4 py-3 text-sm font-bold text-white transition hover:bg-[#21594c]"><span x-text="copied ? @js(__('Link copied to clipboard!')) : @js(__('Copy Link'))"></span></button><button type="button" @click="share('native_share')" class="col-span-2 rounded-xl border border-slate-200 px-4 py-3 text-sm font-bold text-slate-700 transition hover:bg-slate-50">{{ __('Share from device') }}</button></div><button type="button" @click="shareModalOpen = false" class="w-full border-t border-slate-100 px-6 py-4 text-sm font-bold text-slate-500 transition hover:bg-slate-50">{{ __('Cancel') }}</button></div></div>
</div>
