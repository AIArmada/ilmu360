<?php

use App\Enums\DawahShareOutcomeType;
use App\Models\Event;
use App\Models\Reference;
use App\Services\ShareTrackingService;
use App\Support\Auth\IntendedRedirect;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new
    #[Layout('layouts.app')]
    #[Title('Reference')]
    class extends Component
    {
        public Reference $reference;

        public bool $isFollowing = false;

        public int $upcomingPerPage = 10;

        public int $pastPerPage = 10;

        public bool $includeAllParts = false;

        public function mount(Reference $reference): void
        {
            if (! $reference->is_active) {
                abort(404);
            }

            $reference->load(['media', 'socialMedia']);

            $this->reference = $reference;
            $this->isFollowing = Auth::user()?->isFollowing($reference) ?? false;
        }

        /**
         * @return Collection<int, Event>
         */
        public function toggleFollow(): void
        {
            $user = Auth::user();

            if (! $user) {
                $this->redirect(IntendedRedirect::loginUrl(route('references.show', $this->reference)), navigate: true);

                return;
            }

            if ($this->isFollowing) {
                $user->unfollow($this->reference);
                $this->isFollowing = false;
            } else {
                $user->follow($this->reference);
                $this->isFollowing = true;
                app(ShareTrackingService::class)->recordOutcome(
                    type: DawahShareOutcomeType::ReferenceFollow,
                    outcomeKey: 'reference_follow:user:'.$user->id.':reference:'.$this->reference->id,
                    subject: $this->reference,
                    actor: $user,
                    request: request(),
                    metadata: [
                        'reference_id' => $this->reference->id,
                    ],
                );
            }
        }

        public function getUpcomingEventsProperty(): Collection
        {
            return $this->referenceEventsQuery()
                ->active()
                ->where('starts_at', '>=', now())
                ->with([
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
            return $this->referenceEventsQuery()
                ->active()
                ->where('starts_at', '>=', now())
                ->count();
        }

        /**
         * @return Collection<int, Event>
         */
        public function getPastEventsProperty(): Collection
        {
            return $this->referenceEventsQuery()
                ->active()
                ->where('starts_at', '<', now())
                ->with([
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
            return $this->referenceEventsQuery()
                ->active()
                ->where('starts_at', '<', now())
                ->count();
        }

        public function toggleAllParts(): void
        {
            if ($this->reference->isRootReference()) {
                return;
            }

            $this->includeAllParts = ! $this->includeAllParts;
        }

        private function referenceEventsQuery(): Builder
        {
            $referenceIds = $this->reference->isRootReference() || $this->includeAllParts
                ? $this->reference->familyReferenceIds()
                : $this->reference->defaultEventReferenceIds();

            return Event::query()
                ->whereHas('references', function (Builder $referenceQuery) use ($referenceIds): void {
                    $referenceQuery->whereIn('references.id', $referenceIds);
                });
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
            $view->title($this->reference->display_title.' - '.config('app.name'));
        }
    };
?>

@section('title', $this->reference->display_title . ' - ' . config('app.name'))
@section('meta_description', Str::limit(trim(strip_tags((string) $this->reference->description)) ?: __('Lihat rujukan ini, termasuk penerangan dan majlis berkaitan di :app.', ['app' => config('app.name')]), 160))
@section('meta_robots', ($this->reference->is_active && $this->reference->status === 'verified') ? 'index, follow' : 'noindex, nofollow')
@section('og_url', route('references.show', $this->reference))
@section('og_image', $this->reference->getFirstMediaUrl('front_cover', 'thumb') ?: asset('images/default-mosque-hero.png'))
@section('og_image_alt', __('Rujukan :title', ['title' => $this->reference->title]))

@php
    $reference = $this->reference;
    $upcomingEvents = $this->upcomingEvents;
    $pastEvents = $this->pastEvents;
    $upcomingTotal = $this->upcomingTotal;
    $pastTotal = $this->pastTotal;
    $referenceUrl = route('references.show', $reference);
    $referenceShareText = trim($reference->display_title . ' - ' . config('app.name'));
    $referenceShareData = [
        'title' => $reference->display_title,
        'text' => Str::limit((string) $reference->description, 140) ?: __('Lihat rujukan ini di :app', ['app' => config('app.name')]),
        'url' => $referenceUrl,
        'sourceUrl' => $referenceUrl,
        'shareText' => $referenceShareText,
        'fallbackTitle' => $reference->display_title,
        'payloadEndpoint' => route('dawah-share.payload'),
    ];
    $referenceShareLinks = app(\App\Services\ShareTrackingService::class)->redirectLinks(
        $referenceUrl,
        $referenceShareText,
        $reference->title,
    );

    $frontCover = $reference->getFirstMediaUrl('front_cover', 'thumb');
    $backCover = $reference->getFirstMediaUrl('back_cover', 'thumb');

    $socialLinks = $reference->socialMedia
        ->filter(fn($social) => filled($social->resolved_url) && filled($social->platform))
        ->mapWithKeys(fn($social) => [strtolower((string) $social->platform) => $social])
        ->values();
    $hasSocialLinks = $socialLinks->isNotEmpty();

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
    <header
        class="noise-overlay relative isolate overflow-hidden bg-linear-to-br from-slate-950 via-indigo-950/70 to-slate-950">
        {{-- Ambient orbs --}}
        <div class="pointer-events-none absolute inset-0">
            <div
                class="animate-float-drift absolute -top-24 left-[5%] h-112 w-md rounded-full bg-indigo-500/25 blur-[110px]">
            </div>
            <div
                class="animate-float-drift-alt absolute -bottom-16 right-[8%] h-88 w-88 rounded-full bg-gold-400/15 blur-[90px]">
            </div>
            <div
                class="animate-float-drift-slow absolute top-16 right-[40%] h-72 w-72 rounded-full bg-violet-400/15 blur-[80px]">
            </div>
        </div>
        <div
            class="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_80%_70%_at_55%_50%,rgba(99,102,241,0.12)_0%,transparent_60%)]">
        </div>
        <div class="absolute inset-0 opacity-[0.03]"
            style="background-image: url('{{ asset('images/pattern-bg.png') }}'); background-size: 200px;"></div>
        <div class="absolute inset-0 bg-linear-to-t from-slate-950/90 via-transparent to-transparent"></div>
        <div class="absolute inset-x-0 top-0 h-24 bg-linear-to-b from-slate-950/60 to-transparent"></div>

        <div
            class="container relative z-10 mx-auto flex max-w-6xl flex-col items-center gap-8 px-6 pb-12 pt-14 sm:flex-row sm:items-end sm:gap-10 lg:px-8 lg:pb-16 lg:pt-20">

            {{-- Book cover --}}
            <div class="animate-scale-in relative shrink-0" style="animation-delay: 150ms; opacity: 0;">
                <div
                    class="animate-glow-breathe absolute -inset-3 rounded-2xl bg-linear-to-br from-indigo-400/35 via-gold-400/15 to-indigo-600/30 blur-xl">
                </div>
                @if($frontCover)
                    <div
                        class="relative h-44 w-32 overflow-hidden rounded-2xl shadow-2xl shadow-indigo-950/60 ring-2 ring-white/15 sm:h-52 sm:w-36 lg:h-60 lg:w-44">
                        <img src="{{ $frontCover }}" alt="{{ $reference->title }}" class="h-full w-full object-cover"
                            width="176" height="240">
                    </div>
                @else
                    <div
                        class="relative flex h-44 w-32 items-center justify-center rounded-2xl bg-indigo-900/60 shadow-2xl shadow-indigo-950/60 ring-2 ring-white/10 sm:h-52 sm:w-36 lg:h-60 lg:w-44">
                        <svg class="size-16 text-indigo-400/60" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                            stroke-width="1">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                        </svg>
                    </div>
                @endif
            </div>

            {{-- Title & meta --}}
            <div class="animate-fade-in-up flex-1 text-center sm:text-left" style="animation-delay: 300ms; opacity: 0;">
                @if($reference->type)
                    <p class="mb-2 text-sm font-semibold uppercase tracking-widest text-indigo-400/80">
                        {{ ucfirst($reference->type) }}
                    </p>
                @endif
                <h1 class="font-heading text-3xl font-bold tracking-tight text-white sm:text-4xl lg:text-5xl">
                    {{ $reference->display_title }}
                </h1>
                <div
                    class="shimmer-line mx-auto mt-3 h-0.5 w-20 rounded-full bg-linear-to-r from-gold-400/80 via-gold-300/60 to-gold-600/30 sm:mx-0">
                </div>

                <div
                    class="mt-4 flex flex-wrap items-center justify-center gap-4 text-sm text-slate-300 sm:justify-start">
                    @if($reference->author)
                        <span class="inline-flex items-center gap-1.5">
                            <svg class="size-4 text-indigo-400/70" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />
                            </svg>
                            {{ $reference->author }}
                        </span>
                    @endif
                    @if($reference->publisher)
                        <span class="inline-flex items-center gap-1.5 text-slate-400">
                            <svg class="size-4 text-indigo-400/60" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />
                            </svg>
                            {{ $reference->publisher }}
                        </span>
                    @endif
                    @if($reference->publication_year)
                        <span class="inline-flex items-center gap-1.5 text-slate-400">
                            <svg class="size-4 text-indigo-400/60" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" />
                            </svg>
                            {{ $reference->publication_year }}
                        </span>
                    @endif
                </div>

                {{-- Action buttons --}}
                <div class="mt-5 flex flex-wrap items-center justify-center gap-2.5 sm:justify-start">
                    {{-- Follow button --}}
                    <button wire:click="toggleFollow" wire:loading.attr="disabled"
                        class="inline-flex items-center gap-1.5 rounded-full px-4 py-1.5 text-xs font-semibold transition-all duration-200 backdrop-blur-sm {{ $this->isFollowing ? 'border border-indigo-400/40 bg-indigo-500/20 text-indigo-300 hover:border-red-400/40 hover:bg-red-500/20 hover:text-red-300' : 'border border-white/15 bg-white/10 text-white hover:border-indigo-400/40 hover:bg-indigo-500/20 hover:text-indigo-300' }}"
                        x-data="{ hovering: false }" @mouseenter="hovering = true" @mouseleave="hovering = false">
                        @if($this->isFollowing)
                            <template x-if="!hovering">
                                <span class="inline-flex items-center gap-1.5">
                                    <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                        stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                    </svg>
                                    {{ __('Mengikuti') }}
                                </span>
                            </template>
                            <template x-if="hovering">
                                <span class="inline-flex items-center gap-1.5">
                                    <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                        stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                    {{ __('Nyahikut') }}
                                </span>
                            </template>
                        @else
                            <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                            </svg>
                            {{ __('Ikuti') }}
                        @endif
                    </button>
                    <button type="button" @click="shareModalOpen = true"
                        class="inline-flex items-center gap-1.5 rounded-full border border-white/15 bg-white/10 px-4 py-1.5 text-xs font-semibold text-white transition-all duration-200 hover:border-emerald-400/40 hover:bg-emerald-500/20 hover:text-emerald-200 backdrop-blur-sm">
                        <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z" />
                        </svg>
                        {{ __('Kongsi') }}
                    </button>
                    @can('update', $reference)
                        <a href="{{ route('filament.admin.resources.references.edit', ['record' => $reference]) }}"
                            target="_blank" rel="noopener noreferrer"
                            class="inline-flex items-center gap-1.5 rounded-full border border-white/15 bg-white/10 px-4 py-1.5 text-xs font-semibold text-white transition-all duration-200 hover:border-amber-400/40 hover:bg-amber-500/20 hover:text-amber-200 backdrop-blur-sm">
                            <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M16.862 3.487a2.25 2.25 0 113.182 3.182L7.5 19.213l-4.5.9.9-4.5L16.862 3.487z" />
                            </svg>
                            {{ __('Edit Rujukan') }}
                        </a>
                    @endcan
                </div>

            </div>
        </div>

        <div
            class="absolute inset-x-0 bottom-0 h-px bg-linear-to-r from-transparent via-indigo-400/60 to-transparent">
        </div>
        <div
            class="absolute inset-x-0 -bottom-0.5 h-1 bg-linear-to-r from-transparent via-indigo-500/20 to-transparent blur-sm">
        </div>
    </header>

    {{-- ═══ MAIN CONTENT ═══ --}}
    <div class="relative">
        <div
            class="pointer-events-none absolute inset-x-0 top-0 h-64 bg-linear-to-b from-indigo-50/60 via-slate-50/40 to-transparent">
        </div>

        <div class="container relative z-10 mx-auto max-w-6xl px-4 pb-16 pt-8 sm:px-6 lg:px-8">
            <div class="flex flex-col gap-8 lg:grid lg:grid-cols-[1fr_300px]">

                {{-- LEFT — Events tabs --}}
                <div class="space-y-8">

                    {{-- Description --}}
                    @if($reference->description)
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
                                {!! nl2br(e($reference->description)) !!}
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
                                    class="flex size-10 items-center justify-center rounded-xl bg-linear-to-br from-emerald-500 to-teal-600 text-white shadow-lg shadow-emerald-500/25">
                                    <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                        stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" />
                                    </svg>
                                </div>
                                <div>
                                    <h2 class="font-heading text-2xl font-bold text-slate-900">{{ __('Majlis') }}</h2>
                                    <div
                                        class="mt-0.5 h-0.5 w-12 rounded-full bg-linear-to-r from-emerald-500 to-transparent">
                                    </div>
                                </div>
                            </div>

                            <div class="flex flex-wrap items-center gap-2">
                                @if($reference->isPart())
                                    <button type="button" wire:click="toggleAllParts"
                                        class="inline-flex items-center rounded-xl border border-indigo-200 bg-white px-3 py-2 text-xs font-semibold text-indigo-700 shadow-sm transition hover:border-indigo-300 hover:bg-indigo-50">
                                        {{ $this->includeAllParts ? __('Showing all parts') : __('Show all parts') }}
                                    </button>
                                @endif

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
                                            class="group relative flex overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-sm ring-1 ring-transparent transition-all duration-300 hover:-translate-y-0.5 hover:border-emerald-200/80 hover:ring-emerald-100 hover:shadow-xl hover:shadow-emerald-500/8">
                                            {{-- Date accent sidebar --}}
                                            <div
                                                class="flex w-18 shrink-0 flex-col items-center justify-center bg-linear-to-b {{ $isPendingEvent ? 'from-amber-600 to-amber-800' : ($isRemoteEvent ? 'from-sky-600 to-sky-800' : 'from-emerald-600 to-emerald-800') }} p-2.5 text-white sm:w-24 sm:p-3">
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
                                            class="group relative flex overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-sm ring-1 ring-transparent transition-all duration-300 hover:-translate-y-0.5 hover:border-slate-300 hover:ring-slate-200 hover:shadow-xl hover:shadow-slate-500/6">
                                            <div
                                                class="flex w-18 shrink-0 flex-col items-center justify-center bg-linear-to-b {{ $isPendingEvent ? 'from-amber-600 to-amber-800' : ($isRemoteEvent ? 'from-sky-600 to-sky-800' : 'from-slate-500 to-slate-700') }} p-2.5 text-white sm:w-24 sm:p-3">
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

                    {{-- External links --}}
                    @if($hasSocialLinks)
                        <div class="scroll-reveal reveal-right revealed rounded-2xl border border-slate-200/70 bg-white shadow-sm"
                            x-intersect.once="$el.classList.add('revealed')">
                            <div class="border-b border-slate-100 px-5 py-4">
                                <h3 class="flex items-center gap-2 text-sm font-bold text-slate-900">
                                    <svg class="size-4 text-indigo-500" fill="none" viewBox="0 0 24 24"
                                        stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 00-6.364-6.364l-4.5 4.5a4.5 4.5 0 001.242 7.244" />
                                    </svg>
                                    {{ __('Pautan Luar') }}
                                </h3>
                            </div>
                            <div class="space-y-1 p-3">
                                @foreach($socialLinks as $social)
                                    @php
                                        $platform = strtolower((string) $social->platform);
                                        $label = \App\Enums\SocialMediaPlatform::tryFrom($platform)?->getLabel() ?? ucfirst($platform);
                                        $colorClass = match ($platform) {
                                            'website' => 'text-emerald-600 hover:bg-emerald-50',
                                            'youtube' => 'text-red-600 hover:bg-red-50',
                                            'facebook' => 'text-blue-600 hover:bg-blue-50',
                                            default => 'text-indigo-600 hover:bg-indigo-50',
                                        };
                                    @endphp
                                    <a href="{{ $social->resolved_url }}" target="_blank" rel="noopener noreferrer"
                                        class="group flex items-center gap-3 rounded-xl px-3 py-2.5 transition-colors {{ $colorClass }}">
                                        <img src="{{ $social->icon_url }}" alt="{{ $label }}" class="size-5 shrink-0"
                                            loading="lazy">
                                        <span
                                            class="min-w-0 flex-1 truncate text-sm font-medium text-slate-700 group-hover:text-current">{{ $label }}</span>
                                        <svg class="size-3.5 shrink-0 text-slate-400 group-hover:text-current" fill="none"
                                            viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                d="M4.5 19.5l15-15m0 0H8.25m11.25 0v11.25" />
                                        </svg>
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    {{-- Metadata card --}}
                    <div
                        class="rounded-3xl border border-slate-200/60 bg-white/80 p-6 shadow-lg shadow-slate-200/40 backdrop-blur-xl">
                        <h3 class="mb-4 font-heading text-sm font-bold uppercase tracking-widest text-slate-500">
                            {{ __('Details') }}
                        </h3>
                        <dl class="space-y-3 text-sm">
                            @if($reference->author)
                                <div>
                                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">
                                        {{ __('Author') }}
                                    </dt>
                                    <dd class="mt-0.5 font-medium text-slate-800">{{ $reference->author }}</dd>
                                </div>
                            @endif
                            @if($reference->publisher)
                                <div>
                                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">
                                        {{ __('Publisher') }}
                                    </dt>
                                    <dd class="mt-0.5 text-slate-700">{{ $reference->publisher }}</dd>
                                </div>
                            @endif
                            @if($reference->publication_year)
                                <div>
                                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">
                                        {{ __('Year') }}
                                    </dt>
                                    <dd class="mt-0.5 text-slate-700">{{ $reference->publication_year }}</dd>
                                </div>
                            @endif
                            @if($reference->type)
                                <div>
                                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">
                                        {{ __('Type') }}
                                    </dt>
                                    <dd class="mt-0.5 text-slate-700">{{ ucfirst($reference->type) }}</dd>
                                </div>
                            @endif
                        </dl>
                    </div>

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

                    <div class="rounded-2xl border border-slate-200/70 bg-slate-50/80 p-4 shadow-sm">
                        @php($referenceContributionRouteSegment = \App\Enums\ContributionSubjectType::Reference->publicRouteSegment())
                        <p class="text-[11px] font-bold uppercase tracking-[0.22em] text-slate-400">{{ __('Bantu Semak Rujukan') }}</p>
                        <p class="mt-2 text-sm leading-6 text-slate-600">
                            {{ __('Jumpa metadata yang salah atau rujukan yang meragukan?') }}
                        </p>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <a href="{{ route('contributions.suggest-update', ['subjectType' => $referenceContributionRouteSegment, 'subjectId' => $reference->slug]) }}" wire:navigate
                                class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-600 transition hover:border-sky-200 hover:bg-sky-50 hover:text-sky-700">
                                {{ __('Cadang Kemaskini') }}
                            </a>
                            <a href="{{ route('reports.create', ['subjectType' => $referenceContributionRouteSegment, 'subjectId' => $reference->slug]) }}" wire:navigate
                                class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-600 transition hover:border-rose-200 hover:bg-rose-50 hover:text-rose-700">
                                {{ __('Lapor') }}
                            </a>
                        </div>
                    </div>
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
                    <h3 class="font-heading text-xl font-bold text-slate-900">{{ __('Kongsi Rujukan') }}</h3>
                    <button type="button" @click="shareModalOpen = false"
                        class="inline-flex size-10 items-center justify-center rounded-full bg-white text-slate-500 shadow-sm ring-1 ring-slate-200 transition hover:bg-slate-50 hover:text-slate-700">
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="p-6 sm:p-8">
                    <x-dawah-share-panel
                        :heading="__('Share This Reference')"
                        :description="__('Share this reference with others and keep every visit and response on one tracked link.')"
                        :preview-title="$reference->title"
                        :preview-subtitle="Str::limit((string) $reference->description, 110)"
                        :share-data="$referenceShareData"
                        :share-links="$referenceShareLinks"
                    />
                </div>
            </div>
        </div>
    </div>
</div>
