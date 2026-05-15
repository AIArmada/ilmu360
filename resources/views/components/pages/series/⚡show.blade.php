<?php

use App\Models\Series;
use App\Support\Auth\IntendedRedirect;
use Illuminate\Support\Str;
use Livewire\Component;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;

new
    #[Layout('layouts.app')]
    #[Title('Siri')]
    class extends Component {
    public Series $series;

    public bool $isFollowing = false;

    public int $upcomingPerPage = 10;

    public int $pastPerPage = 10;

    public function mount(Series $series): void
    {
        if ($series->visibility !== 'public' && (!auth()->user()?->hasAnyRole(['super_admin', 'moderator']))) {
            abort(404);
        }

        $series->load(['media']);

        $this->series = $series;
        $this->isFollowing = Auth::user()?->isFollowing($series) ?? false;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\Event>
     */
    public function toggleFollow(): void
    {
        $user = Auth::user();

        if (! $user) {
            $this->redirect(IntendedRedirect::loginUrl(route('series.show', $this->series)), navigate: true);

            return;
        }

        if ($this->isFollowing) {
            $user->unfollow($this->series);
            $this->isFollowing = false;
        } else {
            $user->follow($this->series);
            $this->isFollowing = true;
            app(\App\Services\ShareTrackingService::class)->recordOutcome(
                type: \App\Enums\DawahShareOutcomeType::SeriesFollow,
                outcomeKey: 'series_follow:user:'.$user->id.':series:'.$this->series->id,
                subject: $this->series,
                actor: $user,
                request: request(),
                metadata: [
                    'series_id' => $this->series->id,
                ],
            );
        }
    }

    public function getUpcomingEventsProperty(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->series->events()
            ->active()
            ->where('starts_at', '>=', now())
            ->with([
                'references',
                'institution',
                'institution.address.state',
                'institution.address.district',
                'institution.address.subdistrict',
                'venue.address.state',
                'venue.address.district',
                'venue.address.subdistrict',
                'media',
            ])
            ->orderBy('starts_at', 'asc')
            ->take($this->upcomingPerPage)
            ->get();
    }

    public function getUpcomingTotalProperty(): int
    {
        return $this->series->events()
            ->active()
            ->where('starts_at', '>=', now())
            ->count();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\Event>
     */
    public function getPastEventsProperty(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->series->events()
            ->active()
            ->where('starts_at', '<', now())
            ->with([
                'references',
                'institution',
                'institution.address.state',
                'institution.address.district',
                'institution.address.subdistrict',
                'venue.address.state',
                'venue.address.district',
                'venue.address.subdistrict',
                'media',
            ])
            ->orderBy('starts_at', 'desc')
            ->take($this->pastPerPage)
            ->get();
    }

    public function getPastTotalProperty(): int
    {
        return $this->series->events()
            ->active()
            ->where('starts_at', '<', now())
            ->count();
    }

    public function loadMoreUpcoming(): void
    {
        $this->upcomingPerPage += 10;
    }

    public function loadMorePast(): void
    {
        $this->pastPerPage += 10;
    }

    public function rendering($view): void
    {
        $view->title($this->series->title . ' - ' . config('app.name'));
    }
};
?>

@section('title', $this->series->title . ' - ' . config('app.name'))
@section('meta_description', Str::limit(trim(strip_tags((string) $this->series->description)) ?: __('Lihat siri majlis ilmu ini, termasuk acara akan datang dan arkib program di :app.', ['app' => config('app.name')]), 160))
@section('meta_robots', ($this->series->is_active && $this->series->visibility === 'public') ? 'index, follow' : 'noindex, nofollow')
@section('og_url', route('series.show', $this->series))
@section('og_image', $this->series->getFirstMediaUrl('cover', 'thumb') ?: asset('images/default-mosque-hero.png'))
@section('og_image_alt', __('Siri :title', ['title' => $this->series->title]))

@php
    $series = $this->series;
    $upcomingEvents = $this->upcomingEvents;
    $pastEvents = $this->pastEvents;
    $upcomingTotal = $this->upcomingTotal;
    $pastTotal = $this->pastTotal;
    $seriesUrl = route('series.show', $series);
    $seriesShareText = trim($series->title . ' - ' . config('app.name'));
    $seriesShareData = [
        'title' => $series->title,
        'text' => Str::limit((string) $series->description, 140) ?: __('Lihat siri ini di :app', ['app' => config('app.name')]),
        'url' => $seriesUrl,
        'sourceUrl' => $seriesUrl,
        'shareText' => $seriesShareText,
        'fallbackTitle' => $series->title,
        'payloadEndpoint' => route('dawah-share.payload'),
    ];
    $seriesShareLinks = app(\App\Services\ShareTrackingService::class)->redirectLinks(
        $seriesUrl,
        $seriesShareText,
        $series->title,
    );

    $frontCover = $series->getFirstMediaUrl('cover', 'thumb');
    $backCover = null;

    // ── Helpers identical to speaker page ────────────────────────────────────
    $resolveEventTypeLabel = static function (mixed $eventType): string {
        if ($eventType instanceof \Illuminate\Support\Collection) {
            $eventType = $eventType->first();
        } elseif (is_array($eventType)) {
            $eventType = $eventType[0] ?? null;
        }
        if ($eventType instanceof \App\Enums\EventType) {
            return $eventType->getLabel();
        }
        if (is_string($eventType) && $eventType !== '') {
            return \App\Enums\EventType::tryFrom($eventType)?->getLabel() ?? __('Umum');
        }
        return __('Umum');
    };

    $resolveVenueLocation = static function (\App\Models\Event $event) use ($resolveEventTypeLabel): string {
        $venueName = $event->venue?->name;
        $institutionName = $event->institution?->name;
        $primaryLocationName = $venueName ?: $institutionName;
        $address = $event->venue?->addressModel ?? $event->institution?->addressModel;

        $parts = array_filter([
            $primaryLocationName,
            ...\App\Support\Location\AddressHierarchyFormatter::parts($address),
        ]);

        return $parts !== [] ? implode(', ', $parts) : (string) $primaryLocationName;
    };

    $resolveEventTimeDisplay = static function (\App\Models\Event $event): string {
        return $event->timing_display !== ''
            ? $event->timing_display
            : \App\Support\Timezone\UserDateTimeFormatter::format($event->starts_at, 'h:i A');
    };

    $resolveEventEndTimeDisplay = static function (\App\Models\Event $event): string {
        return \App\Support\Timezone\UserDateTimeFormatter::format($event->ends_at, 'h:i A');
    };
@endphp

<div class="min-h-screen bg-slate-50/80" x-data="{ shareModalOpen: false }">


    {{-- ═══ HERO ═══ --}}
    <div class="relative w-full overflow-hidden bg-slate-950 pt-20 lg:pt-0" @if($frontCover)
    x-data="{ posterModalOpen: false }" @endif>
        {{-- ── ATMOSPHERE LAYER ── --}}
        <div class="absolute inset-0">
            @if($frontCover)
                <img src="{{ $frontCover }}" alt="" class="size-full object-cover opacity-65" loading="eager"
                    aria-hidden="true">
            @else
                {{-- Enriched fallback gradient --}}
                <div class="absolute inset-0 bg-gradient-to-br from-indigo-950 via-slate-900 to-indigo-900"
                    aria-hidden="true"></div>

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
                <div class="absolute -left-40 -top-40 size-[600px] rounded-full bg-indigo-500/40 blur-[120px]"
                    aria-hidden="true"></div>
                <div class="absolute -bottom-20 right-20 size-[500px] rounded-full bg-emerald-400/30 blur-[100px]"
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

            <div class="absolute inset-0 bg-gradient-to-t from-slate-950 via-slate-950/75 to-slate-950/40"
                aria-hidden="true"></div>
            <div class="absolute inset-0 bg-gradient-to-r from-slate-950/90 via-slate-950/50 to-transparent"
                aria-hidden="true"></div>
            <div class="absolute inset-0 opacity-[0.03]"
                style="background-image: url('{{ asset('images/pattern-bg.png') }}'); background-size: 200px;"></div>
        </div>

        {{-- ── CONTENT ── --}}
        <div class="container relative mx-auto px-5 sm:px-8 lg:px-12">
            @if($frontCover)
                <div class="relative z-10 grid items-end gap-8 pt-12 pb-10 lg:grid-cols-12 lg:gap-12 lg:pt-32 lg:pb-16">
                    {{-- Left: Details --}}
                    <div class="order-2 flex flex-col lg:order-1 lg:col-span-7">
                        <span
                            class="mb-4 inline-flex w-fit items-center gap-1.5 rounded-full border border-indigo-400/30 bg-indigo-400/10 px-3 py-1 text-xs font-semibold tracking-wide text-indigo-300 backdrop-blur-md">
                            {{ __('Siri') }}
                        </span>

                        <h1
                            class="font-heading text-3xl font-bold tracking-tight text-white sm:text-4xl lg:text-5xl lg:leading-tight">
                            {{ $series->title }}
                        </h1>
                        <div
                            class="shimmer-line mt-4 h-0.5 w-20 rounded-full bg-gradient-to-r from-gold-400/80 via-gold-300/60 to-gold-600/30">
                        </div>

                        {{-- Action buttons --}}
                        <div class="mt-8 flex flex-wrap items-center gap-3">
                            <button wire:click="toggleFollow" wire:loading.attr="disabled"
                                class="inline-flex items-center gap-1.5 rounded-full px-5 py-2 text-sm font-semibold transition-all duration-200 backdrop-blur-sm {{ $this->isFollowing ? 'border border-indigo-400/40 bg-indigo-500/20 text-indigo-300 hover:border-red-400/40 hover:bg-red-500/20 hover:text-red-300' : 'border border-white/15 bg-white/10 text-white hover:border-indigo-400/40 hover:bg-indigo-500/20 hover:text-indigo-300' }}"
                                x-data="{ hovering: false }" @mouseenter="hovering = true" @mouseleave="hovering = false">
                                @if($this->isFollowing)
                                    <template x-if="!hovering">
                                        <span class="inline-flex items-center gap-1.5">
                                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                                stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round"
                                                    d="M4.5 12.75l6 6 9-13.5" />
                                            </svg>
                                            {{ __('Mengikuti') }}
                                        </span>
                                    </template>
                                    <template x-if="hovering">
                                        <span class="inline-flex items-center gap-1.5">
                                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                                stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                            </svg>
                                            {{ __('Nyahikut') }}
                                        </span>
                                    </template>
                                @else
                                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                                    </svg>
                                    {{ __('Ikuti Siri') }}
                                @endif
                            </button>

                            <button type="button" @click="shareModalOpen = true"
                                class="inline-flex items-center gap-1.5 rounded-full border border-white/15 bg-white/10 px-5 py-2 text-sm font-semibold text-white transition-all duration-200 hover:border-emerald-400/40 hover:bg-emerald-500/20 hover:text-emerald-200 backdrop-blur-sm">
                                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z" />
                                </svg>
                                {{ __('Kongsi') }}
                            </button>

                            @can('update', $series)
                                <a href="{{ route('filament.admin.resources.series.edit', ['record' => $series]) }}"
                                    target="_blank" rel="noopener noreferrer"
                                    class="inline-flex items-center gap-1.5 rounded-full border border-white/15 bg-white/10 px-5 py-2 text-sm font-semibold text-white transition-all duration-200 hover:border-amber-400/40 hover:bg-amber-500/20 hover:text-amber-200 backdrop-blur-sm">
                                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M16.862 3.487a2.25 2.25 0 113.182 3.182L7.5 19.213l-4.5.9.9-4.5L16.862 3.487z" />
                                    </svg>
                                    {{ __('Edit Siri') }}
                                </a>
                            @endcan
                        </div>

                    </div>

                    {{-- Right Poster --}}
                    <div class="order-1 flex justify-center lg:order-2 lg:col-span-5 lg:justify-end animate-fade-in-up">
                        <div class="relative w-full max-w-[260px] sm:max-w-[300px] lg:max-w-none">
                            <div class="absolute -inset-3 rounded-3xl bg-white/5 blur-xl" aria-hidden="true"></div>
                            <button type="button" @click="posterModalOpen = true"
                                class="group relative block w-full overflow-hidden rounded-2xl shadow-2xl ring-1 ring-white/15 transition-transform duration-300 hover:scale-[1.02] focus:outline-none">
                                <img src="{{ $frontCover }}" alt="{{ $series->title }}"
                                    class="w-full object-contain max-h-[480px] bg-slate-900" loading="lazy">
                                <div
                                    class="absolute bottom-3 right-3 flex items-center gap-1.5 rounded-full bg-slate-950/60 px-3 py-1.5 text-xs font-semibold text-white/90 backdrop-blur-sm ring-1 ring-white/10 transition-opacity duration-300 group-hover:opacity-0">
                                    <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                        stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M21 21l-5-5m2-5a7 7 0 11-14 0 7 7 0 0114 0zM10 7v3m0 0v3m0-3h3m-3 0H7" />
                                    </svg>
                                    {{ __('Penuh') }}
                                </div>
                            </button>
                        </div>

                        {{-- Modal --}}
                        <template x-teleport="body">
                            <div x-show="posterModalOpen" x-cloak x-transition.opacity
                                @keydown.escape.window="posterModalOpen = false"
                                class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/95 p-4 backdrop-blur-xl sm:p-8">
                                <button type="button" @click="posterModalOpen = false"
                                    class="absolute right-4 top-4 z-10 inline-flex size-12 items-center justify-center rounded-full bg-white/10 text-white backdrop-blur-md transition hover:bg-white/20 hover:scale-110 sm:right-8 sm:top-8">
                                    <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                        stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                                <div @click.away="posterModalOpen = false" x-show="posterModalOpen"
                                    class="relative max-h-full max-w-full">
                                    <img src="{{ $series->getFirstMediaUrl('cover') ?: $frontCover }}"
                                        alt="{{ $series->title }}"
                                        class="max-h-[90vh] max-w-full w-auto rounded-2xl object-contain shadow-2xl ring-1 ring-white/20">
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            @else
                {{-- No Poster Layout --}}
                <div class="relative z-10 flex flex-col justify-end pb-16 pt-12 lg:max-w-[70%] lg:pb-24 lg:pt-24">
                    <span
                        class="mb-4 inline-flex w-fit items-center gap-1.5 rounded-full border border-indigo-400/30 bg-indigo-400/10 px-3 py-1 text-xs font-semibold tracking-wide text-indigo-300 backdrop-blur-md">
                        {{ __('Siri') }}
                    </span>
                    <h1
                        class="font-heading text-3xl font-bold tracking-tight text-white sm:text-4xl lg:text-6xl lg:leading-tight">
                        {{ $series->title }}
                    </h1>
                    <div
                        class="shimmer-line mt-6 h-0.5 w-20 rounded-full bg-gradient-to-r from-gold-400/80 via-gold-300/60 to-gold-600/30">
                    </div>

                    <div class="mt-8 flex flex-wrap items-center gap-3">
                        <button wire:click="toggleFollow" wire:loading.attr="disabled"
                            class="inline-flex items-center gap-1.5 rounded-full px-5 py-2 text-sm font-semibold transition-all duration-200 backdrop-blur-sm {{ $this->isFollowing ? 'border border-indigo-400/40 bg-indigo-500/20 text-indigo-300 hover:border-red-400/40 hover:bg-red-500/20 hover:text-red-300' : 'border border-white/15 bg-white/10 text-white hover:border-indigo-400/40 hover:bg-indigo-500/20 hover:text-indigo-300' }}"
                            x-data="{ hovering: false }" @mouseenter="hovering = true" @mouseleave="hovering = false">
                            @if($this->isFollowing)
                                <template x-if="!hovering">
                                    <span class="inline-flex items-center gap-1.5">
                                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                            stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                        </svg>
                                        {{ __('Mengikuti') }}
                                    </span>
                                </template>
                                <template x-if="hovering">
                                    <span class="inline-flex items-center gap-1.5">
                                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                            stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                        {{ __('Nyahikut') }}
                                    </span>
                                </template>
                            @else
                                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                                </svg>
                                {{ __('Ikuti Siri') }}
                            @endif
                        </button>
                        <button type="button" @click="shareModalOpen = true"
                            class="inline-flex items-center gap-1.5 rounded-full border border-white/15 bg-white/10 px-5 py-2 text-sm font-semibold text-white transition-all duration-200 hover:border-emerald-400/40 hover:bg-emerald-500/20 hover:text-emerald-200 backdrop-blur-sm">
                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z" />
                            </svg>
                            {{ __('Kongsi') }}
                        </button>
                        @can('update', $series)
                            <a href="{{ route('filament.admin.resources.series.edit', ['record' => $series]) }}" target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex items-center gap-1.5 rounded-full border border-white/15 bg-white/10 px-5 py-2 text-sm font-semibold text-white transition-all duration-200 hover:border-amber-400/40 hover:bg-amber-500/20 hover:text-amber-200 backdrop-blur-sm">
                                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M16.862 3.487a2.25 2.25 0 113.182 3.182L7.5 19.213l-4.5.9.9-4.5L16.862 3.487z" />
                                </svg>
                                {{ __('Edit Siri') }}
                            </a>
                        @endcan
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{-- ═══ MAIN CONTENT ═══ --}}
    <div class="relative">
        <div
            class="pointer-events-none absolute inset-x-0 top-0 h-64 bg-gradient-to-b from-indigo-50/60 via-slate-50/40 to-transparent">
        </div>

        <div class="container relative z-10 mx-auto max-w-6xl px-4 pb-16 pt-8 sm:px-6 lg:px-8">
            <div class="flex flex-col gap-8 lg:grid lg:grid-cols-[1fr_300px]">

                {{-- LEFT — Events tabs --}}
                <div class="space-y-8">

                    {{-- Description --}}
                    @if($series->description)
                        <section
                            class="rounded-3xl border border-slate-200/60 bg-white/80 p-6 shadow-lg shadow-slate-200/40 backdrop-blur-xl sm:p-8">
                            <div class="mb-4 flex items-center gap-3">
                                <div
                                    class="flex size-9 items-center justify-center rounded-xl bg-indigo-100 text-indigo-600">
                                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                        stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" />
                                    </svg>
                                </div>
                                <h2 class="font-heading text-lg font-bold text-slate-900">{{ __('About This Reference') }}
                                </h2>
                            </div>
                            <div class="prose prose-slate max-w-none text-sm leading-relaxed text-slate-600">
                                {!! nl2br(e($series->description)) !!}
                            </div>
                        </section>
                    @endif

                    {{-- ═══ EVENTS SECTION (Tabs: Upcoming / Past) ═══ --}}
                    <section class="scroll-reveal reveal-up revealed" x-data="{ tab: 'upcoming' }"
                        x-intersect.once="$el.classList.add('revealed')">

                        {{-- Section header --}}
                        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                            <div class="flex items-center gap-3">
                                <div
                                    class="flex size-10 items-center justify-center rounded-xl bg-gradient-to-br from-emerald-500 to-teal-600 text-white shadow-lg shadow-emerald-500/25">
                                    <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                        stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" />
                                    </svg>
                                </div>
                                <div>
                                    <h2 class="font-heading text-2xl font-bold text-slate-900">{{ __('Majlis') }}</h2>
                                    <div
                                        class="mt-0.5 h-0.5 w-12 rounded-full bg-gradient-to-r from-emerald-500 to-transparent">
                                    </div>
                                </div>
                            </div>

                            {{-- Tab toggle --}}
                            <div class="flex items-center gap-1 rounded-xl bg-slate-100 p-1">
                                <button @click="tab = 'upcoming'"
                                    :class="tab === 'upcoming' ? 'bg-white shadow-sm text-slate-900' : 'text-slate-500 hover:text-slate-700'"
                                    class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition-all duration-200">
                                    <svg class="size-3.5 hidden sm:block" fill="none" viewBox="0 0 24 24"
                                        stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" />
                                    </svg>
                                    {{ __('Akan Datang') }}
                                    <span
                                        class="ml-0.5 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-slate-200 px-1.5 text-[10px] font-bold text-slate-600">{{ $upcomingTotal }}</span>
                                </button>
                                <button @click="tab = 'past'"
                                    :class="tab === 'past' ? 'bg-white shadow-sm text-slate-900' : 'text-slate-500 hover:text-slate-700'"
                                    class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition-all duration-200 disabled:cursor-not-allowed disabled:opacity-50"
                                    @disabled($pastTotal === 0)>
                                    <svg class="size-3.5 hidden sm:block" fill="none" viewBox="0 0 24 24"
                                        stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                    {{ __('Lepas') }}
                                    <span
                                        class="ml-0.5 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-slate-200 px-1.5 text-[10px] font-bold text-slate-600">{{ $pastTotal }}</span>
                                </button>
                            </div>
                        </div>

                        {{-- ═══ UPCOMING TAB ═══ --}}
                        <div x-show="tab === 'upcoming'" x-transition:enter="transition ease-out duration-200"
                            x-transition:enter-start="opacity-0 translate-y-2"
                            x-transition:enter-end="opacity-100 translate-y-0">
                            @if($upcomingEvents->isEmpty())
                                <div
                                    class="rounded-2xl border-2 border-dashed border-slate-200 bg-white/80 p-10 text-center sm:p-12">
                                    <div
                                        class="mx-auto mb-4 flex size-16 items-center justify-center rounded-2xl bg-emerald-50 ring-1 ring-emerald-100">
                                        <svg class="size-8 text-emerald-300" fill="none" viewBox="0 0 24 24"
                                            stroke="currentColor" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" />
                                        </svg>
                                    </div>
                                    <p class="text-base font-semibold text-slate-500">
                                        {{ __('Tiada majlis dijadualkan buat masa ini') }}
                                    </p>
                                    <p class="mt-1 text-sm text-slate-400">
                                        {{ __('Semak semula nanti untuk kemas kini terbaru.') }}
                                    </p>
                                </div>
                            @else
                                <div class="space-y-4">
                                    @foreach($upcomingEvents as $event)
                                        @php
                                            $venueLocation = $resolveVenueLocation($event);
                                            $eventFormatValue = $event->event_format?->value ?? $event->event_format;
                                            $isRemoteEvent = in_array($eventFormatValue, ['online', 'hybrid'], true);
                                            $isPendingEvent = $event->status instanceof \App\States\EventStatus\Pending;
                                        @endphp
                                        <a href="{{ route('events.show', $event) }}" wire:navigate
                                            wire:key="upcoming-{{ $event->id }}"
                                            class="group relative flex overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-sm ring-1 ring-transparent transition-all duration-300 hover:-translate-y-0.5 hover:border-emerald-200/80 hover:ring-emerald-100 hover:shadow-xl hover:shadow-emerald-500/[0.08]">
                                            {{-- Date accent sidebar --}}
                                            <div
                                                class="flex w-[4.5rem] shrink-0 flex-col items-center justify-center bg-gradient-to-b {{ $isPendingEvent ? 'from-amber-600 to-amber-800' : ($isRemoteEvent ? 'from-sky-600 to-sky-800' : 'from-emerald-600 to-emerald-800') }} p-2.5 text-white sm:w-24 sm:p-3">
                                                <span
                                                    class="text-[10px] font-bold uppercase tracking-widest {{ $isPendingEvent ? 'text-amber-200/80' : ($isRemoteEvent ? 'text-sky-200/80' : 'text-emerald-200/80') }} sm:text-[11px]">{{ \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($event->starts_at, 'l') }}</span>
                                                <span
                                                    class="font-heading text-2xl font-black leading-none sm:text-4xl">{{ \App\Support\Timezone\UserDateTimeFormatter::format($event->starts_at, 'd') }}</span>
                                                <span
                                                    class="mt-0.5 text-[11px] font-bold tracking-wide {{ $isPendingEvent ? 'text-amber-200/80' : ($isRemoteEvent ? 'text-sky-200/80' : 'text-emerald-200/80') }} sm:text-[13px]">{{ \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($event->starts_at, 'F') }}</span>
                                            </div>
                                            {{-- Event details --}}
                                            <div class="flex flex-1 flex-col justify-center gap-2 p-4 sm:p-5">
                                                <div class="flex items-center gap-2">
                                                    <span
                                                        class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-[11px] font-semibold text-emerald-700 ring-1 ring-emerald-200/60">
                                                        {{ $resolveEventTypeLabel($event->event_type) }}
                                                    </span>
                                                    @if($isPendingEvent)
                                                        <span
                                                            class="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-0.5 text-[11px] font-semibold text-amber-700 ring-1 ring-amber-200/60">{{ __('Menunggu Kelulusan') }}</span>
                                                    @endif
                                                    @if($isRemoteEvent)
                                                        <span
                                                            class="inline-flex animate-pulse items-center gap-1 rounded-full bg-sky-50 px-2.5 py-0.5 text-[11px] font-semibold text-sky-700 ring-1 ring-sky-200/80">
                                                            <span class="size-1.5 rounded-full bg-sky-500"></span>
                                                            {{ $eventFormatValue === 'hybrid' ? __('Hybrid') : __('Online') }}
                                                        </span>
                                                    @endif
                                                </div>
                                                <h3
                                                    class="font-heading text-base font-bold leading-snug text-slate-900 transition-colors group-hover:text-emerald-700 sm:text-lg">
                                                    {{ $event->title }}
                                                </h3>
                                                @if($event->reference_study_subtitle)
                                                    <p class="pl-3 text-sm font-bold italic text-slate-500 sm:pl-4">
                                                        {{ $event->reference_study_subtitle }}
                                                    </p>
                                                @endif
                                                <div class="space-y-1 text-sm text-slate-500">
                                                    <div class="flex items-center gap-1.5">
                                                        <svg class="size-3.5 text-slate-400" fill="none" viewBox="0 0 24 24"
                                                            stroke="currentColor" stroke-width="2">
                                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                                d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                        </svg>
                                                        {{ $resolveEventTimeDisplay($event) }}
                                                        @if($event->ends_at)
                                                            <span
                                                                class="text-slate-300">–</span>{{ $resolveEventEndTimeDisplay($event) }}
                                                        @endif
                                                    </div>
                                                    @if($venueLocation && $eventFormatValue !== 'online')
                                                        <div class="flex items-center gap-1.5">
                                                            <svg class="size-3.5 shrink-0 text-slate-400" fill="none"
                                                                viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                                <path stroke-linecap="round" stroke-linejoin="round"
                                                                    d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" />
                                                                <path stroke-linecap="round" stroke-linejoin="round"
                                                                    d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 0115 0z" />
                                                            </svg>
                                                            <span class="line-clamp-1">{{ $venueLocation }}</span>
                                                        </div>
                                                    @elseif($event->institution && $eventFormatValue !== 'online')
                                                        <div class="flex items-center gap-1.5">
                                                            <svg class="size-3.5 shrink-0 text-slate-400" fill="none"
                                                                viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                                <path stroke-linecap="round" stroke-linejoin="round"
                                                                    d="M12 21v-8.25M15.75 21v-8.25M8.25 21v-8.25M3 9l9-6 9 6M4.5 9.75v10.5h15V9.75" />
                                                            </svg>
                                                            {{ $event->institution->name }}
                                                        </div>
                                                    @endif
                                                </div>
                                            </div>
                                            {{-- Arrow --}}
                                            <div class="hidden items-center pr-5 sm:flex">
                                                <div
                                                    class="flex size-8 items-center justify-center rounded-full bg-slate-100 text-slate-400 transition-all duration-300 group-hover:bg-emerald-100 group-hover:text-emerald-600">
                                                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                                        stroke-width="2">
                                                        <path stroke-linecap="round" stroke-linejoin="round"
                                                            d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                                                    </svg>
                                                </div>
                                            </div>
                                        </a>
                                    @endforeach
                                </div>

                                {{-- Load more upcoming --}}
                                @if($upcomingTotal > $upcomingEvents->count())
                                    <div class="mt-6 text-center">
                                        <button wire:click="loadMoreUpcoming" wire:loading.attr="disabled"
                                            class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-5 py-2.5 text-sm font-semibold text-slate-600 shadow-sm transition-all duration-200 hover:border-emerald-200 hover:bg-emerald-50 hover:text-emerald-700 disabled:opacity-50">
                                            <span wire:loading.remove wire:target="loadMoreUpcoming">{{ __('Lihat Lagi') }}
                                                ({{ $upcomingTotal - $upcomingEvents->count() }} {{ __('lagi') }})</span>
                                            <span wire:loading wire:target="loadMoreUpcoming"
                                                class="inline-flex items-center gap-2">
                                                <svg class="size-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                                        stroke-width="4" />
                                                    <path class="opacity-75" fill="currentColor"
                                                        d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                                                </svg>
                                                {{ __('Memuatkan...') }}
                                            </span>
                                        </button>
                                    </div>
                                @endif
                            @endif
                        </div>

                        {{-- ═══ PAST EVENTS TAB ═══ --}}
                        @if($pastEvents->isNotEmpty())
                            <div x-show="tab === 'past'" x-cloak x-transition:enter="transition ease-out duration-200"
                                x-transition:enter-start="opacity-0 translate-y-2"
                                x-transition:enter-end="opacity-100 translate-y-0">
                                <div class="space-y-4">
                                    @foreach($pastEvents as $event)
                                        @php
                                            $pastVenueLocation = $resolveVenueLocation($event);
                                            $eventFormatValue = $event->event_format?->value ?? $event->event_format;
                                            $isRemoteEvent = in_array($eventFormatValue, ['online', 'hybrid'], true);
                                            $isPendingEvent = $event->status instanceof \App\States\EventStatus\Pending;
                                        @endphp
                                        <a href="{{ route('events.show', $event) }}" wire:navigate
                                            wire:key="past-{{ $event->id }}"
                                            class="group relative flex overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-sm ring-1 ring-transparent transition-all duration-300 hover:-translate-y-0.5 hover:border-slate-300 hover:ring-slate-200 hover:shadow-xl hover:shadow-slate-500/[0.06]">
                                            <div
                                                class="flex w-[4.5rem] shrink-0 flex-col items-center justify-center bg-gradient-to-b {{ $isPendingEvent ? 'from-amber-600 to-amber-800' : ($isRemoteEvent ? 'from-sky-600 to-sky-800' : 'from-slate-500 to-slate-700') }} p-2.5 text-white sm:w-24 sm:p-3">
                                                <span
                                                    class="text-[10px] font-bold uppercase tracking-widest {{ $isPendingEvent ? 'text-amber-200/80' : ($isRemoteEvent ? 'text-sky-200/80' : 'text-slate-300/80') }} sm:text-[11px]">{{ \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($event->starts_at, 'l') }}</span>
                                                <span
                                                    class="font-heading text-2xl font-black leading-none sm:text-4xl">{{ \App\Support\Timezone\UserDateTimeFormatter::format($event->starts_at, 'd') }}</span>
                                                <span
                                                    class="mt-0.5 text-[11px] font-bold tracking-wide {{ $isPendingEvent ? 'text-amber-200/80' : ($isRemoteEvent ? 'text-sky-200/80' : 'text-slate-300/80') }} sm:text-[13px]">{{ \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($event->starts_at, 'F') }}</span>
                                            </div>
                                            <div class="flex flex-1 flex-col justify-center gap-2 p-4 sm:p-5">
                                                <div class="flex items-center gap-2">
                                                    <span
                                                        class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-[11px] font-semibold text-slate-600 ring-1 ring-slate-200/60">{{ $resolveEventTypeLabel($event->event_type) }}</span>
                                                    @if($isPendingEvent)
                                                        <span
                                                            class="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-0.5 text-[11px] font-semibold text-amber-700 ring-1 ring-amber-200/60">{{ __('Menunggu Kelulusan') }}</span>
                                                    @endif
                                                    @if($isRemoteEvent)
                                                        <span
                                                            class="inline-flex animate-pulse items-center gap-1 rounded-full bg-sky-50 px-2.5 py-0.5 text-[11px] font-semibold text-sky-700 ring-1 ring-sky-200/80">
                                                            <span class="size-1.5 rounded-full bg-sky-500"></span>
                                                            {{ $eventFormatValue === 'hybrid' ? __('Hybrid') : __('Online') }}
                                                        </span>
                                                    @endif
                                                    <span
                                                        class="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-0.5 text-[11px] font-semibold text-amber-700 ring-1 ring-amber-200/60">{{ __('Selesai') }}</span>
                                                </div>
                                                <h3
                                                    class="font-heading text-base font-bold leading-snug text-slate-900 transition-colors group-hover:text-slate-700 sm:text-lg">
                                                    {{ $event->title }}
                                                </h3>
                                                @if($event->reference_study_subtitle)
                                                    <p class="pl-3 text-sm font-bold italic text-slate-500 sm:pl-4">
                                                        {{ $event->reference_study_subtitle }}
                                                    </p>
                                                @endif
                                                <div class="space-y-1 text-sm text-slate-500">
                                                    <div class="flex items-center gap-1.5">
                                                        <svg class="size-3.5 text-slate-400" fill="none" viewBox="0 0 24 24"
                                                            stroke="currentColor" stroke-width="2">
                                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                                d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                        </svg>
                                                        {{ $resolveEventTimeDisplay($event) }}
                                                        @if($event->ends_at)
                                                            <span
                                                                class="text-slate-300">–</span>{{ $resolveEventEndTimeDisplay($event) }}
                                                        @endif
                                                    </div>
                                                    @if($pastVenueLocation && $eventFormatValue !== 'online')
                                                        <div class="flex items-center gap-1.5">
                                                            <svg class="size-3.5 shrink-0 text-slate-400" fill="none"
                                                                viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                                <path stroke-linecap="round" stroke-linejoin="round"
                                                                    d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" />
                                                                <path stroke-linecap="round" stroke-linejoin="round"
                                                                    d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 0115 0z" />
                                                            </svg>
                                                            <span class="line-clamp-1">{{ $pastVenueLocation }}</span>
                                                        </div>
                                                    @elseif($event->institution && $eventFormatValue !== 'online')
                                                        <div class="flex items-center gap-1.5">
                                                            <svg class="size-3.5 shrink-0 text-slate-400" fill="none"
                                                                viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                                <path stroke-linecap="round" stroke-linejoin="round"
                                                                    d="M12 21v-8.25M15.75 21v-8.25M8.25 21v-8.25M3 9l9-6 9 6M4.5 9.75v10.5h15V9.75" />
                                                            </svg>
                                                            {{ $event->institution->name }}
                                                        </div>
                                                    @endif
                                                </div>
                                            </div>
                                            <div class="hidden items-center pr-5 sm:flex">
                                                <div
                                                    class="flex size-8 items-center justify-center rounded-full bg-slate-100 text-slate-400 transition-all duration-300 group-hover:bg-slate-200 group-hover:text-slate-600">
                                                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                                        stroke-width="2">
                                                        <path stroke-linecap="round" stroke-linejoin="round"
                                                            d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                                                    </svg>
                                                </div>
                                            </div>
                                        </a>
                                    @endforeach
                                </div>

                                {{-- Load more past --}}
                                @if($pastTotal > $pastEvents->count())
                                    <div class="mt-6 text-center">
                                        <button wire:click="loadMorePast" wire:loading.attr="disabled"
                                            class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-5 py-2.5 text-sm font-semibold text-slate-600 shadow-sm transition-all duration-200 hover:border-slate-300 hover:bg-slate-50 disabled:opacity-50">
                                            <span wire:loading.remove wire:target="loadMorePast">{{ __('Lihat Lagi') }}
                                                ({{ $pastTotal - $pastEvents->count() }} {{ __('lagi') }})</span>
                                            <span wire:loading wire:target="loadMorePast"
                                                class="inline-flex items-center gap-2">
                                                <svg class="size-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                                        stroke-width="4" />
                                                    <path class="opacity-75" fill="currentColor"
                                                        d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                                                </svg>
                                                {{ __('Memuatkan...') }}
                                            </span>
                                        </button>
                                    </div>
                                @endif
                            </div>
                        @endif
                    </section>
                </div>

                {{-- RIGHT SIDEBAR — Book details --}}
                <aside class="space-y-6">
                    {{-- Islamic inspiration quote --}}
                    <x-sidebar-inspiration />

                    {{-- Back cover --}}
                    @if($backCover)
                        <div class="overflow-hidden rounded-2xl shadow-md ring-1 ring-slate-200/60">
                            <img src="{{ $backCover }}" alt="{{ __('Back Cover') }}" class="w-full object-cover">
                        </div>
                    @endif



                    {{-- Events count badge --}}
                    @php $totalEvents = $upcomingTotal + $pastTotal; @endphp
                    @if($totalEvents > 0)
                        <div class="flex items-center gap-3 rounded-2xl border border-emerald-200/60 bg-emerald-50/60 p-4">
                            <div
                                class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-emerald-500 text-white shadow-sm">
                                <span class="text-sm font-bold">{{ $totalEvents }}</span>
                            </div>
                            <p class="text-sm font-medium text-emerald-800">
                                {{ trans_choice(':count majlis menggunakan rujukan ini', $totalEvents, ['count' => $totalEvents]) }}
                            </p>
                        </div>
                    @endif
                </aside>

            </div>
        </div>
    </div>

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
                class="w-full max-w-lg overflow-hidden rounded-3xl bg-white shadow-2xl ring-1 ring-slate-200/50">
                <div class="flex items-center justify-between border-b border-slate-100 bg-slate-50/50 px-6 py-5">
                    <h3 class="font-heading text-xl font-bold text-slate-900">{{ __('Kongsi Siri') }}</h3>
                    <button type="button" @click="shareModalOpen = false"
                        class="inline-flex size-10 items-center justify-center rounded-full bg-white text-slate-500 shadow-sm ring-1 ring-slate-200 transition hover:bg-slate-50 hover:text-slate-700">
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="p-6 sm:p-8">
                    <x-dawah-share-panel
                        :heading="__('Share This Series')"
                        :description="__('Share this series with others and keep every visit and response on one tracked link.')"
                        :preview-title="$series->title"
                        :preview-subtitle="Str::limit((string) $series->description, 110)"
                        :share-data="$seriesShareData"
                        :share-links="$seriesShareLinks"
                    />
                </div>
            </div>
        </div>
    </div>
</div>
