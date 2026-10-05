@section('title', $session->title.' — '.trim((string) ($occurrence->title ?: $event->title)))

@php
    $detail = $this->detail;
    $sessionCapacity = $detail->capacityFor($session);
    $sessionReserved = $detail->participantCount($session);
    $sessionRemaining = $detail->effectiveCapacityRemaining($session);
    $sessionFull = $sessionRemaining !== null && $sessionRemaining <= 0;
    $ownLocation = $detail->primaryLocationFor($session);
    $occurrenceLocation = $detail->primaryLocationFor($occurrence);
    $location = $ownLocation ?? $occurrenceLocation ?? $detail->primaryLocationFor($event);
    $locationVenue = $location?->relationLoaded('venue') ? $location->venue : null;
    $locationSpace = $location?->relationLoaded('venueSpace') ? $location->venueSpace : null;
    $locationInstitution = $location?->relationLoaded('locationable') && $location->locationable instanceof \App\Models\Institution ? $location->locationable : null;
    $locationAddress = $location?->primaryAddress() ?? $locationInstitution?->primaryAddress() ?? $locationVenue?->primaryAddress();
    $primaryAddress = $locationAddress
        ?? (($ownLocation ?? $occurrenceLocation)?->venue_id !== null
            ? null
            : $event->resolvedLocationAddress());
    $addressDisplayLines = \App\Support\Location\AddressHierarchyFormatter::displayLines($primaryAddress);
    $venueDisplayName = $locationVenue?->name ?? $locationInstitution?->display_name ?? $detail->locationLabel($location) ?? $event->resolvedLocationName();
    $isOnlineOnly = (string) $event->delivery_mode === \App\Enums\EventFormat::Online->value
        && $location === null
        && $locationVenue === null
        && $primaryAddress === null;
    $hasLocationContent = ! $isOnlineOnly && ($venueDisplayName || $locationSpace || $primaryAddress);
    $lat = $primaryAddress?->latitude;
    $lng = $primaryAddress?->longitude;
    $mapQuery = implode(', ', array_filter([
        $locationVenue?->name ?? $locationInstitution?->display_name,
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
    $mapsUrl = filled($normalizedMapQuery)
        ? 'https://www.google.com/maps/search/?api=1&query='.urlencode((string) $normalizedMapQuery)
        : null;
    $sessionPolicy = $detail->policiesForScope($session)->first();
    $sessionHasOffering = $detail->scopeHasRegistrationOffering($session);
    $sessionRegistrationsOpen = $detail->scopeRegistrationsOpen($session);
    $tickets = $detail->ticketEntries();
    $ticketScopeCount = $tickets->pluck('scope_label')->unique()->count();
    $eventActionsDisabled = $this->eventActionsDisabled || $detail->scopeActionsDisabled($session);
    $capacities = $detail->capacityEntries();
    $ownLinks = $detail->linksFor($session);
    $ownMaterials = $detail->materialsFor($session);
    $occurrenceLinks = $detail->linksFor($occurrence);
    $occurrenceMaterials = $detail->materialsFor($occurrence);
    $eventLinks = $detail->linksFor($event);
    $eventMaterials = $detail->materialsFor($event);
    $rawEventUrls = collect([
        ['url' => $event->event_url, 'label' => __('Laman rasmi')],
        ['url' => $event->live_url, 'label' => __('Siaran langsung')],
    ])->filter(fn (array $row): bool => filled($row['url'])
        && ! $ownLinks->concat($occurrenceLinks)->concat($eventLinks)->contains(fn ($link): bool => (string) $link->url === (string) $row['url']));
    $sessionReferences = $detail->referencesFor($session);
    $occurrenceReferences = $detail->referencesFor($occurrence);
    $eventReferences = $event->relationLoaded('references') ? $event->references : collect();
    $ownInvolvements = $session->relationLoaded('involvements') ? $session->involvements : collect();
    $calendarLinks = $this->calendarLinks;
    $viewerTimezone = \App\Support\Timezone\UserTimezoneResolver::resolve();
    $sessionSameLocalDay = $session->starts_at && $session->ends_at
        ? $session->starts_at->copy()->timezone($viewerTimezone)->isSameDay($session->ends_at->copy()->timezone($viewerTimezone))
        : true;
    $sessionTime = $session->starts_at
        ? \App\Support\Timezone\UserDateTimeFormatter::format($session->starts_at, 'h:i A')
        : __('TBC');
    if ($session->ends_at) {
        $sessionTime .= ' — '.\App\Support\Timezone\UserDateTimeFormatter::format($session->ends_at, $sessionSameLocalDay ? 'h:i A' : 'j M, h:i A');
    }
    $organizer = $event->organizer ?? $event->institution;
    $organizerHref = $organizer instanceof \App\Models\Institution
        ? route('institutions.show', $organizer)
        : ($organizer instanceof \App\Models\Person ? route('persons.show', $organizer) : null);
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
    $muslimOnly = $event->is_muslim_only === true;
    $keyPeopleByRole = $this->keyPeopleByRole;
    $galleryImages = $this->galleryImages;
@endphp

<div class="space-y-8">
    <nav class="text-xs font-semibold text-slate-500" aria-label="{{ __('Breadcrumb') }}">
        <ol class="flex flex-wrap items-center gap-1.5">
            <li><a href="{{ route('events.index') }}" wire:navigate class="hover:text-emerald-800">{{ __('Majlis') }}</a></li>
            <li aria-hidden="true">/</li>
            <li><a href="{{ route('events.show', $event) }}" wire:navigate class="hover:text-emerald-800">{{ $event->title }}</a></li>
            <li aria-hidden="true">/</li>
            <li><a href="{{ route('events.occurrence', ['event' => $event, 'occurrenceSlug' => \App\Support\Events\PublicScheduleSlug::occurrence($occurrence)]) }}" wire:navigate class="hover:text-emerald-800">{{ trim((string) ($occurrence->title ?: $event->title)) }}</a></li>
            <li aria-hidden="true">/</li>
            <li class="text-slate-700" aria-current="page">{{ $session->title }}</li>
        </ol>
    </nav>

    <header class="overflow-hidden rounded-[2.5rem] bg-gradient-to-br from-[#0e2b26] via-[#16463d] to-[#1d5c50] text-white">
        <div class="grid gap-0 lg:grid-cols-[minmax(0,1fr)_360px]">
            <div class="p-6 sm:p-10">
                <p class="text-xs font-bold uppercase tracking-[0.2em] text-[#f2c867]">{{ __('Sesi program') }}</p>
                <h1 class="mt-3 font-heading text-3xl font-bold leading-tight tracking-tight sm:text-5xl">{{ $session->title }}</h1>
                <p class="mt-3 text-sm font-semibold text-white/70">{{ trim((string) ($occurrence->title ?: $event->title)) }} · {{ $event->title }}</p>
                <div class="mt-5 flex flex-wrap items-center gap-3 text-sm font-semibold">
                    <span class="inline-flex items-center gap-2 rounded-full bg-white/10 px-4 py-2">
                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2m6-2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        @if($session->starts_at)
                            {{ \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($session->starts_at, 'l, j F Y') }} · {{ $sessionTime }}
                        @else
                            {{ __('TBC') }}
                        @endif
                    </span>
                    @if($venueDisplayName)<span class="inline-flex items-center gap-2 rounded-full bg-white/10 px-4 py-2">{{ $venueDisplayName }}</span>@endif
                </div>
                @if($session->ends_at === null && $session->starts_at !== null)
                    <p class="mt-3 text-xs font-semibold text-white/70">{{ __('Masa tamat tidak ditetapkan untuk sesi ini.') }}</p>
                @endif
                <div class="mt-6 flex flex-wrap gap-3">
                    <a href="{{ route('events.occurrence', ['event' => $event, 'occurrenceSlug' => \App\Support\Events\PublicScheduleSlug::occurrence($occurrence)]) }}" wire:navigate class="inline-flex items-center gap-2 rounded-xl bg-white px-5 py-3 text-sm font-bold text-emerald-950 transition hover:bg-emerald-50">{{ __('View occurrence') }}</a>
                    @if($calendarLinks !== [])
                        <div class="relative" x-data="{ calendarOpen: false }">
                            <button type="button" @click="calendarOpen = !calendarOpen" class="inline-flex items-center gap-2 rounded-xl border border-white/25 bg-white/10 px-5 py-3 text-sm font-bold text-white transition hover:bg-white/20">
                                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 4h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5v12a2 2 0 002 2z" /></svg>
                                {{ __('Tambah ke Kalendar') }}
                            </button>
                            <div x-show="calendarOpen" x-cloak @click.away="calendarOpen = false" class="absolute left-0 top-full z-30 mt-2 w-64 overflow-hidden rounded-xl border border-slate-200 bg-white p-2 shadow-2xl">
                                @foreach(['google' => 'Google Calendar', 'ics' => 'Apple / iCal (.ics)', 'outlook' => 'Outlook.com', 'office365' => 'Office 365', 'yahoo' => 'Yahoo Calendar'] as $calendarKey => $calendarLabel)
                                    <a href="{{ $calendarLinks[$calendarKey] ?? '#' }}" @if($calendarKey !== 'ics') target="_blank" rel="noopener" @else download @endif class="block rounded-lg px-3 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-100">{{ $calendarLabel }}</a>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            </div>
            <div class="relative min-h-56 bg-black/20">
                @php($sessionCover = $session->getFirstMedia('cover')?->getAvailableUrl(['banner', 'thumb']) ?? $occurrence->getFirstMedia('cover')?->getAvailableUrl(['banner', 'thumb']) ?? $event->cover_url ?? null)
                @if($sessionCover)
                    <img src="{{ $sessionCover }}" alt="{{ $session->title }}" class="absolute inset-0 size-full object-cover" loading="eager">
                @else
                    <div class="absolute inset-0 flex items-center justify-center bg-[radial-gradient(circle_at_top_right,_rgba(242,200,103,0.35),_transparent_48%),linear-gradient(145deg,#0e2b26,#285b4e)]">
                        <span class="font-heading text-6xl font-bold text-white/20" aria-hidden="true">{{ __('S') }}</span>
                    </div>
                @endif
            </div>
        </div>
    </header>

    <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_360px]">
        <div class="min-w-0 space-y-8">
            @if(filled($session->summary) || filled($session->description))
                <section class="rounded-[2rem] border border-slate-200 bg-white p-6 sm:p-8" aria-labelledby="session-about-title">
                    <h2 id="session-about-title" class="font-heading text-2xl font-bold text-emerald-950">{{ __('Tentang sesi ini') }}</h2>
                    @if(filled($session->summary))<p class="mt-3 text-sm font-semibold leading-7 text-slate-700">{{ $session->summary }}</p>@endif
                    @if(filled($session->description))<div class="mt-3 text-sm leading-7 text-slate-700">{!! nl2br(e((string) $session->description)) !!}</div>@endif
                </section>
            @endif

            @if($ownInvolvements->isNotEmpty())
                <section class="rounded-[2rem] border border-slate-200 bg-white p-6 sm:p-8" aria-labelledby="session-people-title">
                    <h2 id="session-people-title" class="font-heading text-2xl font-bold text-emerald-950">{{ __('Barisan sesi ini') }}</h2>
                    <ul class="mt-4 grid gap-3 sm:grid-cols-2">
                        @foreach($ownInvolvements as $involvement)
                            @php($involvementName = $involvement->display_name ?: $involvement->involveable?->name)
                            @if($involvementName)
                                <li class="flex items-center gap-3 rounded-2xl border border-slate-200 bg-[#fbfaf7] p-4">
                                    <span class="flex size-11 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-sm font-bold text-emerald-900" aria-hidden="true">{{ mb_strtoupper(mb_substr((string) $involvementName, 0, 1)) }}</span>
                                    <span class="min-w-0">
                                        <span class="block truncate font-semibold text-slate-900">{{ $involvementName }}</span>
                                        @if($involvement->role?->name)<span class="block truncate text-xs font-semibold text-slate-500">{{ $involvement->role->name }}</span>@endif
                                    </span>
                                </li>
                            @endif
                        @endforeach
                    </ul>
                </section>
            @endif

            @if($sessionCapacity !== null || $sessionPolicy || $tickets->isNotEmpty())
                <section class="rounded-[2rem] border border-slate-200 bg-white p-6 sm:p-8" aria-labelledby="session-participation-title">
                    <p class="text-xs font-bold uppercase tracking-[0.2em] text-emerald-900">{{ __('Penyertaan') }}</p>
                    <h2 id="session-participation-title" class="mt-2 font-heading text-2xl font-bold text-emerald-950">{{ __('Cara menyertai') }}</h2>
                    <div class="mt-4 space-y-3 text-sm leading-6 text-slate-700">
                        @if($sessionCapacity !== null)<p><span class="font-bold text-emerald-950">{{ $sessionReserved }} / {{ $sessionCapacity }}</span> {{ __('tempat telah ditempah untuk sesi ini.') }}</p>@endif
                        @if($sessionFull)<p class="rounded-xl bg-rose-50 px-3 py-2 font-semibold text-rose-700">{{ __('Tempat penuh buat masa ini.') }}</p>@endif
                        @if($sessionPolicy && ! $sessionPolicy->registration_required)<p>{{ __('Tiada pendaftaran diperlukan untuk sesi ini.') }}</p>@endif
                        @if($sessionPolicy?->approval_required)<p>{{ __('Penyertaan memerlukan kelulusan penganjur.') }}</p>@endif
                        @if(filled($sessionPolicy?->notes))<p>{{ $sessionPolicy->notes }}</p>@endif
                        @if($detail->allowsWalkIn())<p class="rounded-xl bg-emerald-50 px-3 py-2 font-semibold text-emerald-900">{{ __('Walk-in dibenarkan.') }}</p>@endif
                        @if($sessionHasOffering && ! $sessionRegistrationsOpen && ! $sessionFull)<p class="rounded-xl bg-amber-50 px-3 py-2 font-semibold text-amber-900">{{ __('Pendaftaran dalam talian ditutup buat masa ini.') }}</p>@endif
                    </div>
                    @if($tickets->isNotEmpty())
                        <ul class="mt-5 space-y-3">
                            @foreach($tickets as $ticketEntry)
                                <li>
                                    @include('livewire.pages.events.partials.admission-ticket-card', ['ticketEntry' => $ticketEntry, 'ticketScopeCount' => $ticketScopeCount, 'detail' => $detail, 'event' => $event, 'eventActionsDisabled' => $eventActionsDisabled])
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            @endif

            @if($ownLinks->isNotEmpty() || $ownMaterials->isNotEmpty() || $occurrenceLinks->isNotEmpty() || $occurrenceMaterials->isNotEmpty() || $eventLinks->isNotEmpty() || $eventMaterials->isNotEmpty() || $rawEventUrls->isNotEmpty())
                <section class="rounded-[2rem] border border-slate-200 bg-white p-6 sm:p-8" aria-labelledby="session-resources-title">
                    <h2 id="session-resources-title" class="font-heading text-2xl font-bold text-emerald-950">{{ __('Pautan & bahan') }}</h2>
                    <ul class="mt-4 space-y-2">
                        @foreach($ownLinks as $link)
                            <li><a href="{{ $link->url }}" target="_blank" rel="noopener" class="flex items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-[#fbfaf7] px-4 py-3 font-semibold text-emerald-900 transition hover:border-emerald-200 hover:bg-emerald-50"><span class="min-w-0 truncate">{{ $detail->linkTypeLabel($link) }}</span><span aria-hidden="true">↗</span></a></li>
                        @endforeach
                        @foreach($ownMaterials as $material)
                            <li><a href="{{ $material->url }}" target="_blank" rel="noopener" class="flex items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-[#fbfaf7] px-4 py-3 font-semibold text-emerald-900 transition hover:border-emerald-200 hover:bg-emerald-50"><span class="min-w-0 truncate">{{ $material->title }}</span><span aria-hidden="true">↗</span></a></li>
                        @endforeach
                        @foreach($occurrenceLinks as $link)
                            <li><a href="{{ $link->url }}" target="_blank" rel="noopener" class="flex items-center justify-between gap-3 rounded-2xl border border-dashed border-slate-300 bg-white px-4 py-3 font-semibold text-slate-700 transition hover:border-emerald-200 hover:bg-emerald-50"><span class="min-w-0 truncate">{{ $detail->linkTypeLabel($link) }} <span class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-slate-500">{{ __('Tarikh') }}</span></span><span aria-hidden="true">↗</span></a></li>
                        @endforeach
                        @foreach($occurrenceMaterials as $material)
                            <li><a href="{{ $material->url }}" target="_blank" rel="noopener" class="flex items-center justify-between gap-3 rounded-2xl border border-dashed border-slate-300 bg-white px-4 py-3 font-semibold text-slate-700 transition hover:border-emerald-200 hover:bg-emerald-50"><span class="min-w-0 truncate">{{ $material->title }} <span class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-slate-500">{{ __('Tarikh') }}</span></span><span aria-hidden="true">↗</span></a></li>
                        @endforeach
                        @foreach($eventLinks as $link)
                            <li><a href="{{ $link->url }}" target="_blank" rel="noopener" class="flex items-center justify-between gap-3 rounded-2xl border border-dashed border-slate-300 bg-white px-4 py-3 font-semibold text-slate-700 transition hover:border-emerald-200 hover:bg-emerald-50"><span class="min-w-0 truncate">{{ $detail->linkTypeLabel($link) }} <span class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-slate-500">{{ __('Majlis') }}</span></span><span aria-hidden="true">↗</span></a></li>
                        @endforeach
                        @foreach($eventMaterials as $material)
                            <li><a href="{{ $material->url }}" target="_blank" rel="noopener" class="flex items-center justify-between gap-3 rounded-2xl border border-dashed border-slate-300 bg-white px-4 py-3 font-semibold text-slate-700 transition hover:border-emerald-200 hover:bg-emerald-50"><span class="min-w-0 truncate">{{ $material->title }} <span class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-slate-500">{{ __('Majlis') }}</span></span><span aria-hidden="true">↗</span></a></li>
                        @endforeach
                        @foreach($rawEventUrls as $rawUrl)
                            <li><a href="{{ $rawUrl['url'] }}" target="_blank" rel="noopener" class="flex items-center justify-between gap-3 rounded-2xl border border-dashed border-slate-300 bg-white px-4 py-3 font-semibold text-slate-700 transition hover:border-emerald-200 hover:bg-emerald-50"><span class="min-w-0 truncate">{{ $rawUrl['label'] }} <span class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-slate-500">{{ __('Majlis') }}</span></span><span aria-hidden="true">↗</span></a></li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if($sessionReferences->isNotEmpty() || $occurrenceReferences->isNotEmpty() || $eventReferences->isNotEmpty())
                <section class="rounded-[2rem] border border-slate-200 bg-white p-6 sm:p-8" aria-labelledby="session-references-title">
                    <h2 id="session-references-title" class="font-heading text-2xl font-bold text-emerald-950">{{ __('Rujukan') }}</h2>
                    <ul class="mt-4 space-y-3">
                        @foreach($sessionReferences as $referenceRow)
                            @if($referenceRow->referenceable instanceof \App\Models\Reference)
                                <li class="rounded-2xl border border-slate-200 bg-[#fbfaf7] p-4">
                                    <p class="font-bold text-emerald-950">{{ $referenceRow->referenceable->displayTitle() }}</p>
                                    @if(filled($referenceRow->referenceable->effectiveAuthorNames()))<p class="mt-1 text-sm text-slate-600">{{ $referenceRow->referenceable->effectiveAuthorNames() }}</p>@endif
                                </li>
                            @elseif($detail->isUnboundReference($referenceRow))
                                <li class="rounded-2xl border border-slate-200 bg-[#fbfaf7] p-4">
                                    <p class="font-bold text-emerald-950">{{ $referenceRow->title }}</p>
                                    @if(filled($referenceRow->citation))<p class="mt-1 text-sm text-slate-600">{{ $referenceRow->citation }}</p>@endif
                                    @if(filled($referenceRow->url))<a href="{{ $referenceRow->url }}" target="_blank" rel="noopener" class="mt-1 block truncate text-sm font-semibold text-emerald-800 hover:underline">{{ $referenceRow->url }}</a>@endif
                                </li>
                            @endif
                        @endforeach
                        @foreach($occurrenceReferences as $referenceRow)
                            @if($referenceRow->referenceable instanceof \App\Models\Reference)
                                <li class="rounded-2xl border border-dashed border-slate-300 bg-white p-4">
                                    <p class="font-bold text-slate-800">{{ $referenceRow->referenceable->displayTitle() }} <span class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-slate-500">{{ __('Tarikh') }}</span></p>
                                    @if(filled($referenceRow->referenceable->effectiveAuthorNames()))<p class="mt-1 text-sm text-slate-600">{{ $referenceRow->referenceable->effectiveAuthorNames() }}</p>@endif
                                </li>
                            @elseif($detail->isUnboundReference($referenceRow))
                                <li class="rounded-2xl border border-dashed border-slate-300 bg-white p-4">
                                    <p class="font-bold text-slate-800">{{ $referenceRow->title }} <span class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-slate-500">{{ __('Tarikh') }}</span></p>
                                    @if(filled($referenceRow->citation))<p class="mt-1 text-sm text-slate-600">{{ $referenceRow->citation }}</p>@endif
                                    @if(filled($referenceRow->url))<a href="{{ $referenceRow->url }}" target="_blank" rel="noopener" class="mt-1 block truncate text-sm font-semibold text-emerald-800 hover:underline">{{ $referenceRow->url }}</a>@endif
                                </li>
                            @endif
                        @endforeach
                        @foreach($eventReferences as $reference)
                            <li class="rounded-2xl border border-dashed border-slate-300 bg-white p-4">
                                <p class="font-bold text-slate-800">{{ $reference->displayTitle() }} <span class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-slate-500">{{ __('Majlis') }}</span></p>
                                @if(filled($reference->effectiveAuthorNames()))<p class="mt-1 text-sm text-slate-600">{{ $reference->effectiveAuthorNames() }}</p>@endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @include('livewire.pages.events.partials.shared-event-info', [
                'event' => $event,
                'detail' => $detail,
                'languageLabels' => $languageLabels,
                'classificationLabels' => $classificationLabels,
                'audienceLabels' => $audienceLabels,
                'muslimOnly' => $muslimOnly,
                'keyPeopleByRole' => $keyPeopleByRole,
                'organizer' => $organizer,
                'organizerHref' => $organizerHref,
                'galleryImages' => $galleryImages,
            ])
        </div>

        <aside class="min-w-0 space-y-6 lg:sticky lg:top-6 lg:self-start">
            @if($hasLocationContent)
                <section class="rounded-[2rem] border border-slate-200 bg-white p-6" aria-labelledby="session-location-title">
                    <p class="text-xs font-bold uppercase tracking-[0.2em] text-emerald-900">{{ __('Lokasi') }}</p>
                    @if($venueDisplayName)<h2 id="session-location-title" class="mt-2 font-heading text-xl font-bold text-emerald-950">{{ $venueDisplayName }}</h2>@endif
                    @if($locationSpace)<p class="mt-2 text-sm font-semibold text-slate-600">{{ $locationSpace->name }}</p>@endif
                    @if($primaryAddress)
                        <address class="mt-2 text-sm leading-6 text-slate-600 not-italic">
                            @if(filled($primaryAddress->line1))<span class="block">{{ $primaryAddress->line1 }}</span>@endif
                            @if(filled($primaryAddress->line2))<span class="block">{{ $primaryAddress->line2 }}</span>@endif
                            <span class="block">{{ implode(', ', array_filter([$primaryAddress->postcode, $addressDisplayLines['locality'] ?? null])) }}</span>
                            @if(filled($addressDisplayLines['regional'] ?? null))<span class="block">{{ $addressDisplayLines['regional'] }}</span>@endif
                        </address>
                    @endif
                    @if($mapsUrl)<a href="{{ $mapsUrl }}" target="_blank" rel="noopener" class="mt-4 inline-flex items-center gap-2 rounded-xl bg-emerald-950 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-emerald-900">{{ __('Buka Peta') }} <span aria-hidden="true">↗</span></a>@endif
                </section>
            @endif

            @if($capacities->isNotEmpty())
                <section class="rounded-[2rem] border border-slate-200 bg-white p-6" aria-labelledby="session-capacity-title">
                    <h2 id="session-capacity-title" class="font-heading text-xl font-bold text-emerald-950">{{ __('Kapasiti') }}</h2>
                    <ul class="mt-3 space-y-2 text-sm">
                        @foreach($capacities as $capacity)
                            <li class="flex items-center justify-between gap-3 rounded-xl bg-slate-50 px-3 py-2">
                                <span class="min-w-0 truncate font-semibold text-slate-700">{{ $capacity['scope_label'] }}</span>
                                <span class="shrink-0 font-mono font-bold text-slate-900">{{ $capacity['reserved'] }} / {{ $capacity['capacity'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            <section class="rounded-[2rem] border border-slate-200 bg-emerald-950 p-6 text-white" aria-labelledby="session-date-title">
                <h2 id="session-date-title" class="font-heading text-xl font-bold">{{ __('Tarikh & majlis') }}</h2>
                <p class="mt-2 text-sm leading-6 text-white/70">{{ trim((string) ($occurrence->title ?: $event->title)) }}</p>
                <div class="mt-4 flex flex-col gap-2">
                    <a href="{{ route('events.occurrence', ['event' => $event, 'occurrenceSlug' => \App\Support\Events\PublicScheduleSlug::occurrence($occurrence)]) }}" wire:navigate class="inline-flex items-center gap-2 rounded-xl bg-white px-4 py-2.5 text-sm font-bold text-emerald-950 transition hover:bg-emerald-50">{{ __('View occurrence') }}</a>
                    <a href="{{ route('events.show', $event) }}" wire:navigate class="inline-flex items-center gap-2 rounded-xl border border-white/25 bg-white/10 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-white/20">{{ __('View programme') }}</a>
                </div>
            </section>
        </aside>
    </div>
</div>
