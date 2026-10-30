@php
    $occurrencePageTitle = trim((string) ($occurrence->title ?: $event->title));
    $occurrencePageTitle = $occurrencePageTitle === $event->title
        ? $event->title.($occurrence->starts_at ? ' · '.\App\Support\Timezone\UserDateTimeFormatter::translatedFormat($occurrence->starts_at, 'j M Y') : '')
        : $occurrencePageTitle.' — '.$event->title;
    $shareCardOg = app(\App\Services\ShareCards\ShareCardService::class)->ogCard($event, (string) ($occurrence->slug ?? $occurrence->getKey()));

    // A majlis with one public date has a single page, reachable at both URLs.
    // The event URL is the shorter, more stable one, so it stays canonical and
    // the date URL defers to it. Only a genuinely multi-date majlis needs the
    // date URL as canonical, because then the event URL is a listing instead.
    $occurrenceIsOnlyDate = $event->occurrences->count() <= 1;
    $occurrenceCanonicalUrl = $occurrenceIsOnlyDate
        ? route('events.show', $event)
        : route('events.occurrence', [$event, \App\Support\Events\PublicScheduleSlug::occurrence($occurrence)]);
@endphp
@push('head')
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700&display=swap">
@endpush

@section('title', $occurrencePageTitle)
@section('meta_description', \Illuminate\Support\Str::limit(
    $event->description_text !== '' ? $event->description_text : $event->title,
    160,
))
@section('meta_og_type', 'event')
@section('meta_robots', $this->metaRobots)
@section('og_url', $occurrenceCanonicalUrl)
@section('og_image', $shareCardOg['url'] ?? $event->card_image_url)
@section('og_image_alt', $shareCardOg['alt'] ?? __('Poster untuk :title', ['title' => $event->title]))
@section('og_image_width', $shareCardOg['url'] ? '1200' : '1024')
@section('og_image_height', $shareCardOg['url'] ? '630' : '1024')

@push('head')
    <link rel="canonical" href="{{ $occurrenceCanonicalUrl }}">
    <meta property="article:published_time" content="{{ $occurrence->starts_at?->toIso8601String() }}">
    <x-event-json-ld
        :event="$event"
        :url="$occurrenceCanonicalUrl"
        :name="$occurrence->title ?: $event->title"
        :starts-at="$occurrence->starts_at"
        :ends-at="$occurrence->ends_at"
    />
@endpush

@include('partials.filament-assets', [
    'scripts' => ['filament/support', 'filament/notifications'],
])

@php
    $detail = $this->detail;
    $occurrenceSessions = $detail->sessionsFor($occurrence)
        ->filter(fn (\AIArmada\Events\Models\EventSession $session): bool => \App\Support\Events\PublicSchedulePolicy::isMeaningfulSession($session))
        ->values();
    $occurrenceCapacity = $detail->capacityFor($occurrence);
    $occurrenceReserved = $detail->participantCount($occurrence);
    $occurrenceRemaining = $detail->effectiveCapacityRemaining($occurrence);
    $occurrenceFull = $occurrenceRemaining !== null && $occurrenceRemaining <= 0;
    $ownLocation = $detail->primaryLocationFor($occurrence);
    $location = $ownLocation ?? $detail->primaryLocationFor($event);
    $locationVenue = $location?->relationLoaded('venue') ? $location->venue : null;
    $locationSpace = $location?->relationLoaded('venueSpace') ? $location->venueSpace : null;
    $locationAddress = $location?->primaryAddress() ?? $locationVenue?->primaryAddress();
    $primaryAddress = $locationAddress
        ?? ($ownLocation?->venue_id !== null ? null : $event->resolvedLocationAddress());
    $addressDisplayLines = \App\Support\Location\AddressHierarchyFormatter::displayLines($primaryAddress);
    $venueDisplayName = $locationVenue?->name ?? $detail->locationLabel($location) ?? $event->resolvedLocationName();
    $isOnlineOnly = (string) $occurrence->delivery_mode === \App\Enums\EventFormat::Online->value
        && $location === null
        && $locationVenue === null
        && $primaryAddress === null;
    $hasLocationContent = ! $isOnlineOnly && ($venueDisplayName || $locationSpace || $primaryAddress);
    $lat = $primaryAddress?->latitude;
    $lng = $primaryAddress?->longitude;
    $mapQuery = implode(', ', array_filter([
        $locationVenue?->name,
        $detail->locationLabel($location),
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
    // Live map + turn-by-turn navigation. Coordinates win over free text so the
    // pin lands on the building rather than on a street centroid.
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
        ? 'https://www.google.com/maps/dir/?'.http_build_query([
            'api' => '1',
            'destination' => $mapTarget,
        ])
        : null;
    $occurrencePolicy = $detail->policiesForScope($occurrence)->first();
    $occurrenceHasOffering = $detail->scopeHasRegistrationOffering($occurrence);
    $occurrenceRegistrationsOpen = $detail->scopeRegistrationsOpen($occurrence);
    $tickets = $detail->ticketEntries();
    $ticketScopeCount = $tickets->pluck('scope_label')->unique()->count();
    $eventActionsDisabled = $this->eventActionsDisabled || $detail->scopeActionsDisabled($occurrence);
    $capacities = $detail->capacityEntries();
    $ownLinks = $detail->linksFor($occurrence);
    $ownMaterials = $detail->materialsFor($occurrence);
    $eventLinks = $detail->linksFor($event);
    $eventMaterials = $detail->materialsFor($event);
    $rawEventUrls = collect([
        ['url' => $event->event_url, 'label' => __('Laman rasmi')],
        ['url' => $event->live_url, 'label' => __('Siaran langsung')],
    ])->filter(fn (array $row): bool => filled($row['url'])
        && ! $ownLinks->concat($eventLinks)->contains(fn ($link): bool => (string) $link->url === (string) $row['url']));
    $occurrenceReferences = $detail->referencesFor($occurrence);
    $eventReferences = $event->relationLoaded('references') ? $event->references : collect();
    $ownInvolvements = $occurrence->relationLoaded('involvements') ? $occurrence->involvements : collect();
    $calendarLinks = $this->calendarLinks;
    $viewerTimezone = \App\Support\Timezone\UserTimezoneResolver::resolve();
    $occurrenceSameLocalDay = $occurrence->starts_at && $occurrence->ends_at
        ? $occurrence->starts_at->copy()->timezone($viewerTimezone)->isSameDay($occurrence->ends_at->copy()->timezone($viewerTimezone))
        : true;
    $occurrenceTime = $occurrence->starts_at
        ? \App\Support\Timezone\UserDateTimeFormatter::format($occurrence->starts_at, 'h:i A')
        : __('TBC');
    if ($occurrence->ends_at) {
        $occurrenceTime .= ' — '.\App\Support\Timezone\UserDateTimeFormatter::format($occurrence->ends_at, $occurrenceSameLocalDay ? 'h:i A' : 'j M, h:i A');
    }
    $occurrenceOwnsSubmissionContent = is_array($occurrence->metadata['public_submission'] ?? null);
    $occurrenceOrganizer = $occurrenceOwnsSubmissionContent
        ? $ownInvolvements->firstWhere('role_code', 'organizer')?->involveable
        : null;
    $organizer = $occurrenceOrganizer ?? $event->organizer ?? $event->institution;
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
    // A reader who needs to ask about a cancellation, a lift, or a seat has to
    // be able to reach the organiser without leaving the page.
    $organizerContacts = $organizer?->relationLoaded('contactMethods')
        ? $organizer->contactMethods
        : collect();
    $organizerPhone = $organizerContacts->firstWhere('type', \AIArmada\Contacting\Enums\ContactMethodType::Phone->value)?->value;
    $organizerEmail = $organizerContacts->firstWhere('type', \AIArmada\Contacting\Enums\ContactMethodType::Email->value)?->value;
    $occurrenceClassifications = $occurrenceOwnsSubmissionContent && $occurrence->relationLoaded('classifications')
        ? $occurrence->classifications
        : $event->classifications;
    $classificationLabels = $occurrenceClassifications
        ->filter(fn ($classification): bool => $classification->term !== null)
        ->map(fn ($classification): string => (string) $classification->term->name)
        ->unique()
        ->values();
    $languageLabels = $this->languageLabels;
    $audienceRows = $occurrenceOwnsSubmissionContent && $occurrence->relationLoaded('audiences')
        ? $occurrence->audiences
        : $event->audiences;
    $audienceLabels = $audienceRows
        ->reject(fn ($audience): bool => in_array($audience->audience_type, ['gender', 'age_group', 'religion'], true))
        ->map(fn ($audience): ?string => filled($audience->value) ? (string) $audience->value : null)
        ->filter()
        ->unique()
        ->values();
    $genderValue = $audienceRows->firstWhere('audience_type', 'gender')?->value;
    $genderRestriction = \App\Enums\EventGenderRestriction::tryFrom((string) $genderValue);
    $muslimOnly = $audienceRows->contains(fn ($audience): bool => $audience->audience_type === 'religion' && $audience->value === 'muslim_only');
    $keyPeopleByRole = $this->keyPeopleByRole;
    $galleryImages = $this->galleryImages;

    $occurrenceDisplayTitle = trim((string) ($occurrence->title ?: $event->title));
    $occurrenceCancelled = (string) $occurrence->status === 'cancelled';
    $occurrenceFormatLabel = match ((string) $occurrence->delivery_mode) {
        \App\Enums\EventFormat::Online->value => __('Dalam talian'),
        \App\Enums\EventFormat::Hybrid->value => __('Hibrid'),
        default => __('Fizikal'),
    };
    $occurrenceStartLocal = $occurrence->starts_at?->copy()->timezone($viewerTimezone);
    $occurrenceEndLocal = $occurrence->ends_at?->copy()->timezone($viewerTimezone);
    $occurrenceSingleDay = $occurrenceStartLocal !== null
        && $occurrenceEndLocal !== null
        && $occurrenceStartLocal->isSameDay($occurrenceEndLocal);
    $occurrenceDateLabel = match (true) {
        $occurrenceStartLocal === null => __('Tarikh akan dikemas kini'),
        $occurrenceEndLocal === null, $occurrenceSingleDay => \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($occurrenceStartLocal, 'l, j F Y'),
        $occurrenceStartLocal->year === $occurrenceEndLocal->year => \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($occurrenceStartLocal, 'j F').' — '.\App\Support\Timezone\UserDateTimeFormatter::translatedFormat($occurrenceEndLocal, 'j F Y'),
        default => \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($occurrenceStartLocal, 'j F Y').' — '.\App\Support\Timezone\UserDateTimeFormatter::translatedFormat($occurrenceEndLocal, 'j F Y'),
    };
    $occurrencePrayerLabel = $occurrence->relationLoaded('timeExpressions')
        ? $occurrence->timeExpressions->firstWhere('anchor_type', 'prayer')?->display_label
        : null;
    // Real uploaded media only. Event::card_image_url() falls back to an
    // institution logo or a stock placeholder; both crop badly at 16:9, and a
    // generic photo reads worse than the typographic date leaf below.
    $occurrenceCoverUrl = $occurrence->getFirstMedia('cover')?->getAvailableUrl(['banner', 'thumb'])
        ?? $event->getFirstMedia('cover')?->getAvailableUrl(['banner', 'thumb']);
    $occurrencePosterUrl = $occurrence->getFirstMedia('poster')?->getAvailableUrl(['preview', 'thumb'])
        ?? $event->getFirstMedia('poster')?->getAvailableUrl(['poster_thumb']);
    // Occurrence `cover` is converted to 1920x1080 (16:9); the event `poster` is
    // 1080x1440 (3:4). Both are cropped by object-cover, so either works here.
    $heroImageUrl = $occurrenceCoverUrl ?? $occurrencePosterUrl;
    $occurrenceHasParticipation = $occurrenceCapacity !== null || $occurrencePolicy !== null || $tickets->isNotEmpty() || $detail->hasRegistration();
    // Two ways to take part: pick a ticket, or — when the majlis sells none —
    // go straight to checkout. Ticket sales gate the first; the second is gated
    // by the registration window and the absence of anything to pay for.
    $occurrencePaymentRequired = $detail->hasPaidTickets()
        || $detail->policyEntries()->contains(fn (array $entry): bool => (bool) $entry['policy']->payment_required);
    $occurrenceRegistrationClosed = ($occurrencePolicy?->closes_at?->isPast() ?? false)
        || ($occurrencePolicy?->opens_at?->isFuture() ?? false);
    $occurrenceDirectCheckoutUrl = $tickets->isEmpty()
        && $detail->hasRegistration()
        && ! $occurrencePaymentRequired
        && ! $eventActionsDisabled
        && ! $occurrenceRegistrationClosed
        && ! $occurrenceFull
        && ! $occurrenceCancelled
        ? route('events.checkout', ['event' => $event])
        : null;
    $occurrenceRegistrationCta = $occurrenceRegistrationsOpen || $occurrenceDirectCheckoutUrl !== null;
    $occurrenceCapacityPercent = $occurrenceCapacity !== null && $occurrenceCapacity > 0
        ? max(0, min(100, (int) round($occurrenceReserved / $occurrenceCapacity * 100)))
        : null;
    $occurrenceShareUrl = $occurrenceCanonicalUrl;
    $occurrenceTimeLabel = $occurrenceStartLocal
        ? \App\Support\Timezone\UserDateTimeFormatter::format($occurrenceStartLocal, 'h:i A')
        : null;
    if ($occurrenceTimeLabel !== null && $occurrenceSingleDay && $occurrenceEndLocal !== null) {
        $occurrenceTimeLabel .= ' — '.\App\Support\Timezone\UserDateTimeFormatter::format($occurrenceEndLocal, 'h:i A');
    }

    // Event::resolvedLocationAddress() falls back to the institution when the
    // event names no venue, so the organiser *is* the place for most majlis.
    // Merge the two into one card instead of printing the same name twice.
    $organizerAddress = $organizer instanceof \App\Models\Institution ? $organizer->primaryAddress() : null;
    $locationIsOrganizer = $hasLocationContent
        && $organizer instanceof \App\Models\Institution
        && $primaryAddress !== null
        && $organizerAddress !== null
        && (string) $organizerAddress->getKey() === (string) $primaryAddress->getKey();
    $placeTitle = $locationIsOrganizer ? $organizer->name : $venueDisplayName;
    $placeSpace = $locationSpace && $locationSpace->name !== $placeTitle ? $locationSpace->name : null;

    // The four things a reader scans for before deciding to come. Rendered as a
    // band inside the hero so the headline is never followed by a wall of prose.
    $heroFacts = collect([
        ['label' => __('Tarikh'), 'value' => $occurrenceDateLabel, 'testid' => 'event-hero-date'],
        ['label' => __('Masa'), 'value' => $occurrenceTimeLabel, 'testid' => 'event-hero-time'],
        ['label' => __('Tempat'), 'value' => $placeTitle ?: $venueDisplayName, 'testid' => 'event-hero-location'],
        ['label' => __('Format'), 'value' => $occurrenceFormatLabel, 'testid' => 'event-hero-format'],
    ])->filter(fn (array $fact): bool => filled($fact['value']))->values();

    // The kitab under study is the strongest signal of what a majlis actually
    // is, so it sits in the hero rather than only in the references list.
    $heroReferenceTitle = $detail->referencesFor($event)
        ->map(fn ($row): ?string => $row->referenceable instanceof \App\Models\Reference
            ? $row->referenceable->displayTitle()
            : null)
        ->filter(fn (?string $title): bool => filled($title))
        ->first();
    $heroFactGridClass = match ($heroFacts->count()) {
        1 => 'sm:grid-cols-1',
        2 => 'sm:grid-cols-2',
        3 => 'sm:grid-cols-3',
        default => 'sm:grid-cols-2 lg:grid-cols-4',
    };

    // A single-date majlis has no listing to go back to: the event route now
    // redirects here, so linking to it would bounce the reader in a circle.
    $eventHasMultipleDates = $event->occurrences->count() > 1;
    $canEditEvent = auth()->user()?->hasAnyRole(['super_admin', 'admin']) ?? false;
    $changeNotice = $this->activeChangeNotice;
    $replacementEvent = $this->replacementEvent;
    $relatedEvents = $this->relatedEvents;
    $shareProviders = collect([
        'whatsapp' => __('WhatsApp'),
        'telegram' => __('Telegram'),
        'facebook' => __('Facebook'),
        'x' => __('X'),
        'email' => __('E-mel'),
    ])->filter(fn (string $label, string $provider): bool => filled($this->shareLinks[$provider] ?? null));
    $checkInOffered = $detail->hasRegistration() || $tickets->isNotEmpty();
@endphp

<div
    class="event-detail-page min-h-screen bg-[#f4f0e7] text-[#14342e]"
    x-data="{
        shareOpen: false,
        posterOpen: false,
        copied: false,
        shareUrl: @js($occurrenceShareUrl),
        shareTitle: @js($occurrenceDisplayTitle),
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
    <header class="event-detail-hero relative isolate overflow-hidden bg-[#131209] text-[#f7f3e8]">
        {{-- Ambient wash: the same photo, scaled and blurred, so the dark panel
             carries the colour of the room without competing with the type. --}}
        <div class="mi-lattice-light pointer-events-none absolute inset-0 opacity-[0.05]" aria-hidden="true"></div>

        <div class="relative mx-auto max-w-7xl px-4 pt-12 sm:px-8 sm:pt-16 lg:px-12 lg:pt-16">
            <div class="grid items-stretch gap-10 lg:grid-cols-[minmax(0,1fr)_minmax(0,23rem)] lg:gap-10 xl:grid-cols-[minmax(0,1fr)_minmax(0,32rem)] xl:gap-14">
                <div class="flex min-w-0 flex-col justify-center">
                    {{-- Eyebrow --}}
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                        @foreach($classificationLabels->take(2) as $classificationLabel)
                            <span class="text-[11px] font-semibold uppercase tracking-[0.26em] text-[#f2c867]">{{ $classificationLabel }}</span>
                            <span class="size-1 rounded-full bg-[#f2c867]/40" aria-hidden="true"></span>
                        @endforeach
                        <span class="text-[11px] font-semibold uppercase tracking-[0.26em] text-[#f7f3e8]/70">{{ $occurrenceFormatLabel }}</span>
                        @if($occurrenceCancelled)
                            <span class="rounded-full bg-[#f2c867] px-2.5 py-1 text-[10px] font-bold uppercase tracking-[0.14em] text-[#0e2e27]">{{ __('Dibatalkan') }}</span>
                        @endif
                    </div>

                    <h1 class="mt-6 max-w-[38rem] font-heading text-4xl font-semibold tracking-tight text-balance sm:text-5xl xl:text-6xl">
                        {{ $occurrenceDisplayTitle }}
                    </h1>

                    @if($occurrenceDisplayTitle !== $event->title && $eventHasMultipleDates)
                        <a href="{{ route('events.show', $event) }}" wire:navigate class="group mt-4 inline-flex items-center gap-2 text-sm font-medium text-[#f2c867] transition-colors duration-300 hover:text-[#fdfaf2]">
                            {{ $event->title }}
                            <span class="transition-transform duration-300 group-hover:translate-x-0.5" aria-hidden="true">→</span>
                        </a>
                    @endif

                    @if($occurrencePrayerLabel)
                        <p class="mt-5 text-sm font-medium text-[#f2c867]">{{ $occurrencePrayerLabel }}</p>
                    @endif

                    @if($heroReferenceTitle)
                        <p class="mt-4 inline-flex items-start gap-2 text-sm leading-6 text-[#f7f3e8]/80" data-testid="event-hero-reference">
                            <svg class="mt-1 size-3.5 shrink-0 text-[#f2c867]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" /></svg>
                            <span>{{ $heroReferenceTitle }}</span>
                        </p>
                    @endif

                    {{-- Actions: exactly one filled button, the rest quiet. --}}
                    @php
                        $heroCalendarUrl = $calendarLinks['google'] ?? $calendarLinks['ics'] ?? null;
                        $heroPrimary = match (true) {
                            $occurrenceRegistrationCta => 'register',
                            $occurrenceSessions->isNotEmpty() => 'schedule',
                            filled($heroCalendarUrl) => 'calendar',
                            default => null,
                        };
                    @endphp
                    <div class="mt-9 flex flex-wrap items-center gap-3">
                        @if($heroPrimary === 'register')
                            <a href="#penyertaan" class="inline-flex items-center gap-2 rounded-full bg-[#f2c867] px-6 py-3 text-sm font-semibold text-[#0e2e27] transition duration-300 ease-[cubic-bezier(0.22,1,0.36,1)] hover:bg-[#f7d98d] active:scale-[0.98] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#f2c867]">
                                {{ __('Daftar sekarang') }}
                                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M13 6l6 6-6 6" /></svg>
                            </a>
                        @elseif($heroPrimary === 'schedule')
                            <a href="#jadual" class="inline-flex items-center gap-2 rounded-full bg-[#f2c867] px-6 py-3 text-sm font-semibold text-[#0e2e27] transition duration-300 ease-[cubic-bezier(0.22,1,0.36,1)] hover:bg-[#f7d98d] active:scale-[0.98] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#f2c867]">
                                {{ __('Lihat jadual') }}
                                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M13 6l6 6-6 6" /></svg>
                            </a>
                        @elseif($heroPrimary === 'calendar')
                            <a href="{{ $heroCalendarUrl }}" target="_blank" rel="noopener" class="inline-flex items-center gap-2 rounded-full bg-[#f2c867] px-6 py-3 text-sm font-semibold text-[#0e2e27] transition duration-300 ease-[cubic-bezier(0.22,1,0.36,1)] hover:bg-[#f7d98d] active:scale-[0.98] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#f2c867]">
                                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 4h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5v12a2 2 0 002 2z" /></svg>
                                {{ __('Tambah ke kalendar') }}
                            </a>
                        @endif

                        <button type="button" @click="openShare()" data-signal-event="share.modal_opened" data-signal-category="share" data-signal-component="event_detail_actions" data-signal-control="share" data-signal-entity-type="event" data-signal-entity-id="{{ $event->id }}" class="inline-flex items-center gap-2 rounded-full border border-[#f7f3e8]/40 px-5 py-3 text-sm font-semibold text-[#f7f3e8] transition duration-300 ease-[cubic-bezier(0.22,1,0.36,1)] hover:border-[#f2c867]/60 hover:bg-[#f7f3e8]/10 active:scale-[0.98] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#f2c867]">
                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684zm0 9.316a3 3 0 105.368 2.684z" /></svg>
                            {{ __('Kongsi') }}
                        </button>
                    </div>
                </div>

                {{-- Photo plate, or a typographic date leaf when nothing is
                     uploaded. Same slot, so the hero never reflows around a
                     missing image. --}}
                @if($heroImageUrl)
                    <div class="relative">
                        <figure class="mi-plate aspect-[4/3] overflow-hidden rounded-2xl ring-1 ring-[#f2c867]/25">
                            <img
                                src="{{ $heroImageUrl }}"
                                alt="{{ __('Suasana :title', ['title' => $occurrenceDisplayTitle]) }}"
                                class="size-full object-cover"
                                loading="eager"
                                fetchpriority="high"
                            >
                        </figure>

                        @if($occurrencePosterUrl)
                            <button type="button" @click="posterOpen = true" class="absolute right-3 top-3 inline-flex items-center gap-1.5 rounded-full bg-[#0b2b25]/80 px-3 py-1.5 text-[11px] font-semibold text-[#f7f3e8] backdrop-blur-sm transition duration-300 ease-[cubic-bezier(0.22,1,0.36,1)] hover:bg-[#0b2b25] active:scale-[0.97] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#f2c867]">
                                <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 4.5h16.5v15H3.75zM3.75 15l4.5-4.5 3.75 3.75 2.25-2.25 4.5 4.5" /></svg>
                                {{ __('Lihat poster') }}
                            </button>
                        @endif

                    </div>
                @elseif($occurrenceStartLocal)
                    {{-- No photograph uploaded: the date becomes the image. --}}
                    <div class="hidden items-center lg:flex">
                        <div class="relative w-full overflow-hidden rounded-2xl border border-[#f2c867]/25 bg-[#f7f3e8]/[0.06] px-8 py-9 text-center">
                            <div class="mi-lattice-light pointer-events-none absolute inset-0 opacity-[0.06]" aria-hidden="true"></div>
                            <div class="relative">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.3em] text-[#f2c867]">
                                    {{ \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($occurrenceStartLocal, 'l') }}
                                </p>
                                <p class="mt-3 font-heading text-[5.5rem] font-normal leading-[0.85] tracking-[-0.03em] text-[#fdfaf2]">
                                    {{ $occurrenceStartLocal->format('j') }}
                                </p>
                                <p class="mt-4 text-[11px] font-semibold uppercase tracking-[0.3em] text-[#f7f3e8]/75">
                                    {{ \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($occurrenceStartLocal, 'F Y') }}
                                </p>
                                @if($occurrenceTimeLabel)
                                    <div class="mt-7 border-t border-[#f2c867]/20 pt-6">
                                        <p class="font-mono text-xs tracking-[0.06em] text-[#f7f3e8]/85">{{ $occurrenceTimeLabel }}</p>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                @endif
            </div>

            {{-- Fact band. Four answers before any prose: when, what time, where,
                 how. On cream this would be another card; inside the hero it
                 closes the invitation instead. --}}
            @if($heroFacts->isNotEmpty())
                <dl class="mt-12 grid grid-cols-2 gap-x-8 gap-y-6 border-t border-[#f7f3e8]/12 py-8 sm:mt-12 {{ $heroFactGridClass }}">
                    @foreach($heroFacts as $fact)
                        <div class="min-w-0" data-testid="{{ $fact['testid'] }}">
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
        'calendarLinks' => $calendarLinks,
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
        <div class="grid gap-x-16 gap-y-16 pt-14 pb-6 lg:grid-cols-[minmax(0,1fr)_20rem] lg:pt-20 lg:pb-8">

            <div class="min-w-0 space-y-16">

                {{-- Schedule --}}
                @if($occurrenceSessions->isNotEmpty())
                    <section id="jadual" data-testid="event-schedule-section" class="scroll-mt-8" aria-labelledby="occurrence-schedule-title">
                        <div class="mb-7">
                            <span class="block h-[2px] w-7 bg-[#c9962f]" aria-hidden="true"></span>
                            <p class="mt-4 text-[11px] font-semibold uppercase tracking-[0.26em] text-[#8a5f13]">{{ __('Program') }}</p>
                            <div class="mt-2 flex flex-wrap items-baseline justify-between gap-x-6 gap-y-1">
                                <h2 id="occurrence-schedule-title" class="font-heading text-[1.75rem] leading-[1.15] tracking-[-0.01em] text-balance">{{ __('Event Schedule') }}</h2>
                                <p class="font-mono text-xs text-[#6b645e]">{{ $occurrenceSessions->count() }} {{ __('sesi') }}</p>
                            </div>
                        </div>

                        <ol class="mi-paper overflow-hidden rounded-2xl border border-[#e6dccb] bg-[#fffdf9]">
                            @foreach($occurrenceSessions as $session)
                                @include('livewire.pages.events.partials.occurrence-session', [
                                    'session' => $session,
                                    'detail' => $detail,
                                    'event' => $event,
                                    'viewerTimezone' => $viewerTimezone,
                                    'number' => $loop->iteration,
                                    'isLast' => $loop->last,
                                ])
                            @endforeach
                        </ol>
                    </section>
                @endif

                {{-- Line-up for this date --}}
                @if($ownInvolvements->isNotEmpty())
                    <section id="barisan" class="scroll-mt-8" aria-labelledby="occurrence-lineup-title">
                        <div class="mb-7">
                            <span class="block h-[2px] w-7 bg-[#c9962f]" aria-hidden="true"></span>
                            <p class="mt-4 text-[11px] font-semibold uppercase tracking-[0.26em] text-[#8a5f13]">{{ __('Barisan') }}</p>
                            <h2 id="occurrence-lineup-title" class="mt-2 font-heading text-[1.75rem] leading-[1.15] tracking-[-0.01em] text-balance">{{ __('Barisan tarikh ini') }}</h2>
                        </div>

                        <ul class="mi-paper divide-y divide-[#f0e8d9] overflow-hidden rounded-2xl border border-[#e6dccb] bg-[#fffdf9]">
                            @foreach($ownInvolvements as $involvement)
                                @php
                                    $involvementName = $involvement->display_name ?: $involvement->involveable?->name;
                                @endphp
                                @if($involvementName)
                                    <li class="flex items-center gap-4 px-6 py-5">
                                        <span class="flex size-11 shrink-0 items-center justify-center rounded-full bg-[#14342e] font-heading text-lg text-[#f2c867]" aria-hidden="true">{{ mb_strtoupper(mb_substr((string) $involvementName, 0, 1)) }}</span>
                                        <span class="min-w-0">
                                            <span class="block truncate text-[15px] font-semibold text-[#14342e]">{{ $involvementName }}</span>
                                            @if($involvement->role?->name)
                                                <span class="mt-0.5 block truncate text-[13px] text-[#6b645e]">{{ $involvement->role->name }}</span>
                                            @endif
                                        </span>
                                    </li>
                                @endif
                            @endforeach
                        </ul>
                    </section>
                @endif

                {{-- Participation --}}
                @if($occurrenceHasParticipation)
                    <section id="penyertaan" data-testid="event-admission-section" class="scroll-mt-8" aria-labelledby="occurrence-participation-title">
                        <div class="mb-7">
                            <span class="block h-[2px] w-7 bg-[#c9962f]" aria-hidden="true"></span>
                            <p class="mt-4 text-[11px] font-semibold uppercase tracking-[0.26em] text-[#8a5f13]">{{ __('Penyertaan') }}</p>
                            <h2 id="occurrence-participation-title" class="mt-2 font-heading text-[1.75rem] leading-[1.15] tracking-[-0.01em] text-balance">{{ __('Cara menyertai') }}</h2>
                        </div>

                        <div class="mi-paper rounded-2xl border border-[#e6dccb] bg-[#fffdf9] p-6 sm:p-8">
                            @if($occurrenceCapacity !== null)
                                <div class="flex flex-wrap items-baseline justify-between gap-3">
                                    <p class="font-heading text-2xl font-normal text-[#14342e]">
                                        {{ $occurrenceReserved }}
                                        <span class="text-sm font-medium text-[#6b645e]">/ {{ $occurrenceCapacity }} {{ __('tempat ditempah') }}</span>
                                    </p>
                                    @if($occurrenceCapacityPercent !== null)
                                        <span class="font-mono text-xs font-semibold text-[#6b645e]">{{ $occurrenceCapacityPercent }}%</span>
                                    @endif
                                </div>
                                <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-[#f0e8d9]" role="presentation">
                                    <div class="h-full rounded-full bg-[#c9962f]" style="width: {{ $occurrenceCapacityPercent ?? 0 }}%"></div>
                                </div>
                            @endif

                            <div class="mt-5 flex flex-wrap gap-2 text-[13px] font-medium">
                                @if($occurrenceFull)
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-[#fdf0ee] px-3 py-1.5 text-[#9a3324]">{{ __('Tempat penuh buat masa ini.') }}</span>
                                @endif
                                @if($occurrencePolicy && ! $occurrencePolicy->registration_required)
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-[#f3efe6] px-3 py-1.5 text-[#6b6459]">{{ __('Tiada pendaftaran diperlukan untuk tarikh ini.') }}</span>
                                @endif
                                @if($occurrencePolicy?->approval_required)
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-[#fdf3dd] px-3 py-1.5 text-[#8a5f13]">{{ __('Penyertaan memerlukan kelulusan penganjur.') }}</span>
                                @endif
                                @if($detail->allowsWalkIn())
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-[#eef4ee] px-3 py-1.5 text-[#2f5d43]">{{ __('Walk-in dibenarkan.') }}</span>
                                @endif
                                @if($occurrenceHasOffering && ! $occurrenceRegistrationsOpen && ! $occurrenceFull)
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-[#fdf3dd] px-3 py-1.5 text-[#8a5f13]">{{ __('Pendaftaran dalam talian ditutup buat masa ini.') }}</span>
                                @endif
                            </div>

                            @if(filled($occurrencePolicy?->notes))
                                <p class="mt-4 text-sm leading-7 text-[#4c5b56]">{{ $occurrencePolicy->notes }}</p>
                            @endif

                            @if($tickets->isNotEmpty())
                                <ul class="mt-6 space-y-3">
                                    @foreach($tickets as $ticketEntry)
                                        <li>
                                            @include('livewire.pages.events.partials.admission-ticket-card', ['ticketEntry' => $ticketEntry, 'ticketScopeCount' => $ticketScopeCount, 'detail' => $detail, 'event' => $event, 'eventActionsDisabled' => $eventActionsDisabled])
                                        </li>
                                    @endforeach
                                </ul>
                            @elseif($occurrenceDirectCheckoutUrl !== null)
                                <a
                                    href="{{ $occurrenceDirectCheckoutUrl }}"
                                    wire:navigate
                                    data-signal-event="commerce.event_checkout_started"
                                    data-signal-category="commerce"
                                    data-signal-component="event_detail_registration"
                                    data-signal-control="register_open"
                                    data-signal-entity-type="event"
                                    data-signal-entity-id="{{ $event->id }}"
                                    data-signal-props='@json(['registration_mode' => $this->registrationMode()->value])'
                                    class="mt-6 inline-flex w-full items-center justify-center gap-2 rounded-full bg-[#14342e] px-5 py-3 text-sm font-semibold text-[#fdfaf2] transition duration-300 ease-[cubic-bezier(0.22,1,0.36,1)] hover:bg-[#1d4a41] active:scale-[0.98] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#14342e]"
                                >
                                    {{ __('Daftar sekarang') }}
                                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m0 0-6-6m6 6-6 6" /></svg>
                                </a>
                            @endif

                            @if($occurrencePaymentRequired && ! \App\Support\Commerce\EventCommerceModes::publicPaidCheckoutEnabled())
                                <div class="mt-5 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm leading-6 text-amber-900">{{ __('Maklumat tiket dipaparkan di sini. Pembelian tiket dalam talian belum tersedia untuk majlis ini.') }}</div>
                            @endif
                        </div>
                    </section>
                @endif

                @include('livewire.pages.events.partials.admission-seating', [
                    'detail' => $detail,
                    'scopeId' => 'tarikh',
                ])

                @include('livewire.pages.events.partials.shared-event-info', [
                    'event' => $event,
                    'occurrence' => $occurrence,
                    'detail' => $detail,
                    'languageLabels' => $languageLabels,
                    'classificationLabels' => $classificationLabels,
                    'audienceLabels' => $audienceLabels,
                    'muslimOnly' => $muslimOnly,
                    'genderRestriction' => $genderRestriction,
                    'keyPeopleByRole' => $keyPeopleByRole,
                    'organizer' => $organizer,
                    'organizerHref' => $organizerHref,
                    'hideOrganizer' => true,
                    'largeSpeakerPortraits' => true,
                    'galleryImages' => $galleryImages,
                    'occurrenceDateLabel' => $occurrenceDateLabel,
                    'occurrenceTimeLabel' => $occurrenceTimeLabel,
                    'occurrenceFormatLabel' => $occurrenceFormatLabel,
                ])

                @php
                    $referenceRows = $occurrenceReferences
                        ->filter(fn ($referenceRow): bool => $referenceRow->referenceable instanceof \App\Models\Reference || $detail->isUnboundReference($referenceRow))
                        ->values();
                    $referenceTotal = $referenceRows->count() + $eventReferences->count();
                @endphp
                @if($referenceRows->isNotEmpty() || $eventReferences->isNotEmpty())
                    <section id="rujukan" data-testid="event-detail-references-section" class="scroll-mt-8" aria-labelledby="occurrence-references-title">
                        <div class="mb-7">
                            <span class="block h-[2px] w-7 bg-[#c9962f]" aria-hidden="true"></span>
                            <p class="mt-4 text-[11px] font-semibold uppercase tracking-[0.26em] text-[#8a5f13]">{{ __('Sumber') }}</p>
                            <div class="mt-2 flex flex-wrap items-baseline justify-between gap-x-6 gap-y-1">
                                <h2 id="occurrence-references-title" class="font-heading text-[1.75rem] leading-[1.15] tracking-[-0.01em] text-balance">{{ __('References') }}</h2>
                                <p class="font-mono text-xs text-[#6b645e]">{{ $referenceTotal }}</p>
                            </div>
                        </div>

                        <ol class="{{ $referenceTotal <= 1 ? 'grid gap-5' : 'grid gap-5 sm:grid-cols-2' }}">
                            @foreach($referenceRows as $referenceRow)
                                <li class="flex gap-5 rounded-2xl border border-[#e6dccb] bg-[#fffdf9] px-6 py-5">
                                    <span class="mt-0.5 shrink-0 font-heading text-sm font-normal tabular-nums text-[#8a5f13]" aria-hidden="true">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                                    <div class="min-w-0">
                                        @if($referenceRow->referenceable instanceof \App\Models\Reference)
                                            <p class="font-heading text-lg leading-snug text-[#14342e]">
                                                <a href="{{ route('references.show', $referenceRow->referenceable) }}" wire:navigate class="underline decoration-[#c9bfae] underline-offset-4 transition-colors duration-300 hover:text-[#8a5f13] hover:decoration-[#c9962f] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#14342e]">{{ $referenceRow->referenceable->displayTitle() }}</a>
                                            </p>
                                            @if(filled($referenceRow->referenceable->effectiveAuthorNames()))
                                                <p class="mt-1 text-[13px] text-[#6b645e]">{{ $referenceRow->referenceable->effectiveAuthorNames() }}</p>
                                            @endif
                                        @else
                                            <p class="font-heading text-lg leading-snug text-[#14342e]">{{ $referenceRow->title }}</p>
                                            @if(filled($referenceRow->citation))
                                                <p class="mt-1 text-[13px] text-[#6b645e]">{{ $referenceRow->citation }}</p>
                                            @endif
                                            @if(filled($referenceRow->url))
                                                <a href="{{ $referenceRow->url }}" target="_blank" rel="noopener" class="mt-1.5 inline-block max-w-full truncate text-[13px] font-medium text-[#1f6b57] underline decoration-[#1f6b57]/30 underline-offset-4 transition-colors duration-300 hover:decoration-[#1f6b57]">{{ $referenceRow->url }}</a>
                                            @endif
                                        @endif
                                    </div>
                                </li>
                            @endforeach

                            @foreach($eventReferences as $reference)
                                <li class="flex gap-5 rounded-2xl border border-[#e6dccb] bg-[#fffdf9] px-6 py-5">
                                    <span class="mt-0.5 shrink-0 font-heading text-sm font-normal tabular-nums text-[#8a5f13]" aria-hidden="true">{{ str_pad((string) ($referenceRows->count() + $loop->iteration), 2, '0', STR_PAD_LEFT) }}</span>
                                    <div class="min-w-0">
                                        <p class="font-heading text-lg leading-snug text-[#14342e]">
                                            <a href="{{ route('references.show', $reference) }}" wire:navigate class="underline decoration-[#c9bfae] underline-offset-4 transition-colors duration-300 hover:text-[#8a5f13] hover:decoration-[#c9962f] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#14342e]">{{ $reference->displayTitle() }}</a>
                                            <span class="ml-1.5 align-middle rounded-full bg-[#f3efe6] px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.1em] text-[#6b645e]">{{ __('Majlis') }}</span>
                                        </p>
                                        @if(filled($reference->effectiveAuthorNames()))
                                            <p class="mt-1 text-[13px] text-[#6b645e]">{{ $reference->effectiveAuthorNames() }}</p>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ol>
                    </section>
                @endif

                @php
                    $resourceRows = collect();
                    foreach ($ownLinks as $link) {
                        $resourceRows->push(['label' => $detail->linkTypeLabel($link), 'url' => $link->url, 'scope' => null, 'kind' => 'link']);
                    }
                    foreach ($ownMaterials as $material) {
                        $resourceRows->push(['label' => $material->title, 'url' => $material->url, 'scope' => null, 'kind' => 'material']);
                    }
                    foreach ($eventLinks as $link) {
                        $resourceRows->push(['label' => $detail->linkTypeLabel($link), 'url' => $link->url, 'scope' => __('Majlis'), 'kind' => 'link']);
                    }
                    foreach ($eventMaterials as $material) {
                        $resourceRows->push(['label' => $material->title, 'url' => $material->url, 'scope' => __('Majlis'), 'kind' => 'material']);
                    }
                    foreach ($rawEventUrls as $rawUrl) {
                        $resourceRows->push(['label' => $rawUrl['label'], 'url' => $rawUrl['url'], 'scope' => __('Majlis'), 'kind' => 'link']);
                    }
                @endphp
                @if($resourceRows->isNotEmpty())
                    <section id="pautan" class="scroll-mt-8" aria-labelledby="occurrence-links-title">
                        <div class="mb-7">
                            <span class="block h-[2px] w-7 bg-[#c9962f]" aria-hidden="true"></span>
                            <p class="mt-4 text-[11px] font-semibold uppercase tracking-[0.26em] text-[#8a5f13]">{{ __('Bahan') }}</p>
                            <h2 id="occurrence-links-title" class="mt-2 font-heading text-[1.75rem] leading-[1.15] tracking-[-0.01em] text-balance">{{ __('Pautan & bahan') }}</h2>
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
                                        @if($row['scope'])
                                            <span class="shrink-0 rounded-full bg-[#f3efe6] px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.1em] text-[#6b645e]">{{ $row['scope'] }}</span>
                                        @endif
                                        <svg class="size-4 shrink-0 text-[#c9bfae] transition duration-300 ease-[cubic-bezier(0.22,1,0.36,1)] group-hover:translate-x-0.5 group-hover:text-[#c9962f]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M7 17L17 7M9 7h8v8" /></svg>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                {{-- Closing invitation --}}
                <section class="relative overflow-hidden rounded-2xl bg-[#14342e] px-7 py-8 text-[#f7f3e8] sm:px-9 sm:py-10">
                    <div class="mi-lattice-light pointer-events-none absolute inset-0 opacity-[0.05]" aria-hidden="true"></div>
                    <div class="relative flex flex-wrap items-center justify-between gap-6">
                        <div class="max-w-md">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.26em] text-[#f2c867]">{{ __('Sebarkan') }}</p>
                            <p class="mt-3 font-heading text-2xl font-normal leading-snug text-balance">{{ __('Kongsi majlis ini, moga lebih ramai hadir.') }}</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-3">
                            <button type="button" @click="openShare()" data-signal-event="share.modal_opened" data-signal-category="share" data-signal-component="event_detail_actions" data-signal-control="share" data-signal-entity-type="event" data-signal-entity-id="{{ $event->id }}" class="inline-flex items-center gap-2 rounded-full bg-[#f2c867] px-5 py-3 text-sm font-semibold text-[#0e2e27] transition duration-300 ease-[cubic-bezier(0.22,1,0.36,1)] hover:bg-[#f7d98d] active:scale-[0.98] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#f2c867]">
                                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684zm0 9.316a3 3 0 105.368 2.684z" /></svg>
                                {{ __('Kongsi pautan') }}
                            </button>
                            @if($calendarLinks !== [])
                                <a href="{{ $calendarLinks['ics'] ?? '#' }}" download class="inline-flex items-center gap-2 rounded-full border border-[#f7f3e8]/40 px-5 py-3 text-sm font-semibold text-[#f7f3e8] transition duration-300 ease-[cubic-bezier(0.22,1,0.36,1)] hover:border-[#f2c867]/60 hover:bg-[#f7f3e8]/10 active:scale-[0.98] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#f2c867]">
                                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v12m0 0l-4-4m4 4l4-4M5 20h14" /></svg>
                                    {{ __('Muat turun .ics') }}
                                </a>
                            @endif
                        </div>
                    </div>
                </section>
            </div>

            {{-- ── Sidebar ─────────────────────────────────────────────── --}}            <aside class="min-w-0 space-y-4 lg:sticky lg:top-8 lg:self-start">

                {{-- One card for "who runs this and where it is". When the event
                     names no venue the organiser's own address is the location, so
                     splitting them would print the same name twice. --}}
                @if($hasLocationContent)
                    <section data-testid="event-detail-location-section" class="mi-paper overflow-hidden rounded-2xl border border-[#e6dccb] bg-[#fffdf9]" aria-labelledby="occurrence-location-title">
                        @if($locationIsOrganizer && filled($organizerCoverImageUrl))
                            <img src="{{ $organizerCoverImageUrl }}" alt="" aria-hidden="true" loading="lazy" class="block aspect-[16/9] w-full object-cover">
                        @endif

                        <div class="p-6">
                            @if(! $locationIsOrganizer && filled($organizerCoverImageUrl))
                                <div class="mb-5">
                                    <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-[#8a5f13]">{{ __('Penganjur') }}</p>
                                    <img src="{{ $organizerCoverImageUrl }}" alt="" aria-hidden="true" loading="lazy" class="mt-3 aspect-[16/9] w-full rounded-xl object-cover">
                                </div>
                            @endif

                            @unless($locationIsOrganizer)
                                <p class="text-[11px] font-semibold uppercase tracking-[0.26em] text-[#8a5f13]">{{ __('Lokasi') }}</p>
                            @endunless

                            @if($placeTitle)
                                <h2 id="occurrence-location-title" class="mt-3 flex min-w-0 items-center gap-3 font-heading text-xl font-normal leading-snug text-[#14342e]">
                                    @if($locationIsOrganizer && filled($organizerImageUrl))
                                        <img src="{{ $organizerImageUrl }}" alt="" aria-hidden="true" class="size-12 shrink-0 rounded-xl border border-[#e6dccb] bg-white p-1 object-contain">
                                    @endif
                                    @if($locationIsOrganizer && $organizerHref)
                                        <a href="{{ $organizerHref }}" wire:navigate class="min-w-0 transition-colors duration-300 hover:text-[#8a5f13]">{{ $placeTitle }}</a>
                                    @else
                                        {{ $placeTitle }}
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

                            @if($mapEmbedUrl)
                                <div class="mt-5 overflow-hidden rounded-xl border border-[#e6dccb] bg-[#edf1ec]">
                                    <iframe
                                        src="{{ $mapEmbedUrl }}"
                                        title="{{ __('Peta lokasi :venue', ['venue' => $placeTitle ?: __('majlis')]) }}"
                                        class="block aspect-[16/10] w-full border-0"
                                        loading="lazy"
                                        referrerpolicy="no-referrer-when-downgrade"
                                        allowfullscreen
                                    ></iframe>
                                </div>
                            @endif

                            @if($navigationUrl)
                                <a href="{{ $navigationUrl }}" target="_blank" rel="noopener" class="mt-5 inline-flex w-full items-center justify-center gap-2 rounded-full bg-[#14342e] px-4 py-2.5 text-[13px] font-semibold text-[#fdfaf2] transition duration-300 ease-[cubic-bezier(0.22,1,0.36,1)] hover:bg-[#1d4a41] active:scale-[0.98] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#14342e]">
                                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.27 3.13a59.77 59.77 0 0118.18 8.6c.36.36.36.94 0 1.3a59.77 59.77 0 01-18.18 8.6L6 12zm0 0h6.75" /></svg>
                                    {{ __('Navigasi ke sini') }}
                                </a>
                            @endif

                            @if(filled($organizerPhone) || filled($organizerEmail))
                                <dl class="mt-4 space-y-1.5 border-t border-[#f0e8d9] pt-3 text-[13px]">
                                    @if(filled($organizerPhone))
                                        <div class="flex items-baseline justify-between gap-3">
                                            <dt class="text-[#6b645e]">{{ __('Telefon') }}</dt>
                                            <dd><a href="tel:{{ preg_replace('/[^0-9+]/', '', (string) $organizerPhone) }}" class="font-medium text-[#14342e] underline decoration-[#c9bfae] underline-offset-4 transition-colors duration-300 hover:decoration-[#14342e]">{{ $organizerPhone }}</a></dd>
                                        </div>
                                    @endif
                                    @if(filled($organizerEmail))
                                        <div class="flex items-baseline justify-between gap-3">
                                            <dt class="text-[#6b645e]">{{ __('E-mel') }}</dt>
                                            <dd class="min-w-0"><a href="mailto:{{ $organizerEmail }}" class="block truncate font-medium text-[#14342e] underline decoration-[#c9bfae] underline-offset-4 transition-colors duration-300 hover:decoration-[#14342e]">{{ $organizerEmail }}</a></dd>
                                        </div>
                                    @endif
                                </dl>
                            @endif
                        </div>
                    </section>
                @elseif($organizer)
                    {{-- No usable place data: the organiser still has to appear. --}}
                    <section class="mi-paper rounded-2xl border border-[#e6dccb] bg-[#fffdf9] p-6" aria-labelledby="occurrence-organizer-title">
                        @if(filled($organizerCoverImageUrl))
                            <img src="{{ $organizerCoverImageUrl }}" alt="" aria-hidden="true" loading="lazy" class="mb-5 aspect-[16/9] w-full rounded-xl object-cover">
                        @endif
                        <p class="text-[11px] font-semibold uppercase tracking-[0.26em] text-[#8a5f13]">{{ __('Penganjur') }}</p>
                        <div class="mt-3.5 flex items-center gap-3.5">
                            @if(filled($organizerImageUrl))
                                <img src="{{ $organizerImageUrl }}" alt="" aria-hidden="true" class="size-12 shrink-0 rounded-xl border border-[#e6dccb] bg-white p-1 object-contain">
                            @endif
                            <span class="min-w-0">
                                @if($organizerHref)
                                    <a href="{{ $organizerHref }}" wire:navigate class="block truncate font-heading text-lg leading-snug text-[#14342e] transition-colors duration-300 hover:text-[#8a5f13]">{{ $organizer->name }}</a>
                                @else
                                    <span class="block truncate font-heading text-lg leading-snug text-[#14342e]">{{ $organizer->name }}</span>
                                @endif
                                <span class="mt-0.5 block text-[13px] text-[#6b645e]">{{ __('Menganjurkan majlis ini') }}</span>
                            </span>
                        </div>
                    </section>
                @endif

                @if($capacities->isNotEmpty())
                    <section class="mi-paper rounded-2xl border border-[#e6dccb] bg-[#fffdf9] p-6" aria-labelledby="occurrence-capacity-title">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.26em] text-[#8a5f13]">{{ __('Kapasiti') }}</p>
                        <h2 id="occurrence-capacity-title" class="mt-3 font-heading text-xl font-normal leading-snug text-[#14342e]">{{ __('Tempat tersedia') }}</h2>
                        <ul class="mt-5 space-y-4">
                            @foreach($capacities as $capacity)
                                @php
                                    $capacityPercent = $capacity['capacity'] > 0
                                        ? max(0, min(100, (int) round($capacity['reserved'] / $capacity['capacity'] * 100)))
                                        : 0;
                                @endphp
                                <li>
                                    <div class="flex items-baseline justify-between gap-3">
                                        <span class="min-w-0 truncate text-[13px] font-medium text-[#4c5b56]">{{ $capacity['scope_label'] }}</span>
                                        <span class="shrink-0 font-mono text-[11px] text-[#6b645e]">{{ $capacity['reserved'] }} / {{ $capacity['capacity'] }}</span>
                                    </div>
                                    <div class="mt-2 h-1 overflow-hidden rounded-full bg-[#f0e8d9]" role="presentation">
                                        <div class="h-full rounded-full bg-[#c9962f]" style="width: {{ $capacityPercent }}%"></div>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                @if($calendarLinks !== [])
                    <section class="mi-paper rounded-2xl border border-[#e6dccb] bg-[#fffdf9] p-6" aria-labelledby="occurrence-save-title">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.26em] text-[#8a5f13]">{{ __('Simpan') }}</p>
                        <h2 id="occurrence-save-title" class="mt-3 font-heading text-xl font-normal leading-snug text-[#14342e]">{{ __('Jangan terlepas') }}</h2>

                        <a href="{{ $calendarLinks['google'] ?? $calendarLinks['ics'] ?? '#' }}" target="_blank" rel="noopener" class="mt-4 inline-flex w-full items-center justify-center gap-2 rounded-full bg-[#14342e] px-4 py-2.5 text-[13px] font-semibold text-[#fdfaf2] transition duration-300 ease-[cubic-bezier(0.22,1,0.36,1)] hover:bg-[#1d4a41] active:scale-[0.98] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#14342e]">
                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 4h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5v12a2 2 0 002 2z" /></svg>
                            {{ __('Tambah ke kalendar') }}
                        </a>

                        <div class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1.5 text-[12px] font-medium text-[#6b645e]">
                            @foreach(['ics' => 'Apple / iCal', 'outlook' => 'Outlook', 'office365' => 'Office 365'] as $calendarKey => $calendarLabel)
                                @if(filled($calendarLinks[$calendarKey] ?? null))
                                    <a href="{{ $calendarLinks[$calendarKey] }}" @if($calendarKey === 'ics') download @else target="_blank" rel="noopener" @endif class="underline decoration-[#c9bfae] underline-offset-4 transition-colors duration-300 hover:text-[#14342e] hover:decoration-[#14342e]">{{ $calendarLabel }}</a>
                                @endif
                            @endforeach
                        </div>
                    </section>
                @endif

                {{-- Ask the reader to fact-check, not just to share. Same two
                     routes as the shared feedback panel, in the page palette. --}}
                <section id="event-share-panel" aria-label="{{ __('Kongsi majlis') }}">
                    <x-dawah-share-panel :share-data="[
                        'title' => $occurrenceDisplayTitle,
                        'text' => \Illuminate\Support\Str::limit($event->description_text, 100),
                        'url' => $occurrenceShareUrl,
                        'sourceUrl' => $occurrenceShareUrl,
                        'shareText' => trim($occurrenceDisplayTitle.' - '.config('app.name')),
                        'fallbackTitle' => $occurrenceDisplayTitle,
                        'payloadEndpoint' => route('dawah-share.payload'),
                    ]" :share-links="$this->shareLinks" />
                </section>

                <section class="mi-paper rounded-2xl border border-[#e6dccb] bg-[#fffdf9] p-6" aria-labelledby="occurrence-feedback-title">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.26em] text-[#8a5f13]">{{ __('Ketepatan maklumat') }}</p>
                    <h2 id="occurrence-feedback-title" class="mt-3 font-heading text-xl font-normal leading-snug text-[#14342e]">{{ __('Bantu semak majlis ini') }}</h2>
                    <p class="mt-2 text-[13px] leading-6 text-[#4c5b56]">{{ __('Nampak maklumat yang tidak tepat? Maklumkan kepada kami.') }}</p>
                    @php
                        $feedbackSubjectType = \App\Enums\ContributionSubjectType::Event->publicRouteSegment();
                    @endphp
                    <div class="mt-4 grid grid-cols-2 gap-2">
                        <a href="{{ route('contributions.suggest-update', ['subjectType' => $feedbackSubjectType, 'subjectId' => $event->slug]) }}" wire:navigate class="inline-flex items-center justify-center gap-1.5 rounded-full border border-[#e6dccb] bg-white px-3 py-2 text-[12px] font-semibold text-[#14342e] transition duration-300 hover:border-[#c9962f]/50 hover:bg-[#f8f3e8] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#14342e]">{{ __('Cadang kemaskini') }}</a>
                        <a href="{{ route('reports.create', ['subjectType' => $feedbackSubjectType, 'subjectId' => $event->slug]) }}" wire:navigate class="inline-flex items-center justify-center gap-1.5 rounded-full border border-[#e6dccb] bg-white px-3 py-2 text-[12px] font-semibold text-[#14342e] transition duration-300 hover:border-[#c9962f]/50 hover:bg-[#f8f3e8] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#14342e]">{{ __('Lapor isu') }}</a>
                    </div>
                </section>

                @if($eventHasMultipleDates)
                    <a href="{{ route('events.show', $event) }}" wire:navigate class="group flex items-center justify-between gap-4 rounded-2xl border border-[#e6dccb] bg-[#faf6ed] px-6 py-5 transition duration-300 ease-[cubic-bezier(0.22,1,0.36,1)] hover:border-[#c9962f]/40 hover:bg-[#f6f0e2] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#14342e]">
                        <span class="min-w-0">
                            <span class="block text-[11px] font-semibold uppercase tracking-[0.26em] text-[#8a5f13]">{{ __('Program penuh') }}</span>
                            <span class="mt-1.5 block truncate font-heading text-lg leading-snug text-[#14342e]">{{ $event->title }}</span>
                            <span class="mt-1 block text-[13px] text-[#6b645e]">{{ __('Semua tarikh dan sesi') }}</span>
                        </span>
                        <svg class="size-5 shrink-0 text-[#c9962f] transition-transform duration-300 ease-[cubic-bezier(0.22,1,0.36,1)] group-hover:translate-x-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M13 6l6 6-6 6" /></svg>
                    </a>
                @endif
            </aside>
        </div>
    </div>

    {{-- ── Related majlis ──────────────────────────────────────────── --}}
    @if($relatedEvents->isNotEmpty())
        <section class="mt-16 border-t border-[#e6dccb] bg-[#faf6ed]" aria-labelledby="occurrence-related-title">
            <div class="mx-auto max-w-7xl px-5 py-14 sm:px-8 lg:px-12">
                <div class="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <span class="block h-[2px] w-7 bg-[#c9962f]" aria-hidden="true"></span>
                        <p class="mt-4 text-[11px] font-semibold uppercase tracking-[0.26em] text-[#8a5f13]">{{ __('Teruskan') }}</p>
                        <h2 id="occurrence-related-title" class="mt-2 font-heading text-[1.75rem] leading-[1.15] tracking-[-0.01em]">{{ __('Majlis lain yang berdekatan') }}</h2>
                    </div>
                    <a href="{{ route('events.index') }}" wire:navigate class="hidden items-center gap-2 rounded-full border border-[#e6dccb] bg-white px-4 py-2 text-[13px] font-semibold text-[#14342e] transition duration-300 hover:border-[#c9962f]/50 hover:bg-[#f8f3e8] sm:inline-flex">
                        {{ __('Lihat semua majlis') }}
                        <span aria-hidden="true">→</span>
                    </a>
                </div>

                <div class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach($relatedEvents as $relatedEvent)
                        @php
                            // Real uploads only: card_image_url falls back to a stock
                            // placeholder, and six identical stock photos read as
                            // broken rather than as imagery.
                            $relatedImage = $relatedEvent->getFirstMedia('cover')?->getAvailableUrl(['thumb'])
                                ?? $relatedEvent->displayOccurrence?->getFirstMedia('cover')?->getAvailableUrl(['thumb']);
                        @endphp
                        <a href="{{ route('events.show', $relatedEvent) }}" wire:navigate class="group mi-paper flex flex-col overflow-hidden rounded-2xl border border-[#e6dccb] bg-[#fffdf9] transition duration-300 ease-[cubic-bezier(0.22,1,0.36,1)] hover:-translate-y-0.5 hover:border-[#c9962f]/45 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#14342e]">
                            @if($relatedImage)
                                <img src="{{ $relatedImage }}" alt="" class="aspect-[16/9] w-full object-cover" loading="lazy">
                            @else
                                <div class="relative flex aspect-[16/9] items-end overflow-hidden bg-[#14342e] p-5">
                                    <div class="mi-lattice-light pointer-events-none absolute inset-0 opacity-[0.08]" aria-hidden="true"></div>
                                    <span class="relative font-heading text-lg leading-tight text-[#f2c867]">{{ $relatedEvent->title }}</span>
                                </div>
                            @endif
                            <div class="flex flex-1 flex-col p-5">
                                @if($relatedImage)
                                    <p class="font-heading text-lg leading-snug text-[#14342e] transition-colors duration-300 group-hover:text-[#8a5f13]">{{ $relatedEvent->title }}</p>
                                @endif
                                @if($relatedEvent->starts_at)
                                    <p class="mt-auto pt-2 text-[12px] font-medium text-[#6b645e]">{{ \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($relatedEvent->starts_at, 'j M Y, h:i A') }}</p>
                                @endif
                            </div>
                        </a>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{-- ── Share dialog ────────────────────────────────────────────── --}}
    @include('livewire.pages.events.partials.share-dialog', [
        'shareUrl' => $occurrenceShareUrl,
        'shareTitle' => $occurrenceDisplayTitle,
        'shareLinks' => $this->shareLinks,
        'shareProviders' => $shareProviders,
    ])

    {{-- ── Poster dialog ───────────────────────────────────────────── --}}
    @if($occurrencePosterUrl)
        <div
            x-show="posterOpen"
            x-cloak
            x-transition.opacity
            @keydown.escape.window="posterOpen = false"
            class="fixed inset-0 z-50 flex items-center justify-center bg-[#0b2b25]/95 p-4"
            role="dialog"
            aria-modal="true"
            aria-label="{{ __('Poster majlis') }}"
        >
            <button type="button" @click="posterOpen = false" class="absolute right-4 top-4 inline-flex size-11 items-center justify-center rounded-full bg-[#f7f3e8]/10 text-[#f7f3e8] transition duration-200 hover:bg-[#f7f3e8]/20 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#f2c867]" aria-label="{{ __('Tutup') }}">
                <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
            </button>
            <button type="button" @click="posterOpen = false" class="max-h-full max-w-full focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#f2c867]">
                <img src="{{ $occurrencePosterUrl }}" alt="{{ __('Poster :title', ['title' => $occurrenceDisplayTitle]) }}" class="max-h-[90vh] max-w-full rounded-xl object-contain shadow-2xl">
            </button>
        </div>
    @endif
</div>
