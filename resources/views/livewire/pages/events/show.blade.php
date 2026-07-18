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
    $lat = $venueAddress?->latitude ?? $institutionAddress?->latitude;
    $lng = $venueAddress?->longitude ?? $institutionAddress?->longitude;
    $galleryImages = $this->galleryImages;
    $keyPeopleByRole = $this->keyPeopleByRole;
    $registrationMode = $this->registrationMode();
    $shareLinks = $this->shareLinks;
    $sharePreviewImage = $event->card_image_url;
    $sharePreviewDateTime = __('TBC');
    if ($event->starts_at) {
        $sharePreviewDateTime = \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($event->starts_at, 'd M Y')
            . ', '
            . \App\Support\Timezone\UserDateTimeFormatter::format($event->starts_at, 'h:i A');

        if ($event->ends_at && ! $event->isPrayerRelative()) {
            $sharePreviewDateTime .= ' — ' . \App\Support\Timezone\UserDateTimeFormatter::format($event->ends_at, 'h:i A');
        }
    }
    $eventTimeStatus = $this->eventTimeStatus;
    $descriptionHtml = $this->descriptionHtml;
    $hasAboutContent = $this->hasAboutContent;
    $isCancelledStatus = $event->status instanceof \App\States\EventStatus\Cancelled || (string) $event->status === 'cancelled';
    $checkInState = $this->checkInState;
    $canCheckIn = $checkInState['available'];
    $checkInReason = $checkInState['reason'];
    $isCheckedIn = $this->isCheckedIn;
    $isAuthenticated = auth()->check();
    $checkInActionDisabled = $isAuthenticated && ! $canCheckIn && ! $isCheckedIn;

    $shareData = [
        'title' => $event->title,
        'text' => Str::limit($event->description_text, 100),
        'url' => route('events.show', $event),
        'sourceUrl' => route('events.show', $event),
        'shareText' => trim($event->title . ' - ' . config('app.name')),
        'fallbackTitle' => $event->title,
        'payloadEndpoint' => route('dawah-share.payload'),
    ];
    $copyMessage = __('Link copied to clipboard!');
    $copyPrompt = __('Copy this link:');
    $eventHasPoster = $event->hasMedia('poster');
    $eventPosterDisplayAspectRatio = $eventHasPoster ? $event->poster_display_aspect_ratio : '16:9';
    $eventPosterIsPortrait = $eventHasPoster && $eventPosterDisplayAspectRatio === '4:5';
    $eventPosterFrameAspectClass = match ($eventPosterDisplayAspectRatio) {
        '4:5' => 'aspect-[4/5]',
        '16:9' => 'aspect-[16/9]',
        default => 'aspect-[16/9]',
    };
    $eventPosterCardMaxWidthClass = match ($eventPosterDisplayAspectRatio) {
        '4:5' => 'max-w-[260px] sm:max-w-[300px] lg:max-w-[360px]',
        '16:9' => 'max-w-[420px] sm:max-w-[540px] lg:max-w-[640px]',
        default => 'max-w-[420px] sm:max-w-[540px] lg:max-w-[640px]',
    };
    $eventPosterPreviewUrl = $eventHasPoster ? $event->getFirstMedia('poster')?->getAvailableUrl(['preview', 'card', 'thumb']) : null;
    $eventPosterOriginalUrl = $eventHasPoster ? $event->getFirstMediaUrl('poster') : null;
    // Hero atmospheric background:
    // event cover -> institution cover -> venue cover/main -> organizer institution cover -> gradient fallback.
    // The event poster is NEVER used as background — it is a factual flyer and must be displayed clearly.
    $heroImage = $event->getFirstMedia('cover')?->getAvailableUrl(['banner', 'card', 'preview', 'thumb']) ?? '';
    if (!$heroImage) {
        $heroImage = $event->institution?->getFirstMedia('cover')?->getAvailableUrl(['banner']) ?? '';
    }
    if (!$heroImage) {
        $heroImage = $event->venue?->getFirstMedia('main')?->getAvailableUrl(['banner'])
            ?? $event->venue?->getFirstMedia('cover')?->getAvailableUrl(['banner'])
            ?? '';
    }

    if (!$heroImage && $event->organizer instanceof \App\Models\Institution) {
        $heroImage = $event->organizer->getFirstMedia('cover')?->getAvailableUrl(['banner']) ?? '';
    }

    $eventFormat = $event->delivery_mode;
    $eventFormatValue = $eventFormat instanceof \App\Enums\EventFormat
        ? $eventFormat->value
        : (is_string($eventFormat) ? $eventFormat : null);
    $genderValue = $event->gender instanceof \App\Enums\EventGenderRestriction
        ? $event->gender->value
        : (is_string($event->gender) ? $event->gender : null);
    $genderLabel = $event->gender instanceof \App\Enums\EventGenderRestriction
        ? $event->gender->getLabel()
        : \App\Enums\EventGenderRestriction::tryFrom((string) $genderValue)?->getLabel();
    $isOnlineFormat = $eventFormatValue === \App\Enums\EventFormat::Online->value;
    $isHybridFormat = $eventFormatValue === \App\Enums\EventFormat::Hybrid->value;
    $heroFallbackGradient = match ($eventFormatValue) {
        \App\Enums\EventFormat::Online->value => 'from-sky-950 via-indigo-950 to-slate-950',
        \App\Enums\EventFormat::Hybrid->value => 'from-cyan-950 via-slate-950 to-emerald-950',
        default => 'from-emerald-950 via-slate-950 to-teal-950',
    };

    // Format label
    $formatLabel = $eventFormat instanceof \App\Enums\EventFormat
        ? $eventFormat->getLabel()
        : match ($eventFormatValue) {
            \App\Enums\EventFormat::Online->value => __('Dalam talian'),
            \App\Enums\EventFormat::Hybrid->value => __('Hibrid'),
            default => __('Fizikal'),
        };

    // Schedule kind label
    $scheduleKindLabel = $event->schedule_kind?->label();

    // Age group labels
    $ageGroupLabels = [];
    if ($event->age_group instanceof \Illuminate\Support\Collection && $event->age_group->isNotEmpty()) {
        $ageGroupLabels = $event->age_group->map(fn($ag) => $ag->getLabel())->all();
    }

    // Language
    $primaryLanguage = $event->languages->first();
    $languageName = $primaryLanguage?->name ?? __('Bahasa Melayu');

    // Organize package classifications by taxonomy code
    $classifications = $event->classifications->loadMissing('term');
    $classificationsByTaxonomy = $classifications->groupBy('taxonomy_code');

    // Schedule state
    $scheduleState = (string) $event->primaryOccurrence?->status;
    $latestChangeNotice = $this->activeChangeNotice;
    $replacementEvent = $this->replacementEvent;
    $isPostponedWithoutConfirmedTime = $this->isPostponedWithoutConfirmedTime;
    $eventActionsDisabled = $this->eventActionsDisabled;

    // Full address parts for venue/institution
    $addressDisplayLines = \App\Support\Location\AddressHierarchyFormatter::displayLines($primaryAddress);
    $locationHierarchyParts = \App\Support\Location\AddressHierarchyFormatter::parts($primaryAddress);
    $fullAddressParts = array_filter([
        $primaryAddress?->line1,
        $primaryAddress?->line2,
    ]);
    $fullAddressCityLine = $addressDisplayLines['locality'] ?? '';
    $fullAddressStateName = $addressDisplayLines['regional'] ?? null;
    $fullAddressDistrictName = $locationHierarchyParts[0] ?? null;

    // Location short label (for hero) — deduplicate when district == state (e.g. "Kuala Lumpur")
    $locationDistrict = $locationHierarchyParts[0] ?? null;
    $locationState = $locationHierarchyParts[1] ?? null;
    $locationShortLabel = implode(', ', array_filter(
        $locationDistrict !== $locationState ? [$locationDistrict, $locationState] : [$locationState]
    ));

    $hasPhysicalHeroLocation = $event->venue !== null || $event->institution !== null;
    if ($hasPhysicalHeroLocation) {
        $heroLocationTitle = $event->venue?->name ?? $event->institution?->name ?? __('Lokasi');
        $heroLocationSubtitle = $locationShortLabel !== '' ? $locationShortLabel : __('Lokasi fizikal');
        $heroLocationIcon = 'map-pin';
    } elseif ($isOnlineFormat) {
        $heroLocationTitle = __('Acara Dalam Talian');
        $heroLocationSubtitle = __('Sertai melalui pautan siaran langsung');
        $heroLocationIcon = 'globe';
    } elseif ($isHybridFormat) {
        $heroLocationTitle = __('Mod Hibrid');
        $heroLocationSubtitle = __('Sertai secara fizikal atau maya');
        $heroLocationIcon = 'arrows-right-left';
    } else {
        $heroLocationTitle = __('Lokasi Akan Dikemaskini');
        $heroLocationSubtitle = __('Butiran lokasi akan diumumkan kemudian');
        $heroLocationIcon = 'clock';
    }
    $showHeroLocationChip = true;

    // Navigation URLs: prefer stored URLs, fall back to lat/lng
    $wazeNavUrl = filled($primaryAddress?->waze_url) ? (string) $primaryAddress->waze_url : ($lat && $lng ? "https://www.waze.com/ul?ll={$lat},{$lng}&navigate=yes" : null);
    $googleMapsNavUrl = filled($primaryAddress?->google_maps_url) ? (string) $primaryAddress->google_maps_url : ($lat && $lng ? "https://www.google.com/maps/dir/?api=1&destination={$lat},{$lng}" : null);

    // Institution contacts
    $institutionEmail = null;
    $institutionPhone = null;
    if ($event->institution && $event->institution->relationLoaded('contactMethods')) {
        $institutionEmail = $event->institution->contactMethods->firstWhere('type', \AIArmada\Contacting\Enums\ContactMethodType::Email->value)?->value;
        $institutionPhone = $event->institution->contactMethods->firstWhere('type', \AIArmada\Contacting\Enums\ContactMethodType::Phone->value)?->value;
    }

    // Canonical location entity for location UI blocks.
    $locationEntity = $event->venue ?: $event->institution;
    $locationCover = null;
    $locationHref = null;
    if ($locationEntity instanceof \App\Models\Institution) {
        $locationCover = $locationEntity->getFirstMedia('cover')?->getAvailableUrl(['banner']) ?? null;
        $locationHref = route('institutions.show', $locationEntity);
    } elseif ($locationEntity instanceof \App\Models\Venue) {
        $locationCover = $locationEntity->getFirstMedia('main')?->getAvailableUrl(['banner'])
            ?? $locationEntity->getFirstMedia('cover')?->getAvailableUrl(['banner'])
            ?? null;
        $locationHref = route('venues.show', $locationEntity);
    }

    // Single context card below speakers:
    // - show Organizer only when organizer differs from location
    // - otherwise show Location
    $organizerEntity = $event->organizer ?: $event->institution;

    $organizerSameAsLocation = false;
    if ($organizerEntity && $locationEntity) {
        $organizerSameAsLocation = get_class($organizerEntity) === get_class($locationEntity)
            && (string) $organizerEntity->getKey() === (string) $locationEntity->getKey();
    }

    $contextEntity = null;
    $contextKind = null;

    if ($organizerEntity && !$organizerSameAsLocation) {
        $contextEntity = $organizerEntity;
        $contextKind = 'organizer';
    } elseif ($locationEntity) {
        $contextEntity = $locationEntity;
        $contextKind = 'location';
    } elseif ($organizerEntity) {
        $contextEntity = $organizerEntity;
        $contextKind = 'organizer';
    }

    $showContextCard = $contextEntity !== null;
    $contextTitle = $contextKind === 'organizer' ? __('Organizer') : __('Location');
    $contextLabel = $contextKind === 'organizer' ? __('Organized by') : __('Location');
    $contextName = $contextEntity?->name ?? __('TBC');
    $contextThumb = null;
    $contextCover = null;
    $contextHref = null;
    $contextPhone = null;
    $contextEmail = null;

    if ($contextEntity instanceof \App\Models\Institution) {
        $contextHref = route('institutions.show', $contextEntity);
        $contextThumb = $contextEntity->getFirstMediaUrl('logo', 'thumb');
        $contextCover = $contextEntity->getFirstMediaUrl('cover', 'banner');

        if ($contextEntity->relationLoaded('contactMethods')) {
            $contextPhone = $contextEntity->contactMethods->firstWhere('type', \AIArmada\Contacting\Enums\ContactMethodType::Phone->value)?->value;
            $contextEmail = $contextEntity->contactMethods->firstWhere('type', \AIArmada\Contacting\Enums\ContactMethodType::Email->value)?->value;
        }
    } elseif ($contextEntity instanceof \App\Models\Speaker) {
        $contextHref = route('speakers.show', $contextEntity);
        $contextThumb = $contextEntity->getFirstMediaUrl('avatar', 'thumb');
        $contextCover = $contextEntity->getFirstMediaUrl('cover', 'banner');
    } elseif ($contextEntity instanceof \App\Models\Venue) {
        $contextHref = route('venues.show', $contextEntity);
        $contextThumb = $contextEntity->getFirstMediaUrl('cover', 'thumb');
        $contextCover = $contextEntity->getFirstMediaUrl('cover', 'banner');
    }
@endphp

<div class="min-h-screen bg-slate-50 pb-28 lg:pb-16" x-data='{
        registerOpen: false,
        shareModalOpen: false,
        copied: false,
        shareData: @json($shareData),
        copyMessage: @json($copyMessage),
        copyPrompt: @json($copyPrompt),
        trackEndpoint: @json(route("dawah-share.track")),
        providerQueryParameter: @json(config("dawah-share.provider_query_parameter", "channel")),
        attributedShareData: null,
        async resolveShareData() {
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
                    Accept: "application/json",
                },
            });

            if (!response.ok) {
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

            const csrfToken = document.querySelector("meta[name=csrf-token]")?.content;

            if (! csrfToken) {
                return;
            }

            await fetch(this.trackEndpoint, {
                method: "POST",
                headers: {
                    Accept: "application/json",
                    "Content-Type": "application/json",
                    "X-CSRF-TOKEN": csrfToken,
                },
                body: JSON.stringify({
                    provider,
                    tracking_token: shareData.tracking_token,
                }),
            });
        },
        async nativeShare() {
            const shareData = await this.sharePayloadForChannel("native_share");
            if (navigator.share) {
                try {
                    await navigator.share(shareData);
                    await this.trackShare("native_share");
                } catch (error) {
                }

                return;
            }

            await this.copyLink();
        },
        async copyLink(shouldTrack = true, provider = "copy_link") {
            const shareData = await this.sharePayloadForChannel(provider);
            if (navigator.clipboard) {
                navigator.clipboard.writeText(shareData.url).then(async () => {
                    if (shouldTrack) {
                        await this.trackShare(provider);
                    }

                    this.copied = true;
                    setTimeout(() => { this.copied = false; }, 2000);
                }, async () => {
                    window.prompt(this.copyPrompt, shareData.url);

                    if (shouldTrack) {
                        await this.trackShare(provider);
                    }
                });
                return;
            }

            window.prompt(this.copyPrompt, shareData.url);

            if (shouldTrack) {
                await this.trackShare(provider);
            }
        },
        openShareModal() {
            this.shareModalOpen = true;
            this.copied = false;
        },
    }'>

    {{-- ==============================
    HERO SECTION (Full Width)
    ============================== --}}
    {{-- ==============================
    CINEMATIC HERO
    Atmosphere: institution/venue cover or gradient — NEVER the poster.
    With poster: grid layout, poster as clear card on right.
    Without poster: full-width text, speakers emerge from bottom-right.
    ============================== --}}
    <div class="relative w-full overflow-hidden bg-slate-950 pt-20 lg:pt-0" @if($eventHasPoster)
    x-data="{ posterModalOpen: false }" @endif>

        {{-- ── ATMOSPHERE LAYER ── --}}
        <div class="absolute inset-0">
            @if($heroImage)
                {{-- Poster/image becomes the atmosphere at ~65% opacity --}}
                <img src="{{ $heroImage }}" alt="" class="size-full object-cover opacity-65" loading="eager"
                    aria-hidden="true">
            @else
                {{-- No image: enriched format-aware gradient base --}}
                <div class="absolute inset-0 bg-gradient-to-br {{ $heroFallbackGradient }}" aria-hidden="true"></div>
                {{-- Islamic hexagonal tessellation --}}
                <div class="absolute inset-0 opacity-15" aria-hidden="true">
                    <svg width="100%" height="100%" xmlns="http://www.w3.org/2000/svg">
                        <defs>
                            <pattern id="hero-hex" x="0" y="0" width="56" height="96" patternUnits="userSpaceOnUse">
                                <polygon points="28,4 52,18 52,46 28,60 4,46 4,18" fill="none" stroke="white"
                                    stroke-width="1.5" />
                                <polygon points="28,48 52,62 52,90 28,104 4,90 4,62" fill="none" stroke="white"
                                    stroke-width="1.5" />
                            </pattern>
                        </defs>
                        <rect width="100%" height="100%" fill="url(#hero-hex)" />
                    </svg>
                </div>
                {{-- Ambient glow orbs --}}
                <div class="absolute -left-40 -top-40 size-[600px] rounded-full {{ $isOnlineFormat ? 'bg-sky-500/40' : ($isHybridFormat ? 'bg-cyan-400/40' : 'bg-emerald-500/40') }} blur-[120px]"
                    aria-hidden="true"></div>
                <div class="absolute -bottom-20 right-20 size-[500px] rounded-full {{ $isOnlineFormat ? 'bg-indigo-400/30' : ($isHybridFormat ? 'bg-emerald-400/30' : 'bg-teal-400/30') }} blur-[100px]"
                    aria-hidden="true"></div>

                {{-- Geometric glass accents to keep hero intentional even without media --}}
                <div class="pointer-events-none absolute inset-y-0 right-0 hidden w-[42%] lg:block" aria-hidden="true">
                    <div
                        class="absolute right-14 top-24 size-56 rotate-6 rounded-[2.25rem] border border-white/30 bg-white/10 backdrop-blur-sm">
                    </div>
                    <div
                        class="absolute bottom-14 right-28 size-44 -rotate-6 rounded-[1.8rem] border border-white/20 bg-white/5">
                    </div>
                    <div
                        class="absolute inset-y-14 left-0 w-px bg-gradient-to-b from-transparent via-white/25 to-transparent">
                    </div>
                </div>
            @endif

            {{-- Bottom-to-top gradient: protects text legibility, fades into page --}}
            <div class="absolute inset-0 bg-gradient-to-t from-slate-950 via-slate-950/75 to-slate-950/40"
                aria-hidden="true"></div>
            {{-- Left-to-right gradient: protects the left text column --}}
            <div class="absolute inset-0 bg-gradient-to-r from-slate-950/90 via-slate-950/50 to-transparent"
                aria-hidden="true"></div>
            <div class="noise-overlay"></div>
        </div>

        {{-- ── CONTENT ── --}}
        <div class="container relative mx-auto px-5 sm:px-8 lg:px-12">

            @if($eventHasPoster)
                {{-- ── POSTER LAYOUT: 12-col grid — event details left (7 cols), poster card right (5 cols) ── --}}
                <div
                    class="relative z-10 grid items-end gap-8 pt-12 pb-10 lg:grid-cols-12 lg:gap-12 {{ $eventPosterIsPortrait ? 'lg:pt-32' : 'lg:pt-12' }} lg:pb-16">

                    {{-- Left (7 cols): event details --}}
                    <div class="order-2 flex flex-col lg:order-1 lg:col-span-7">

                        <x-events.show.hero-details
                            :event="$event"
                            :format-label="$formatLabel"
                            :schedule-kind-label="$scheduleKindLabel"
                            :hero-reference-title="$event->reference_study_subtitle"
                            :show-hero-location-chip="$showHeroLocationChip"
                            :hero-location-icon="$heroLocationIcon"
                            :hero-location-title="$heroLocationTitle"
                            :hero-location-subtitle="$heroLocationSubtitle"
                            :location-href="$locationHref"
                        />

                    </div>{{-- /left col --}}

                    {{-- Right (5 cols): poster card — displayed clearly as a factual flyer --}}
                    <div class="order-1 flex justify-center lg:order-2 lg:col-span-5 lg:justify-end animate-fade-in-up"
                        style="--reveal-d: 100ms;">
                        <div class="relative w-full {{ $eventPosterCardMaxWidthClass }}">
                            {{-- Subtle glow behind the card --}}
                            <div class="absolute -inset-3 rounded-3xl bg-white/5 blur-xl" aria-hidden="true"></div>
                            {{-- Poster card button → opens fullscreen --}}
                            <button type="button" @click="posterModalOpen = true"
                                data-signal-event="engagement.poster_opened"
                                data-signal-category="engagement"
                                data-signal-component="event_detail_hero"
                                data-signal-control="poster"
                                data-signal-entity-type="event"
                                data-signal-entity-id="{{ $event->id }}"
                                class="group relative block w-full overflow-hidden rounded-2xl shadow-2xl ring-1 ring-white/15 transition-transform duration-300 hover:scale-[1.02] focus:outline-none"
                                aria-label="{{ __('Lihat poster penuh') }}"
                                data-poster-aspect="{{ $eventPosterDisplayAspectRatio }}">
                                <div class="relative w-full {{ $eventPosterFrameAspectClass }} bg-slate-900">
                                    <img src="{{ $eventPosterPreviewUrl }}" alt="{{ $event->title }}"
                                        class="h-full w-full object-contain bg-slate-900" loading="lazy">
                                    {{-- Tap-to-expand hint --}}
                                    <div
                                        class="absolute bottom-3 right-3 flex items-center gap-1.5 rounded-full bg-slate-950/60 px-3 py-1.5 text-xs font-semibold text-white/90 backdrop-blur-sm ring-1 ring-white/10 transition-opacity duration-300 group-hover:opacity-0">
                                        <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                            stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                d="M21 21l-5-5m2-5a7 7 0 11-14 0 7 7 0 0114 0zM10 7v3m0 0v3m0-3h3m-3 0H7" />
                                        </svg>
                                        {{ __('Lihat Penuh') }}
                                    </div>
                                </div>
                            </button>
                        </div>

                        {{-- Fullscreen Poster Modal --}}
                        <template x-teleport="body">
                            <div x-show="posterModalOpen" x-cloak x-transition.opacity
                                @keydown.escape.window="posterModalOpen = false"
                                class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/95 p-4 backdrop-blur-xl sm:p-8"
                                aria-modal="true" role="dialog">
                                <button type="button" @click="posterModalOpen = false"
                                    class="absolute right-4 top-4 z-10 inline-flex size-12 items-center justify-center rounded-full bg-white/10 text-white backdrop-blur-md transition hover:bg-white/20 hover:scale-110 sm:right-8 sm:top-8">
                                    <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                        stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                                <div @click.away="posterModalOpen = false" x-show="posterModalOpen"
                                    x-transition:enter="transition ease-out duration-300"
                                    x-transition:enter-start="opacity-0 scale-95"
                                    x-transition:enter-end="opacity-100 scale-100"
                                    x-transition:leave="transition ease-in duration-200"
                                    x-transition:leave-start="opacity-100 scale-100"
                                    x-transition:leave-end="opacity-0 scale-95" class="relative max-h-full max-w-full">
                                    <img src="{{ $eventPosterOriginalUrl }}" alt="{{ $event->title }}"
                                        class="max-h-[90vh] max-w-full w-auto rounded-2xl object-contain shadow-2xl ring-1 ring-white/20">
                                </div>
                            </div>
                        </template>
                    </div>
                </div>{{-- /poster grid --}}

            @else
                {{-- ── NO-POSTER LAYOUT: full-width text column, speakers emerge from bottom-right ── --}}
                <div
                    class="relative z-10 flex flex-col justify-end pb-16 pt-12 lg:max-w-[60%] lg:pb-24 lg:pt-12 xl:max-w-[55%]">

                    <x-events.show.hero-details
                        :event="$event"
                        :format-label="$formatLabel"
                        :schedule-kind-label="$scheduleKindLabel"
                        :hero-reference-title="$event->reference_study_subtitle"
                        :show-hero-location-chip="$showHeroLocationChip"
                        :hero-location-icon="$heroLocationIcon"
                        :hero-location-title="$heroLocationTitle"
                        :hero-location-subtitle="$heroLocationSubtitle"
                        :location-href="$locationHref"
                    />

                </div>{{-- /text column --}}

            @endif{{-- /poster vs no-poster --}}
        </div>{{-- /container --}}
    </div>{{-- /hero --}}

    {{-- ==============================
    FLOATING ACTION BAR
    ============================== --}}
    <div class="container relative z-30 mx-auto -mt-12 mb-12 hidden px-5 sm:px-8 lg:block lg:px-12">
        <div
            class="flex flex-wrap items-center justify-between gap-4 rounded-3xl border border-white/40 bg-white/80 p-4 shadow-2xl shadow-slate-200/50 backdrop-blur-xl sm:p-6">
            <div class="flex flex-wrap items-center gap-3">
                @if(!$eventActionsDisabled)
                    @if(!$event->starts_at || !$event->starts_at->isPast())
                        <button type="button" wire:click="toggleGoing" wire:loading.attr="disabled"
                            data-signal-event="engagement.event_going_clicked"
                            data-signal-category="engagement"
                            data-signal-component="event_detail_actions"
                            data-signal-control="going"
                            data-signal-entity-type="event"
                            data-signal-entity-id="{{ $event->id }}"
                            data-signal-props='@json(['currently_going' => $isGoing])'
                            class="inline-flex items-center gap-2 rounded-2xl px-6 py-3 text-sm font-bold shadow-sm transition-all
                                                                                                                                                                                                                    {{ $isGoing
                        ? 'bg-emerald-600 text-white shadow-emerald-200'
                        : 'bg-slate-900 text-white hover:-translate-y-0.5 hover:bg-emerald-600 hover:shadow-lg hover:shadow-emerald-500/30' }}">
                            <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                @if($isGoing)
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                @else
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                @endif
                            </svg>
                            {{ $isGoing ? __('Hadir') : __('Akan Hadir') }}
                            @if($goingCount > 0)
                                <span class="ml-1 rounded-full bg-white/20 px-2 py-0.5 text-xs">{{ $goingCount }}</span>
                            @endif
                        </button>
                    @endif

                    <button type="button" wire:click="checkIn" wire:loading.attr="disabled"
                        @disabled($checkInActionDisabled)
                        data-signal-event="engagement.event_check_in_clicked"
                        data-signal-category="engagement"
                        data-signal-component="event_detail_actions"
                        data-signal-control="check_in"
                        data-signal-entity-type="event"
                        data-signal-entity-id="{{ $event->id }}"
                        data-signal-props='@json(['available' => ! $checkInActionDisabled, 'checked_in' => $isCheckedIn])'
                        @if($checkInActionDisabled && filled($checkInReason)) title="{{ $checkInReason }}" @endif
                        class="inline-flex items-center gap-2 rounded-2xl border-2 px-5 py-3 text-sm font-bold transition-all
                        {{ $isCheckedIn
                            ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                            : ($checkInActionDisabled
                                ? 'cursor-not-allowed border-slate-200 bg-slate-100 text-slate-400'
                                : 'border-emerald-200 bg-white text-emerald-700 hover:border-emerald-300 hover:bg-emerald-50') }}">
                        <svg class="size-5 {{ $isCheckedIn ? 'text-emerald-600' : '' }}" fill="none" viewBox="0 0 24 24"
                            stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                        </svg>
                        {{ $isCheckedIn ? __('Sudah Check-in') : __('Check-in') }}
                    </button>

                    <button type="button" wire:click="toggleSave" wire:loading.attr="disabled"
                        data-signal-event="engagement.event_save_clicked"
                        data-signal-category="engagement"
                        data-signal-component="event_detail_actions"
                        data-signal-control="save"
                        data-signal-entity-type="event"
                        data-signal-entity-id="{{ $event->id }}"
                        data-signal-props='@json(['currently_saved' => $isSaved])'
                        class="inline-flex items-center gap-2 rounded-2xl border-2 px-5 py-3 text-sm font-bold transition-all
                        {{ $isSaved
        ? 'border-blue-200 bg-blue-50 text-blue-600'
        : 'border-slate-200 bg-white text-slate-700 hover:border-blue-200 hover:bg-blue-50 hover:text-blue-600' }}">
                        <svg class="size-5 {{ $isSaved ? 'fill-blue-500' : '' }}" viewBox="0 0 24 24"
                            fill="{{ $isSaved ? 'currentColor' : 'none' }}" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M5 5a2 2 0 012-2h10a2 2 0 012 2v16l-7-3.5L5 21V5z" />
                        </svg>
                        {{ $isSaved ? __('Disimpan') : __('Simpan') }}
                    </button>
                @else
                    <span class="inline-flex items-center gap-2 rounded-2xl border {{ $isPostponedWithoutConfirmedTime ? 'border-amber-200 bg-amber-50 text-amber-700' : 'border-rose-200 bg-rose-50 text-rose-700' }} px-4 py-3 text-sm font-bold">
                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="{{ $isPostponedWithoutConfirmedTime ? 'M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z' : 'M18.364 5.636a9 9 0 010 12.728m-12.728 0a9 9 0 010-12.728m12.728 12.728L5.636 5.636' }}" />
                        </svg>
                        {{ $isPostponedWithoutConfirmedTime ? __('Tarikh Belum Disahkan') : __('Majlis Dibatalkan') }}
                    </span>
                @endif

                @if($eventActionsDisabled && !$isCancelledStatus)
                    <button type="button" wire:click="toggleSave" wire:loading.attr="disabled"
                        data-signal-event="engagement.event_save_clicked"
                        data-signal-category="engagement"
                        data-signal-component="event_detail_actions"
                        data-signal-control="save"
                        data-signal-entity-type="event"
                        data-signal-entity-id="{{ $event->id }}"
                        data-signal-props='@json(['currently_saved' => $isSaved])'
                        class="inline-flex items-center gap-2 rounded-2xl border-2 px-5 py-3 text-sm font-bold transition-all
                        {{ $isSaved
        ? 'border-blue-200 bg-blue-50 text-blue-600'
        : 'border-slate-200 bg-white text-slate-700 hover:border-blue-200 hover:bg-blue-50 hover:text-blue-600' }}">
                        <svg class="size-5 {{ $isSaved ? 'fill-blue-500' : '' }}" viewBox="0 0 24 24"
                            fill="{{ $isSaved ? 'currentColor' : 'none' }}" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M5 5a2 2 0 012-2h10a2 2 0 012 2v16l-7-3.5L5 21V5z" />
                        </svg>
                        {{ $isSaved ? __('Disimpan') : __('Simpan') }}
                    </button>
                @endif

                @if(!$eventActionsDisabled)
                    <div class="relative" x-data="{ open: false }">
                        <button type="button" @click="open = !open"
                            data-signal-event="engagement.calendar_opened"
                            data-signal-category="engagement"
                            data-signal-component="event_detail_actions"
                            data-signal-control="calendar"
                            data-signal-entity-type="event"
                            data-signal-entity-id="{{ $event->id }}"
                            class="inline-flex items-center gap-2 rounded-2xl border-2 border-slate-200 bg-white px-5 py-3 text-sm font-bold text-slate-700 transition-all hover:border-slate-300 hover:bg-slate-50">
                            <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                            </svg>
                            {{ __('Tambah ke Kalendar') }}
                            <svg class="size-4 transition-transform duration-300" :class="{ 'rotate-180': open }"
                                fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                            </svg>
                        </button>

                        <div x-show="open" @click.away="open = false" x-transition:enter="transition ease-out duration-200"
                            x-transition:enter-start="opacity-0 -translate-y-2 scale-95"
                            x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                            x-transition:leave="transition ease-in duration-150"
                            x-transition:leave-start="opacity-100 translate-y-0 scale-100"
                            x-transition:leave-end="opacity-0 -translate-y-2 scale-95"
                            class="absolute left-0 z-50 mt-3 w-72 max-w-[90vw] overflow-hidden rounded-2xl border border-slate-200/60 bg-white/95 p-2 shadow-2xl backdrop-blur-xl">
                            <a href="{{ $this->calendarLinks['google'] }}" target="_blank" rel="noopener"
                                data-signal-event="engagement.calendar_provider_clicked"
                                data-signal-category="engagement"
                                data-signal-component="event_detail_actions"
                                data-signal-control="calendar_google"
                                data-signal-entity-type="event"
                                data-signal-entity-id="{{ $event->id }}"
                                data-signal-props='@json(['provider' => 'google'])'
                                class="flex items-center gap-3 rounded-xl px-4 py-3 transition hover:bg-slate-100">
                                <svg class="size-5 text-[#4285F4]" viewBox="0 0 24 24" fill="currentColor">
                                    <path
                                        d="M19.5 22h-15A2.5 2.5 0 012 19.5v-15A2.5 2.5 0 014.5 2H9v2H4.5a.5.5 0 00-.5.5v15a.5.5 0 00.5.5h15a.5.5 0 00.5-.5V15h2v4.5a2.5 2.5 0 01-2.5 2.5z" />
                                    <path d="M8 10h2v2H8v-2zm0 4h2v2H8v-2zm4-4h2v2h-2v-2zm0 4h2v2h-2v-2zm4-4h2v2h-2v-2z" />
                                </svg>
                                <span class="text-sm font-bold text-slate-700">Google Calendar</span>
                            </a>
                            <a href="{{ $this->calendarLinks['ics'] }}" download
                                data-signal-event="engagement.calendar_provider_clicked"
                                data-signal-category="engagement"
                                data-signal-component="event_detail_actions"
                                data-signal-control="calendar_ics"
                                data-signal-entity-type="event"
                                data-signal-entity-id="{{ $event->id }}"
                                data-signal-props='@json(['provider' => 'ics'])'
                                class="flex items-center gap-3 rounded-xl px-4 py-3 transition hover:bg-slate-100">
                                <svg class="size-5 text-slate-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                    stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                                </svg>
                                <span class="text-sm font-bold text-slate-700">Apple / iCal (.ics)</span>
                            </a>
                            <a href="{{ $this->calendarLinks['outlook'] }}" target="_blank" rel="noopener"
                                data-signal-event="engagement.calendar_provider_clicked"
                                data-signal-category="engagement"
                                data-signal-component="event_detail_actions"
                                data-signal-control="calendar_outlook"
                                data-signal-entity-type="event"
                                data-signal-entity-id="{{ $event->id }}"
                                data-signal-props='@json(['provider' => 'outlook'])'
                                class="flex items-center gap-3 rounded-xl px-4 py-3 transition hover:bg-slate-100">
                                <svg class="size-5 text-[#0078D4]" viewBox="0 0 24 24" fill="currentColor">
                                    <path
                                        d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z" />
                                </svg>
                                <span class="text-sm font-bold text-slate-700">Outlook.com</span>
                            </a>
                            <a href="{{ $this->calendarLinks['office365'] }}" target="_blank" rel="noopener"
                                data-signal-event="engagement.calendar_provider_clicked"
                                data-signal-category="engagement"
                                data-signal-component="event_detail_actions"
                                data-signal-control="calendar_office365"
                                data-signal-entity-type="event"
                                data-signal-entity-id="{{ $event->id }}"
                                data-signal-props='@json(['provider' => 'office365'])'
                                class="flex items-center gap-3 rounded-xl px-4 py-3 transition hover:bg-slate-100">
                                <svg class="size-5 text-[#D83B01]" viewBox="0 0 24 24" fill="currentColor">
                                    <path
                                        d="M21 5H3a1 1 0 00-1 1v12a1 1 0 001 1h18a1 1 0 001-1V6a1 1 0 00-1-1zM3 6h18v2H3V6zm0 12V10h18v8H3z" />
                                </svg>
                                <span class="text-sm font-bold text-slate-700">Office 365</span>
                            </a>
                            <a href="{{ $this->calendarLinks['yahoo'] }}" target="_blank" rel="noopener"
                                data-signal-event="engagement.calendar_provider_clicked"
                                data-signal-category="engagement"
                                data-signal-component="event_detail_actions"
                                data-signal-control="calendar_yahoo"
                                data-signal-entity-type="event"
                                data-signal-entity-id="{{ $event->id }}"
                                data-signal-props='@json(['provider' => 'yahoo'])'
                                class="flex items-center gap-3 rounded-xl px-4 py-3 transition hover:bg-slate-100">
                                <svg class="size-5 text-[#6001D2]" viewBox="0 0 24 24" fill="currentColor">
                                    <path
                                        d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z" />
                                </svg>
                                <span class="text-sm font-bold text-slate-700">Yahoo Calendar</span>
                            </a>
                        </div>
                    </div>
                @else
                    <span class="inline-flex items-center gap-2 rounded-2xl border {{ $isPostponedWithoutConfirmedTime ? 'border-amber-200 bg-amber-50 text-amber-700' : 'border-rose-200 bg-rose-50 text-rose-700' }} px-4 py-3 text-sm font-bold">
                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="{{ $isPostponedWithoutConfirmedTime ? 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z' : 'M18.364 5.636a9 9 0 010 12.728m-12.728 0a9 9 0 010-12.728m12.728 12.728L5.636 5.636' }}" />
                        </svg>
                        {{ $isPostponedWithoutConfirmedTime ? __('Kalendar ditutup sehingga tarikh disahkan.') : __('Kalendar tidak tersedia untuk majlis dibatalkan.') }}
                    </span>
                @endif
            </div>

            <div class="flex items-center gap-3">
                <button type="button" @click="openShareModal()"
                    data-signal-event="share.modal_opened"
                    data-signal-category="share"
                    data-signal-component="event_detail_actions"
                    data-signal-control="share"
                    data-signal-entity-type="event"
                    data-signal-entity-id="{{ $event->id }}"
                    class="inline-flex items-center gap-2 rounded-2xl border-2 border-slate-200 bg-white px-5 py-3 text-sm font-bold text-slate-700 transition-all hover:border-slate-300 hover:bg-slate-50">
                    <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z" />
                    </svg>
                    {{ __('Kongsi') }}
                </button>

                @can('update', $event)
                    <a href="{{ \AIArmada\FilamentEvents\Resources\EventResource::getUrl('edit', ['record' => $event]) }}"
                        target="_blank" rel="noopener noreferrer"
                        class="inline-flex items-center gap-2 rounded-2xl border-2 border-amber-200 bg-amber-50 px-5 py-3 text-sm font-bold text-amber-700 transition-all hover:bg-amber-100">
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M16.862 3.487a2.25 2.25 0 113.182 3.182L7.5 19.213l-4.5.9.9-4.5L16.862 3.487z" />
                        </svg>
                        <span class="hidden sm:inline">{{ __('Edit Event') }}</span>
                    </a>
                @endcan
            </div>
        </div>
    </div>

    {{-- ==============================
    STATUS BANNERS
    ============================== --}}
    <div class="container mx-auto px-5 sm:px-8 lg:px-12">
        @php
            $hasModerationStatusBanner = $event->status instanceof \App\States\EventStatus\Pending || $isCancelledStatus;
        @endphp

        @if($latestChangeNotice)
            <div class="relative z-30 -mt-4 mb-4">
                <div class="rounded-2xl border {{ $latestChangeNotice->severity === \App\Enums\EventChangeSeverity::Urgent ? 'border-rose-200 bg-rose-50' : 'border-amber-200 bg-amber-50' }} p-4 shadow-sm">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div class="flex items-start gap-3">
                            <svg class="mt-0.5 size-5 shrink-0 {{ $latestChangeNotice->severity === \App\Enums\EventChangeSeverity::Urgent ? 'text-rose-500' : 'text-amber-500' }}" fill="none" viewBox="0 0 24 24"
                                stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M12 9v3.75m0 3.75h.008v.008H12v-.008zM10.29 3.86 1.82 18a2.25 2.25 0 0 0 1.93 3.38h16.5A2.25 2.25 0 0 0 22.18 18L13.71 3.86a2.25 2.25 0 0 0-3.42 0z" />
                            </svg>
                            <div>
                                <p class="text-sm font-black {{ $latestChangeNotice->severity === \App\Enums\EventChangeSeverity::Urgent ? 'text-rose-800' : 'text-amber-800' }}">
                                    {{ $latestChangeNotice->update_type->publicBadgeLabel() }}
                                </p>
                                <p class="mt-1 text-sm leading-6 {{ $latestChangeNotice->severity === \App\Enums\EventChangeSeverity::Urgent ? 'text-rose-700' : 'text-amber-700' }}">
                                    {{ $latestChangeNotice->message ?: __('Maklumat majlis ini telah dikemas kini. Sila semak butiran terkini sebelum hadir.') }}
                                </p>
                                @if($latestChangeNotice->published_at)
                                    <p class="mt-2 text-xs font-semibold {{ $latestChangeNotice->severity === \App\Enums\EventChangeSeverity::Urgent ? 'text-rose-600' : 'text-amber-600' }}">
                                        {{ __('Diumumkan') }}:
                                        {{ \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($latestChangeNotice->published_at, 'j M Y') }},
                                        {{ \App\Support\Timezone\UserDateTimeFormatter::format($latestChangeNotice->published_at, 'h:i A') }}
                                    </p>
                                @endif
                            </div>
                        </div>

                        @if($replacementEvent)
                            <a href="{{ route('events.show', $replacementEvent) }}" wire:navigate
                                class="inline-flex shrink-0 items-center justify-center gap-2 rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-emerald-700">
                                {{ __('Lihat Majlis Pengganti') }}
                                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                                </svg>
                            </a>
                        @endif
                    </div>
                </div>
            </div>
        @endif

        @if($event->status instanceof \App\States\EventStatus\Pending)
            <div class="relative z-30 -mt-4 mb-4">
                <div class="flex items-start gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-4 shadow-sm">
                    <svg class="mt-0.5 size-5 shrink-0 text-amber-500" fill="none" viewBox="0 0 24 24"
                        stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" />
                    </svg>
                    <div>
                        <p class="text-sm font-bold text-amber-800">{{ __('Menunggu Kelulusan') }}</p>
                        <p class="mt-0.5 text-sm text-amber-700">
                            {{ __('Majlis ini belum disahkan oleh pentadbir. Sila pastikan sendiri kesahihan majlis ini sebelum hadir.') }}
                        </p>
                    </div>
                </div>
            </div>
        @elseif($isCancelledStatus)
            <div class="relative z-30 -mt-4 mb-4">
                <div class="flex items-start gap-3 rounded-2xl border border-rose-200 bg-rose-50 p-4 shadow-sm">
                    <svg class="mt-0.5 size-5 shrink-0 text-rose-500" fill="none" viewBox="0 0 24 24"
                        stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M18.364 5.636a9 9 0 010 12.728m-12.728 0a9 9 0 010-12.728m12.728 12.728L5.636 5.636" />
                    </svg>
                    <div>
                        <p class="text-sm font-bold text-rose-800">{{ __('Majlis Dibatalkan') }}</p>
                        <p class="mt-0.5 text-sm text-rose-700">
                            {{ __('Majlis ini telah dibatalkan. Semak pengumuman penganjur untuk maklumat terkini.') }}
                        </p>
                    </div>
                </div>
            </div>
        @endif

        @if($eventTimeStatus === 'past')
            <div class="relative z-30 {{ $hasModerationStatusBanner ? '' : '-mt-4' }} mb-4">
                <div class="flex items-center gap-3 rounded-2xl border border-slate-200 bg-slate-100 p-4">
                    <svg class="size-5 shrink-0 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <p class="text-sm font-medium text-slate-600">{{ __('Majlis ini telah berlalu.') }}
                        {{ $event->recording_url ? __('Anda boleh menonton rakaman di bawah.') : '' }}
                    </p>
                </div>
            </div>
        @elseif($eventTimeStatus === 'happening_now')
            <div class="relative z-30 {{ $hasModerationStatusBanner ? '' : '-mt-4' }} mb-4">
                <div class="flex items-center gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 p-4">
                    <span class="relative flex size-3">
                        <span
                            class="absolute inline-flex size-full animate-ping rounded-full bg-emerald-400 opacity-75"></span>
                        <span class="relative inline-flex size-3 rounded-full bg-emerald-500"></span>
                    </span>
                    <p class="text-sm font-bold text-emerald-800">{{ __('Sedang Berlangsung') }}</p>
                    @if($event->live_url)
                        <a href="{{ $event->live_url }}" target="_blank" rel="noopener"
                            data-signal-event="navigation.external_link_clicked"
                            data-signal-category="navigation"
                            data-signal-component="event_detail_status"
                            data-signal-control="live_url"
                            data-signal-entity-type="event"
                            data-signal-entity-id="{{ $event->id }}"
                            data-signal-props='@json(['link_type' => 'live_url'])'
                            class="ml-auto inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-bold text-white transition hover:bg-emerald-700">
                            <svg class="size-3" fill="currentColor" viewBox="0 0 24 24">
                                <circle cx="12" cy="12" r="4" />
                            </svg>
                            {{ __('Tonton Sekarang') }}
                        </a>
                    @endif
                </div>
            </div>
        @elseif($eventTimeStatus === 'starting_soon' && $event->starts_at)
            <div class="relative z-30 {{ $hasModerationStatusBanner ? '' : '-mt-4' }} mb-4">
                <div class="flex items-center gap-3 rounded-2xl border border-blue-200 bg-blue-50 p-4" x-data="{
                                                                                    target: {{ $event->starts_at->timestamp * 1000 }},
                                                                                    display: '',
                                                                                    update() {
                                                                                        const diff = this.target - Date.now();
                                                                                        if (diff <= 0) { this.display = ''; return; }
                                                                                        const h = Math.floor(diff / 3600000);
                                                                                        const m = Math.floor((diff % 3600000) / 60000);
                                                                                        this.display = (h > 0 ? h + 'j ' : '') + m + 'm';
                                                                                    }
                                                                                }"
                    x-init="update(); setInterval(() => update(), 60000)">
                    <svg class="size-5 shrink-0 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <p class="text-sm font-medium text-blue-800">
                        {{ __('Bermula Tidak Lama Lagi') }}
                        <span x-show="display" class="ml-1 font-bold" x-text="'(' + display + ' lagi)'"></span>
                    </p>
                </div>
            </div>
        @endif

        @if($isPostponedWithoutConfirmedTime)
            <div class="mb-4">
                <div class="flex items-center gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-4">
                    <svg class="size-5 shrink-0 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <p class="text-sm font-bold text-amber-700">{{ __('Tarikh baharu belum disahkan. Pendaftaran, check-in dan kalendar ditutup buat masa ini.') }}</p>
                </div>
            </div>
        @elseif($scheduleState === 'postponed' || $scheduleState === 'delayed')
            <div class="mb-4">
                <div class="flex items-center gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-4">
                    <svg class="size-5 shrink-0 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 018 0z" />
                    </svg>
                    <p class="text-sm font-medium text-amber-700">{{ __('Jadual ditangguhkan buat sementara waktu.') }}</p>
                </div>
            </div>
        @elseif($scheduleState === 'cancelled')
            <div class="mb-4">
                <div class="flex items-center gap-3 rounded-2xl border border-red-200 bg-red-50 p-4">
                    <svg class="size-5 shrink-0 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
                    </svg>
                    <p class="text-sm font-bold text-red-700">{{ __('Majlis ini telah dibatalkan.') }}</p>
                </div>
            </div>
        @endif
    </div>

    {{-- ==============================
    MAIN GRID
    ============================== --}}
    <div class="container mx-auto mt-2 grid gap-8 px-5 sm:px-8 lg:grid-cols-3 lg:px-12">

        {{-- ====== LEFT COLUMN (Main Content) ====== --}}
        <div class="space-y-8 lg:col-span-2">

            @if($event->publishedChangeAnnouncements->isNotEmpty())
                <section class="scroll-reveal reveal-up revealed" x-intersect.once="$el.classList.add('revealed')">
                    <div class="mb-5 flex items-center gap-3">
                        <div class="flex size-10 items-center justify-center rounded-xl bg-amber-100 text-amber-600">
                            <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M12 6v6h4.5M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                            </svg>
                        </div>
                        <h2 class="font-heading text-2xl font-bold text-slate-900">{{ __('Sejarah Perubahan') }}</h2>
                    </div>

                    <div class="space-y-3">
                        @foreach($event->publishedChangeAnnouncements as $changeAnnouncement)
                            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <p class="text-sm font-black text-slate-900">{{ $changeAnnouncement->update_type->publicBadgeLabel() }}</p>
                                        <p class="mt-1 text-sm leading-6 text-slate-600">
                                            {{ $changeAnnouncement->message ?: __('Maklumat majlis ini telah dikemas kini.') }}
                                        </p>
                                        @php
                                            $replacementTarget = $this->replacementLinkTargetForAnnouncement($changeAnnouncement);
                                        @endphp
                                        @if($replacementTarget)
                                            <a href="{{ route('events.show', $replacementTarget) }}" wire:navigate
                                                class="mt-2 inline-flex items-center gap-1.5 text-sm font-bold text-emerald-700 hover:text-emerald-800">
                                                {{ __('Lihat majlis pengganti') }}
                                                <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                                                </svg>
                                            </a>
                                        @endif
                                    </div>
                                    @if($changeAnnouncement->published_at)
                                        <time class="shrink-0 text-xs font-semibold text-slate-500" datetime="{{ $changeAnnouncement->published_at->toIso8601String() }}">
                                            {{ \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($changeAnnouncement->published_at, 'j M Y') }},
                                            {{ \App\Support\Timezone\UserDateTimeFormatter::format($changeAnnouncement->published_at, 'h:i A') }}
                                        </time>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif

            {{-- SPEAKERS — shown first: people come for the speaker --}}
            @if($event->speakers->isNotEmpty())
                <section class="scroll-reveal reveal-up revealed" x-intersect.once="$el.classList.add('revealed')">
                    <div class="mb-5 flex items-center gap-3">
                        <div class="flex size-10 items-center justify-center rounded-xl bg-teal-100 text-teal-600">
                            <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                            </svg>
                        </div>
                        <h2 class="font-heading text-2xl font-bold text-slate-900">
                            {{ $event->speakers->count() === 1 ? __('Speaker') : __('Speakers') }}
                        </h2>
                    </div>

                    @if($event->speakers->count() === 1)
                        {{-- Single speaker: full-width horizontal featured card --}}
                        @php
                            $sp = $event->speakers->first();
                            $spProfile = $sp->getFirstMediaUrl('avatar', 'profile') ?: $sp->avatar_url ?: $sp->default_avatar_url;
                            $spCover = $sp->getMedia('cover')->isNotEmpty() ? $sp->getFirstMediaUrl('cover', 'banner') : null;
                            $spBio = $sp->bio ? Str::limit(strip_tags(is_array($sp->bio) ? ($sp->bio['html'] ?? '') : $sp->bio), 220) : null;
                        @endphp
                        <a href="{{ route('speakers.show', $sp) }}" wire:navigate
                            class="group relative flex flex-col overflow-hidden rounded-3xl border border-emerald-200/60 bg-white/90 shadow-xl shadow-emerald-100/40 backdrop-blur-xl transition-all duration-300 hover:-translate-y-0.5 hover:border-emerald-300 hover:shadow-2xl hover:shadow-emerald-100/60 sm:min-h-44 sm:flex-row">

                            {{-- Left: cover/portrait panel --}}
                            <div
                                class="relative h-44 w-full shrink-0 overflow-hidden bg-slate-100 sm:h-full sm:min-h-44 sm:w-44">
                                @if($spCover)
                                    {{-- Real cover photo behind a floating avatar --}}
                                    <img src="{{ $spCover }}" alt=""
                                        class="size-full object-cover transition duration-700 group-hover:scale-105" loading="lazy">
                                    <div class="absolute inset-0 bg-gradient-to-t from-black/30 to-transparent"></div>
                                    <div class="absolute inset-0 flex items-center justify-center">
                                        <div
                                            class="size-24 overflow-hidden rounded-full bg-white shadow-xl transition-transform duration-300 group-hover:scale-105">
                                            <img src="{{ $spProfile }}" alt="{{ $sp->name }}" class="size-full object-contain"
                                                width="96" height="96" loading="lazy">
                                        </div>
                                    </div>
                                @else
                                    {{-- Gradient bg + centred avatar: object-contain on white so the illustration disc fills
                                    cleanly --}}
                                    <div
                                        class="absolute inset-0 bg-gradient-to-br from-emerald-400/30 via-teal-400/20 to-cyan-400/30">
                                    </div>
                                    <div class="absolute inset-0 flex items-center justify-center">
                                        <div
                                            class="size-32 overflow-hidden rounded-full bg-white shadow-2xl transition-transform duration-300 group-hover:scale-105">
                                            <img src="{{ $spProfile }}" alt="{{ $sp->name }}" class="size-full object-contain"
                                                width="128" height="128" loading="lazy">
                                        </div>
                                    </div>
                                @endif
                            </div>

                            {{-- Right: details --}}
                            <div class="flex flex-col justify-center gap-1 p-3 sm:p-4">
                                <h3
                                    class="font-heading text-2xl font-bold leading-tight text-slate-900 transition-colors group-hover:text-emerald-700">
                                    {{ $sp->formatted_name ?? $sp->name }}
                                </h3>
                                    @if($sp->job_title)
                                        <p class="mt-0.5 text-sm font-semibold text-slate-500">{{ $sp->job_title }}</p>
                                @endif
                                @if($spBio)
                                    <p class="mt-4 text-sm leading-relaxed text-slate-600">{{ $spBio }}</p>
                                @endif
                            </div>
                        </a>

                    @else
                        {{-- Multiple speakers: responsive grid --}}
                        <div class="flex flex-wrap justify-center gap-5">
                            @foreach($event->speakers as $speaker)
                                @php
                                    $speakerProfileImg = $speaker->getFirstMediaUrl('avatar', 'profile') ?: null;
                                    $speakerThumbImg = $speaker->avatar_url ?: $speaker->default_avatar_url;
                                    $speakerCoverImg = $speaker->getFirstMedia('cover')?->getAvailableUrl(['banner']) ?? null;
                                @endphp
                                <a wire:key="speaker-{{ $speaker->id }}" href="{{ route('speakers.show', $speaker) }}" wire:navigate
                                    class="group relative w-[240px] flex flex-col overflow-hidden rounded-3xl border border-slate-200/60 bg-white/80 shadow-lg shadow-slate-200/40 backdrop-blur-xl transition-all duration-300 hover:-translate-y-1 hover:border-emerald-300 hover:shadow-xl hover:shadow-emerald-100">

                                    {{-- Cover background --}}
                                    <div class="relative h-24 w-full overflow-hidden bg-slate-100">
                                        @if($speakerCoverImg)
                                            <img src="{{ $speakerCoverImg }}" alt=""
                                                class="size-full object-cover transition duration-700 group-hover:scale-105 group-hover:opacity-80"
                                                loading="lazy">
                                        @else
                                            <div
                                                class="absolute inset-0 bg-gradient-to-br from-emerald-500/20 via-teal-500/10 to-cyan-500/20">
                                            </div>
                                            <div class="absolute inset-0 opacity-[0.05]"
                                                style="background-image: url('{{ asset('images/pattern-bg.png') }}');"></div>
                                        @endif
                                        <div class="absolute inset-0 bg-gradient-to-t from-white via-white/20 to-transparent"></div>
                                    </div>

                                    {{-- Profile overlay --}}
                                    <div class="relative -mt-16 flex flex-col items-center px-3 pb-2 text-center">
                                        <div
                                            class="relative size-32 shrink-0 overflow-hidden rounded-2xl border-4 border-white bg-white shadow-xl transition-transform duration-300 group-hover:-translate-y-2">
                                            <img src="{{ $speakerProfileImg ?: $speakerThumbImg }}" alt="{{ $speaker->name }}"
                                                class="size-full object-cover" width="128" height="128" loading="lazy">
                                        </div>

                                        <div class="mt-2">
                                            <h4
                                                class="font-heading text-lg font-bold text-slate-900 transition-colors group-hover:text-emerald-700">
                                                {{ $speaker->formatted_name ?? $speaker->name }}
                                            </h4>
                                            @if($speaker->job_title)
                                                <p class="mt-1 text-sm font-medium text-slate-500">{{ $speaker->job_title }}</p>
                                            @endif
                                        </div>
                                    </div>

                                    {{-- Bio snippet --}}
                                    @if($speaker->bio)
                                        <div
                                            class="mt-auto border-t border-slate-100 bg-slate-50/50 px-4 py-3 transition-colors group-hover:bg-emerald-50/30">
                                            <p class="line-clamp-2 text-sm leading-relaxed text-slate-600">
                                                {{ Str::limit(strip_tags(is_array($speaker->bio) ? ($speaker->bio['html'] ?? '') : $speaker->bio), 120) }}
                                            </p>
                                        </div>
                                    @endif
                                </a>
                            @endforeach
                        </div>
                    @endif
                </section>
            @endif

            @if($keyPeopleByRole->isNotEmpty())
                <section class="scroll-reveal reveal-up revealed" x-intersect.once="$el.classList.add('revealed')">
                    <div class="mb-5 flex items-center gap-3">
                        <div class="flex size-10 items-center justify-center rounded-xl bg-amber-100 text-amber-600">
                            <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 6.75h7.5M8.25 12h7.5m-7.5 5.25h7.5" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h.008v.008H3.75V6.75zm0 5.25h.008v.008H3.75V12zm0 5.25h.008v.008H3.75v-.008z" />
                            </svg>
                        </div>
                        <h2 class="font-heading text-2xl font-bold text-slate-900">{{ __('Peranan Lain') }}</h2>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        @foreach($keyPeopleByRole as $role => $keyPeople)
                            @php
                                $roleLabel = \App\Enums\EventKeyPersonRole::tryFrom($role)?->getLabel() ?? Str::headline($role);
                            @endphp
                            <div class="rounded-3xl border border-amber-200/70 bg-amber-50/60 p-5 shadow-sm">
                                <h3 class="font-heading text-lg font-bold text-slate-900">{{ $roleLabel }}</h3>
                                <div class="mt-3 space-y-3">
                                    @foreach($keyPeople as $keyPerson)
                                        @php
                                            $linkedSpeaker = $keyPerson->speaker;
                                            $displayName = $keyPerson->display_name;
                                        @endphp
                                        <div wire:key="key-person-{{ $keyPerson->id }}" class="rounded-2xl bg-white/80 p-3 ring-1 ring-amber-100">
                                            @if($linkedSpeaker)
                                                <a href="{{ route('speakers.show', $linkedSpeaker) }}" wire:navigate class="font-semibold text-slate-900 hover:text-emerald-700">
                                                    {{ $displayName }}
                                                </a>
                                            @else
                                                <p class="font-semibold text-slate-900">{{ $displayName }}</p>
                                            @endif

                                            @if(filled($keyPerson->notes))
                                                <p class="mt-1 text-sm text-slate-600">{{ $keyPerson->notes }}</p>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif

            {{-- REFERENCES --}}
            @if($event->references->isNotEmpty())
                <section data-testid="event-detail-references-section" class="scroll-reveal reveal-up revealed" x-intersect.once="$el.classList.add('revealed')">
                    <div class="mb-6 flex items-center gap-3">
                        <div class="flex size-10 items-center justify-center rounded-xl bg-indigo-100 text-indigo-600">
                            <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                            </svg>
                        </div>
                        <h2 class="font-heading text-2xl font-bold text-slate-900">{{ __('References') }}</h2>
                    </div>

                    <div @class([
                        'grid gap-5',
                        'sm:grid-cols-2' => $event->references->count() > 1,
                    ])>
                        @foreach($event->references as $reference)
                            <a href="{{ route('references.show', $reference) }}" wire:navigate wire:key="ref-{{ $reference->id }}"
                                class="group flex gap-5 rounded-3xl border border-slate-200/60 bg-white/80 p-5 shadow-lg shadow-slate-200/40 backdrop-blur-xl transition-all duration-300 hover:-translate-y-1 hover:border-indigo-300 hover:shadow-xl hover:shadow-indigo-100">
                                @php $coverUrl = $reference->getFirstMediaUrl('front_cover', 'thumb'); @endphp
                                @if($coverUrl)
                                    <div
                                        class="w-20 shrink-0 overflow-hidden rounded-xl shadow-md transition-transform duration-300 group-hover:scale-105">
                                        <img src="{{ $coverUrl }}" alt="{{ $reference->title }}" class="h-28 w-full object-cover"
                                            loading="lazy">
                                    </div>
                                @else
                                    <div
                                        class="flex w-20 shrink-0 items-center justify-center rounded-xl bg-indigo-50 border border-indigo-100">
                                        <svg class="size-8 text-indigo-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                                d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                                        </svg>
                                    </div>
                                @endif
                                <div class="min-w-0 flex-1 py-1">
                                    <h4
                                        class="font-heading text-base font-bold text-slate-900 transition-colors group-hover:text-indigo-700">
                                        {{ $reference->title }}
                                    </h4>
                                    @if($reference->author)
                                        <p class="mt-1.5 text-sm font-medium text-slate-600">{{ $reference->author }}</p>
                                    @endif
                                    @if($reference->publisher)
                                        <p class="mt-1 text-xs text-slate-400">{{ $reference->publisher }}</p>
                                    @endif
                                </div>
                                <div class="flex shrink-0 items-center">
                                    <div
                                        class="flex size-8 items-center justify-center rounded-full bg-slate-100 text-slate-400 transition-all duration-300 group-hover:bg-indigo-100 group-hover:text-indigo-600">
                                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                                        </svg>
                                    </div>
                                </div>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif

            {{-- Location cover (institution/venue) --}}
            @if($locationCover)
                    <section data-testid="event-detail-location-section" class="scroll-reveal reveal-up revealed" x-intersect.once="$el.classList.add('revealed')">
                        <div class="mb-5 flex items-center gap-3">
                            <div class="flex size-10 items-center justify-center rounded-xl bg-emerald-100 text-emerald-600">
                                <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" />
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" />
                                </svg>
                            </div>
                            <h2 class="font-heading text-2xl font-bold text-slate-900">{{ __('Location') }}</h2>
                        </div>

                        @if($locationHref)
                            <a href="{{ $locationHref }}" wire:navigate
                                class="group block overflow-hidden rounded-3xl border border-slate-200/70 bg-white shadow-xl shadow-slate-200/40">
                        @else
                                <div
                                    class="group overflow-hidden rounded-3xl border border-slate-200/70 bg-white shadow-xl shadow-slate-200/40">
                            @endif
                                <div class="relative h-56 overflow-hidden sm:h-64">
                                    <img src="{{ $locationCover }}" alt="{{ $locationEntity?->name ?? __('Location') }}"
                                        class="size-full object-cover transition duration-700 group-hover:scale-105"
                                        loading="lazy">
                                    <div
                                        class="absolute inset-0 bg-gradient-to-t from-slate-950/80 via-slate-950/20 to-transparent">
                                    </div>
                                    <div class="absolute inset-x-0 bottom-0 p-5 sm:p-6">
                                        <p class="text-xs font-bold uppercase tracking-widest text-white/70">{{ __('Lokasi') }}
                                        </p>
                                        <p class="mt-1 text-lg font-bold text-white sm:text-xl">
                                            {{ $locationEntity?->name ?? __('TBC') }}
                                        </p>
                                    </div>
                                </div>
                                @if($locationHref)
                                    </a>
                                @else
                            </div>
                        @endif
                </section>
            @endif
        {{-- ABOUT --}}
        @if($hasAboutContent)
            <section
                class="group relative overflow-hidden rounded-3xl border border-slate-200/60 bg-white/80 p-6 shadow-xl shadow-slate-200/40 backdrop-blur-xl transition-all hover:shadow-2xl hover:shadow-slate-200/50 sm:p-8 scroll-reveal reveal-up revealed"
                x-intersect.once="$el.classList.add('revealed')">
                <div
                    class="absolute -right-20 -top-20 size-64 rounded-full bg-emerald-50 opacity-50 blur-3xl transition-opacity group-hover:opacity-100">
                </div>

                <div class="relative z-10">
                    <div class="flex items-center gap-3">
                        <div class="flex size-10 items-center justify-center rounded-xl bg-emerald-100 text-emerald-600">
                            <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <h2 class="font-heading text-2xl font-bold text-slate-900">{{ __('About this Event') }}</h2>
                    </div>

                    @if($descriptionHtml !== '')
                        <div
                            class="prose prose-slate prose-lg mt-6 max-w-none prose-headings:font-heading prose-headings:font-bold prose-a:text-emerald-600 hover:prose-a:text-emerald-500 prose-img:rounded-2xl">
                            {!! $descriptionHtml !!}
                        </div>
                    @endif

                    {{-- Taxonomy cloud by package classification type --}}
                    @if($classifications->isNotEmpty())
                        @php
                            $tagCloudSections = [
                                [
                                    'key' => \App\Enums\TagType::Domain->value,
                                    'label' => __('Kategori'),
                                    'color' => 'border-blue-200 bg-blue-50 text-blue-700 hover:bg-blue-100',
                                ],
                                [
                                    'key' => \App\Enums\TagType::Discipline->value,
                                    'label' => __('Bidang Ilmu'),
                                    'color' => 'border-cyan-200 bg-cyan-50 text-cyan-700 hover:bg-cyan-100',
                                ],
                                [
                                    'key' => \App\Enums\TagType::Source->value,
                                    'label' => __('Sumber Utama'),
                                    'color' => 'border-emerald-200 bg-emerald-50 text-emerald-700 hover:bg-emerald-100',
                                ],
                                [
                                    'key' => \App\Enums\TagType::Issue->value,
                                    'label' => __('Tema / Isu'),
                                    'color' => 'border-amber-200 bg-amber-50 text-amber-700 hover:bg-amber-100',
                                ],
                            ];
                        @endphp
                        <div class="mt-8 border-t border-slate-100 pt-6">
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 sm:gap-5">
                                @foreach($tagCloudSections as $section)
                                    @php
                                        $sectionTags = $classificationsByTaxonomy->get($section['key']);
                                    @endphp
                                    @if($sectionTags instanceof \Illuminate\Support\Collection && $sectionTags->isNotEmpty())
                                        <div>
                                            <p class="mb-2.5 text-xs font-bold uppercase tracking-widest text-slate-400">
                                                {{ $section['label'] }}
                                            </p>
                                            <div class="flex flex-wrap gap-2.5">
                                                @foreach($sectionTags as $classification)
                                                    <span wire:key="taxonomy-cloud-{{ $section['key'] }}-{{ $classification->id }}"
                                                        class="inline-flex items-center rounded-full border px-4 py-1.5 text-sm font-semibold transition-colors {{ $section['color'] }}">
                                                        {{ $classification->term?->name ?? $classification->term_code }}
                                                    </span>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            </section>
        @endif

        {{-- TEMP-HIDDEN SECTION (DO NOT REMOVE):
        Organizer/Location context card is intentionally disabled per product request.
        Keep this block intact so it can be re-enabled quickly when requested. --}}
        @if(false && $showContextCard)
                <section class="scroll-reveal reveal-up revealed" x-intersect.once="$el.classList.add('revealed')">
                    <div class="mb-6 flex items-center gap-3">
                        <div class="flex size-10 items-center justify-center rounded-xl bg-emerald-100 text-emerald-600">
                            <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M12 21v-8.25M15.75 21v-8.25M8.25 21v-8.25M3 9l9-6 9 6m-1.5 12V10.332A48.36 48.36 0 0012 9.75c-2.551 0-5.056.2-7.5.582V21M3 21h18M12 6.75h.008v.008H12V6.75z" />
                            </svg>
                        </div>
                        <h2 class="font-heading text-2xl font-bold text-slate-900">{{ $contextTitle }}</h2>
                    </div>

                    <div
                        class="overflow-hidden rounded-3xl border border-slate-200/60 bg-white/80 shadow-xl shadow-slate-200/40 backdrop-blur-xl">
                        <div class="p-6 sm:p-8">
                            @if($contextHref)
                                <a href="{{ $contextHref }}" wire:navigate class="group flex items-center gap-5">
                            @else
                                    <div class="group flex items-center gap-5">
                                @endif
                                    <div
                                        class="size-16 shrink-0 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition-transform duration-300 group-hover:scale-105 group-hover:shadow-md">
                                        @if($contextThumb)
                                            <img src="{{ $contextThumb }}" alt="{{ $contextName }}" class="size-full object-cover"
                                                loading="lazy">
                                        @else
                                            <div class="flex size-full items-center justify-center bg-emerald-50">
                                                @if($contextEntity instanceof \App\Models\Speaker)
                                                    <svg class="size-7 text-emerald-400" fill="none" viewBox="0 0 24 24"
                                                        stroke="currentColor" stroke-width="1.5">
                                                        <path stroke-linecap="round" stroke-linejoin="round"
                                                            d="M15.75 6.75a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a8.967 8.967 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />
                                                    </svg>
                                                @elseif($contextEntity instanceof \App\Models\Venue)
                                                    <svg class="size-7 text-emerald-400" fill="none" viewBox="0 0 24 24"
                                                        stroke="currentColor" stroke-width="1.5">
                                                        <path stroke-linecap="round" stroke-linejoin="round"
                                                            d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" />
                                                        <path stroke-linecap="round" stroke-linejoin="round"
                                                            d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" />
                                                    </svg>
                                                @else
                                                    <svg class="size-7 text-emerald-400" fill="none" viewBox="0 0 24 24"
                                                        stroke="currentColor" stroke-width="1.5">
                                                        <path stroke-linecap="round" stroke-linejoin="round"
                                                            d="M12 21v-8.25M15.75 21v-8.25M8.25 21v-8.25M3 9l9-6 9 6m-1.5 12V10.332A48.36 48.36 0 0012 9.75c-2.551 0-5.056.2-7.5.582V21M3 21h18M12 6.75h.008v.008H12V6.75z" />
                                                    </svg>
                                                @endif
                                            </div>
                                        @endif
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <span
                                            class="text-xs font-bold uppercase tracking-widest text-slate-400">{{ $contextLabel }}</span>
                                        <h3
                                            class="mt-1 font-heading text-lg font-bold text-slate-900 transition-colors group-hover:text-emerald-600">
                                            {{ $contextName }}
                                        </h3>
                                    </div>
                                    @if($contextHref)
                                        <div
                                            class="flex size-10 shrink-0 items-center justify-center rounded-full bg-slate-50 text-slate-400 transition-colors group-hover:bg-emerald-50 group-hover:text-emerald-600">
                                            <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                                stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                                            </svg>
                                        </div>
                                    @endif
                                    @if($contextHref)
                                        </a>
                                    @else
                                </div>
                            @endif

                        {{-- Context contact info (institution only) --}}
                        @if($contextPhone || $contextEmail)
                            <div class="mt-4 flex flex-wrap gap-4 pl-[5.25rem]">
                                @if($contextPhone)
                                    <a href="tel:{{ $contextPhone }}"
                                        class="inline-flex items-center gap-2 rounded-lg bg-slate-50 px-3 py-1.5 text-sm font-medium text-slate-600 transition hover:bg-emerald-50 hover:text-emerald-700">
                                        <svg class="size-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                            stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z" />
                                        </svg>
                                        {{ $contextPhone }}
                                    </a>
                                @endif
                                @if($contextEmail)
                                    <a href="mailto:{{ $contextEmail }}"
                                        class="inline-flex items-center gap-2 rounded-lg bg-slate-50 px-3 py-1.5 text-sm font-medium text-slate-600 transition hover:bg-emerald-50 hover:text-emerald-700">
                                        <svg class="size-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                            stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" />
                                        </svg>
                                        {{ $contextEmail }}
                                    </a>
                                @endif
                            </div>
                        @endif

                        {{-- Context cover --}}
                        @if($contextCover)
                            <div class="mt-6 overflow-hidden rounded-2xl border border-slate-100">
                                <img src="{{ $contextCover }}" alt="{{ $contextName }}" class="h-40 w-full object-cover"
                                    loading="lazy">
                            </div>
                        @endif
                    </div>
            </div>
            </section>
        @endif

    {{-- SERIES CONTEXT --}}
    @if($event->series->isNotEmpty())
        <section class="scroll-reveal reveal-up revealed" x-intersect.once="$el.classList.add('revealed')">
            <div class="mb-6 flex items-center gap-3">
                <div class="flex size-10 items-center justify-center rounded-xl bg-fuchsia-100 text-fuchsia-600">
                    <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                    </svg>
                </div>
                <h2 class="font-heading text-2xl font-bold text-slate-900">{{ __('Part of a Series') }}</h2>
            </div>

            <div class="space-y-4">
                @foreach($event->series as $series)
                    <a wire:key="series-{{ $series->id }}" href="{{ route('series.show', $series) }}" wire:navigate
                        class="group flex items-center gap-5 rounded-3xl border border-slate-200/60 bg-white/80 p-5 shadow-lg shadow-slate-200/40 backdrop-blur-xl transition-all duration-300 hover:-translate-y-1 hover:border-fuchsia-300 hover:shadow-xl hover:shadow-fuchsia-100">
                        @php $seriesCover = $series->getFirstMediaUrl('cover', 'thumb'); @endphp
                        @if($seriesCover)
                            <div
                                class="size-20 shrink-0 overflow-hidden rounded-2xl shadow-md transition-transform duration-300 group-hover:scale-105">
                                <img src="{{ $seriesCover }}" alt="{{ $series->title }}" class="size-full object-cover"
                                    loading="lazy">
                            </div>
                        @else
                            <div
                                class="flex size-20 shrink-0 items-center justify-center rounded-2xl bg-fuchsia-50 border border-fuchsia-100">
                                <svg class="size-8 text-fuchsia-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                        d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                                </svg>
                            </div>
                        @endif
                        <div class="min-w-0 flex-1">
                            <h4
                                class="font-heading text-lg font-bold text-slate-900 transition-colors group-hover:text-fuchsia-700">
                                {{ $series->title }}
                            </h4>
                            @if($series->description)
                                <p class="mt-1.5 line-clamp-2 text-sm leading-relaxed text-slate-600">
                                    {{ Str::limit(strip_tags($series->description), 120) }}
                                </p>
                            @endif
                        </div>
                        <div
                            class="flex size-10 shrink-0 items-center justify-center rounded-full bg-slate-50 text-slate-400 transition-colors group-hover:bg-fuchsia-50 group-hover:text-fuchsia-600">
                            <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                            </svg>
                        </div>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    {{-- GALLERY --}}
    @if($galleryImages !== [])
        <section class="scroll-reveal reveal-up revealed" x-intersect.once="$el.classList.add('revealed')"
            x-data="{ active: 0, images: @js($galleryImages), next() { this.active = (this.active + 1) % this.images.length }, prev() { this.active = (this.active - 1 + this.images.length) % this.images.length }, go(index) { this.active = index } }">
            <div class="mb-6 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="flex size-10 items-center justify-center rounded-xl bg-rose-100 text-rose-600">
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                        </svg>
                    </div>
                    <h2 class="font-heading text-2xl font-bold text-slate-900">{{ __('Event Gallery') }}</h2>
                </div>
                <div
                    class="flex items-center gap-2 rounded-full bg-white/80 px-4 py-1.5 text-sm font-bold text-slate-500 shadow-sm backdrop-blur-md border border-slate-200/60">
                    <span x-text="active + 1" class="text-slate-900"></span>
                    <span class="text-slate-300">/</span>
                    <span x-text="images.length"></span>
                </div>
            </div>

            <div
                class="overflow-hidden rounded-3xl border border-slate-200/60 bg-white/80 p-3 shadow-xl shadow-slate-200/40 backdrop-blur-xl">
                <div class="group relative overflow-hidden rounded-2xl bg-slate-900">
                    <img :src="images[active]?.url" :alt="images[active]?.alt"
                        class="h-[400px] w-full object-cover transition-transform duration-700 sm:h-[500px]" loading="lazy">

                    {{-- Navigation Overlays --}}
                    <div
                        class="absolute inset-0 flex items-center justify-between p-4 opacity-0 transition-opacity duration-300 group-hover:opacity-100">
                        <button type="button" @click="prev()"
                            class="inline-flex size-12 items-center justify-center rounded-full bg-white/20 text-white backdrop-blur-md transition hover:bg-white/40 hover:scale-110"
                            aria-label="{{ __('Previous image') }}">
                            <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                            </svg>
                        </button>
                        <button type="button" @click="next()"
                            class="inline-flex size-12 items-center justify-center rounded-full bg-white/20 text-white backdrop-blur-md transition hover:bg-white/40 hover:scale-110"
                            aria-label="{{ __('Next image') }}">
                            <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                            </svg>
                        </button>
                    </div>
                </div>

                @if(count($galleryImages) > 1)
                    <div class="mt-3 grid grid-cols-4 gap-3 sm:grid-cols-6">
                        @foreach($galleryImages as $index => $image)
                            <button type="button" @click="go({{ $index }})"
                                class="relative overflow-hidden rounded-xl transition-all duration-300"
                                :class="{ 'ring-2 ring-rose-500 ring-offset-2 scale-95': active === {{ $index }}, 'opacity-60 hover:opacity-100': active !== {{ $index }} }">
                                <img src="{{ $image['thumb'] }}" alt="{{ $image['alt'] }}" class="h-16 w-full object-cover"
                                    loading="lazy">
                                <div class="absolute inset-0 bg-black/20 transition-opacity"
                                    :class="{ 'opacity-0': active === {{ $index }} }"></div>
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>
        </section>
    @endif

    {{-- WATCH ONLINE --}}
    @if($event->live_url || $event->recording_url)
        <section class="scroll-reveal reveal-up revealed" x-intersect.once="$el.classList.add('revealed')">
            <div class="mb-6 flex items-center gap-3">
                <div class="flex size-10 items-center justify-center rounded-xl bg-red-100 text-red-600">
                    <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
                    </svg>
                </div>
                <h2 class="font-heading text-2xl font-bold text-slate-900">{{ __('Watch Online') }}</h2>
            </div>

            <div
                class="flex flex-wrap gap-4 rounded-3xl border border-slate-200/60 bg-white/80 p-6 shadow-xl shadow-slate-200/40 backdrop-blur-xl sm:p-8">
                @if($event->live_url)
                    <a href="{{ $event->live_url }}" target="_blank" rel="noopener"
                        data-signal-event="navigation.external_link_clicked"
                        data-signal-category="navigation"
                        data-signal-component="event_detail_media"
                        data-signal-control="live_url"
                        data-signal-entity-type="event"
                        data-signal-entity-id="{{ $event->id }}"
                        data-signal-props='@json(['link_type' => 'live_url'])'
                        class="group relative inline-flex items-center gap-3 overflow-hidden rounded-2xl bg-slate-900 px-8 py-4 font-bold text-white shadow-lg shadow-slate-900/20 transition-all hover:-translate-y-1 hover:shadow-xl hover:shadow-slate-900/30">
                        <div
                            class="absolute inset-0 bg-gradient-to-r from-red-600 to-red-500 opacity-0 transition-opacity duration-300 group-hover:opacity-100">
                        </div>
                        <div class="relative flex items-center gap-3">
                            <span class="relative flex size-3">
                                <span
                                    class="absolute inline-flex h-full w-full animate-ping rounded-full bg-red-400 opacity-75"></span>
                                <span class="relative inline-flex size-3 rounded-full bg-red-500"></span>
                            </span>
                            {{ __('Watch Live') }}
                        </div>
                    </a>
                @endif
                @if($event->recording_url)
                    <a href="{{ $event->recording_url }}" target="_blank" rel="noopener"
                        data-signal-event="navigation.external_link_clicked"
                        data-signal-category="navigation"
                        data-signal-component="event_detail_media"
                        data-signal-control="recording_url"
                        data-signal-entity-type="event"
                        data-signal-entity-id="{{ $event->id }}"
                        data-signal-props='@json(['link_type' => 'recording_url'])'
                        class="group inline-flex items-center gap-3 rounded-2xl border border-slate-200 bg-white px-8 py-4 font-bold text-slate-700 shadow-sm transition-all hover:-translate-y-1 hover:border-slate-300 hover:bg-slate-50 hover:shadow-md">
                        <svg class="size-5 text-slate-400 transition-colors group-hover:text-slate-600" fill="none"
                            viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        {{ __('Watch Recording') }}
                    </a>
                @endif
            </div>
        </section>
    @endif

</div>

{{-- ====== RIGHT COLUMN (Sidebar) ====== --}}
<aside class="space-y-8">

    {{-- EVENT DETAILS CARD --}}
    <div class="sticky top-28 space-y-8">
        <div
            class="overflow-hidden rounded-3xl border border-slate-200/60 bg-white/80 shadow-xl shadow-slate-200/40 backdrop-blur-xl">
            <div class="border-b border-slate-100/80 bg-slate-50/50 px-6 py-5">
                <h3 class="font-heading text-lg font-bold text-slate-900">{{ __('Event Details') }}</h3>
            </div>

            <div class="p-6 space-y-6">
                {{-- Date & Time --}}
                <div class="flex items-start gap-4">
                    <div
                        class="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-600 border border-emerald-100">
                        <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                        </svg>
                    </div>
                    <div class="min-w-0 flex-1 pt-0.5">
                        <p class="text-xs font-bold uppercase tracking-widest text-slate-400">{{ __('Date & Time') }}
                        </p>
                        <p class="mt-1.5 font-heading text-base font-bold text-slate-900">
                            {{ $event->starts_at ? \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($event->starts_at, 'l, j F Y') : __('TBC') }}
                        </p>
                        @php
                            $sidebarStartTime = null;
                            if ($event->starts_at) {
                                $sidebarStartTime = $event->isPrayerRelative()
                                    ? (string) $event->timing_display
                                    : \App\Support\Timezone\UserDateTimeFormatter::format($event->starts_at, 'g:i A');
                            }
                            $sidebarEndTime = $event->ends_at
                                ? \App\Support\Timezone\UserDateTimeFormatter::format($event->ends_at, 'g:i A')
                                : null;
                            $sidebarTimeText = $sidebarStartTime ?: __('Waktu belum ditetapkan');
                            if ($sidebarEndTime) {
                                $sidebarTimeText .= ' — ' . $sidebarEndTime;
                            }
                        @endphp
                        <p
                            class="mt-2 inline-flex max-w-full items-center gap-1.5 rounded-full px-3.5 py-1.5 text-[13px] font-semibold text-white shadow-sm ring-1 ring-white/10 whitespace-nowrap {{ $event->isPrayerRelative() ? 'bg-gradient-to-r from-emerald-700 to-emerald-800' : 'bg-gradient-to-r from-slate-800 to-slate-900' }}">
                            <svg class="size-4 shrink-0 {{ $event->isPrayerRelative() ? 'text-emerald-200' : 'text-sky-200' }}"
                                fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <span class="truncate">{{ $sidebarTimeText }}</span>
                        </p>
                    </div>
                </div>

                {{-- Location --}}
                <div class="flex items-start gap-4">
                    @php
                        $sidebarVenueThumb = $event->venue?->getFirstMediaUrl('cover', 'thumb');
                        $sidebarInstCover = $event->institution?->getFirstMediaUrl('cover', 'thumb');
                        $sidebarInstLogo = $event->institution?->getFirstMediaUrl('logo', 'thumb');
                        $sidebarLocationThumb = $sidebarVenueThumb ?: $sidebarInstCover ?: $sidebarInstLogo;
                        $sidebarLocationName = $event->venue?->name ?? $event->institution?->name ?? __('Location');
                    @endphp
                    @if($sidebarLocationThumb)
                        <div class="size-12 shrink-0 overflow-hidden rounded-2xl border border-slate-200 shadow-sm">
                            <img src="{{ $sidebarLocationThumb }}" alt="{{ $sidebarLocationName }}"
                                class="size-full object-cover" loading="lazy">
                        </div>
                    @else
                        <div
                            class="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-blue-50 text-blue-600 border border-blue-100">
                            <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                    d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                    d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                            </svg>
                        </div>
                    @endif
                    <div class="min-w-0 flex-1 pt-0.5">
                        <p class="text-xs font-bold uppercase tracking-widest text-slate-400">{{ __('Location') }}</p>
                        <p class="mt-1.5 font-heading text-base font-bold text-slate-900">
                            @if($event->venue)
                                {{ $event->venue->name }}
                            @elseif($event->institution)
                                <a href="{{ route('institutions.show', $event->institution) }}" wire:navigate
                                    class="text-emerald-600 hover:text-emerald-700 hover:underline">
                                    {{ $event->institution->name }}
                                </a>
                            @else
                                {{ __('Online') }}
                            @endif
                        </p>
                        @if($event->primaryLocation?->venueSpace)
                            <p class="mt-0.5 text-sm font-medium text-slate-600">{{ $event->primaryLocation->venueSpace->name }}</p>
                        @endif
                        @if($event->venue && $event->institution)
                            <div class="mt-2 flex items-center gap-2">
                                @if($sidebarInstLogo)
                                    <img src="{{ $sidebarInstLogo }}" alt=""
                                        class="size-5 rounded-md object-contain bg-white border border-slate-100"
                                        loading="lazy">
                                @endif
                                <a href="{{ route('institutions.show', $event->institution) }}" wire:navigate
                                    class="text-sm font-medium text-emerald-600 hover:text-emerald-700 hover:underline">{{ $event->institution->name }}</a>
                            </div>
                        @endif
                        {{-- Full address --}}
                        @if($primaryAddress && (filled($primaryAddress->line1) || filled($fullAddressCityLine) || filled($fullAddressStateName)))
                            <p class="mt-2 text-sm leading-relaxed text-slate-500">
                                @if($primaryAddress->line1) {{ $primaryAddress->line1 }} @endif
                                @if($primaryAddress->line2), {{ $primaryAddress->line2 }}@endif
                                @if($fullAddressCityLine)
                                    <br>{{ $fullAddressCityLine }}
                                @endif
                                @if($fullAddressStateName)
                                    , {{ $fullAddressStateName }}
                                @endif
                            </p>
                        @endif
                    </div>
                </div>

                {{-- Navigation Buttons (Waze / Google Maps) --}}
                @if($wazeNavUrl || $googleMapsNavUrl)
                    @php
                        $mapAddress = $primaryAddress;
                        $mapAddressHierarchyParts = \App\Support\Location\AddressHierarchyFormatter::parts($mapAddress);
                        $mapQuery = implode(', ', array_filter([
                            $event->venue?->name ?? $event->institution?->name,
                            $mapAddress?->line1,
                            $mapAddress?->line2,
                            $mapAddressHierarchyParts[0] ?? null,
                            $mapAddressHierarchyParts[1] ?? null,
                        ]));
                        $normalizedMapQuery = null;
                        if (filled($mapAddress?->google_maps_url)) {
                            $parsedQs = parse_url((string) $mapAddress->google_maps_url, PHP_URL_QUERY);
                            if (is_string($parsedQs) && $parsedQs !== '') {
                                parse_str($parsedQs, $mapQueryParams);
                                $qv = $mapQueryParams['query'] ?? $mapQueryParams['q'] ?? null;
                                if (is_string($qv) && $qv !== '') {
                                    $normalizedMapQuery = $qv;
                                }
                            }
                        }
                        if (!filled($normalizedMapQuery) && filled($mapQuery)) {
                            $normalizedMapQuery = $mapQuery;
                        }
                        $sidebarMapEmbedUrl = null;
                        if (filled($normalizedMapQuery)) {
                            $sidebarMapEmbedUrl = 'https://www.google.com/maps?q=' . urlencode((string) $normalizedMapQuery) . '&output=embed';
                        }
                    @endphp
                    @if($sidebarMapEmbedUrl)
                        <div class="overflow-hidden rounded-xl border border-slate-200/70">
                            <iframe src="{{ $sidebarMapEmbedUrl }}" class="h-[220px] w-full" loading="lazy"
                                referrerpolicy="no-referrer-when-downgrade" allowfullscreen
                                title="{{ __('Peta Lokasi') }}"></iframe>
                        </div>
                    @endif
                    <div class="flex gap-3 pt-2">
                        @if($wazeNavUrl)
                            <a href="{{ $wazeNavUrl }}" target="_blank" rel="noopener"
                                class="flex-1 inline-flex items-center justify-center gap-2 rounded-xl border border-cyan-200 bg-cyan-50 px-4 py-3 text-sm font-bold text-cyan-700 shadow-sm transition hover:bg-cyan-100 hover:shadow">
                                <img src="{{ asset('images/waze-app-icon-seeklogo.svg') }}" alt="Waze" class="size-5"
                                    loading="lazy">
                                Waze
                            </a>
                        @endif
                        @if($googleMapsNavUrl)
                            <a href="{{ $googleMapsNavUrl }}" target="_blank" rel="noopener"
                                class="flex-1 inline-flex items-center justify-center gap-2 rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 text-sm font-bold text-blue-700 shadow-sm transition hover:bg-blue-100 hover:shadow">
                                <img src="{{ asset('images/google-maps.svg') }}" alt="Google Maps" class="size-5"
                                    loading="lazy">
                                Google Maps
                            </a>
                        @endif
                    </div>
                @endif

                {{-- Audience Info --}}
                @if(($genderValue && $genderValue !== 'all') || !empty($ageGroupLabels) || $event->children_allowed === false || $event->is_muslim_only)
                    <div class="flex items-start gap-4">
                        <div
                            class="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-violet-50 text-violet-600 border border-violet-100">
                            <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                    d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                            </svg>
                        </div>
                        <div class="min-w-0 flex-1 pt-0.5">
                            <p class="text-xs font-bold uppercase tracking-widest text-slate-400">{{ __('Audience') }}</p>
                            <div class="mt-1.5 space-y-1.5 text-sm font-medium text-slate-700">
                                @if($genderValue && $genderValue !== 'all')
                                    <p class="flex items-center gap-2">
                                        <span class="size-1.5 rounded-full bg-violet-400"></span>
                                        {{ $genderLabel }}
                                    </p>
                                @endif
                                @if(!empty($ageGroupLabels))
                                    <p class="flex items-center gap-2">
                                        <span class="size-1.5 rounded-full bg-violet-400"></span>
                                        {{ implode(', ', $ageGroupLabels) }}
                                    </p>
                                @endif
                                @if($event->children_allowed === false)
                                    <p class="flex items-center gap-2 text-rose-600">
                                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                            stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
                                        </svg>
                                        {{ __('Kanak-kanak tidak dibenarkan') }}
                                    </p>
                                @endif
                                @if($event->is_muslim_only)
                                    <p class="flex items-center gap-2 text-emerald-600">
                                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                            stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                        {{ __('Muslim sahaja') }}
                                    </p>
                                @endif
                            </div>
                        </div>
                    </div>
                @endif

                {{-- Language --}}
                @if($primaryLanguage)
                    <div class="flex items-start gap-4">
                        <div
                            class="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-sky-50 text-sky-600 border border-sky-100">
                            <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                    d="M3 5h12M9 3v2m1.048 9.5A18.022 18.022 0 016.412 9m6.088 9h7M11 21l5-10 5 10M12.751 5C11.783 10.77 8.07 15.61 3 18.129" />
                            </svg>
                        </div>
                        <div class="pt-0.5">
                            <p class="text-xs font-bold uppercase tracking-widest text-slate-400">{{ __('Language') }}</p>
                            <p class="mt-1.5 font-heading text-base font-bold text-slate-900">{{ $languageName }}</p>
                        </div>
                    </div>
                @endif

                {{-- Contact --}}
                @if($institutionPhone || $institutionEmail)
                    <div class="flex items-start gap-4">
                        <div
                            class="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-orange-50 text-orange-600 border border-orange-100">
                            <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                    d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z" />
                            </svg>
                        </div>
                        <div class="min-w-0 flex-1 pt-0.5">
                            <p class="text-xs font-bold uppercase tracking-widest text-slate-400">{{ __('Contact') }}</p>
                            @if($institutionPhone)
                                <a href="tel:{{ $institutionPhone }}"
                                    class="mt-1.5 block font-heading text-base font-bold text-slate-900 transition hover:text-emerald-600">{{ $institutionPhone }}</a>
                            @endif
                            @if($institutionEmail)
                                <a href="mailto:{{ $institutionEmail }}"
                                    class="mt-1 block truncate text-sm font-medium text-slate-500 transition hover:text-emerald-600">{{ $institutionEmail }}</a>
                            @endif
                        </div>
                    </div>
                @endif
            </div>

            {{-- Attendance Status --}}
            @php
                $regRequired = $event->accessPolicy?->registration_required ?? false;
                $regOpensAt = $event->accessPolicy?->opens_at;
                $regClosesAt = $event->accessPolicy?->closes_at;
                $regCapacity = $event->accessPolicy?->capacity;
                $spotsTaken = (int) $this->registrationsCount;
                $capacityRatio = $regCapacity
                    ? min(100, (int) round(($spotsTaken / max(1, (int) $regCapacity)) * 100))
                    : null;
            @endphp
            @if($regRequired)
                <div class="border-t border-slate-100/80 px-6 py-5">
                    <div class="rounded-2xl border border-slate-200/80 bg-slate-50/80 p-3.5 sm:p-4">
                        <div class="flex flex-wrap items-center justify-between gap-2.5">
                            <span
                                class="inline-flex items-center gap-1.5 rounded-full bg-amber-100 px-3 py-1.5 text-xs font-bold text-amber-700">
                                <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                    stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                </svg>
                                {{ __('Registration Required') }}
                            </span>
                            @if($regCapacity)
                                <span class="text-xs font-semibold text-slate-500">
                                    <span class="text-slate-700">{{ $spotsTaken }}/{{ $regCapacity }}</span>
                                    {{ __('spots taken') }}
                                </span>
                            @endif
                        </div>

                        @if($regCapacity)
                            <div class="mt-3 h-1.5 w-full overflow-hidden rounded-full bg-slate-200">
                                <div class="h-full rounded-full bg-amber-500/80 transition-all duration-500"
                                    style="width: {{ $capacityRatio }}%"></div>
                            </div>
                        @endif

                        <div class="mt-3 space-y-1.5 text-xs font-medium text-slate-500">
                            @if($regOpensAt && $regOpensAt->isFuture())
                                <p class="flex items-center gap-1.5">
                                    <svg class="size-3.5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                        stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M12 6v6l4 2m6-2a10 10 0 11-20 0 10 10 0 0120 0z" />
                                    </svg>
                                    <span>{{ __('Opens') }}:
                                        {{ \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($regOpensAt, 'j M Y') }},
                                        {{ \App\Support\Timezone\UserDateTimeFormatter::format($regOpensAt, 'g:i A') }}</span>
                                </p>
                            @endif
                            @if($regClosesAt)
                                <p class="flex items-center gap-1.5">
                                    <svg class="size-3.5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                        stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M12 6v6l4 2m6-2a10 10 0 11-20 0 10 10 0 0120 0z" />
                                    </svg>
                                    <span>{{ __('Closes') }}:
                                        {{ \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($regClosesAt, 'j M Y') }},
                                        {{ \App\Support\Timezone\UserDateTimeFormatter::format($regClosesAt, 'g:i A') }}</span>
                                </p>
                            @endif
                        </div>
                    </div>
                </div>
            @endif

            {{-- Registration CTA --}}
            <div class="border-t border-slate-100/80 bg-slate-50/50 p-6">
                @if($event->accessPolicy?->registration_required)
                    @php
                        $regOpen = !$event->accessPolicy?->opens_at || $event->accessPolicy->opens_at <= now();
                        $regClosed = $event->accessPolicy?->closes_at && $event->accessPolicy->closes_at < now();
                        $atCapacity = $registrationMode === \AIArmada\Events\Enums\RegistrationMode::Required && $event->accessPolicy?->capacity && $this->registrationsCount >= $event->accessPolicy->capacity;
                    @endphp

                    @if($eventActionsDisabled)
                        <button disabled
                            class="flex w-full items-center justify-center rounded-2xl bg-slate-200 px-6 py-4 text-sm font-bold text-slate-500 cursor-not-allowed">
                            {{ $isPostponedWithoutConfirmedTime ? __('Pendaftaran ditutup sehingga tarikh disahkan') : __('Registration Closed') }}
                        </button>
                    @elseif($regClosed)
                        <button disabled
                            class="flex w-full items-center justify-center rounded-2xl bg-slate-200 px-6 py-4 text-sm font-bold text-slate-500 cursor-not-allowed">
                            {{ __('Registration Closed') }}
                        </button>
                    @elseif($atCapacity)
                        <button disabled
                            class="flex w-full items-center justify-center rounded-2xl bg-amber-100 px-6 py-4 text-sm font-bold text-amber-700 cursor-not-allowed">
                            {{ __('Fully Booked') }}
                        </button>
                    @elseif(!$regOpen)
                        <button disabled
                            class="flex w-full items-center justify-center rounded-2xl bg-slate-200 px-6 py-4 text-sm font-bold text-slate-600 cursor-not-allowed">
                            {{ __('Opens') }}
                            {{ \App\Support\Timezone\UserDateTimeFormatter::format($event->accessPolicy->opens_at, 'M d, h:i A') }}
                        </button>
                    @else
                        <a href="#register" @click.prevent="registerOpen = true"
                            data-signal-event="submission.event_registration_opened"
                            data-signal-category="submission"
                            data-signal-component="event_detail_registration"
                            data-signal-control="register_open"
                            data-signal-entity-type="event"
                            data-signal-entity-id="{{ $event->id }}"
                            data-signal-props='@json(['registration_mode' => $registrationMode->value])'
                            class="group relative flex w-full items-center justify-center overflow-hidden rounded-2xl bg-emerald-600 px-6 py-4 text-sm font-bold text-white shadow-lg shadow-emerald-500/20 transition-all hover:-translate-y-1 hover:shadow-xl hover:shadow-emerald-500/30">
                            <div
                                class="absolute inset-0 bg-gradient-to-r from-emerald-500 to-teal-400 opacity-0 transition-opacity duration-300 group-hover:opacity-100">
                            </div>
                            <span class="relative flex items-center gap-2">
                                {{ __('Register Now') }}
                                <svg class="size-4 transition-transform group-hover:translate-x-1" fill="none"
                                    viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                                </svg>
                            </span>
                            @if($registrationMode === \AIArmada\Events\Enums\RegistrationMode::Required && $event->accessPolicy?->capacity)
                                <span
                                    class="relative ml-2 text-xs opacity-80">({{ $event->accessPolicy->capacity - $this->registrationsCount }}
                                    {{ __('spots left') }})</span>
                            @endif
                        </a>
                    @endif
                @endif

                @auth
                    @if($hasPass && $passId)
                        <a href="{{ route('events.pass', ['event' => $event, 'pass' => $passId]) }}" target="_blank"
                            class="mt-3 flex w-full items-center justify-center gap-2 rounded-2xl border-2 border-emerald-200 bg-emerald-50 px-6 py-3 text-sm font-bold text-emerald-700 transition hover:bg-emerald-100">
                            <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z" />
                            </svg>
                            {{ __('Lihat Pas') }}
                        </a>
                    @endif
                @endauth
            </div>
        </div>

        {{-- DONATION --}}
        @if($event->donationChannel)
            <div
                class="overflow-hidden rounded-3xl border border-amber-200/60 bg-gradient-to-br from-amber-50 to-orange-50/50 shadow-xl shadow-amber-100/50">
                <div class="p-6">
                    <h3 class="flex items-center gap-3 font-heading text-lg font-bold text-amber-900">
                        <div class="flex size-10 items-center justify-center rounded-xl bg-amber-100 text-amber-600">
                            <svg class="size-5" fill="currentColor" viewBox="0 0 24 24">
                                <path
                                    d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2zm0 2v12h16V6H4zm2 3h12v2H6V9zm0 4h8v2H6v-2z" />
                            </svg>
                        </div>
                        {{ __('Support this Program') }}
                    </h3>
                    <div class="mt-5 rounded-2xl border border-amber-200/60 bg-white/60 p-5 backdrop-blur-sm">
                        <p class="text-xs font-bold uppercase tracking-widest text-amber-600">
                            {{ $event->donationChannel->bank_name }}
                        </p>
                        <p class="mt-1.5 font-mono text-xl font-bold tracking-tight text-amber-900">
                            {{ $event->donationChannel->account_number }}
                        </p>
                        <p class="mt-1 text-sm font-medium text-amber-800">{{ $event->donationChannel->account_name }}</p>
                        @if($event->donationChannel->reference_note)
                            <div
                                class="mt-3 inline-flex items-center gap-1.5 rounded-lg bg-amber-100/50 px-2.5 py-1 text-xs font-bold text-amber-700">
                                <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" />
                                </svg>
                                {{ __('Ref:') }} {{ $event->donationChannel->reference_note }}
                            </div>
                        @endif
                    </div>

                    {{-- QR Code --}}
                    @php $qrUrl = $event->donationChannel->getFirstMediaUrl('qr', 'thumb'); @endphp
                    @if($qrUrl)
                        <div class="mt-5 flex justify-center">
                            <div class="rounded-2xl border border-amber-200/60 bg-white p-3 shadow-sm">
                                <img src="{{ $qrUrl }}" alt="{{ __('QR Code') }}" class="size-32 rounded-xl" loading="lazy">
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        @endif

        {{-- GUEST BENEFITS CTA --}}
        @guest
            <div
                class="group relative overflow-hidden rounded-3xl border border-emerald-200/60 bg-gradient-to-br from-emerald-50 to-teal-50/50 p-6 shadow-xl shadow-emerald-100/50 transition-all hover:shadow-2xl hover:shadow-emerald-200/50">
                <div
                    class="absolute -right-10 -top-10 size-40 rounded-full bg-emerald-200/40 blur-3xl transition-opacity group-hover:opacity-100">
                </div>

                <div class="relative z-10">
                    <div class="flex items-center gap-3">
                        <div class="flex size-12 items-center justify-center rounded-2xl bg-emerald-100 text-emerald-600">
                            <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z" />
                            </svg>
                        </div>
                        <h3 class="font-heading text-xl font-bold text-slate-900">{{ __('Join ilmu360°') }}</h3>
                    </div>
                    <ul class="mt-5 space-y-3">
                        <li class="flex items-start gap-3 text-sm font-medium text-slate-700">
                            <div
                                class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-600">
                                <svg class="size-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                </svg>
                            </div>
                            {{ __('Simpan majlis & tandakan kehadiran') }}
                        </li>
                        <li class="flex items-start gap-3 text-sm font-medium text-slate-700">
                            <div
                                class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-600">
                                <svg class="size-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                </svg>
                            </div>
                            {{ __('Daftar untuk majlis yang memerlukan pendaftaran') }}
                        </li>
                        <li class="flex items-start gap-3 text-sm font-medium text-slate-700">
                            <div
                                class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-600">
                                <svg class="size-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                </svg>
                            </div>
                            {{ __('Dapatkan cadangan majlis yang berkaitan') }}
                        </li>
                        <li class="flex items-start gap-3 text-sm font-medium text-slate-700">
                            <div
                                class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-600">
                                <svg class="size-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                </svg>
                            </div>
                            {{ __('Hantar majlis anda sendiri') }}
                        </li>
                    </ul>
                    <div class="mt-6 space-y-3">
                        <a href="{{ \App\Support\Auth\IntendedRedirect::registerUrl(route('events.show', $event)) }}"
                            class="flex w-full items-center justify-center rounded-2xl bg-emerald-600 py-3.5 text-sm font-bold text-white shadow-lg shadow-emerald-500/20 transition-all hover:-translate-y-0.5 hover:bg-emerald-700 hover:shadow-xl hover:shadow-emerald-500/30">
                            {{ __('Daftar Akaun Percuma') }}
                        </a>
                        <a href="{{ \App\Support\Auth\IntendedRedirect::loginUrl(route('events.show', $event)) }}"
                            class="flex w-full items-center justify-center rounded-2xl border-2 border-emerald-200/60 bg-white/50 py-3 text-sm font-bold text-emerald-700 transition-all hover:border-emerald-300 hover:bg-white">
                            {{ __('Log Masuk') }}
                        </a>
                    </div>
                </div>
            </div>
        @endguest

        <div class="rounded-2xl border border-slate-200/70 bg-slate-50/80 p-4 shadow-sm">
            <div class="flex flex-col gap-4">
                <div>
                    <p class="text-[11px] font-bold uppercase tracking-[0.22em] text-slate-400">{{ __('Bantu Semak Majlis') }}</p>
                    <p class="mt-2 text-sm leading-6 text-slate-600">
                        {{ __('Jumpa maklumat yang perlu diperbetulkan atau majlis yang meragukan?') }}
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    @php($eventContributionRouteSegment = \App\Enums\ContributionSubjectType::Event->publicRouteSegment())
                    <a href="{{ route('contributions.suggest-update', ['subjectType' => $eventContributionRouteSegment, 'subjectId' => $event->slug]) }}" wire:navigate
                        class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-600 transition hover:border-sky-200 hover:bg-sky-50 hover:text-sky-700">
                        {{ __('Cadang Kemaskini') }}
                    </a>
                    <a href="{{ route('reports.create', ['subjectType' => $eventContributionRouteSegment, 'subjectId' => $event->slug]) }}" wire:navigate
                        class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-600 transition hover:border-rose-200 hover:bg-rose-50 hover:text-rose-700">
                        {{ __('Lapor') }}
                    </a>
                </div>
            </div>
        </div>
    </div>
</aside>
</div>

{{-- ==============================
SHARE MODAL
============================== --}}
<div x-show="shareModalOpen" x-cloak x-transition.opacity @keydown.escape.window="shareModalOpen = false"
    class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm" aria-modal="true" role="dialog">
    <div class="flex min-h-screen items-center justify-center p-4 sm:p-6">
        <div @click.away="shareModalOpen = false" x-show="shareModalOpen"
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0 translate-y-8 scale-95"
            x-transition:enter-end="opacity-100 translate-y-0 scale-100"
            x-transition:leave="transition ease-in duration-200"
            x-transition:leave-start="opacity-100 translate-y-0 scale-100"
            x-transition:leave-end="opacity-0 translate-y-8 scale-95"
            class="w-full max-w-xl overflow-hidden rounded-3xl bg-white shadow-2xl ring-1 ring-slate-200/50">

            <div class="flex items-center justify-between border-b border-slate-100 bg-slate-50/50 px-6 py-5">
                <h3 class="font-heading text-xl font-bold text-slate-900">{{ __('Share Preview') }}</h3>
                <button type="button" @click="shareModalOpen = false"
                    class="inline-flex size-10 items-center justify-center rounded-full bg-white text-slate-500 shadow-sm ring-1 ring-slate-200 transition hover:bg-slate-50 hover:text-slate-700">
                    <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <div class="p-6 sm:p-8">
                <article
                    class="overflow-hidden rounded-2xl border border-slate-200/60 bg-white shadow-lg shadow-slate-200/40">
                    <div class="relative h-56 overflow-hidden bg-slate-100">
                        <img src="{{ $sharePreviewImage }}" alt="{{ $event->title }}"
                            class="size-full {{ $eventHasPoster ? 'object-contain' : 'object-cover' }}" loading="lazy">
                        <div class="absolute inset-0 bg-gradient-to-t from-slate-900/60 to-transparent"></div>
                    </div>
                    <div class="p-5">
                        <h4 class="font-heading text-lg font-bold leading-tight text-slate-900">{{ $event->title }}</h4>
                        <p class="mt-2 flex items-center gap-1.5 text-sm font-medium text-emerald-600">
                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                            </svg>
                            {{ $sharePreviewDateTime }}
                        </p>
                        <p class="mt-3 line-clamp-2 text-sm leading-relaxed text-slate-600">
                            {{ Str::limit($event->description_text, 140) }}
                        </p>
                    </div>
                </article>

                <div class="mt-8 grid grid-cols-2 gap-4">
                    <button type="button" @click="nativeShare()"
                        data-signal-event="share.provider_clicked"
                        data-signal-category="share"
                        data-signal-component="event_detail_share_modal"
                        data-signal-control="native_share"
                        data-signal-entity-type="event"
                        data-signal-entity-id="{{ $event->id }}"
                        data-signal-props='@json(['provider' => 'native_share'])'
                        class="group relative flex items-center justify-center gap-2 overflow-hidden rounded-2xl bg-emerald-600 px-4 py-3.5 text-sm font-bold text-white shadow-lg shadow-emerald-500/20 transition-all hover:-translate-y-0.5 hover:shadow-xl hover:shadow-emerald-500/30">
                        <div
                            class="absolute inset-0 bg-gradient-to-r from-emerald-500 to-teal-400 opacity-0 transition-opacity duration-300 group-hover:opacity-100">
                        </div>
                        <span class="relative flex items-center gap-2">
                            <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684l6.632 3.316m-6.632-6l6.632-3.316" />
                            </svg>
                            {{ __('Share Now') }}
                        </span>
                    </button>
                    <button type="button" @click="copyLink()"
                        data-signal-event="share.provider_clicked"
                        data-signal-category="share"
                        data-signal-component="event_detail_share_modal"
                        data-signal-control="copy_link"
                        data-signal-entity-type="event"
                        data-signal-entity-id="{{ $event->id }}"
                        data-signal-props='@json(['provider' => 'copy_link'])'
                        class="flex items-center justify-center gap-2 rounded-2xl border-2 border-slate-200 bg-white px-4 py-3 text-sm font-bold text-slate-700 transition-all hover:border-emerald-500 hover:text-emerald-700 hover:shadow-md">
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M8 16h8M8 12h8m-6-8H6a2 2 0 00-2 2v12a2 2 0 002 2h8a2 2 0 002-2v-4" />
                        </svg>
                        {{ __('Copy Link') }}
                    </button>
                </div>

                <div x-show="copied" x-transition:enter="transition ease-out duration-300"
                    x-transition:enter-start="opacity-0 -translate-y-2"
                    x-transition:enter-end="opacity-100 translate-y-0"
                    class="mt-4 flex items-center justify-center gap-2 rounded-xl bg-emerald-50 py-2 text-sm font-bold text-emerald-600">
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                    </svg>
                    {{ $copyMessage }}
                </div>

                <div class="mt-8">
                    <p
                        class="mb-4 flex items-center justify-center gap-1.5 text-center text-xs font-bold uppercase tracking-widest text-slate-400">
                        <svg class="h-3.5 w-3.5 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                            stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z" />
                        </svg>
                        <span>{{ __('Or share via') }}</span>
                    </p>
                    <div class="grid grid-cols-4 gap-3">
                        <a href="{{ $shareLinks['whatsapp'] }}" target="_blank" rel="noopener" title="WhatsApp"
                            data-signal-event="share.provider_clicked"
                            data-signal-category="share"
                            data-signal-component="event_detail_share_modal"
                            data-signal-control="whatsapp"
                            data-signal-entity-type="event"
                            data-signal-entity-id="{{ $event->id }}"
                            data-signal-props='@json(['provider' => 'whatsapp'])'
                            class="inline-flex h-12 items-center justify-center rounded-2xl border border-slate-200/60 bg-slate-50 transition hover:-translate-y-1 hover:border-[#25D366] hover:bg-[#25D366]/10">
                            <img src="{{ asset('storage/social-media-icons/whatsapp.svg') }}" alt="WhatsApp"
                                class="h-6 w-6" loading="lazy">
                        </a>
                        <a href="{{ $shareLinks['telegram'] }}" target="_blank" rel="noopener" title="Telegram"
                            data-signal-event="share.provider_clicked"
                            data-signal-category="share"
                            data-signal-component="event_detail_share_modal"
                            data-signal-control="telegram"
                            data-signal-entity-type="event"
                            data-signal-entity-id="{{ $event->id }}"
                            data-signal-props='@json(['provider' => 'telegram'])'
                            class="inline-flex h-12 items-center justify-center rounded-2xl border border-slate-200/60 bg-slate-50 transition hover:-translate-y-1 hover:border-[#0088cc] hover:bg-[#0088cc]/10">
                            <img src="{{ asset('storage/social-media-icons/telegram.svg') }}" alt="Telegram"
                                class="h-6 w-6" loading="lazy">
                        </a>
                        <a href="{{ $shareLinks['threads'] }}" target="_blank" rel="noopener" title="Threads"
                            data-signal-event="share.provider_clicked"
                            data-signal-category="share"
                            data-signal-component="event_detail_share_modal"
                            data-signal-control="threads"
                            data-signal-entity-type="event"
                            data-signal-entity-id="{{ $event->id }}"
                            data-signal-props='@json(['provider' => 'threads'])'
                            class="inline-flex h-12 items-center justify-center rounded-2xl border border-slate-200/60 bg-slate-50 transition hover:-translate-y-1 hover:border-black hover:bg-black/10">
                            <img src="{{ asset('storage/social-media-icons/threads.svg') }}" alt="Threads" class="h-6 w-6"
                                loading="lazy">
                        </a>
                        <a href="{{ $shareLinks['facebook'] }}" target="_blank" rel="noopener" title="Facebook"
                            data-signal-event="share.provider_clicked"
                            data-signal-category="share"
                            data-signal-component="event_detail_share_modal"
                            data-signal-control="facebook"
                            data-signal-entity-type="event"
                            data-signal-entity-id="{{ $event->id }}"
                            data-signal-props='@json(['provider' => 'facebook'])'
                            class="inline-flex h-12 items-center justify-center rounded-2xl border border-slate-200/60 bg-slate-50 transition hover:-translate-y-1 hover:border-[#1877F2] hover:bg-[#1877F2]/10">
                            <img src="{{ asset('storage/social-media-icons/facebook.svg') }}" alt="Facebook"
                                class="h-6 w-6" loading="lazy">
                        </a>
                        <a href="{{ $shareLinks['x'] }}" target="_blank" rel="noopener" title="X"
                            data-signal-event="share.provider_clicked"
                            data-signal-category="share"
                            data-signal-component="event_detail_share_modal"
                            data-signal-control="x"
                            data-signal-entity-type="event"
                            data-signal-entity-id="{{ $event->id }}"
                            data-signal-props='@json(['provider' => 'x'])'
                            class="inline-flex h-12 items-center justify-center rounded-2xl border border-slate-200/60 bg-slate-50 transition hover:-translate-y-1 hover:border-slate-900 hover:bg-slate-900/10">
                            <img src="{{ asset('storage/social-media-icons/x.svg') }}" alt="X" class="h-6 w-6"
                                loading="lazy">
                        </a>
                        <a href="{{ $shareLinks['instagram'] }}" target="_blank" rel="noopener" @click="copyLink(false, 'instagram')"
                            title="Instagram"
                            data-signal-event="share.provider_clicked"
                            data-signal-category="share"
                            data-signal-component="event_detail_share_modal"
                            data-signal-control="instagram"
                            data-signal-entity-type="event"
                            data-signal-entity-id="{{ $event->id }}"
                            data-signal-props='@json(['provider' => 'instagram'])'
                            class="inline-flex h-12 items-center justify-center rounded-2xl border border-slate-200/60 bg-slate-50 transition hover:-translate-y-1 hover:border-[#E4405F] hover:bg-[#E4405F]/10">
                            <img src="{{ asset('storage/social-media-icons/instagram.svg') }}" alt="Instagram"
                                class="h-6 w-6" loading="lazy">
                        </a>
                        <a href="{{ $shareLinks['tiktok'] }}" target="_blank" rel="noopener" @click="copyLink(false, 'tiktok')"
                            title="TikTok"
                            data-signal-event="share.provider_clicked"
                            data-signal-category="share"
                            data-signal-component="event_detail_share_modal"
                            data-signal-control="tiktok"
                            data-signal-entity-type="event"
                            data-signal-entity-id="{{ $event->id }}"
                            data-signal-props='@json(['provider' => 'tiktok'])'
                            class="inline-flex h-12 items-center justify-center rounded-2xl border border-slate-200/60 bg-slate-50 transition hover:-translate-y-1 hover:border-black hover:bg-black/10">
                            <img src="{{ asset('storage/social-media-icons/tiktok.svg') }}" alt="TikTok" class="h-6 w-6"
                                loading="lazy">
                        </a>
                        <a href="{{ $shareLinks['email'] }}" title="Email"
                            data-signal-event="share.provider_clicked"
                            data-signal-category="share"
                            data-signal-component="event_detail_share_modal"
                            data-signal-control="email"
                            data-signal-entity-type="event"
                            data-signal-entity-id="{{ $event->id }}"
                            data-signal-props='@json(['provider' => 'email'])'
                            class="inline-flex h-12 items-center justify-center rounded-2xl border border-slate-200/60 bg-slate-50 transition hover:-translate-y-1 hover:border-emerald-500 hover:bg-emerald-500/10">
                            <img src="{{ asset('storage/social-media-icons/email.svg') }}" alt="Email" class="h-6 w-6"
                                loading="lazy">
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- ==============================
REGISTRATION MODAL
============================== --}}
@if($event->accessPolicy?->registration_required && !$eventActionsDisabled)
    <div x-show="registerOpen" x-cloak x-transition.opacity
        class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-sm">
        <div @click.away="registerOpen = false" x-show="registerOpen" x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0 translate-y-8 scale-95"
            x-transition:enter-end="opacity-100 translate-y-0 scale-100"
            x-transition:leave="transition ease-in duration-200"
            x-transition:leave-start="opacity-100 translate-y-0 scale-100"
            x-transition:leave-end="opacity-0 translate-y-8 scale-95"
            class="mx-4 w-full max-w-md overflow-hidden rounded-3xl bg-white shadow-2xl ring-1 ring-slate-200/50">

            <div class="bg-slate-50/50 px-8 py-6 border-b border-slate-100">
                <h3 class="font-heading text-2xl font-bold text-slate-900">{{ __('Register for this Event') }}</h3>
                <p class="mt-1 text-sm text-slate-500">{{ __('Please fill in your details below.') }}</p>
            </div>

            <form action="{{ route('events.register', $event) }}" method="POST"
                data-signal-submit-event="submission.event_registration_submitted"
                data-signal-category="submission"
                data-signal-component="event_detail_registration"
                data-signal-control="registration_form"
                data-signal-entity-type="event"
                data-signal-entity-id="{{ $event->id }}"
                data-signal-props='@json(['registration_mode' => $registrationMode->value])'
                class="p-8">
                @csrf
                <div class="space-y-5">
                    <div>
                        <label class="mb-1.5 block text-sm font-bold text-slate-700">{{ __('Name') }} <span
                                class="text-rose-500">*</span></label>
                        <input type="text" name="name" required
                            class="h-12 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 font-medium text-slate-900 transition focus:border-emerald-500 focus:bg-white focus:outline-none focus:ring-4 focus:ring-emerald-500/10">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-bold text-slate-700">{{ __('Email') }}</label>
                        <input type="email" name="email"
                            class="h-12 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 font-medium text-slate-900 transition focus:border-emerald-500 focus:bg-white focus:outline-none focus:ring-4 focus:ring-emerald-500/10">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-bold text-slate-700">{{ __('Phone') }}</label>
                        <input type="tel" name="phone"
                            class="h-12 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 font-medium text-slate-900 transition focus:border-emerald-500 focus:bg-white focus:outline-none focus:ring-4 focus:ring-emerald-500/10">
                    </div>
                    <div class="rounded-xl bg-blue-50 p-3">
                        <p class="flex items-start gap-2 text-xs font-medium text-blue-700">
                            <svg class="mt-0.5 size-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            {{ __('Please provide either email or phone number so we can send your registration confirmation.') }}
                        </p>
                    </div>
                </div>
                <div class="mt-8 flex gap-3">
                    <button type="button" @click="registerOpen = false"
                        class="flex-1 rounded-xl border-2 border-slate-200 bg-white px-4 py-3.5 text-sm font-bold text-slate-600 transition hover:border-slate-300 hover:bg-slate-50">
                        {{ __('Cancel') }}
                    </button>
                    <button type="submit"
                        class="group relative flex-1 overflow-hidden rounded-xl bg-emerald-600 px-4 py-3.5 text-sm font-bold text-white shadow-lg shadow-emerald-500/20 transition-all hover:-translate-y-0.5 hover:shadow-xl hover:shadow-emerald-500/30">
                        <div
                            class="absolute inset-0 bg-gradient-to-r from-emerald-500 to-teal-400 opacity-0 transition-opacity duration-300 group-hover:opacity-100">
                        </div>
                        <span class="relative">{{ __('Register') }}</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
@endif

{{-- ==============================
MOBILE BOTTOM ACTION BAR
============================== --}}
<div class="fixed inset-x-0 bottom-0 z-40 lg:hidden">
    <div class="border-t border-slate-200/60 bg-white/80 px-4 py-3 backdrop-blur-xl shadow-[0_-8px_30px_-15px_rgba(0,0,0,0.1)]"
        style="padding-bottom: max(0.75rem, env(safe-area-inset-bottom));">
            <div class="flex items-center gap-2">
            @auth
                @if(!$eventActionsDisabled)
                    <button type="button" wire:click="toggleGoing" wire:loading.attr="disabled"
                        data-signal-event="engagement.event_going_clicked"
                        data-signal-category="engagement"
                        data-signal-component="event_detail_mobile_actions"
                        data-signal-control="going"
                        data-signal-entity-type="event"
                        data-signal-entity-id="{{ $event->id }}"
                        data-signal-props='@json(['currently_going' => $isGoing])'
                        class="flex-1 inline-flex items-center justify-center gap-2 rounded-xl px-4 py-3 text-sm font-bold transition-all
                                                                                                                                                            {{ $isGoing
                    ? 'border-2 border-emerald-200 bg-emerald-50 text-emerald-700 shadow-inner'
                    : 'bg-emerald-600 text-white shadow-lg shadow-emerald-500/20 hover:bg-emerald-700' }}">
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            @if($isGoing)
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                            @else
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                            @endif
                        </svg>
                        {{ $isGoing ? __('Hadir') : __('Akan Hadir') }}
                    </button>

                    <button type="button" wire:click="checkIn" wire:loading.attr="disabled"
                        @disabled($checkInActionDisabled)
                        data-signal-event="engagement.event_check_in_clicked"
                        data-signal-category="engagement"
                        data-signal-component="event_detail_mobile_actions"
                        data-signal-control="check_in"
                        data-signal-entity-type="event"
                        data-signal-entity-id="{{ $event->id }}"
                        data-signal-props='@json(['available' => ! $checkInActionDisabled, 'checked_in' => $isCheckedIn])'
                        @if($checkInActionDisabled && filled($checkInReason)) title="{{ $checkInReason }}" @endif
                        class="rounded-xl border-2 p-3 transition-all
                        {{ $isCheckedIn
                            ? 'border-emerald-200 bg-emerald-50 text-emerald-600 shadow-inner'
                            : ($checkInActionDisabled
                                ? 'cursor-not-allowed border-slate-200 bg-slate-100 text-slate-300'
                                : 'border-emerald-200 bg-white text-emerald-600 hover:border-emerald-300 hover:bg-emerald-50') }}">
                        <svg class="size-5 {{ $isCheckedIn ? 'text-emerald-600' : '' }}" fill="none" viewBox="0 0 24 24"
                            stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                        </svg>
                    </button>

                    <button type="button" wire:click="toggleSave" wire:loading.attr="disabled"
                        data-signal-event="engagement.event_save_clicked"
                        data-signal-category="engagement"
                        data-signal-component="event_detail_mobile_actions"
                        data-signal-control="save"
                        data-signal-entity-type="event"
                        data-signal-entity-id="{{ $event->id }}"
                        data-signal-props='@json(['currently_saved' => $isSaved])'
                        class="rounded-xl border-2 p-3 transition-all
                                                                                                                                                            {{ $isSaved ? 'border-blue-200 bg-blue-50 text-blue-500 shadow-inner' : 'border-slate-200 bg-white text-slate-500 hover:border-blue-200 hover:text-blue-500' }}">
                        <svg class="size-5 {{ $isSaved ? 'fill-current' : '' }}" viewBox="0 0 24 24"
                            fill="{{ $isSaved ? 'currentColor' : 'none' }}" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M5 5a2 2 0 012-2h10a2 2 0 012 2v16l-7-3.5L5 21V5z" />
                        </svg>
                    </button>
                @else
                    <div class="flex-1 inline-flex items-center justify-center gap-2 rounded-xl border {{ $isPostponedWithoutConfirmedTime ? 'border-amber-200 bg-amber-50 text-amber-700' : 'border-rose-200 bg-rose-50 text-rose-700' }} px-4 py-3 text-sm font-bold">
                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="{{ $isPostponedWithoutConfirmedTime ? 'M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z' : 'M18.364 5.636a9 9 0 010 12.728m-12.728 0a9 9 0 010-12.728m12.728 12.728L5.636 5.636' }}" />
                        </svg>
                        {{ $isPostponedWithoutConfirmedTime ? __('Tarikh Belum Disahkan') : __('Majlis Dibatalkan') }}
                    </div>
                @endif

                @if($eventActionsDisabled && !$isCancelledStatus)
                    <button type="button" wire:click="toggleSave" wire:loading.attr="disabled"
                        data-signal-event="engagement.event_save_clicked"
                        data-signal-category="engagement"
                        data-signal-component="event_detail_mobile_actions"
                        data-signal-control="save"
                        data-signal-entity-type="event"
                        data-signal-entity-id="{{ $event->id }}"
                        data-signal-props='@json(['currently_saved' => $isSaved])'
                        class="rounded-xl border-2 p-3 transition-all
                                                                                                                                                            {{ $isSaved ? 'border-blue-200 bg-blue-50 text-blue-500 shadow-inner' : 'border-slate-200 bg-white text-slate-500 hover:border-blue-200 hover:text-blue-500' }}">
                        <svg class="size-5 {{ $isSaved ? 'fill-current' : '' }}" viewBox="0 0 24 24"
                            fill="{{ $isSaved ? 'currentColor' : 'none' }}" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M5 5a2 2 0 012-2h10a2 2 0 012 2v16l-7-3.5L5 21V5z" />
                        </svg>
                    </button>
                @endif
            @else
                <a href="{{ \App\Support\Auth\IntendedRedirect::loginUrl(route('events.show', $event)) }}"
                    class="flex-1 inline-flex items-center justify-center gap-2 rounded-xl bg-emerald-600 px-4 py-3 text-sm font-bold text-white shadow-lg shadow-emerald-500/20 transition hover:bg-emerald-700">
                    <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1" />
                    </svg>
                    {{ __('Log Masuk') }}
                </a>
            @endauth

            @if(!$eventActionsDisabled)
                <div class="relative" x-data="{ calendarOpen: false }">
                    <button type="button" @click="calendarOpen = !calendarOpen"
                        data-signal-event="engagement.calendar_opened"
                        data-signal-category="engagement"
                        data-signal-component="event_detail_mobile_actions"
                        data-signal-control="calendar"
                        data-signal-entity-type="event"
                        data-signal-entity-id="{{ $event->id }}"
                        class="rounded-xl border-2 border-slate-200 bg-white p-3 text-slate-500 transition-all hover:border-slate-300 hover:text-slate-700"
                        aria-label="{{ __('Tambah ke Kalendar') }}">
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                        </svg>
                    </button>

                    <div x-show="calendarOpen" @click.away="calendarOpen = false"
                        x-transition:enter="transition ease-out duration-200"
                        x-transition:enter-start="opacity-0 translate-y-2 scale-95"
                        x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                        x-transition:leave="transition ease-in duration-150"
                        x-transition:leave-start="opacity-100 translate-y-0 scale-100"
                        x-transition:leave-end="opacity-0 translate-y-2 scale-95"
                        class="absolute bottom-full right-0 z-50 mb-2 w-72 max-w-[85vw] overflow-hidden rounded-2xl border border-slate-200/60 bg-white/95 p-2 shadow-2xl backdrop-blur-xl"
                        x-cloak>
                        <a href="{{ $this->calendarLinks['google'] }}" target="_blank" rel="noopener"
                            class="flex items-center gap-3 rounded-xl px-4 py-3 transition hover:bg-slate-100">
                            <svg class="size-5 text-[#4285F4]" viewBox="0 0 24 24" fill="currentColor">
                                <path
                                    d="M19.5 22h-15A2.5 2.5 0 012 19.5v-15A2.5 2.5 0 014.5 2H9v2H4.5a.5.5 0 00-.5.5v15a.5.5 0 00.5.5h15a.5.5 0 00.5-.5V15h2v4.5a2.5 2.5 0 01-2.5 2.5z" />
                                <path d="M8 10h2v2H8v-2zm0 4h2v2H8v-2zm4-4h2v2h-2v-2zm0 4h2v2h-2v-2zm4-4h2v2h-2v-2z" />
                            </svg>
                            <span class="text-sm font-bold text-slate-700">Google Calendar</span>
                        </a>
                        <a href="{{ $this->calendarLinks['ics'] }}" download
                            class="flex items-center gap-3 rounded-xl px-4 py-3 transition hover:bg-slate-100">
                            <svg class="size-5 text-slate-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                            </svg>
                            <span class="text-sm font-bold text-slate-700">Apple / iCal (.ics)</span>
                        </a>
                        <a href="{{ $this->calendarLinks['outlook'] }}" target="_blank" rel="noopener"
                            class="flex items-center gap-3 rounded-xl px-4 py-3 transition hover:bg-slate-100">
                            <svg class="size-5 text-[#0078D4]" viewBox="0 0 24 24" fill="currentColor">
                                <path
                                    d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z" />
                            </svg>
                            <span class="text-sm font-bold text-slate-700">Outlook.com</span>
                        </a>
                        <a href="{{ $this->calendarLinks['office365'] }}" target="_blank" rel="noopener"
                            class="flex items-center gap-3 rounded-xl px-4 py-3 transition hover:bg-slate-100">
                            <svg class="size-5 text-[#D83B01]" viewBox="0 0 24 24" fill="currentColor">
                                <path
                                    d="M21 5H3a1 1 0 00-1 1v12a1 1 0 001 1h18a1 1 0 001-1V6a1 1 0 00-1-1zM3 6h18v2H3V6zm0 12V10h18v8H3z" />
                            </svg>
                            <span class="text-sm font-bold text-slate-700">Office 365</span>
                        </a>
                        <a href="{{ $this->calendarLinks['yahoo'] }}" target="_blank" rel="noopener"
                            class="flex items-center gap-3 rounded-xl px-4 py-3 transition hover:bg-slate-100">
                            <svg class="size-5 text-[#6001D2]" viewBox="0 0 24 24" fill="currentColor">
                                <path
                                    d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z" />
                            </svg>
                            <span class="text-sm font-bold text-slate-700">Yahoo Calendar</span>
                        </a>
                    </div>
                </div>
            @else
                <span class="rounded-xl border {{ $isPostponedWithoutConfirmedTime ? 'border-amber-200 bg-amber-50 text-amber-700' : 'border-rose-200 bg-rose-50 text-rose-700' }} px-3 py-2 text-xs font-bold">
                    {{ $isPostponedWithoutConfirmedTime ? __('Tarikh belum disahkan') : __('Kalendar ditutup') }}
                </span>
            @endif

            <button type="button" @click="openShareModal()"
                data-signal-event="share.modal_opened"
                data-signal-category="share"
                data-signal-component="event_detail_mobile_actions"
                data-signal-control="share"
                data-signal-entity-type="event"
                data-signal-entity-id="{{ $event->id }}"
                class="rounded-xl border-2 border-slate-200 bg-white p-3 text-slate-500 transition-all hover:border-slate-300 hover:text-slate-700">
                <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round"
                        d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z" />
                </svg>
            </button>
        </div>
    </div>
</div>
</div>
