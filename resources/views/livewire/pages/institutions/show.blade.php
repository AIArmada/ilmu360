@section('title', $institution->name . ' - ' . config('app.name'))
@section('meta_description', \Illuminate\Support\Str::limit(trim(strip_tags((string) $institution->description)) ?: __('Lihat profil, lokasi, saluran sumbangan, dan majlis akan datang oleh :name di :app.', ['name' => $institution->name, 'app' => config('app.name')]), 160))
@section('meta_robots', $institution->status === 'verified' ? 'index, follow' : 'noindex, nofollow')
@section('og_url', route('institutions.show', $institution))
@section('og_image', $institution->public_image_url)
@section('og_image_alt', __('Profil institusi :name', ['name' => $institution->name]))

@php
    $address = $institution->primaryAddress();
    $typeLabel = $institution->type?->getLabel();
    $addressLines = \App\Support\Location\AddressHierarchyFormatter::displayLines($address);
    $locationString = \App\Support\Location\AddressHierarchyFormatter::format($address);
    $upcomingEvents = $this->upcomingEvents;
    $pastEvents = $this->pastEvents;
    $upcomingTotal = $this->upcomingTotal;
    $pastTotal = $this->pastTotal;
    $publicContacts = $institution->contactMethods->where('is_public', true)->values();
    $donationChannels = $institution->donationChannels;
    $speakers = $institution->speakers;
    $spaces = $institution->spaces;
    $institutionUrl = route('institutions.show', $institution);
    $institutionRedirectUrl = route('institutions.show', $institution, absolute: false);
    $shareText = trim($institution->name . ' - ' . config('app.name'));
    $shareLinks = app(\App\Services\ShareTrackingService::class)->redirectLinks(
        $institutionUrl,
        $shareText,
        $institution->name,
    );
    $shareData = [
        'title' => $institution->name,
        'text' => __('Lihat profil institusi ini di :app', ['app' => config('app.name')]),
        'url' => $institutionUrl,
        'sourceUrl' => $institutionUrl,
        'shareText' => $shareText,
        'fallbackTitle' => $institution->name,
        'payloadEndpoint' => route('dawah-share.payload'),
    ];
    $institutionRouteSegment = \App\Enums\ContributionSubjectType::Institution->publicRouteSegment();
    $showPendingEventStatusNotice = $upcomingEvents->concat($pastEvents)->contains(
        fn (\App\Models\Event $event): bool => (string) $event->status === 'pending'
    );
    $showCancelledEventStatusNotice = $upcomingEvents->concat($pastEvents)->contains(
        fn (\App\Models\Event $event): bool => (string) $event->status === 'cancelled'
    );
    $mapQuery = implode(', ', array_filter([
        $institution->name,
        $address?->line1,
        $address?->line2,
        $addressLines['locality'],
        $addressLines['regional'],
    ]));
    $normalizedMapQuery = null;

    if (filled($address?->google_maps_url)) {
        $parsedQueryString = parse_url((string) $address->google_maps_url, PHP_URL_QUERY);

        if (is_string($parsedQueryString) && $parsedQueryString !== '') {
            parse_str($parsedQueryString, $queryParams);
            $queryValue = $queryParams['query'] ?? $queryParams['q'] ?? null;

            if (is_string($queryValue) && $queryValue !== '') {
                $normalizedMapQuery = $queryValue;
            }
        }
    }

    if (! filled($normalizedMapQuery) && filled($mapQuery)) {
        $normalizedMapQuery = $mapQuery;
    }

    if (! filled($normalizedMapQuery) && $address?->latitude !== null && $address?->longitude !== null) {
        $normalizedMapQuery = $address->latitude . ',' . $address->longitude;
    }

    $googleMapsEmbedUrl = filled($normalizedMapQuery)
        ? 'https://www.google.com/maps?q=' . urlencode((string) $normalizedMapQuery) . '&output=embed'
        : null;
    $googleMapsBrowseUrl = filled($address?->google_maps_url)
        ? (string) $address->google_maps_url
        : (filled($normalizedMapQuery) ? 'https://www.google.com/maps?q=' . urlencode((string) $normalizedMapQuery) : null);
    $wazeUrl = filled($address?->waze_url) ? (string) $address->waze_url : null;

    $formatContactHref = static function ($contact): ?string {
        return match ((string) $contact->type) {
            'phone' => 'tel:' . preg_replace('/\D+/', '', (string) $contact->value),
            'email' => 'mailto:' . (string) $contact->value,
            default => null,
        };
    };

    $resolveEventCategoryLabel = static fn (\App\Models\Event $event): string => app(\App\Support\Events\EventCategoryPresenter::class)->forEvent($event)[0]['path'] ?? __('Umum');

    $resolveVenueLocation = static function (\App\Models\Event $event): string {
        $venueName = $event->venue?->name;
        $address = $event->venue?->primaryAddress();
        $parts = \App\Support\Location\AddressHierarchyFormatter::parts($address);
        $addressValue = implode(', ', array_filter($parts));

        if (filled($venueName) && filled($addressValue)) {
            return $venueName . ' • ' . $addressValue;
        }

        if (filled($venueName)) {
            return (string) $venueName;
        }

        return $addressValue;
    };

    $joinEventPeopleNames = static function (\Illuminate\Support\Collection $names): string {
        return $names->join(', ', ' dan ');
    };

    $resolveEventSpeakerAvatarStack = static function (\App\Models\Event $event): array {
        $avatars = $event->speakers
            ->map(function (\App\Models\Speaker $speaker): array {
                return [
                    'name' => trim((string) ($speaker->formatted_name !== '' ? $speaker->formatted_name : $speaker->name)),
                    'url' => $speaker->public_avatar_url,
                ];
            })
            ->filter(fn (array $avatar): bool => $avatar['name'] !== '' && $avatar['url'] !== '')
            ->unique('name')
            ->values();

        return [
            'items' => $avatars->take(3)->values(),
            'overflow' => max(0, $avatars->count() - 3),
        ];
    };

    $resolveEventPeople = static function (\App\Models\Event $event) use ($joinEventPeopleNames): array {
        $speakerSummary = $event->speakers
            ->map(fn (\App\Models\Speaker $speaker): string => trim((string) ($speaker->formatted_name !== '' ? $speaker->formatted_name : $speaker->name)))
            ->filter(fn (string $name): bool => $name !== '')
            ->unique()
            ->values();

        $roleSummary = $event->keyPeople
            ->filter(function (\App\Models\EventKeyPerson $keyPerson): bool {
                $role = $keyPerson->role_code;
                $role = $role instanceof \App\Enums\EventKeyPersonRole
                    ? $role
                    : \App\Enums\EventKeyPersonRole::tryFrom((string) $role);

                return $keyPerson->visibility === 'public' && $role !== \App\Enums\EventKeyPersonRole::Speaker;
            })
            ->groupBy(function (\App\Models\EventKeyPerson $keyPerson): string {
                $role = $keyPerson->role_code;

                return $role instanceof \App\Enums\EventKeyPersonRole
                    ? $role->value
                    : (string) $role;
            })
            ->map(function (\Illuminate\Support\Collection $keyPeople, string $role) use ($joinEventPeopleNames): ?string {
                $names = $keyPeople
                    ->map(fn (\App\Models\EventKeyPerson $keyPerson): string => trim((string) $keyPerson->display_name))
                    ->filter(fn (string $name): bool => $name !== '')
                    ->unique()
                    ->values();

                if ($names->isEmpty()) {
                    return null;
                }

                $roleLabel = \App\Enums\EventKeyPersonRole::tryFrom($role)?->getLabel()
                    ?? \Illuminate\Support\Str::headline($role);

                return $roleLabel . ': ' . $joinEventPeopleNames($names);
            })
            ->filter()
            ->implode(' • ');

        return [
            'speakers' => $speakerSummary->isNotEmpty() ? $joinEventPeopleNames($speakerSummary) : '',
            'roles' => $roleSummary,
        ];
    };
@endphp

<div class="min-h-screen bg-slate-50/90">
    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_22rem]">
            <div class="space-y-8">
                <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                    <div class="aspect-[16/9] bg-slate-100">
                        <img
                            src="{{ $institution->public_image_url }}"
                            alt="{{ $institution->name }}"
                            class="h-full w-full object-cover"
                            loading="lazy"
                        >
                    </div>

                    <div class="space-y-6 p-6 sm:p-8">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div class="space-y-3">
                                @if($typeLabel)
                                    <span class="inline-flex items-center rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-900">
                                        {{ $typeLabel }}
                                    </span>
                                @endif

                                <div>
                                    <h1 class="font-heading text-3xl font-bold text-slate-950 sm:text-4xl">{{ $institution->name }}</h1>

                                    @if($locationString !== '')
                                        <p class="mt-2 text-sm text-slate-500">{{ $locationString }}</p>
                                    @endif
                                </div>
                            </div>

                            <div class="flex flex-wrap items-center gap-3">
                                <button
                                    type="button"
                                    wire:click="toggleFollow"
                                    wire:loading.attr="disabled"
                                    class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:border-emerald-300 hover:text-emerald-700"
                                >
                                    @if($this->isFollowing)
                                        {{ __('Mengikuti') }}
                                    @else
                                        {{ __('Ikuti') }}
                                    @endif
                                </button>

                                <a
                                    href="#institution-share-panel"
                                    class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:border-emerald-300 hover:text-emerald-700"
                                >
                                    {{ __('Kongsi') }}
                                </a>
                            </div>
                        </div>

                        @guest
                            <div class="flex flex-wrap gap-3">
                                <a
                                    href="{{ \App\Support\Auth\IntendedRedirect::registerUrl($institutionRedirectUrl) }}"
                                    class="inline-flex items-center gap-2 rounded-full bg-emerald-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-emerald-700"
                                >
                                    {{ __('Daftar') }}
                                </a>
                                <a
                                    href="{{ \App\Support\Auth\IntendedRedirect::loginUrl($institutionRedirectUrl) }}"
                                    class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:border-emerald-300 hover:text-emerald-700"
                                >
                                    {{ __('Log Masuk') }}
                                </a>
                            </div>
                        @endguest

                        @if($institution->description)
                            <div class="prose max-w-none text-slate-700 prose-headings:text-slate-950">
                                {!! $institution->description !!}
                            </div>
                        @endif
                    </div>
                </section>

                <section class="scroll-reveal reveal-up revealed space-y-6">
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <h2 class="font-heading text-2xl font-bold text-slate-950">{{ __('Majlis') }}</h2>
                            <p class="mt-1 text-sm text-slate-500">{{ __('Senarai majlis akan datang dan yang telah berlangsung.') }}</p>
                        </div>

                        @if($upcomingEvents->isNotEmpty())
                            <span class="inline-flex items-center rounded-xl border border-emerald-300 bg-emerald-100 text-emerald-900 shadow-emerald-200/80 hover:bg-emerald-200 px-3 py-2 text-sm font-semibold shadow-sm">
                                {{ __('Tarikh Aktif') }}
                            </span>
                        @endif
                    </div>

                    <x-public.moderation-status-note
                        :show-pending="$showPendingEventStatusNotice"
                        :show-cancelled="$showCancelledEventStatusNotice"
                    />

                    <div class="space-y-4">
                        @foreach($upcomingEvents as $event)
                            @php
                                $venueLocation = $resolveVenueLocation($event);
                                $eventPeople = $resolveEventPeople($event);
                                $speakerAvatarStack = $resolveEventSpeakerAvatarStack($event);
                                $eventTypeLabel = $resolveEventCategoryLabel($event);
                                $bookReferenceTitle = $event->reference_study_subtitle;
                                $eventFormatValue = $event->delivery_mode?->value ?? $event->delivery_mode;
                                $isRemoteEvent = in_array($eventFormatValue, ['online', 'hybrid'], true);
                                $isPendingEvent = (string) $event->status === 'pending';
                                $isCancelledEvent = (string) $event->status === 'cancelled';
                            @endphp

                            <a
                                href="{{ route('events.show', $event) }}"
                                wire:key="upcoming-{{ $event->id }}"
                                wire:navigate
                                class="group relative flex overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition hover:-translate-y-0.5 hover:border-emerald-200 hover:shadow-md"
                            >
                                <div class="flex w-[4.5rem] shrink-0 flex-col items-center justify-center bg-gradient-to-b {{ $isCancelledEvent ? 'from-rose-600 to-rose-800' : ($isPendingEvent ? 'from-amber-600 to-amber-800' : ($isRemoteEvent ? 'from-sky-600 to-sky-800' : 'from-emerald-600 to-emerald-800')) }} p-3 text-white sm:w-24">
                                    <span class="text-[10px] font-bold uppercase tracking-widest text-white/80">{{ \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($event->starts_at, 'l') }}</span>
                                    <span class="font-heading text-3xl font-black leading-none sm:text-4xl">{{ \App\Support\Timezone\UserDateTimeFormatter::format($event->starts_at, 'd') }}</span>
                                    <span class="mt-1 text-[11px] font-bold tracking-wide text-white/80">{{ \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($event->starts_at, 'F') }}</span>
                                </div>

                                <div class="flex flex-1 flex-col gap-3 p-4 sm:p-5">
                                    <div class="flex items-start justify-between gap-4">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="inline-flex items-center rounded-full bg-emerald-100 px-2.5 py-0.5 text-[11px] font-semibold text-emerald-900">
                                                {{ $eventTypeLabel }}
                                            </span>

                                            @if($isPendingEvent)
                                                <span class="inline-flex items-center rounded-full bg-amber-100 px-2.5 py-0.5 text-[11px] font-semibold text-amber-800">
                                                    {{ __('Menunggu Kelulusan') }}
                                                </span>
                                            @endif

                                            @if($isCancelledEvent)
                                                <span class="inline-flex items-center rounded-full bg-rose-100 px-2.5 py-0.5 text-[11px] font-semibold text-rose-800">
                                                    {{ __('Dibatalkan') }}
                                                </span>
                                            @endif
                                        </div>

                                        @if($speakerAvatarStack['items']->isNotEmpty())
                                            <div class="flex -space-x-3" aria-label="{{ __('Penceramah') }}">
                                                @foreach($speakerAvatarStack['items'] as $avatar)
                                                    <img
                                                        src="{{ $avatar['url'] }}"
                                                        alt="{{ $avatar['name'] }}"
                                                        title="{{ $avatar['name'] }}"
                                                        class="h-9 w-9 rounded-full object-cover ring-2 ring-white shadow-sm sm:h-11 sm:w-11"
                                                    >
                                                @endforeach

                                                @if($speakerAvatarStack['overflow'] > 0)
                                                    <span class="flex h-9 w-9 items-center justify-center rounded-full bg-slate-100 text-[10px] font-semibold text-slate-600 ring-2 ring-white shadow-sm sm:h-11 sm:w-11 sm:text-[11px]">
                                                        +{{ $speakerAvatarStack['overflow'] }}
                                                    </span>
                                                @endif
                                            </div>
                                        @endif
                                    </div>

                                    <div class="space-y-2">
                                        <h3 class="font-heading text-lg font-bold text-slate-950 transition group-hover:text-emerald-700">
                                            {{ $event->title }}
                                        </h3>

                                        @if($bookReferenceTitle)
                                            <p class="pl-3 text-sm font-bold italic text-slate-500 sm:pl-4">
                                                {{ $bookReferenceTitle }}
                                            </p>
                                        @endif
                                    </div>

                                    <div class="space-y-1.5 text-sm text-slate-500">
                                        <div class="flex items-center gap-2">
                                            <svg class="h-4 w-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                            <span>{{ $event->timing_display !== '' ? $event->timing_display : \App\Support\Timezone\UserDateTimeFormatter::format($event->starts_at, 'h:i A') }}</span>

                                            @if($event->ends_at)
                                                <span class="text-slate-300">-</span>
                                                <span>{{ \App\Support\Timezone\UserDateTimeFormatter::format($event->ends_at, 'h:i A') }}</span>
                                            @endif
                                        </div>

                                        @if($eventPeople['speakers'] !== '')
                                            <div class="flex items-start gap-2">
                                                <svg class="mt-0.5 h-4 w-4 shrink-0 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.742-.479 3 3 0 00-4.682-2.72m.94 3.198v.75c0 .414-.336.75-.75.75H4.75a.75.75 0 01-.75-.75v-.75a4.5 4.5 0 014.5-4.5h4.5a4.5 4.5 0 014.5 4.5z" />
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 7.5a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z" />
                                                </svg>
                                                <span class="line-clamp-2">{{ $eventPeople['speakers'] }}</span>
                                            </div>
                                        @endif

                                        @if($eventPeople['roles'] !== '')
                                            <div class="flex items-start gap-2">
                                                <svg class="mt-0.5 h-4 w-4 shrink-0 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 6.75h12M8.25 12h12m-12 5.25h12M3.75 6.75h.008v.008H3.75V6.75zm0 5.25h.008v.008H3.75V12zm0 5.25h.008v.008H3.75v-.008z" />
                                                </svg>
                                                <span class="line-clamp-2">{{ $eventPeople['roles'] }}</span>
                                            </div>
                                        @endif

                                        @if($venueLocation !== '' && ! $isRemoteEvent)
                                            <div class="flex items-center gap-2">
                                                <svg class="h-4 w-4 shrink-0 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" />
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" />
                                                </svg>
                                                <span class="line-clamp-1">{{ $venueLocation }}</span>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            </a>
                        @endforeach

                        @if($upcomingEvents->isEmpty())
                            <div class="rounded-2xl border border-dashed border-slate-200 bg-white p-10 text-center text-sm text-slate-500">
                                {{ __('Tiada majlis dijadualkan buat masa ini.') }}
                            </div>
                        @endif

                        @if($upcomingTotal > $upcomingEvents->count())
                            <div class="text-center">
                                <button
                                    type="button"
                                    wire:click="loadMoreUpcoming"
                                    wire:loading.attr="disabled"
                                    class="inline-flex items-center rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:border-emerald-300 hover:text-emerald-700"
                                >
                                    {{ __('Lihat Lagi') }}
                                </button>
                            </div>
                        @endif
                    </div>

                    @if($pastEvents->isNotEmpty())
                        <div class="space-y-4 pt-4">
                            <div class="flex items-center justify-between">
                                <h3 class="text-lg font-semibold text-slate-900">{{ __('Lepas') }}</h3>

                                @if($pastTotal > $pastEvents->count())
                                    <button
                                        type="button"
                                        wire:click="loadMorePast"
                                        wire:loading.attr="disabled"
                                        class="inline-flex items-center rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:border-emerald-300 hover:text-emerald-700"
                                    >
                                        {{ __('Lihat Lagi') }}
                                    </button>
                                @endif
                            </div>

                            @foreach($pastEvents as $event)
                                @php
                                    $venueLocation = $resolveVenueLocation($event);
                                    $eventPeople = $resolveEventPeople($event);
                                    $bookReferenceTitle = $event->reference_study_subtitle;
                                $eventFormatValue = $event->delivery_mode?->value ?? $event->delivery_mode;
                                    $isRemoteEvent = in_array($eventFormatValue, ['online', 'hybrid'], true);
                                @endphp

                                <a
                                    href="{{ route('events.show', $event) }}"
                                    wire:key="past-{{ $event->id }}"
                                    wire:navigate
                                    class="group relative flex overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition hover:-translate-y-0.5 hover:border-emerald-200 hover:shadow-md"
                                >
                                    <div class="flex w-[4.5rem] shrink-0 flex-col items-center justify-center bg-gradient-to-b from-slate-700 to-slate-900 p-3 text-white sm:w-24">
                                        <span class="text-[10px] font-bold uppercase tracking-widest text-white/80">{{ \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($event->starts_at, 'l') }}</span>
                                        <span class="font-heading text-3xl font-black leading-none sm:text-4xl">{{ \App\Support\Timezone\UserDateTimeFormatter::format($event->starts_at, 'd') }}</span>
                                        <span class="mt-1 text-[11px] font-bold tracking-wide text-white/80">{{ \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($event->starts_at, 'F') }}</span>
                                    </div>

                                    <div class="flex flex-1 flex-col gap-3 p-4 sm:p-5">
                                        <h3 class="font-heading text-lg font-bold text-slate-950 transition group-hover:text-emerald-700">
                                            {{ $event->title }}
                                        </h3>

                                        @if($bookReferenceTitle)
                                            <p class="pl-3 text-sm font-bold italic text-slate-500 sm:pl-4">
                                                {{ $bookReferenceTitle }}
                                            </p>
                                        @endif

                                        <div class="space-y-1.5 text-sm text-slate-500">
                                            <div class="flex items-center gap-2">
                                                <svg class="h-4 w-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                </svg>
                                                <span>{{ $event->timing_display !== '' ? $event->timing_display : \App\Support\Timezone\UserDateTimeFormatter::format($event->starts_at, 'h:i A') }}</span>

                                                @if($event->ends_at)
                                                    <span class="text-slate-300">-</span>
                                                    <span>{{ \App\Support\Timezone\UserDateTimeFormatter::format($event->ends_at, 'h:i A') }}</span>
                                                @endif
                                            </div>

                                            @if($eventPeople['speakers'] !== '')
                                                <div class="flex items-start gap-2">
                                                    <svg class="mt-0.5 h-4 w-4 shrink-0 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.742-.479 3 3 0 00-4.682-2.72m.94 3.198v.75c0 .414-.336.75-.75.75H4.75a.75.75 0 01-.75-.75v-.75a4.5 4.5 0 014.5-4.5h4.5a4.5 4.5 0 014.5 4.5z" />
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 7.5a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z" />
                                                    </svg>
                                                    <span class="line-clamp-2">{{ $eventPeople['speakers'] }}</span>
                                                </div>
                                            @endif

                                            @if($venueLocation !== '' && ! $isRemoteEvent)
                                                <div class="flex items-center gap-2">
                                                    <svg class="h-4 w-4 shrink-0 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" />
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" />
                                                    </svg>
                                                    <span class="line-clamp-1">{{ $venueLocation }}</span>
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    @endif
                </section>
            </div>

            <aside class="space-y-6">
                @if($addressLines['street'] || $addressLines['locality'] || $addressLines['regional'])
                    <section class="scroll-reveal reveal-right revealed rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                        <h2 class="text-sm font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Alamat') }}</h2>
                        <div class="mt-4 space-y-1 text-sm text-slate-700">
                            @if($addressLines['street'])
                                <p>{{ $addressLines['street'] }}</p>
                            @endif
                            @if($addressLines['locality'])
                                <p>{{ $addressLines['locality'] }}</p>
                            @endif
                            @if($addressLines['regional'])
                                <p>{{ $addressLines['regional'] }}</p>
                            @endif
                        </div>
                    </section>
                @endif

                @if($googleMapsEmbedUrl)
                    <section class="scroll-reveal reveal-right revealed rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                        <h2 class="text-sm font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Peta') }}</h2>

                        <div class="mt-4 overflow-hidden rounded-2xl border border-slate-200">
                            <iframe
                                src="{{ $googleMapsEmbedUrl }}"
                                class="h-64 w-full"
                                loading="lazy"
                                referrerpolicy="no-referrer-when-downgrade"
                            ></iframe>
                        </div>

                        <div class="mt-4 flex flex-wrap gap-3 text-sm font-semibold">
                            @if($wazeUrl)
                                <a href="{{ $wazeUrl }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center rounded-full border border-slate-200 px-4 py-2 text-slate-700 transition hover:border-emerald-300 hover:text-emerald-700">
                                    {{ __('Waze') }}
                                </a>
                            @endif

                            @if($googleMapsBrowseUrl)
                                <a href="{{ $googleMapsBrowseUrl }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center rounded-full border border-slate-200 px-4 py-2 text-slate-700 transition hover:border-emerald-300 hover:text-emerald-700">
                                    {{ __('Google Maps') }}
                                </a>
                            @endif
                        </div>
                    </section>
                @endif

                @if($publicContacts->isNotEmpty())
                    <section class="scroll-reveal reveal-right revealed rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                        <h2 class="text-sm font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Hubungi') }}</h2>
                        <ul class="mt-4 space-y-3 text-sm text-slate-700">
                            @foreach($publicContacts as $contact)
                                @php($contactHref = $formatContactHref($contact))
                                <li>
                                    @if($contactHref)
                                        <a href="{{ $contactHref }}" class="font-medium text-slate-800 transition hover:text-emerald-700">
                                            {{ $contact->value }}
                                        </a>
                                    @else
                                        <span>{{ $contact->value }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                @if($speakers->isNotEmpty())
                    <section class="scroll-reveal reveal-right revealed rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                        <h2 class="text-sm font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Penceramah') }}</h2>
                        <ul class="mt-4 space-y-4">
                            @foreach($speakers as $speaker)
                                <li class="flex items-center gap-3">
                                    <img
                                        src="{{ $speaker->public_avatar_url }}"
                                        alt="{{ $speaker->formatted_name !== '' ? $speaker->formatted_name : $speaker->name }}"
                                        class="h-12 w-12 rounded-full object-cover"
                                        loading="lazy"
                                    >
                                    <div class="min-w-0">
                                        <p class="font-semibold text-slate-900">{{ $speaker->name }}</p>
                                        @if(filled($speaker->pivot?->position))
                                            <p class="text-sm text-slate-500">{{ $speaker->pivot->position }}</p>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                @if($spaces->isNotEmpty())
                    <section class="scroll-reveal reveal-right revealed rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                        <h2 class="text-sm font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Ruang') }}</h2>
                        <ul class="mt-4 space-y-3 text-sm text-slate-700">
                            @foreach($spaces as $space)
                                <li class="flex items-center justify-between gap-4">
                                    <span class="font-medium text-slate-900">{{ $space->name }}</span>
                                    @if($space->capacity)
                                        <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">{{ $space->capacity }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                @if($donationChannels->isNotEmpty())
                    <section class="scroll-reveal reveal-right revealed rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                        <h2 class="text-sm font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Sumbangan') }}</h2>
                        <div class="mt-4 space-y-4">
                            @foreach($donationChannels as $channel)
                                <div class="flex items-start gap-4 rounded-2xl border border-slate-200 p-4">
                                    @if($channel->hasMedia('qr'))
                                        <button type="button" class="group relative shrink-0 transition-transform active:scale-95">
                                            <img
                                                src="{{ $channel->getFirstMedia('qr')?->getAvailableUrl(['thumb']) ?: $channel->getFirstMediaUrl('qr') }}"
                                                alt="{{ $channel->label ?: $channel->recipient }}"
                                                class="h-20 w-20 rounded-2xl object-cover"
                                                loading="lazy"
                                            >
                                        </button>
                                    @endif

                                    <div class="min-w-0 space-y-1">
                                        <p class="font-semibold text-slate-900">{{ $channel->recipient }}</p>

                                        @if(filled($channel->label))
                                            <p class="text-sm text-slate-500">{{ $channel->label }}</p>
                                        @endif

                                        @if(filled($channel->account_number))
                                            <p class="text-sm text-slate-600">{{ $channel->account_number }}</p>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endif

                <section class="scroll-reveal reveal-right revealed rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="flex flex-col gap-4">
                        <div>
                            <p class="text-[11px] font-bold uppercase tracking-[0.22em] text-slate-400">{{ __('Bantu Semak Institusi') }}</p>
                            <p class="mt-2 text-sm leading-6 text-slate-600">
                                {{ __('Jumpa maklumat yang perlu diperbetulkan atau institusi yang meragukan?') }}
                            </p>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <a
                                href="{{ route('contributions.suggest-update', ['subjectType' => $institutionRouteSegment, 'subjectId' => $institution->slug]) }}"
                                wire:navigate
                                class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-600 transition hover:border-sky-200 hover:bg-sky-50 hover:text-sky-700"
                            >
                                {{ __('Cadang Kemaskini') }}
                            </a>
                            <a
                                href="{{ route('reports.create', ['subjectType' => $institutionRouteSegment, 'subjectId' => $institution->slug]) }}"
                                wire:navigate
                                class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-600 transition hover:border-rose-200 hover:bg-rose-50 hover:text-rose-700"
                            >
                                {{ __('Lapor') }}
                            </a>
                        </div>
                    </div>
                </section>

                <section id="institution-share-panel" class="scroll-reveal reveal-right revealed">
                    <x-dawah-share-panel
                        :preview-title="$institution->name"
                        :preview-subtitle="$locationString !== '' ? $locationString : null"
                        :share-data="$shareData"
                        :share-links="$shareLinks"
                    />
                </section>

                <x-sidebar-inspiration />
            </aside>
        </div>
    </div>
</div>
