@props([
    'event',
    'showDate' => true,
    'showAbsoluteTime' => true,
])

@php
    use App\Enums\TimingMode;
    use App\Support\Timezone\UserDateTimeFormatter;
    
    $isPrayerRelative = $event->timing_mode === TimingMode::PrayerRelative;
    $prayerDisplayText = $event->prayer_display_text;
    $absoluteTime = UserDateTimeFormatter::format($event->starts_at, 'g:i A');
    $date = UserDateTimeFormatter::translatedFormat($event->starts_at, 'l, j F Y');
@endphp

<div {{ $attributes->merge(['class' => 'event-timing']) }}>
    @if($showDate && $date)
        <div class="text-sm text-slate-600 dark:text-slate-400">
            <span class="inline-flex items-center gap-1">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                </svg>
                {{ $date }}
            </span>
        </div>
    @endif

    <div class="flex items-center gap-2 mt-1">
        @if($isPrayerRelative && $prayerDisplayText)
            {{-- Prayer-relative timing badge --}}
            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-sm font-bold bg-emerald-600 text-white shadow-sm">
                <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    {{-- Moon crescent (prayer time) --}}
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" />
                </svg>
                {{ $prayerDisplayText }}
            </span>
        @else
            {{-- Absolute time display --}}
            <span class="inline-flex items-center gap-1 px-3 py-1.5 rounded-full text-sm font-medium bg-slate-100 text-slate-800 dark:bg-slate-800 dark:text-slate-300">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                {{ $absoluteTime ?? 'Waktu tidak ditetapkan' }}
            </span>
        @endif
    </div>
</div>