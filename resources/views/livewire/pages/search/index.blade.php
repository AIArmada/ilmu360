@section('title', __('Search Majlis, Speakers, References & Institutions') . ' - ' . config('app.name'))
@section('meta_description', __('Search across upcoming majlis, trusted speakers, references, and institutions in one place.'))
@section('og_url', route('search.index', request()->query()))

@php
    $eventResults = $this->eventResults;
    $speakerResults = $this->speakerResults;
    $referenceResults = $this->referenceResults;
    $institutionResults = $this->institutionResults;

    /** @var \Illuminate\Support\Collection<int, \App\Models\Event> $eventMatches */
    $eventMatches = $eventResults['items'];
    /** @var \Illuminate\Support\Collection<int, \App\Models\Speaker> $speakerMatches */
    $speakerMatches = $speakerResults['items'];
    /** @var \Illuminate\Support\Collection<int, \App\Models\Reference> $referenceMatches */
    $referenceMatches = $referenceResults['items'];
    /** @var \Illuminate\Support\Collection<int, \App\Models\Institution> $institutionMatches */
    $institutionMatches = $institutionResults['items'];

    $eventTotal = $eventResults['total'];
    $speakerTotal = $speakerResults['total'];
    $referenceTotal = $referenceResults['total'];
    $institutionTotal = $institutionResults['total'];

    $hasSearchContext = $this->hasSearchContext;
    $hasTypedSearch = $this->hasTypedSearch;
    $search = $this->search;
    $lat = $this->lat;
    $lng = $this->lng;
    $radiusKm = $this->radius_km;
    $hasAnyResults = $eventTotal > 0 || $speakerTotal > 0 || $referenceTotal > 0 || $institutionTotal > 0;

    $eventQueryParams = array_filter([
        'search' => $search,
        'lat' => $lat,
        'lng' => $lng,
        'radius_km' => ($lat !== null && $lng !== null) ? $radiusKm : null,
    ], static fn (mixed $value): bool => filled($value));

    $speakerQueryParams = array_filter([
        'search' => $search,
    ], static fn (mixed $value): bool => filled($value));

    $institutionQueryParams = array_filter([
        'search' => $search,
    ], static fn (mixed $value): bool => filled($value));

    $referenceQueryParams = array_filter([
        'search' => $search,
    ], static fn (mixed $value): bool => filled($value));

    $formatLocation = static function ($addressModel): string {
        $parts = \App\Support\Location\AddressHierarchyFormatter::parts($addressModel);

        return $parts === [] ? __('Online') : implode(', ', $parts);
    };
@endphp

<div class="relative min-h-screen bg-[radial-gradient(circle_at_top,rgba(16,185,129,0.12),transparent_38%),linear-gradient(180deg,#f8fafc_0%,#ffffff_38%,#f8fafc_100%)] pb-24 pt-24">
    <div class="absolute inset-x-0 top-0 h-[34rem] opacity-[0.04]" style="background-image: url('{{ asset('images/pattern-bg.png') }}');"></div>

    <div class="container relative mx-auto px-6 lg:px-12">
        <section class="overflow-hidden rounded-[2rem] border border-emerald-100/80 bg-white/90 shadow-[0_35px_90px_-55px_rgba(15,118,110,0.55)] backdrop-blur">
            <div class="grid gap-10 px-6 py-8 md:px-10 lg:grid-cols-[1.2fr_0.8fr] lg:px-12 lg:py-12">
                <div>
                    <span class="inline-flex items-center rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-[11px] font-black uppercase tracking-[0.24em] text-emerald-700">
                        {{ __('Search everything in one place') }}
                    </span>

                    <h1 class="mt-5 font-heading text-4xl font-extrabold tracking-tight text-slate-900 md:text-5xl">
                        {{ __('Search Majlis, Speakers, References & Institutions') }}
                    </h1>

                    <p class="mt-4 max-w-2xl text-base leading-7 text-slate-600 md:text-lg">
                        {{ __('Search across upcoming majlis, trusted speakers, references, and institutions in one place.') }}
                    </p>

                    <form action="{{ route('search.index') }}" method="GET" class="mt-8">
                        <div class="relative group">
                            <label for="unified-search" class="sr-only">{{ __('Search Majlis, Speakers, References & Institutions') }}</label>
                            <input
                                id="unified-search"
                                type="text"
                                name="search"
                                wire:model.live.debounce.300ms="search"
                                placeholder="{{ __('Search majlis, speakers, references, or institutions...') }}"
                                class="h-16 w-full rounded-[1.75rem] border-2 border-slate-200 bg-white pl-14 pr-14 text-base font-medium text-slate-900 shadow-lg shadow-slate-200/70 transition focus:border-emerald-500 focus:outline-none focus:ring-4 focus:ring-emerald-500/10 placeholder:text-slate-400"
                            >
                            <svg class="pointer-events-none absolute left-5 top-1/2 h-6 w-6 -translate-y-1/2 text-slate-400 transition group-focus-within:text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>

                            @if(filled($search))
                                <button
                                    type="button"
                                    wire:click="clearSearch"
                                    class="absolute right-4 top-1/2 inline-flex -translate-y-1/2 items-center rounded-full border border-rose-200 bg-rose-50 px-3 py-1 text-xs font-bold text-rose-600 transition hover:bg-rose-100"
                                >
                                    {{ __('Clear Search') }}
                                </button>
                            @endif

                            <input type="hidden" name="lat" value="{{ $lat }}">
                            <input type="hidden" name="lng" value="{{ $lng }}">
                            <input type="hidden" name="radius_km" value="{{ $radiusKm }}">
                        </div>
                    </form>

                    @if($hasSearchContext)
                        <div class="mt-5 flex flex-wrap items-center gap-2">
                            @if($hasTypedSearch)
                                <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">
                                    {{ __('Showing grouped matches for your query.') }}
                                </span>
                            @endif

                            @if($lat && $lng)
                                <span class="inline-flex items-center gap-1.5 rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700">
                                    <svg class="h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                                    </svg>
                                    {{ __('Nearby events are prioritized when location is included.') }}
                                </span>
                            @endif
                        </div>
                    @endif
                </div>

                <div class="rounded-[1.75rem] border border-slate-200 bg-slate-50/90 p-6">
                    @if($hasSearchContext)
                        @if($hasTypedSearch)
                            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-1">
                                <div class="rounded-2xl border border-emerald-100 bg-white p-4 shadow-sm">
                                    <p class="text-xs font-black uppercase tracking-[0.2em] text-emerald-600">{{ __('Majlis') }}</p>
                                    <p class="mt-3 text-3xl font-heading font-bold text-slate-900">{{ $eventTotal }}</p>
                                    <p class="mt-1 text-sm text-slate-500">{{ __('Top matching events') }}</p>
                                </div>

                                <div class="rounded-2xl border border-sky-100 bg-white p-4 shadow-sm">
                                    <p class="text-xs font-black uppercase tracking-[0.2em] text-sky-600">{{ __('Speakers') }}</p>
                                    <p class="mt-3 text-3xl font-heading font-bold text-slate-900">{{ $speakerTotal }}</p>
                                    <p class="mt-1 text-sm text-slate-500">{{ __('Matching speakers') }}</p>
                                </div>

                                <div class="rounded-2xl border border-violet-100 bg-white p-4 shadow-sm">
                                    <p class="text-xs font-black uppercase tracking-[0.2em] text-violet-600">{{ __('References') }}</p>
                                    <p class="mt-3 text-3xl font-heading font-bold text-slate-900">{{ $referenceTotal }}</p>
                                    <p class="mt-1 text-sm text-slate-500">{{ __('Matching references') }}</p>
                                </div>

                                <div class="rounded-2xl border border-amber-100 bg-white p-4 shadow-sm">
                                    <p class="text-xs font-black uppercase tracking-[0.2em] text-amber-600">{{ __('Institutions') }}</p>
                                    <p class="mt-3 text-3xl font-heading font-bold text-slate-900">{{ $institutionTotal }}</p>
                                    <p class="mt-1 text-sm text-slate-500">{{ __('Matching institutions') }}</p>
                                </div>
                            </div>
                        @else
                            <div class="rounded-2xl border border-emerald-100 bg-white p-5 shadow-sm">
                                <p class="text-xs font-black uppercase tracking-[0.2em] text-emerald-600">{{ __('Nearby events') }}</p>
                                <p class="mt-3 text-3xl font-heading font-bold text-slate-900">{{ $eventTotal }}</p>
                                <p class="mt-1 text-sm leading-6 text-slate-500">{{ __('Location is currently shaping event results.') }}</p>
                            </div>
                        @endif

                        <div class="mt-5 flex flex-wrap gap-3">
                            <a href="{{ route('events.index', $eventQueryParams) }}" wire:navigate class="inline-flex items-center justify-center rounded-2xl bg-slate-900 px-4 py-3 text-sm font-semibold text-white transition hover:bg-slate-800">
                                {{ __('View all events') }}
                            </a>

                            @if($hasTypedSearch)
                                <a href="{{ route('speakers.index', $speakerQueryParams) }}" wire:navigate class="inline-flex items-center justify-center rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 transition hover:border-sky-300 hover:text-sky-700">
                                    {{ __('View all speakers') }}
                                </a>

                                <a href="{{ route('references.index', $referenceQueryParams) }}" wire:navigate class="inline-flex items-center justify-center rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 transition hover:border-violet-300 hover:text-violet-700">
                                    {{ __('View all references') }}
                                </a>

                                <a href="{{ route('institutions.index', $institutionQueryParams) }}" wire:navigate class="inline-flex items-center justify-center rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 transition hover:border-amber-300 hover:text-amber-700">
                                    {{ __('View all institutions') }}
                                </a>
                            @endif

                            @if($lat && $lng)
                                <button type="button" wire:click="clearLocation" class="inline-flex items-center justify-center rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-600 transition hover:bg-rose-100">
                                    {{ __('Clear Location') }}
                                </button>
                            @endif
                        </div>
                    @else
                        <div class="rounded-[1.5rem] border border-dashed border-slate-200 bg-white p-6 text-left">
                            <p class="text-xs font-black uppercase tracking-[0.2em] text-slate-500">{{ __('Start with a search') }}</p>
                            <h2 class="mt-3 font-heading text-2xl font-bold text-slate-900">{{ __('Enter a name, topic, speaker, reference, or institution to start searching.') }}</h2>
                            <p class="mt-3 text-sm leading-6 text-slate-600">{{ __('Use the search box to jump into relevant majlis, speaker profiles, references, and institution pages without guessing which directory to open first.') }}</p>
                        </div>
                    @endif
                </div>
            </div>
        </section>

        @if(! $hasSearchContext)
            <section class="mt-10 grid gap-6 lg:grid-cols-3">
                <a href="{{ route('events.index') }}" wire:navigate class="group rounded-[1.75rem] border border-emerald-100 bg-white p-6 shadow-sm transition hover:-translate-y-1 hover:shadow-xl hover:shadow-emerald-900/10">
                    <span class="inline-flex rounded-full bg-emerald-50 px-3 py-1 text-[11px] font-black uppercase tracking-[0.2em] text-emerald-700">{{ __('Majlis') }}</span>
                    <h2 class="mt-4 font-heading text-2xl font-bold text-slate-900">{{ __('Browse upcoming events') }}</h2>
                    <p class="mt-3 text-sm leading-6 text-slate-600">{{ __('Open the full majlis listing if you want filters, saved searches, and nearby discovery.') }}</p>
                </a>

                <a href="{{ route('speakers.index') }}" wire:navigate class="group rounded-[1.75rem] border border-sky-100 bg-white p-6 shadow-sm transition hover:-translate-y-1 hover:shadow-xl hover:shadow-sky-900/10">
                    <span class="inline-flex rounded-full bg-sky-50 px-3 py-1 text-[11px] font-black uppercase tracking-[0.2em] text-sky-700">{{ __('Speakers') }}</span>
                    <h2 class="mt-4 font-heading text-2xl font-bold text-slate-900">{{ __('Explore speaker profiles') }}</h2>
                    <p class="mt-3 text-sm leading-6 text-slate-600">{{ __('Search well-known asatizah and discover who is actively teaching in upcoming majlis.') }}</p>
                </a>

                <a href="{{ route('references.index') }}" wire:navigate class="group rounded-[1.75rem] border border-violet-100 bg-white p-6 shadow-sm transition hover:-translate-y-1 hover:shadow-xl hover:shadow-violet-900/10">
                    <span class="inline-flex rounded-full bg-violet-50 px-3 py-1 text-[11px] font-black uppercase tracking-[0.2em] text-violet-700">{{ __('References') }}</span>
                    <h2 class="mt-4 font-heading text-2xl font-bold text-slate-900">{{ __('Browse books and source material') }}</h2>
                    <p class="mt-3 text-sm leading-6 text-slate-600">{{ __('Open the references directory to find books, articles, and source materials behind upcoming majlis.') }}</p>
                </a>

                <a href="{{ route('institutions.index') }}" wire:navigate class="group rounded-[1.75rem] border border-amber-100 bg-white p-6 shadow-sm transition hover:-translate-y-1 hover:shadow-xl hover:shadow-amber-900/10">
                    <span class="inline-flex rounded-full bg-amber-50 px-3 py-1 text-[11px] font-black uppercase tracking-[0.2em] text-amber-700">{{ __('Institutions') }}</span>
                    <h2 class="mt-4 font-heading text-2xl font-bold text-slate-900">{{ __('Find mosques and learning centres') }}</h2>
                    <p class="mt-3 text-sm leading-6 text-slate-600">{{ __('Go straight into the institution directory when you want to search by masjid, surau, or organiser.') }}</p>
                </a>
            </section>
        @elseif(! $hasAnyResults)
            <section class="mt-10 rounded-[2rem] border border-dashed border-slate-200 bg-white px-6 py-16 text-center shadow-sm">
                <div class="mx-auto flex h-20 w-20 items-center justify-center rounded-full bg-slate-100 text-slate-300">
                    <svg class="h-10 w-10" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                </div>

                <h2 class="mt-6 font-heading text-3xl font-bold text-slate-900">{{ __('Search results') }}</h2>
                <p class="mx-auto mt-3 max-w-2xl text-sm leading-6 text-slate-600 md:text-base">
                    {{ __('Try a different name, title, or keyword.') }}
                </p>

                <div class="mt-8 flex flex-wrap items-center justify-center gap-3">
                    <a href="{{ route('events.index') }}" wire:navigate class="rounded-2xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-slate-800">
                        {{ __('View all events') }}
                    </a>
                    <a href="{{ route('speakers.index') }}" wire:navigate class="rounded-2xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-700 transition hover:border-sky-300 hover:text-sky-700">
                        {{ __('View all speakers') }}
                    </a>
                    <a href="{{ route('references.index') }}" wire:navigate class="rounded-2xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-700 transition hover:border-violet-300 hover:text-violet-700">
                        {{ __('View all references') }}
                    </a>
                    <a href="{{ route('institutions.index') }}" wire:navigate class="rounded-2xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-700 transition hover:border-amber-300 hover:text-amber-700">
                        {{ __('View all institutions') }}
                    </a>
                </div>
            </section>
        @else
            <div class="mt-10 space-y-10">
                <section class="rounded-[2rem] border border-slate-200 bg-white p-6 shadow-sm md:p-8">
                    <div class="flex flex-col gap-4 border-b border-slate-100 pb-6 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <span class="inline-flex rounded-full bg-emerald-50 px-3 py-1 text-[11px] font-black uppercase tracking-[0.2em] text-emerald-700">{{ $lat && $lng ? __('Nearby events') : __('Majlis') }}</span>
                            <h2 class="mt-3 font-heading text-3xl font-bold text-slate-900">{{ __('Top matching events') }}</h2>
                            <p class="mt-2 text-sm leading-6 text-slate-600">{{ $lat && $lng ? __('Location is currently shaping event results.') : __('The best upcoming majlis matches based on your current search.') }}</p>
                        </div>

                        <a href="{{ route('events.index', $eventQueryParams) }}" wire:navigate class="inline-flex items-center gap-2 text-sm font-semibold text-emerald-700 transition hover:text-emerald-800">
                            {{ __('View all events') }}
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                            </svg>
                        </a>
                    </div>

                    @if($eventMatches->isEmpty())
                        <div class="mt-8 rounded-[1.5rem] border border-dashed border-slate-200 bg-slate-50 px-6 py-10 text-center">
                            <p class="font-heading text-2xl font-bold text-slate-900">{{ __('No events found') }}</p>
                            <p class="mt-3 text-sm leading-6 text-slate-600">{{ __('Try a different name, title, or keyword.') }}</p>
                        </div>
                    @else
                        <div class="mt-8 grid gap-6 md:grid-cols-2 xl:grid-cols-3">
                            @foreach($eventMatches as $event)
                                @php
                                    $eventChangeBadgeLabel = $event->public_change_badge_label;
                                    $eventCoverAspectClass = 'aspect-[16/9]';
                                    $primaryLocationName = $event->venue?->name ?? $event->institution?->name;
                                    $addressModel = $event->venue?->addressModel ?? $event->institution?->addressModel;
                                    $locationText = is_string($primaryLocationName) && $primaryLocationName !== ''
                                        ? $primaryLocationName
                                        : $formatLocation($addressModel);
                                @endphp

                                <article class="group overflow-hidden rounded-[1.75rem] border border-slate-200 bg-white shadow-sm transition hover:-translate-y-1 hover:shadow-xl hover:shadow-emerald-900/10">
                                    <a href="{{ route('events.show', $event) }}" wire:navigate class="block">
                                        <div class="relative overflow-hidden bg-slate-100 {{ $eventCoverAspectClass }}"
                                            data-cover-aspect="16:9">
                                            <img src="{{ $event->card_image_url }}" alt="{{ $event->title }}"
                                                class="h-full w-full transition duration-700 group-hover:scale-105 object-cover"
                                                loading="lazy">
                                            <div class="absolute left-4 top-4 rounded-2xl bg-white/95 px-3 py-2 text-center shadow-sm">
                                                <p class="text-[11px] font-black uppercase tracking-[0.18em] text-slate-500">{{ \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($event->starts_at, 'M') }}</p>
                                                <p class="mt-1 font-heading text-2xl font-bold leading-none text-slate-900">{{ \App\Support\Timezone\UserDateTimeFormatter::format($event->starts_at, 'd') }}</p>
                                            </div>

                                            @if($eventChangeBadgeLabel)
                                                <div class="absolute bottom-4 left-4 rounded-full {{ $event->schedule_state === \App\Enums\ScheduleState::Postponed ? 'bg-amber-600/92' : ($event->status instanceof \App\States\EventStatus\Cancelled ? 'bg-rose-600/92' : 'bg-sky-600/92') }} px-3 py-1 text-xs font-bold text-white shadow-lg">
                                                    {{ $eventChangeBadgeLabel }}
                                                </div>
                                            @endif

                                            @if(isset($event->distance_km))
                                                <div class="absolute right-4 top-4 rounded-full bg-emerald-600/90 px-3 py-1 text-xs font-semibold text-white shadow-lg">
                                                    {{ number_format($event->distance_km, 1) }} km
                                                </div>
                                            @endif
                                        </div>
                                    </a>

                                    <div class="space-y-4 p-6">
                                        <div>
                                            <a href="{{ route('events.show', $event) }}" wire:navigate class="transition group-hover:text-emerald-700">
                                                <h3 class="font-heading text-xl font-bold leading-tight text-slate-900 line-clamp-2">{{ $event->title }}</h3>
                                            </a>
                                            @if($event->reference_study_subtitle)
                                                <p class="mt-1 pl-3 text-sm font-bold italic text-slate-500">
                                                    {{ $event->reference_study_subtitle }}
                                                </p>
                                            @endif
                                        </div>

                                        <div class="space-y-3 text-sm text-slate-600">
                                            <div class="flex items-start gap-2.5">
                                                <svg class="mt-0.5 h-4 w-4 shrink-0 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                                                </svg>
                                                <span class="line-clamp-2">{{ $locationText }}</span>
                                            </div>

                                            <div class="flex items-center gap-2.5">
                                                <svg class="h-4 w-4 shrink-0 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                </svg>
                                                <span>{{ $event->timing_display }}</span>
                                            </div>
                                        </div>
                                    </div>
                                </article>
                            @endforeach
                        </div>
                    @endif
                </section>

                @if($hasTypedSearch)
                    <div class="grid gap-10 xl:grid-cols-3">
                        <section class="rounded-[2rem] border border-slate-200 bg-white p-6 shadow-sm md:p-8">
                            <div class="flex items-center justify-between gap-4 border-b border-slate-100 pb-6">
                                <div>
                                    <span class="inline-flex rounded-full bg-sky-50 px-3 py-1 text-[11px] font-black uppercase tracking-[0.2em] text-sky-700">{{ __('Speakers') }}</span>
                                    <h2 class="mt-3 font-heading text-3xl font-bold text-slate-900">{{ __('Matching speakers') }}</h2>
                                </div>

                                <a href="{{ route('speakers.index', $speakerQueryParams) }}" wire:navigate class="inline-flex items-center gap-2 text-sm font-semibold text-sky-700 transition hover:text-sky-800">
                                    {{ __('View all speakers') }}
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                                    </svg>
                                </a>
                            </div>

                            @if($speakerMatches->isEmpty())
                                <div class="mt-8 rounded-[1.5rem] border border-dashed border-slate-200 bg-slate-50 px-6 py-10 text-center">
                                    <p class="font-heading text-2xl font-bold text-slate-900">{{ __('No matching speakers yet') }}</p>
                                    <p class="mt-3 text-sm leading-6 text-slate-600">{{ __('Try a different name, title, or keyword.') }}</p>
                                </div>
                            @else
                                <div class="mt-8 grid gap-5 sm:grid-cols-2">
                                    @foreach($speakerMatches as $speaker)
                                        <a href="{{ route('speakers.show', $speaker) }}" wire:navigate class="group rounded-[1.5rem] border border-slate-200 bg-slate-50/60 p-5 text-center transition hover:-translate-y-1 hover:border-sky-200 hover:bg-white hover:shadow-lg hover:shadow-sky-900/10">
                                            <div class="mx-auto h-24 w-24 overflow-hidden rounded-full bg-white p-1.5 ring-2 ring-slate-200 transition group-hover:ring-sky-300">
                                                <img src="{{ $speaker->public_avatar_url }}" alt="{{ $speaker->formatted_name }}" class="h-full w-full rounded-full object-cover" loading="lazy">
                                            </div>
                                            <h3 class="mt-5 font-heading text-lg font-bold leading-tight text-slate-900 transition group-hover:text-sky-700">{{ $speaker->formatted_name }}</h3>
                                            <div class="mt-4 inline-flex items-center gap-1.5 rounded-full bg-sky-50 px-3 py-1 text-xs font-semibold text-sky-700 ring-1 ring-sky-200">
                                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                                </svg>
                                                {{ $speaker->events_count }} {{ __('Events') }}
                                            </div>
                                        </a>
                                    @endforeach
                                </div>
                            @endif
                        </section>

                        <section class="rounded-[2rem] border border-slate-200 bg-white p-6 shadow-sm md:p-8">
                            <div class="flex items-center justify-between gap-4 border-b border-slate-100 pb-6">
                                <div>
                                    <span class="inline-flex rounded-full bg-violet-50 px-3 py-1 text-[11px] font-black uppercase tracking-[0.2em] text-violet-700">{{ __('References') }}</span>
                                    <h2 class="mt-3 font-heading text-3xl font-bold text-slate-900">{{ __('Matching references') }}</h2>
                                </div>

                                <a href="{{ route('references.index', $referenceQueryParams) }}" wire:navigate class="inline-flex items-center gap-2 text-sm font-semibold text-violet-700 transition hover:text-violet-800">
                                    {{ __('View all references') }}
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                                    </svg>
                                </a>
                            </div>

                            @if($referenceMatches->isEmpty())
                                <div class="mt-8 rounded-[1.5rem] border border-dashed border-slate-200 bg-slate-50 px-6 py-10 text-center">
                                    <p class="font-heading text-2xl font-bold text-slate-900">{{ __('No matching references yet') }}</p>
                                    <p class="mt-3 text-sm leading-6 text-slate-600">{{ __('Try a different name, title, or keyword.') }}</p>
                                </div>
                            @else
                                <div class="mt-8 grid gap-5 sm:grid-cols-2">
                                    @foreach($referenceMatches as $reference)
                                        @php
                                            $referenceCoverUrl = $reference->getFirstMediaUrl('front_cover', 'thumb') ?: $reference->getFirstMediaUrl('back_cover', 'thumb');
                                        @endphp

                                        <a href="{{ route('references.show', $reference) }}" wire:navigate class="group overflow-hidden rounded-[1.5rem] border border-slate-200 bg-slate-50/60 transition hover:-translate-y-1 hover:border-violet-200 hover:bg-white hover:shadow-lg hover:shadow-violet-900/10">
                                            <div class="relative flex aspect-4/5 items-center justify-center overflow-hidden bg-linear-to-br from-slate-50 to-violet-50">
                                                @if($referenceCoverUrl !== '')
                                                    <img src="{{ $referenceCoverUrl }}" alt="{{ $reference->title }}" class="h-full w-full object-cover transition duration-700 group-hover:scale-105" loading="lazy">
                                                @else
                                                    <svg class="h-14 w-14 text-violet-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                                                    </svg>
                                                @endif
                                            </div>

                                            <div class="space-y-4 p-5">
                                                <h3 class="font-heading text-lg font-bold leading-tight text-slate-900 transition group-hover:text-violet-700 line-clamp-2">{{ $reference->title }}</h3>

                                                <div class="inline-flex items-center gap-1.5 rounded-full bg-violet-50 px-3 py-1 text-xs font-semibold text-violet-700 ring-1 ring-violet-200">
                                                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                                    </svg>
                                                    {{ $reference->events_count }} {{ __('Events') }}
                                                </div>
                                            </div>
                                        </a>
                                    @endforeach
                                </div>
                            @endif
                        </section>

                        <section class="rounded-[2rem] border border-slate-200 bg-white p-6 shadow-sm md:p-8">
                            <div class="flex items-center justify-between gap-4 border-b border-slate-100 pb-6">
                                <div>
                                    <span class="inline-flex rounded-full bg-amber-50 px-3 py-1 text-[11px] font-black uppercase tracking-[0.2em] text-amber-700">{{ __('Institutions') }}</span>
                                    <h2 class="mt-3 font-heading text-3xl font-bold text-slate-900">{{ __('Matching institutions') }}</h2>
                                </div>

                                <a href="{{ route('institutions.index', $institutionQueryParams) }}" wire:navigate class="inline-flex items-center gap-2 text-sm font-semibold text-amber-700 transition hover:text-amber-800">
                                    {{ __('View all institutions') }}
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                                    </svg>
                                </a>
                            </div>

                            @if($institutionMatches->isEmpty())
                                <div class="mt-8 rounded-[1.5rem] border border-dashed border-slate-200 bg-slate-50 px-6 py-10 text-center">
                                    <p class="font-heading text-2xl font-bold text-slate-900">{{ __('No matching institutions yet') }}</p>
                                    <p class="mt-3 text-sm leading-6 text-slate-600">{{ __('Try a different name, title, or keyword.') }}</p>
                                </div>
                            @else
                                <div class="mt-8 grid gap-5 sm:grid-cols-2">
                                    @foreach($institutionMatches as $institution)
                                        @php
                                            $cardInstitutionImageUrl = $institution->public_image_url;
                                        @endphp

                                        <a href="{{ route('institutions.show', $institution) }}" wire:navigate class="group overflow-hidden rounded-[1.5rem] border border-slate-200 bg-slate-50/60 transition hover:-translate-y-1 hover:border-amber-200 hover:bg-white hover:shadow-lg hover:shadow-amber-900/10">
                                            <div class="aspect-video overflow-hidden bg-slate-100">
                                                @if($cardInstitutionImageUrl !== '')
                                                    <img src="{{ $cardInstitutionImageUrl }}" alt="{{ $institution->display_name }}" class="h-full w-full object-cover transition duration-700 group-hover:scale-105" loading="lazy">
                                                @else
                                                    <div class="flex h-full w-full items-center justify-center bg-gradient-to-br from-amber-50 to-emerald-50 text-amber-300">
                                                        <svg class="h-14 w-14" fill="currentColor" viewBox="0 0 24 24">
                                                            <path d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                                                        </svg>
                                                    </div>
                                                @endif
                                            </div>

                                            <div class="space-y-4 p-5">
                                                <div>
                                                    <h3 class="font-heading text-lg font-bold leading-tight text-slate-900 transition group-hover:text-amber-700">{{ $institution->display_name }}</h3>
                                                </div>

                                                <div class="flex items-start gap-2.5 text-sm text-slate-600">
                                                    <svg class="mt-0.5 h-4 w-4 shrink-0 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                                                    </svg>
                                                    <span class="line-clamp-2">{{ $formatLocation($institution->addressModel) }}</span>
                                                </div>

                                                <div class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-700 ring-1 ring-amber-200">
                                                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                                    </svg>
                                                    {{ $institution->events_count }} {{ __('Events') }}
                                                </div>
                                            </div>
                                        </a>
                                    @endforeach
                                </div>
                            @endif
                        </section>
                    </div>
                @endif
            </div>
        @endif
    </div>
</div>
