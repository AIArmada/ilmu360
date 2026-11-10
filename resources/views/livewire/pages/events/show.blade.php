@push('head')
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700&display=swap">
@endpush

@section('title', $event->title.' - '.config('app.name'))
@section('meta_description', Str::limit($event->description_text !== '' ? $event->description_text : __('Lihat masa, lokasi, penceramah, dan maklumat pendaftaran untuk majlis ilmu ini di :app.', ['app' => config('app.name')]), 160))
@section('meta_og_type', 'event')
@section('meta_robots', $this->metaRobots)
@section('og_url', route('events.show', $event))
@php
    $shareCardOg = app(\App\Services\ShareCards\ShareCardService::class)->ogCard($event);
@endphp
@section('og_image', $shareCardOg['url'] ?? $event->card_image_url)
@section('og_image_alt', $shareCardOg['alt'] ?? __('Poster untuk :title', ['title' => $event->title]))
@section('og_image_width', $shareCardOg['url'] ? '1200' : '1024')
@section('og_image_height', $shareCardOg['url'] ? '630' : '1024')

@include('partials.filament-assets', [
    'scripts' => ['filament/support', 'filament/notifications'],
])

@push('head')
    <x-event-json-ld :event="$this->event" />
    <link rel="canonical" href="{{ route('events.show', $event) }}">
    <meta property="article:published_time" content="{{ $event->starts_at?->toIso8601String() }}">
@endpush

@php
    $detail = $this->detail;
    $viewerTimezone = \App\Platform\Time\UserTimezoneResolver::resolve();
    $occurrences = $detail->occurrences();
    $occurrenceCount = $occurrences->count();
    $firstOccurrence = $occurrences->first();
    $lastOccurrence = $occurrences->last();

    // ── Place ────────────────────────────────────────────────────────
    $eventLocation = $detail->primaryLocationFor($event);
    $locationVenue = $eventLocation?->relationLoaded('venue') ? $eventLocation->venue : null;
    $locationSpace = $eventLocation?->relationLoaded('venueSpace') ? $eventLocation->venueSpace : null;
    $primaryAddress = $eventLocation?->primaryAddress()
        ?? $locationVenue?->primaryAddress()
        ?? $event->resolvedLocationAddress();
    $addressDisplayLines = \App\Support\Location\AddressHierarchyFormatter::displayLines($primaryAddress);
    $venueDisplayName = $locationVenue?->name ?? $detail->locationLabel($eventLocation) ?? $event->resolvedLocationName();
    $hasLocationContent = filled($venueDisplayName) || $primaryAddress !== null;
    $lat = $primaryAddress?->latitude;
    $lng = $primaryAddress?->longitude;
    $mapQuery = implode(', ', array_filter([
        $locationVenue?->name,
        $detail->locationLabel($eventLocation),
        $primaryAddress?->line1,
        $primaryAddress?->line2,
        $addressDisplayLines['locality'] ?? null,
        $addressDisplayLines['regional'] ?? null,
        $primaryAddress?->city,
        $primaryAddress?->state,
    ]));
    $normalizedMapQuery = null;
    $locationMapUrl = $primaryAddress?->google_maps_url;
    if (filled($locationMapUrl)) {
        $queryString = parse_url((string) $locationMapUrl, PHP_URL_QUERY);
        if (is_string($queryString) && $queryString !== '') {
            parse_str($queryString, $queryParameters);
            $normalizedMapQuery = $queryParameters['query'] ?? $queryParameters['q'] ?? null;
        }
    }
    if (! filled($normalizedMapQuery) && $lat !== null && $lng !== null) {
        $normalizedMapQuery = $lat.','.$lng;
    }
    if (! filled($normalizedMapQuery)) {
        $normalizedMapQuery = $mapQuery;
    }
    $mapTarget = $lat !== null && $lng !== null
        ? $lat.','.$lng
        : (filled($normalizedMapQuery) ? (string) $normalizedMapQuery : null);
    $mapEmbedUrl = $mapTarget !== null
        ? 'https://www.google.com/maps?'.http_build_query([
            'q' => $mapTarget,
            'output' => 'embed',
            'z' => $lat !== null && $lng !== null ? 16 : 15,
            'hl' => str_replace('_', '-', (string) app()->getLocale()),
        ])
        : null;
    $navigationUrl = $mapTarget !== null
        ? 'https://www.google.com/maps/dir/?'.http_build_query(['api' => '1', 'destination' => $mapTarget])
        : null;
    $mapsUrl = filled($normalizedMapQuery)
        ? 'https://www.google.com/maps/search/?api=1&query='.urlencode((string) $normalizedMapQuery)
        : null;

    // ── People and identity ──────────────────────────────────────────
    $organizer = $event->organizer ?? $event->institution;
    $organizerHref = $organizer instanceof \App\Models\Institution
        ? route('institutions.show', $organizer)
        : ($organizer instanceof \App\Models\Person ? route('persons.show', $organizer) : null);
    $organizerCoverImage = match (true) {
        $organizer instanceof \App\Models\Institution => $organizer->getFirstMedia('cover'),
        default => null,
    };
    $organizerImage = match (true) {
        $organizer instanceof \App\Models\Institution => $organizer->getFirstMedia('logo'),
        default => null,
    };
    $organizerCoverImageUrl = $organizerCoverImage?->getAvailableUrl(['banner']);
    $organizerImageUrl = $organizerImage?->getAvailableUrl(['thumb']);
    $organizerInitial = filled($organizer?->name) ? mb_strtoupper(mb_substr((string) $organizer->name, 0, 1)) : null;
    $organizerAddress = $organizer instanceof \App\Models\Institution ? $organizer->primaryAddress() : null;
    $locationIsOrganizer = $hasLocationContent
        && $organizer instanceof \App\Models\Institution
        && $primaryAddress !== null
        && $organizerAddress !== null
        && (string) $organizerAddress->getKey() === (string) $primaryAddress->getKey();
    $placeTitle = $locationIsOrganizer ? $organizer->name : $venueDisplayName;
    $placeSpace = $locationSpace && $locationSpace->name !== $placeTitle ? $locationSpace->name : null;

    // The place card is the reader's visual anchor for "where is this?".
    // Prefer the actual venue/institution image, then the matching placeholder:
    // an empty media collection must not leave the card without a picture.
    $placeVenue = $locationVenue ?? $event->venue;
    $venuePlaceImageUrl = $placeVenue !== null
        ? ($placeVenue->getFirstMedia('main')?->getAvailableUrl(['thumb'])
            ?? $placeVenue->getFirstMedia('cover')?->getAvailableUrl(['thumb'])
            ?? asset('images/placeholders/venue.png'))
        : null;
    $institutionPlaceImageUrl = $organizer instanceof \App\Models\Institution
        ? ($organizerCoverImageUrl ?? asset('images/placeholders/institution-v2.png'))
        : null;
    $locationImageUrl = match (true) {
        $locationIsOrganizer && $institutionPlaceImageUrl !== null => $institutionPlaceImageUrl,
        $venuePlaceImageUrl !== null => $venuePlaceImageUrl,
        $institutionPlaceImageUrl !== null => $institutionPlaceImageUrl,
        default => null,
    };

    $classificationLabels = $event->classifications
        ->filter(fn ($classification): bool => $classification->term !== null)
        ->map(fn ($classification): string => (string) $classification->term->name)
        ->unique()
        ->values();
    $languageLabels = $this->languageLabels;
    $audienceLabels = $event->audiences
        ->reject(fn ($audience): bool => in_array($audience->audience_type, ['gender', 'age_group', 'religion'], true))
        ->map(fn ($audience): ?string => filled($audience->value) ? (string) $audience->value : null)
        ->filter()
        ->unique()
        ->values();
    $genderRestriction = $event->gender instanceof \App\Enums\EventGenderRestriction
        ? $event->gender
        : \App\Enums\EventGenderRestriction::tryFrom((string) $event->gender);
    $muslimOnly = (bool) $event->is_muslim_only;
    $keyPeopleByRole = $this->keyPeopleByRole;
    $galleryImages = $this->galleryImages;

    $eventFormatLabel = match ((string) $event->delivery_mode) {
        \App\Enums\EventFormat::Online->value => __('Dalam talian'),
        \App\Enums\EventFormat::Hybrid->value => __('Hibrid'),
        default => __('Fizikal'),
    };

    // ── Media ────────────────────────────────────────────────────────
    // Real uploads only: card_image_url falls back to a stock placeholder,
    // which crops badly behind the hero type.
    $heroImageUrl = $event->getFirstMedia('cover')?->getAvailableUrl(['thumb'])
        ?? $event->getFirstMedia('poster')?->getAvailableUrl(['poster_thumb']);
    $eventPosterUrl = $event->getFirstMedia('poster')?->getAvailableUrl(['poster_thumb']);
    // Permanent ambient art: a rotating backdrop from the site's own set,
    // never the event cover. The cover itself lives in the body.
    $heroBackdropUrl = \App\Support\Events\HeroBackdrop::randomUrl();

    // ── Actions ──────────────────────────────────────────────────────
    $eventActionsDisabled = $this->eventActionsDisabled();
    $checkInOffered = $this->checkInOffered;
    $canEditEvent = auth()->user()?->hasAnyRole(['super_admin', 'admin']) ?? false;
    $scheduleLeaves = $this->scheduleLeaves;
    $savedEventIds = $this->isSaved ? [(string) $event->getKey()] : [];
    $changeNotice = $this->activeChangeNotice;
    $replacementEvent = $this->replacementEvent;

    $eventShareUrl = route('events.show', $event);
    $shareLinks = $this->shareLinks;
    $shareProviders = collect([
        'whatsapp' => __('WhatsApp'),
        'telegram' => __('Telegram'),
        'facebook' => __('Facebook'),
        'x' => __('X'),
        'email' => __('E-mel'),
    ])->filter(fn (string $label, string $provider): bool => filled($shareLinks[$provider] ?? null));

    // ── Hero fact band ───────────────────────────────────────────────
    $firstDateLabel = $firstOccurrence?->starts_at
        ? \App\Platform\Time\UserDateTimeFormatter::translatedFormat($firstOccurrence->starts_at->copy()->timezone($viewerTimezone), 'j M Y')
        : __('Akan dikemas kini');
    $lastDateLabel = $lastOccurrence?->starts_at
        ? \App\Platform\Time\UserDateTimeFormatter::translatedFormat($lastOccurrence->starts_at->copy()->timezone($viewerTimezone), 'j M Y')
        : null;
    $heroFacts = collect([
        ['label' => $occurrenceCount > 1 ? __('Tarikh pertama') : __('Tarikh'), 'value' => $firstDateLabel],
        ['label' => __('Tarikh akhir'), 'value' => $occurrenceCount > 1 ? $lastDateLabel : null],
        ['label' => __('Tempat'), 'value' => $placeTitle ?: $venueDisplayName],
        ['label' => __('Format'), 'value' => $eventFormatLabel],
    ])->filter(fn (array $fact): bool => filled($fact['value']))->values();
    $heroFactGridClass = match ($heroFacts->count()) {
        1 => 'sm:grid-cols-1',
        2 => 'sm:grid-cols-2',
        3 => 'sm:grid-cols-3',
        default => 'sm:grid-cols-2 lg:grid-cols-4',
    };
@endphp

<div
    class="event-detail-page min-h-screen bg-[#f4f0e7] text-[#14342e]"
    x-data="{
        shareOpen: false,
        posterOpen: false,
        copied: false,
        shareUrl: @js($eventShareUrl),
        shareTitle: @js($event->title),
        openShare() {
            this.shareOpen = true;
            this.copied = false;
        },
        async copyLink() {
            try {
                await navigator.clipboard.writeText(this.shareUrl);
                this.copied = true;
                setTimeout(() => { this.copied = false; }, 2400);
            } catch (error) {}
        },
        async nativeShare() {
            if (! navigator.share) {
                await this.copyLink();
                return;
            }

            try {
                await navigator.share({ title: this.shareTitle, url: this.shareUrl });
            } catch (error) {}
        }
    }"
>
    {{-- ── Hero ────────────────────────────────────────────────────── --}}
    <header class="event-detail-hero relative isolate overflow-hidden bg-[#07231f] text-[#f7f3e8]">
        {{-- Ambient art: the room behind the majlis. A photograph carries
             warmth into the panel; the emerald wash keeps the type legible. --}}
        <div class="pointer-events-none absolute inset-0" aria-hidden="true">
            <img
                src="{{ $heroBackdropUrl }}"
                alt=""
                class="absolute inset-0 h-full w-full object-cover object-right"
                loading="eager"
                fetchpriority="low"
            >
            <div class="absolute inset-0 bg-[radial-gradient(95%_110%_at_8%_0%,rgba(242,200,103,0.18),transparent_58%)]"></div>
            {{-- Mobile keeps the photo full-bleed under the copy, so it needs
                 its own scrim; wider layouts rely on the dark right of frame. --}}
            <div class="absolute inset-0 bg-[linear-gradient(180deg,rgba(7,35,31,0.88)_0%,rgba(7,35,31,0.66)_42%,rgba(7,35,31,0.40)_100%)] sm:hidden"></div>
            <div class="absolute inset-x-0 bottom-0 h-32 bg-gradient-to-t from-[#07231f] via-[#07231f]/60 to-transparent"></div>
        </div>

        <div class="relative mx-auto max-w-7xl px-4 pt-32 sm:px-8 sm:pt-36 lg:px-12 lg:pt-40">
            <div class="grid items-stretch gap-10 {{ $heroImageUrl ? 'lg:grid-cols-[minmax(0,1fr)_minmax(0,28rem)] lg:gap-10 xl:gap-12' : '' }}">
                <div class="flex min-w-0 flex-col justify-center">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                        <span class="text-[11px] font-semibold uppercase tracking-[0.26em] text-[#f2c867]">{{ __('Majlis Ilmu') }}</span>
                        @if($occurrenceCount > 1)
                            <span class="size-1 rounded-full bg-[#f2c867]/40" aria-hidden="true"></span>
                            <span class="text-[11px] font-semibold uppercase tracking-[0.26em] text-[#f7f3e8]/70">{{ trans_choice(':count tarikh', $occurrenceCount, ['count' => $occurrenceCount]) }}</span>
                        @endif
                    </div>

                    <h1 class="mt-6 max-w-[38rem] font-heading text-4xl font-semibold tracking-tight text-balance sm:text-5xl xl:text-6xl">
                        {{ $event->title }}
                    </h1>

                    @include('livewire.pages.events.partials.event-status-notice')

                    @if(trim((string) $event->summary) !== '')
                        <p class="mt-6 max-w-[42ch] text-[15px] leading-7 text-[#f7f3e8]/80">{{ $event->summary }}</p>
                    @endif

                    <div class="mt-9 flex flex-wrap items-center gap-3">
                        <a href="#tarikh" class="inline-flex items-center gap-2 rounded-full bg-[#f2c867] px-6 py-3 text-sm font-semibold text-[#0e2e27] transition duration-300 ease-[cubic-bezier(0.22,1,0.36,1)] hover:bg-[#f7d98d] active:scale-[0.98] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#f2c867]">
                            {{ __('Lihat :count tarikh', ['count' => $occurrenceCount]) }}
                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M13 6l6 6-6 6" /></svg>
                        </a>
                        <a href="#event-share-panel" class="inline-flex items-center gap-2 rounded-full border border-[#f7f3e8]/40 px-5 py-3 text-sm font-semibold text-[#f7f3e8] transition duration-300 ease-[cubic-bezier(0.22,1,0.36,1)] hover:border-[#f2c867]/60 hover:bg-[#f7f3e8]/10 active:scale-[0.98] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#f2c867]">
                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684zm0 9.316a3 3 0 105.368 2.684z" /></svg>
                            {{ __('Kongsi') }}
                        </a>
                        @if($eventPosterUrl)
                            <button type="button" @click="posterOpen = true" data-signal-event="engagement.poster_opened" data-signal-category="engagement" data-signal-component="event_detail_hero" data-signal-control="poster" data-signal-entity-type="event" data-signal-entity-id="{{ $event->id }}" class="inline-flex items-center gap-2 rounded-full border border-[#f7f3e8]/40 px-5 py-3 text-sm font-semibold text-[#f7f3e8]">{{ __('Lihat poster') }}</button>
                        @endif
                    </div>
                </div>

                @if($heroImageUrl)
                    <div class="relative">
                        <figure class="mi-plate aspect-[4/3] overflow-hidden rounded-2xl" data-testid="event-hero-media">
                            <img src="{{ $heroImageUrl }}" alt="{{ $event->title }}" class="size-full object-cover" loading="eager">
                        </figure>
                    </div>
                @endif
            </div>

            @if($heroFacts->isNotEmpty())
                <dl class="mt-12 grid grid-cols-2 gap-x-8 gap-y-6 border-t border-[#f7f3e8]/12 py-8 sm:mt-12 {{ $heroFactGridClass }}">
                    @foreach($heroFacts as $fact)
                        <div class="min-w-0">
                            <dt class="text-xs font-semibold tracking-wide text-[#f2c867]">{{ $fact['label'] }}</dt>
                            <dd class="mt-2 font-heading text-base font-medium text-balance text-[#fdfaf2]">{{ $fact['value'] }}</dd>
                        </div>
                    @endforeach
                </dl>
            @endif
        </div>
    </header>

    {{-- ── Action strip ────────────────────────────────────────────── --}}
    @include('livewire.pages.events.partials.event-action-strip', [
        'event' => $event,
        'eventActionsDisabled' => $eventActionsDisabled,
        'checkInOffered' => $checkInOffered,
        'canEditEvent' => $canEditEvent,
    ])

    {{-- ── Change notice ───────────────────────────────────────────── --}}
    @if($changeNotice)
        <div class="mx-auto max-w-7xl px-5 pt-8 sm:px-8 lg:px-12">
            <div class="flex gap-3.5 rounded-2xl border border-[#e6d3a3] bg-[#fdf7e7] p-5">
                <svg class="mt-0.5 size-5 shrink-0 text-[#a3730f]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m0 3.75h.008M10.29 3.86l-8.1 14A2 2 0 003.92 21h16.16a2 2 0 001.73-3.14l-8.1-14a2 2 0 00-3.42 0z" /></svg>
                <div class="min-w-0 flex-1">
                    <p class="font-heading text-lg leading-snug text-[#4a3711]">{{ $changeNotice->title ?: __('Kemaskini majlis') }}</p>
                    @if(filled($changeNotice->message))
                        <p class="mt-1.5 text-sm leading-6 text-[#5f4a1c]">{{ $changeNotice->message }}</p>
                    @endif
                    @if($replacementEvent instanceof \App\Models\Event)
                        <a href="{{ route('events.show', $replacementEvent) }}" wire:navigate class="mt-3.5 inline-flex items-center gap-2 rounded-full bg-[#14342e] px-4 py-2 text-[13px] font-semibold text-[#fdfaf2] transition duration-300 ease-[cubic-bezier(0.22,1,0.36,1)] hover:bg-[#1d4a41] active:scale-[0.98] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#14342e]">
                            {{ __('Lihat majlis pengganti') }}
                            <span aria-hidden="true">→</span>
                        </a>
                    @endif
                </div>
            </div>
        </div>
    @endif

    {{-- ── Body ────────────────────────────────────────────────────── --}}
    <div class="mx-auto max-w-7xl px-5 sm:px-8 lg:px-12">
        <div class="grid gap-x-16 gap-y-16 pt-8 pb-6 lg:grid-cols-[minmax(0,1fr)_20rem] lg:pt-10 lg:pb-8">

            <div class="min-w-0 space-y-16">

                {{-- The listing. This is the reason the page exists: an event
                     with several dates must let the reader pick one, not dump
                     every date's detail onto a single page. --}}
                <section id="tarikh" class="scroll-mt-8" aria-labelledby="event-dates-title">
                    <div class="mb-7">
                        <span class="block h-[2px] w-7 bg-[#c9962f]" aria-hidden="true"></span>
                        <p class="mt-4 text-[11px] font-semibold uppercase tracking-[0.26em] text-[#8a5f13]">{{ __('Jadual') }}</p>
                        <div class="mt-2 flex flex-wrap items-baseline justify-between gap-x-6 gap-y-1">
                            <h2 id="event-dates-title" class="font-heading text-[1.75rem] leading-[1.15] tracking-[-0.01em] text-balance">{{ __('Pilih tarikh anda') }}</h2>
                            <p class="font-mono text-xs text-[#6b645e]">{{ trans_choice(':count tarikh', $occurrenceCount, ['count' => $occurrenceCount]) }}</p>
                        </div>
                    </div>

                    <div class="flex flex-wrap justify-center gap-4 *:w-full sm:*:w-[calc(50%_-_0.5rem)]">
                        @foreach($scheduleLeaves as $scheduleLeaf)
                            <div data-testid="event-occurrence-{{ $scheduleLeaf->id() }}">
                                <x-events.schedule-card
                                    :schedule-leaf="$scheduleLeaf"
                                    :saved-event-ids="$savedEventIds"
                                    :is-going="$isGoing"
                                    signal-component="event_detail_schedule"
                                    testid-prefix="event-detail-schedule-card"
                                    :wire-key="'event-detail-schedule-'.$scheduleLeaf->entityType().'-'.$scheduleLeaf->id()"
                                />
                            </div>
                        @endforeach
                    </div>
                </section>

                @php
                    $ticketEntries = $detail->ticketEntries();
                    $ticketScopeCount = $ticketEntries->pluck('scope_label')->unique()->count();
                    $policy = $detail->policiesForScope($event)->first();
                    $paymentRequired = $detail->hasPaidTickets() || (bool) $policy?->payment_required;
                    $remaining = $detail->effectiveCapacityRemaining($event);
                    $eventRegistration = $detail->scopeHasRegistrationOffering($event);
                    $registrationOpen = ! $eventActionsDisabled
                        && ! ($policy?->closes_at?->isPast() ?? false)
                        && ! ($policy?->opens_at?->isFuture() ?? false)
                        && ($remaining === null || $remaining > 0);
                @endphp
                @if($ticketEntries->isNotEmpty() || $detail->hasRegistration() || $detail->capacityFor($event) !== null)
                    <section id="penyertaan" data-testid="event-admission-section" class="scroll-mt-8" aria-labelledby="event-participation-title">
                        <h2 id="event-participation-title" class="font-heading text-[1.75rem] text-[#14342e]">{{ __('Cara menyertai') }}</h2>
                        <div class="mi-paper mt-6 rounded-2xl border border-[#e6dccb] bg-[#fffdf9] p-6">
                            @if($ticketEntries->isNotEmpty())
                                <div class="space-y-3">
                                    @foreach($ticketEntries as $ticketEntry)
                                        @include('livewire.pages.events.partials.admission-ticket-card', ['ticketEntry' => $ticketEntry, 'ticketScopeCount' => $ticketScopeCount, 'detail' => $detail, 'event' => $event, 'eventActionsDisabled' => $eventActionsDisabled])
                                    @endforeach
                                </div>
                            @endif
                            @include('livewire.pages.events.partials.admission-capacity', ['capacityEntries' => $detail->capacityEntries()])
                            @if(filled($policy?->notes))
                                <p class="mt-4 text-sm leading-7 text-[#4c5b56]">{{ $policy->notes }}</p>
                            @endif
                            @if($eventRegistration && $ticketEntries->isEmpty() && ! $paymentRequired && $registrationOpen)
                                <a href="{{ route('events.checkout', ['event' => $event]) }}" wire:navigate data-signal-event="commerce.event_checkout_started" data-signal-category="commerce" data-signal-component="event_detail_registration" data-signal-control="register_open" data-signal-entity-type="event" data-signal-entity-id="{{ $event->id }}" class="mt-5 inline-flex rounded-full bg-[#14342e] px-5 py-3 text-sm font-semibold text-white">{{ __('Register Now') }}</a>
                            @elseif($eventRegistration && ! $registrationOpen)
                                <p class="mt-4 text-sm text-[#6b645e]">{{ __('Pendaftaran dalam talian ditutup buat masa ini.') }}</p>
                            @endif
                            @if($paymentRequired && ! \App\Support\Commerce\EventCommerceModes::publicPaidCheckoutEnabled())
                                <p class="mt-4 text-sm text-amber-900">{{ __('Maklumat tiket dipaparkan di sini. Pembelian tiket dalam talian belum tersedia untuk majlis ini.') }}</p>
                            @endif
                        </div>
                    </section>
                @endif
                @include('livewire.pages.events.partials.admission-seating', ['detail' => $detail, 'scopeId' => 'majlis'])

                @include('livewire.pages.events.partials.shared-event-info', [
                    'event' => $event,
                    'occurrence' => null,
                    'detail' => $detail,
                    'languageLabels' => $languageLabels,
                    'audienceLabels' => $audienceLabels,
                    'muslimOnly' => $muslimOnly,
                    'genderRestriction' => $genderRestriction,
                    'keyPeopleByRole' => $keyPeopleByRole,
                    'organizer' => $organizer,
                    'organizerHref' => $organizerHref,
                    'hideOrganizer' => true,
                    'galleryImages' => $galleryImages,
                ])

                {{-- Location --}}
                @if($hasLocationContent)
                    <section id="lokasi" class="scroll-mt-8" aria-labelledby="event-location-title">
                        <div class="mb-7">
                            <span class="block h-[2px] w-7 bg-[#c9962f]" aria-hidden="true"></span>
                            <p class="mt-4 text-[11px] font-semibold uppercase tracking-[0.26em] text-[#8a5f13]">{{ __('Tempat') }}</p>
                            <h2 id="event-location-title" class="mt-2 font-heading text-[1.75rem] leading-[1.15] tracking-[-0.01em] text-balance">{{ __('Lokasi majlis') }}</h2>
                        </div>

                        <div class="mi-paper overflow-hidden rounded-2xl border border-[#e6dccb] bg-[#fffdf9]">
                            <div class="grid lg:grid-cols-[minmax(0,1fr)_minmax(0,22rem)]">
                                <div class="p-6 sm:p-7">
                                    <h3 class="font-heading text-xl leading-snug text-[#14342e]">{{ $placeTitle }}</h3>
                                    @if($placeSpace)
                                        <p class="mt-1 text-[13px] font-medium text-[#8a5f13]">{{ $placeSpace }}</p>
                                    @endif
                                    @if($primaryAddress)
                                        <address class="mt-4 border-t border-[#f0e8d9] pt-4 text-[14px] leading-7 text-[#4c5b56] not-italic">
                                            @if(filled($primaryAddress->line1))<span class="block">{{ $primaryAddress->line1 }}</span>@endif
                                            @if(filled($primaryAddress->line2))<span class="block">{{ $primaryAddress->line2 }}</span>@endif
                                            @if(filled($addressDisplayLines['locality'] ?? null))<span class="block">{{ $addressDisplayLines['locality'] }}</span>@endif
                                            @if(filled($addressDisplayLines['regional'] ?? null))<span class="block">{{ $addressDisplayLines['regional'] }}</span>@endif
                                        </address>
                                    @endif
                                    <div class="mt-5 flex flex-wrap items-center gap-2.5">
                                        @if($navigationUrl)
                                            <a href="{{ $navigationUrl }}" target="_blank" rel="noopener" class="inline-flex items-center gap-2 rounded-full bg-[#14342e] px-4 py-2.5 text-[13px] font-semibold text-[#fdfaf2] transition duration-300 ease-[cubic-bezier(0.22,1,0.36,1)] hover:bg-[#1d4a41] active:scale-[0.98] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#14342e]">
                                                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.27 3.13a59.77 59.77 0 0118.18 8.6c.36.36.36.94 0 1.3a59.77 59.77 0 01-18.18 8.6L6 12zm0 0h6.75" /></svg>
                                                {{ __('Navigasi ke sini') }}
                                            </a>
                                        @endif
                                        @if($mapsUrl)
                                            <a href="{{ $mapsUrl }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 rounded-full px-2 py-2 text-[12px] font-medium text-[#6b645e] transition-colors duration-300 hover:text-[#14342e] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#14342e]">
                                                {{ __('Buka dalam Google Maps') }}
                                                <svg class="size-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M7 17L17 7M9 7h8v8" /></svg>
                                            </a>
                                        @endif
                                    </div>
                                </div>
                                @if($mapEmbedUrl)
                                    <div class="min-h-[14rem] border-t border-[#f0e8d9] lg:border-l lg:border-t-0">
                                        <iframe
                                            src="{{ $mapEmbedUrl }}"
                                            title="{{ __('Peta lokasi :venue', ['venue' => $placeTitle]) }}"
                                            class="block size-full min-h-[14rem] border-0"
                                            loading="lazy"
                                            referrerpolicy="no-referrer-when-downgrade"
                                            allowfullscreen
                                        ></iframe>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </section>
                @endif

                {{-- References --}}
                @php
                    $eventReferences = $event->relationLoaded('references') ? $event->references : collect();
                @endphp
                @if($eventReferences->isNotEmpty())
                    <section id="rujukan" class="scroll-mt-8" aria-labelledby="event-references-title">
                        <div class="mb-7">
                            <div class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-1">
                                <h2 id="event-references-title" class="font-heading text-[1.75rem] leading-[1.15] tracking-[-0.01em] text-balance">{{ __('References') }}</h2>
                                <p class="font-mono text-xs text-[#6b645e]">{{ $eventReferences->count() }}</p>
                            </div>
                        </div>

                        <ol class="mi-paper divide-y divide-[#f0e8d9] overflow-hidden rounded-2xl border border-[#e6dccb] bg-[#fffdf9]">
                            @foreach($eventReferences as $reference)
                                @php
                                    $referenceCoverUrl = $reference->getFirstMediaUrl('front_cover', 'thumb')
                                        ?: $reference->getFirstMediaUrl('front_cover')
                                        ?: $reference->getFirstMediaUrl('back_cover', 'thumb')
                                        ?: $reference->getFirstMediaUrl('back_cover');
                                @endphp
                                <li class="relative flex gap-4 px-4 py-4 transition duration-300 hover:bg-[#faf6ed] sm:px-6 sm:py-5">
                                    @if(filled($referenceCoverUrl))
                                        <img src="{{ $referenceCoverUrl }}" alt="" aria-hidden="true" loading="lazy" class="w-20 shrink-0 self-start rounded-lg border border-[#e6dccb] bg-[#f4f0e7] object-cover shadow-sm aspect-[3/4] sm:w-24">
                                    @endif
                                    <div class="min-w-0">
                                        <p class="font-heading text-lg leading-snug text-[#14342e]">
                                            <a href="{{ route('references.show', $reference) }}" wire:navigate class="after:absolute after:inset-0 underline decoration-[#c9bfae] underline-offset-4 transition-colors duration-300 hover:text-[#8a5f13] hover:decoration-[#c9962f] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#14342e]">{{ $reference->displayTitle() }}</a>
                                        </p>
                                        @if($reference->effectiveAuthorNamesList() !== [])
                                            <div class="mt-2 space-y-0.5 text-[13px] text-[#6b645e]">
                                                @foreach($reference->effectiveAuthorNamesList() as $authorName)
                                                    <p>{{ $authorName }}</p>
                                                @endforeach
                                            </div>
                                        @endif
                                        @if(filled($reference->publisher))
                                            <p class="mt-0.5 text-[12px] text-[#8b8272]">{{ $reference->publisher }}</p>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ol>
                    </section>
                @endif

                {{-- Links and materials --}}
                @php
                    $resourceRows = collect();
                    foreach ($detail->linkEntries() as $linkEntry) {
                        $resourceRows->push(['label' => $detail->linkTypeLabel($linkEntry['link']), 'url' => $linkEntry['link']->url, 'kind' => 'link']);
                    }
                    foreach ($detail->materialEntries() as $material) {
                        $resourceRows->push(['label' => $material->title, 'url' => $material->url, 'kind' => 'material']);
                    }
                    $rawEventUrls = collect([
                        ['url' => $event->event_url, 'label' => __('Laman rasmi')],
                        ['url' => $event->live_url, 'label' => __('Siaran langsung')],
                    ])->filter(fn (array $row): bool => filled($row['url']));
                @endphp
                @if($resourceRows->isNotEmpty() || $rawEventUrls->isNotEmpty())
                    <section id="pautan" class="scroll-mt-8" aria-labelledby="event-links-title">
                        <div class="mb-7">
                            <h2 id="event-links-title" class="font-heading text-[1.75rem] leading-[1.15] tracking-[-0.01em] text-balance">{{ __('Pautan & bahan') }}</h2>
                        </div>

                        <ul class="mi-paper divide-y divide-[#f0e8d9] overflow-hidden rounded-2xl border border-[#e6dccb] bg-[#fffdf9]">
                            @foreach($resourceRows as $row)
                                <li>
                                    <a href="{{ $row['url'] }}" target="_blank" rel="noopener" class="group flex items-center gap-4 px-6 py-4 transition-colors duration-300 hover:bg-[#faf6ed] focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-[#14342e]">
                                        <span class="flex size-9 shrink-0 items-center justify-center rounded-[10px] bg-[#f3efe6] text-[#14342e]">
                                            @if($row['kind'] === 'material')
                                                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" /></svg>
                                            @else
                                                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 00-6.364-6.364l-4.5 4.5a4.5 4.5 0 001.242 7.244" /></svg>
                                            @endif
                                        </span>
                                        <span class="min-w-0 flex-1 truncate text-[15px] font-medium text-[#14342e]">{{ $row['label'] }}</span>
                                        <svg class="size-4 shrink-0 text-[#c9bfae] transition duration-300 ease-[cubic-bezier(0.22,1,0.36,1)] group-hover:translate-x-0.5 group-hover:text-[#c9962f]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M7 17L17 7M9 7h8v8" /></svg>
                                    </a>
                                </li>
                            @endforeach
                            @foreach($rawEventUrls as $rawUrl)
                                <li>
                                    <a href="{{ $rawUrl['url'] }}" target="_blank" rel="noopener" class="group flex items-center gap-4 px-6 py-4 transition-colors duration-300 hover:bg-[#faf6ed] focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-[#14342e]">
                                        <span class="flex size-9 shrink-0 items-center justify-center rounded-[10px] bg-[#f3efe6] text-[#14342e]">
                                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 00-6.364-6.364l-4.5 4.5a4.5 4.5 0 001.242 7.244" /></svg>
                                        </span>
                                        <span class="min-w-0 flex-1 truncate text-[15px] font-medium text-[#14342e]">{{ $rawUrl['label'] }}</span>
                                        <svg class="size-4 shrink-0 text-[#c9bfae] transition duration-300 ease-[cubic-bezier(0.22,1,0.36,1)] group-hover:translate-x-0.5 group-hover:text-[#c9962f]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M7 17L17 7M9 7h8v8" /></svg>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif

            </div>

            {{-- ── Sidebar ─────────────────────────────────────────────── --}}
            <aside class="min-w-0 space-y-4 lg:sticky lg:top-8 lg:self-start">
                @if($hasLocationContent || $organizer)
                    <section class="mi-paper overflow-hidden rounded-2xl border border-[#e6dccb] bg-[#fffdf9]" aria-labelledby="event-sidebar-place-title">
                        @if(filled($locationImageUrl))
                            <img src="{{ $locationImageUrl }}" alt="" aria-hidden="true" loading="lazy" class="block aspect-[16/9] w-full object-cover">
                        @endif

                        @if($mapEmbedUrl)
                            <iframe
                                src="{{ $mapEmbedUrl }}"
                                title="{{ __('Peta lokasi :venue', ['venue' => $placeTitle ?: __('majlis')]) }}"
                                class="block aspect-[16/10] w-full border-0"
                                loading="lazy"
                                referrerpolicy="no-referrer-when-downgrade"
                                allowfullscreen
                            ></iframe>
                        @endif

                        <div class="p-6">
                            @if(! $locationIsOrganizer && filled($organizerCoverImageUrl))
                                <div class="mb-5">
                                    <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-[#8a5f13]">{{ __('Penganjur') }}</p>
                                    <img src="{{ $organizerCoverImageUrl }}" alt="" aria-hidden="true" loading="lazy" class="mt-3 aspect-[16/9] w-full rounded-xl object-cover">
                                </div>
                            @endif

                            <p class="text-[11px] font-semibold uppercase tracking-[0.26em] text-[#8a5f13]">
                                {{ $locationIsOrganizer ? __('Penganjur & lokasi') : __('Penganjur') }}
                            </p>

                            @if($placeTitle && $hasLocationContent)
                                <h2 id="event-sidebar-place-title" class="mt-3 flex min-w-0 items-center gap-3 font-heading text-xl font-normal leading-snug text-[#14342e]">
                                    @if($locationIsOrganizer && filled($organizerImageUrl))
                                        <img src="{{ $organizerImageUrl }}" alt="" aria-hidden="true" class="size-12 shrink-0 rounded-xl border border-[#e6dccb] bg-white p-1 object-contain">
                                    @elseif($locationIsOrganizer && $organizerInitial)
                                        <span class="flex size-12 shrink-0 items-center justify-center rounded-xl bg-[#14342e] font-heading text-base text-[#f2c867]" aria-hidden="true">{{ $organizerInitial }}</span>
                                    @endif
                                    @if($locationIsOrganizer && $organizerHref)
                                        <a href="{{ $organizerHref }}" wire:navigate class="min-w-0 transition-colors duration-300 hover:text-[#8a5f13]">{{ $placeTitle }}</a>
                                    @else
                                        {{ $placeTitle }}
                                    @endif
                                </h2>
                            @elseif($organizer)
                                <h2 id="event-sidebar-place-title" class="mt-3 flex min-w-0 items-center gap-3 font-heading text-xl font-normal leading-snug text-[#14342e]">
                                    @if(filled($organizerImageUrl))
                                        <img src="{{ $organizerImageUrl }}" alt="" aria-hidden="true" class="size-12 shrink-0 rounded-xl border border-[#e6dccb] bg-white p-1 object-contain">
                                    @elseif($organizerInitial)
                                        <span class="flex size-12 shrink-0 items-center justify-center rounded-xl bg-[#14342e] font-heading text-base text-[#f2c867]" aria-hidden="true">{{ $organizerInitial }}</span>
                                    @endif
                                    @if($organizerHref)
                                        <a href="{{ $organizerHref }}" wire:navigate class="min-w-0 transition-colors duration-300 hover:text-[#8a5f13]">{{ $organizer->name }}</a>
                                    @else
                                        {{ $organizer->name }}
                                    @endif
                                </h2>
                            @endif

                            @if($placeSpace)
                                <p class="mt-1 text-[13px] font-medium text-[#8a5f13]">{{ $placeSpace }}</p>
                            @endif

                            @if(! $locationIsOrganizer && $organizer)
                                <div class="mt-3 flex items-center gap-2.5 text-[13px] text-[#6b645e]">
                                    @if(filled($organizerImageUrl))
                                        <img src="{{ $organizerImageUrl }}" alt="" aria-hidden="true" class="size-9 shrink-0 rounded-lg border border-[#e6dccb] bg-white p-1 object-contain">
                                    @elseif($organizerInitial)
                                        <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-[#14342e] font-heading text-sm text-[#f2c867]" aria-hidden="true">{{ $organizerInitial }}</span>
                                    @endif
                                    <p>
                                        {{ __('Dianjurkan oleh') }}
                                        @if($organizerHref)
                                            <a href="{{ $organizerHref }}" wire:navigate class="font-semibold text-[#14342e] underline decoration-[#c9bfae] underline-offset-4 transition-colors duration-300 hover:decoration-[#14342e]">{{ $organizer->name }}</a>
                                        @else
                                            <span class="font-semibold text-[#14342e]">{{ $organizer->name }}</span>
                                        @endif
                                    </p>
                                </div>
                            @endif

                            @if($primaryAddress)
                                <address class="mt-3 border-t border-[#f0e8d9] pt-3 text-[13px] leading-6 text-[#4c5b56] not-italic">
                                    @if(filled($primaryAddress->line1))<span class="block">{{ $primaryAddress->line1 }}</span>@endif
                                    @if(filled($primaryAddress->line2))<span class="block">{{ $primaryAddress->line2 }}</span>@endif
                                    @if(filled($addressDisplayLines['locality'] ?? null))<span class="block">{{ $addressDisplayLines['locality'] }}</span>@endif
                                    @if(filled($addressDisplayLines['regional'] ?? null))<span class="block">{{ $addressDisplayLines['regional'] }}</span>@endif
                                </address>
                            @endif

                            @if($navigationUrl)
                                <a href="{{ $navigationUrl }}" target="_blank" rel="noopener" class="mt-5 inline-flex w-full items-center justify-center gap-2 rounded-full bg-[#14342e] px-4 py-2.5 text-[13px] font-semibold text-[#fdfaf2] transition duration-300 ease-[cubic-bezier(0.22,1,0.36,1)] hover:bg-[#1d4a41] active:scale-[0.98] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#14342e]">
                                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.27 3.13a59.77 59.77 0 0118.18 8.6c.36.36.36.94 0 1.3a59.77 59.77 0 01-18.18 8.6L6 12zm0 0h6.75" /></svg>
                                    {{ __('Navigasi ke sini') }}
                                </a>
                            @endif
                        </div>
                    </section>
                @endif

                <section class="mi-paper rounded-2xl border border-[#e6dccb] bg-[#fffdf9] p-6" aria-labelledby="event-sidebar-info-title">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.26em] text-[#8a5f13]">{{ __('Maklumat majlis') }}</p>
                    <h2 id="event-sidebar-info-title" class="mt-3 font-heading text-xl font-normal leading-snug text-[#14342e]">{{ __('Ringkasan') }}</h2>

                    <dl class="mt-5 divide-y divide-[#f0e8d9] text-[13px]">
                        <div class="flex items-baseline justify-between gap-4 py-2.5">
                            <dt class="text-[#6b645e]">{{ __('Format') }}</dt>
                            <dd class="text-right font-medium text-[#14342e]">{{ $eventFormatLabel }}</dd>
                        </div>
                        @if($languageLabels->isNotEmpty())
                            <div class="flex items-baseline justify-between gap-4 py-2.5">
                                <dt class="text-[#6b645e]">{{ __('Bahasa') }}</dt>
                                <dd class="text-right font-medium text-[#14342e]">{{ $languageLabels->implode(', ') }}</dd>
                            </div>
                        @endif
                        @if($genderRestriction instanceof \App\Enums\EventGenderRestriction)
                            <div class="flex items-baseline justify-between gap-4 py-2.5">
                                <dt class="text-[#6b645e]">{{ __('Kehadiran') }}</dt>
                                <dd class="text-right font-medium text-[#14342e]">{{ $genderRestriction->getLabel() }}</dd>
                            </div>
                        @endif
                        @if($firstDateLabel)
                            <div class="flex items-baseline justify-between gap-4 py-2.5">
                                <dt class="text-[#6b645e]">{{ __('Mula') }}</dt>
                                <dd class="text-right font-medium text-[#14342e]">{{ $firstDateLabel }}</dd>
                            </div>
                        @endif
                        @if($occurrenceCount > 1 && $lastDateLabel)
                            <div class="flex items-baseline justify-between gap-4 py-2.5">
                                <dt class="text-[#6b645e]">{{ __('Akhir') }}</dt>
                                <dd class="text-right font-medium text-[#14342e]">{{ $lastDateLabel }}</dd>
                            </div>
                        @endif
                    </dl>

                    @if($audienceLabels->isNotEmpty())
                        <div class="mt-4 border-t border-[#f0e8d9] pt-4">
                            <p class="text-[10px] font-semibold uppercase tracking-[0.2em] text-[#8a5f13]">{{ __('Sasaran') }}</p>
                            <div class="mt-2 flex flex-wrap gap-1.5">
                                @foreach($audienceLabels as $audienceLabel)
                                    <span class="rounded-full bg-[#f3efe6] px-2.5 py-1 text-[11px] font-semibold text-[#4c5b56]">{{ $audienceLabel }}</span>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @if($muslimOnly || $event->children_allowed === false)
                        <div class="mt-4 space-y-1.5">
                            @if($muslimOnly)
                                <p class="rounded-[10px] bg-[#f3efe6] px-3 py-2 text-[11px] font-semibold text-[#4c5b56]">{{ __('Untuk Muslim sahaja') }}</p>
                            @endif
                            @if($event->children_allowed === false)
                                <p class="rounded-[10px] bg-[#f3efe6] px-3 py-2 text-[11px] font-semibold text-[#4c5b56]">{{ __('Kanak-kanak tidak dibenarkan') }}</p>
                            @endif
                        </div>
                    @endif
                </section>

                <section id="event-share-panel" aria-label="{{ __('Kongsi majlis') }}">
                    <x-share-panel :share-data="[
                        'title' => $event->title,
                        'text' => \Illuminate\Support\Str::limit($event->description_text, 100),
                        'url' => $eventShareUrl,
                        'sourceUrl' => $eventShareUrl,
                        'shareText' => trim($event->title.' - '.config('app.name')),
                        'fallbackTitle' => $event->title,
                        'payloadEndpoint' => route('share.payload'),
                    ]" :share-links="$shareLinks" />
                </section>

                <section class="mi-paper rounded-2xl border border-[#e6dccb] bg-[#fffdf9] p-6" aria-labelledby="event-sidebar-feedback-title">
                    <h2 id="event-sidebar-feedback-title" class="font-heading text-xl font-normal leading-snug text-[#14342e]">{{ __('Bantu semak majlis ini') }}</h2>
                    <p class="mt-2 text-[13px] leading-6 text-[#4c5b56]">{{ __('Nampak maklumat yang tidak tepat? Maklumkan kepada kami.') }}</p>
                    @php
                        $feedbackSubjectType = \App\Enums\ContributionSubjectType::Event->publicRouteSegment();
                    @endphp
                    <div class="mt-4 grid grid-cols-2 gap-2">
                        <a href="{{ route('contributions.suggest-update', ['subjectType' => $feedbackSubjectType, 'subjectId' => $event->slug]) }}" wire:navigate class="inline-flex items-center justify-center gap-1.5 rounded-full border border-[#e6dccb] bg-white px-3 py-2 text-[12px] font-semibold text-[#14342e] transition duration-300 hover:border-[#c9962f]/50 hover:bg-[#f8f3e8] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#14342e]">{{ __('Cadang kemaskini') }}</a>
                        <a href="{{ route('reports.create', ['subjectType' => $feedbackSubjectType, 'subjectId' => $event->slug]) }}" wire:navigate class="inline-flex items-center justify-center gap-1.5 rounded-full border border-[#e6dccb] bg-white px-3 py-2 text-[12px] font-semibold text-[#14342e] transition duration-300 hover:border-[#c9962f]/50 hover:bg-[#f8f3e8] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#14342e]">{{ __('Lapor isu') }}</a>
                    </div>
                </section>
            </aside>
        </div>
    </div>

    @if($eventPosterUrl)
        <div x-show="posterOpen" x-cloak x-transition.opacity @keydown.escape.window="posterOpen = false" class="fixed inset-0 z-50 flex items-center justify-center bg-[#0b2b25]/95 p-4" role="dialog" aria-modal="true" aria-label="{{ __('Poster majlis') }}">
            <button type="button" @click="posterOpen = false" class="absolute right-4 top-4 rounded-full bg-white/10 px-4 py-2 text-white" aria-label="{{ __('Tutup') }}">✕</button>
            <img src="{{ $event->getFirstMediaUrl('poster') }}" alt="{{ $event->title }}" class="max-h-[90vh] max-w-full rounded-xl object-contain">
        </div>
    @endif

    {{-- ── Share dialog ────────────────────────────────────────────── --}}
    @include('livewire.pages.events.partials.share-dialog', [
        'shareUrl' => $eventShareUrl,
        'shareTitle' => $event->title,
        'shareLinks' => $shareLinks,
        'shareProviders' => $shareProviders,
    ])
</div>
