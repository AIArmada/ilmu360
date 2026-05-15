@props([
    'event',
    'formatLabel',
    'scheduleKindLabel' => null,
    'heroReferenceTitle' => null,
    'showHeroLocationChip' => false,
    'heroLocationIcon' => 'map-pin',
    'heroLocationTitle' => null,
    'heroLocationSubtitle' => null,
    'locationHref' => null,
])

<div class="animate-fade-in-up" style="--reveal-d: 100ms;">
    <div class="flex flex-wrap gap-2">
        @if($event->status instanceof \App\States\EventStatus\Draft)
            <span
                class="inline-flex items-center gap-1.5 rounded-full border border-slate-400/50 bg-slate-400/20 px-3 py-1 text-xs font-semibold tracking-wide text-slate-200 backdrop-blur-md">
                <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round"
                        d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m6.75 12l-3-3m0 0l-3 3m3-3v6m-1.5-15H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                </svg>
                {{ __('Draf') }}
            </span>
        @elseif($event->status instanceof \App\States\EventStatus\Pending)
            <span
                class="inline-flex items-center gap-1.5 rounded-full border border-amber-400/30 bg-amber-400/10 px-3 py-1 text-xs font-semibold tracking-wide text-amber-300 backdrop-blur-md">
                <span class="relative flex size-2"><span
                        class="absolute inline-flex size-full animate-ping rounded-full bg-amber-400 opacity-75"></span><span
                        class="relative inline-flex size-2 rounded-full bg-amber-500"></span></span>
                {{ __('Menunggu Kelulusan') }}
            </span>
        @elseif($event->status instanceof \App\States\EventStatus\Cancelled)
            <span
                class="inline-flex items-center gap-1.5 rounded-full border border-rose-400/40 bg-rose-500/15 px-3 py-1 text-xs font-semibold tracking-wide text-rose-200 backdrop-blur-md">
                <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round"
                        d="M18.364 5.636a9 9 0 010 12.728m-12.728 0a9 9 0 010-12.728m12.728 12.728L5.636 5.636" />
                </svg>
                {{ __('Dibatalkan') }}
            </span>
        @endif

        @php
            $eventTypeValues = $event->event_type;
            $firstEventType = $eventTypeValues instanceof \Illuminate\Support\Collection ? $eventTypeValues->first() : null;
        @endphp

        @if($firstEventType)
            <span
                class="inline-flex items-center rounded-full border border-emerald-400/30 bg-emerald-400/10 px-3 py-1 text-xs font-semibold tracking-wide text-emerald-300 backdrop-blur-md">
                {{ $firstEventType->getLabel() }}
            </span>
        @endif

        @if($event->settings?->registration_required)
            <span
                class="inline-flex items-center gap-1.5 rounded-full border border-amber-400/40 bg-amber-400/15 px-3 py-1 text-xs font-bold tracking-wide text-amber-300 backdrop-blur-md">
                <svg class="size-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round"
                        d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
                {{ __('Pendaftaran Diperlukan') }}
            </span>
        @else
            <span
                class="inline-flex items-center gap-1.5 rounded-full border border-sky-400/30 bg-sky-400/10 px-3 py-1 text-xs font-bold tracking-wide text-sky-300 backdrop-blur-md">
                <svg class="size-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round"
                        d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                {{ __('Terbuka') }}
            </span>
        @endif

        <span
            class="inline-flex items-center gap-1.5 rounded-full border border-white/10 bg-white/5 px-3 py-1 text-xs font-medium tracking-wide text-white/80 backdrop-blur-md">
            @if($event->event_format === \App\Enums\EventFormat::Online)
                <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9" />
                </svg>
            @elseif($event->event_format === \App\Enums\EventFormat::Hybrid)
                <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M7.5 21L3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5" />
                </svg>
            @else
                <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" />
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" />
                </svg>
            @endif
            {{ $formatLabel }}
        </span>

        @if($scheduleKindLabel && $event->schedule_kind !== \App\Enums\ScheduleKind::Single)
            <span
                class="inline-flex items-center gap-1.5 rounded-full border border-violet-400/30 bg-violet-400/10 px-3 py-1 text-xs font-medium tracking-wide text-violet-300 backdrop-blur-md">
                <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round"
                        d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                </svg>
                {{ $scheduleKindLabel }}
            </span>
        @endif
    </div>
</div>

<h1 class="mt-5 text-balance font-heading text-4xl font-bold leading-[1.1] tracking-tight text-white drop-shadow-2xl sm:text-5xl lg:text-6xl xl:text-7xl animate-fade-in-up"
    style="--reveal-d: 200ms;">
    {{ $event->title }}
</h1>

@if($heroReferenceTitle)
    <div data-testid="event-hero-reference" class="mt-6 flex items-center gap-3 rounded-2xl border border-white/10 bg-white/[0.08] p-3 shadow-lg shadow-black/20 backdrop-blur-md animate-fade-in-up"
        style="--reveal-d: 300ms;">
        <div class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-white/10 shadow-inner">
            <svg class="size-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round"
                    d="M17.25 3.75H6.75A2.25 2.25 0 004.5 6v15l7.5-4.5 7.5 4.5V6a2.25 2.25 0 00-2.25-2.25z" />
            </svg>
        </div>
        <div class="min-w-0">
            <p class="text-xs font-bold uppercase tracking-widest text-white/60">{{ __('References') }}</p>
            <p class="mt-0.5 text-sm font-bold text-white">{{ $heroReferenceTitle }}</p>
        </div>
    </div>
@endif

<div class="mt-7 flex flex-wrap gap-3 animate-fade-in-up" style="--reveal-d: 400ms;">
    @if($event->starts_at)
        <div
            class="flex items-center gap-3 rounded-2xl border border-white/10 bg-white/[0.08] p-3 backdrop-blur-md shadow-lg shadow-black/20">
            <div
                class="flex size-11 shrink-0 flex-col items-center justify-center rounded-xl bg-gradient-to-b from-emerald-400 to-emerald-600 shadow-inner">
                <span
                    class="text-base font-bold leading-none text-white">{{ \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($event->starts_at, 'j') }}</span>
                <span
                    class="text-[8px] font-bold uppercase tracking-widest text-emerald-50">{{ \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($event->starts_at, 'M') }}</span>
            </div>
            <div>
                <p class="text-sm font-bold text-white">
                    {{ \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($event->starts_at, 'l, j F Y') }}
                </p>
                <p class="mt-0.5 text-xs font-medium text-emerald-200/80">
                    @if($event->isPrayerRelative())
                        {{ $event->timing_display }}
                        @if($event->ends_at) — {{ \App\Support\Timezone\UserDateTimeFormatter::format($event->ends_at, 'g:i A') }}@endif
                    @else
                        {{ \App\Support\Timezone\UserDateTimeFormatter::format($event->starts_at, 'g:i A') }}
                        @if($event->ends_at) — {{ \App\Support\Timezone\UserDateTimeFormatter::format($event->ends_at, 'g:i A') }}@endif
                    @endif
                </p>
            </div>
        </div>
    @endif

    @if($showHeroLocationChip)
        <div data-testid="event-hero-location"
            class="flex items-center gap-3 rounded-2xl border border-white/10 bg-white/[0.08] p-3 backdrop-blur-md shadow-lg shadow-black/20">
            <div class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-white/10 shadow-inner">
                @if($heroLocationIcon === 'globe')
                    <svg class="size-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9" />
                    </svg>
                @elseif($heroLocationIcon === 'arrows-right-left')
                    <svg class="size-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M7.5 21L3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5" />
                    </svg>
                @elseif($heroLocationIcon === 'clock')
                    <svg class="size-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M12 6v6l4 2m6 0A10 10 0 1112 2a10 10 0 0110 10z" />
                    </svg>
                @else
                    <svg class="size-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" />
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" />
                    </svg>
                @endif
            </div>
            <div>
                @if($locationHref)
                    <a href="{{ $locationHref }}" wire:navigate
                        class="text-sm font-bold text-white hover:text-emerald-400 transition-colors">{{ $heroLocationTitle }}</a>
                @else
                    <p class="text-sm font-bold text-white">{{ $heroLocationTitle }}</p>
                @endif
                <p class="mt-0.5 text-xs font-medium text-white/60">{{ $heroLocationSubtitle }}</p>
            </div>
        </div>
    @endif
</div>