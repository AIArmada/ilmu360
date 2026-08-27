@php
    $isRoleParticipation = filled($roleLabel ?? null);
    $eventTypeLabel = $resolveEventCategoryLabel($event);
    $eventLocation = $resolveEventLocation($event);
    $eventFormatValue = $event->delivery_mode?->value ?? $event->delivery_mode;
    $isRemoteEvent = in_array($eventFormatValue, ['online', 'hybrid'], true);
    $isPendingEvent = (string) $event->status === 'pending';
    $isCancelledEvent = (string) $event->status === 'cancelled';
    $datePanelClass = $isCancelledEvent
        ? 'from-rose-700 to-rose-950'
        : ($isPendingEvent
            ? 'from-amber-600 to-amber-900'
            : ($isRoleParticipation
                ? 'from-violet-700 to-indigo-950'
                : ($isRemoteEvent ? 'from-sky-700 to-sky-950' : 'from-emerald-700 to-emerald-950')));
    $cardHoverClass = $isRoleParticipation
        ? 'hover:border-violet-300 hover:shadow-[0_20px_45px_-30px_rgba(76,29,149,0.45)]'
        : 'hover:border-emerald-300 hover:shadow-[0_20px_45px_-30px_rgba(6,78,59,0.45)]';
    $eventTypeClass = $isRoleParticipation
        ? 'bg-violet-50 text-violet-800 ring-violet-100'
        : 'bg-emerald-50 text-emerald-800 ring-emerald-100';
    $titleClass = $isRoleParticipation
        ? 'text-indigo-950 group-hover:text-violet-700'
        : 'text-emerald-950 group-hover:text-emerald-700';
    $accentClass = $isRoleParticipation ? 'text-violet-600' : 'text-amber-600';
    $arrowClass = $isRoleParticipation
        ? 'bg-violet-50 text-violet-700 group-hover:bg-violet-700'
        : 'bg-emerald-50 text-emerald-700 group-hover:bg-emerald-700';
@endphp

<a
    href="{{ route('events.show', $event) }}"
    wire:key="{{ $wireKey }}"
    wire:navigate
    class="group block overflow-hidden rounded-[1.4rem] border border-slate-200 bg-white shadow-sm transition duration-300 hover:-translate-y-0.5 {{ $cardHoverClass }}"
>
    <div class="grid sm:grid-cols-[7.5rem_minmax(0,1fr)]">
        <div class="flex items-center gap-4 bg-gradient-to-br {{ $datePanelClass }} px-5 py-4 text-white sm:flex-col sm:justify-center sm:gap-0 sm:px-3 sm:py-6 sm:text-center">
            <span class="text-[10px] font-bold uppercase tracking-[0.18em] text-white/70">
                {{ \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($event->starts_at, 'l') }}
            </span>
            <span class="font-heading text-4xl font-black leading-none sm:mt-1">
                {{ \App\Support\Timezone\UserDateTimeFormatter::format($event->starts_at, 'd') }}
            </span>
            <span class="text-xs font-bold uppercase tracking-[0.12em] text-white/80 sm:mt-1">
                {{ \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($event->starts_at, 'F') }}
            </span>
        </div>

        <div class="p-5 sm:p-6">
            <div class="flex flex-wrap items-center gap-2">
                @if($isRoleParticipation)
                    <span class="inline-flex items-center rounded-full bg-violet-100 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide text-violet-900 ring-1 ring-violet-200">
                        {{ __('Peranan') }}: {{ $roleLabel }}
                    </span>
                @endif

                <span class="inline-flex items-center rounded-full {{ $eventTypeClass }} px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide ring-1">
                    {{ $eventTypeLabel }}
                </span>

                @if($isRemoteEvent)
                    <span class="inline-flex items-center rounded-full bg-sky-50 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide text-sky-800 ring-1 ring-sky-100">
                        {{ __('Dalam Talian') }}
                    </span>
                @endif

                @if($isPendingEvent)
                    <span class="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide text-amber-800 ring-1 ring-amber-100">
                        {{ __('Menunggu Kelulusan') }}
                    </span>
                @endif

                @if($isCancelledEvent)
                    <span class="inline-flex items-center rounded-full bg-rose-50 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide text-rose-800 ring-1 ring-rose-100">
                        {{ __('Dibatalkan') }}
                    </span>
                @endif
            </div>

            <h3 class="mt-3 font-heading text-xl font-bold leading-tight {{ $titleClass }} transition sm:text-2xl">
                {{ $event->title }}
            </h3>

            @if($event->reference_study_subtitle)
                <p class="mt-2 text-sm font-semibold italic text-slate-500">
                    {{ $event->reference_study_subtitle }}
                </p>
            @endif

            <div class="mt-4 grid gap-2 text-sm text-slate-500">
                <p class="flex items-center gap-2">
                    <svg class="h-4 w-4 shrink-0 {{ $accentClass }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                    <span>
                        {{ $event->timing_display !== '' ? $event->timing_display : \App\Support\Timezone\UserDateTimeFormatter::format($event->starts_at, 'h:i A') }}
                        @if($event->ends_at)
                            <span class="mx-1 text-slate-300">—</span>
                            {{ \App\Support\Timezone\UserDateTimeFormatter::format($event->ends_at, 'h:i A') }}
                        @endif
                    </span>
                </p>

                @if($eventLocation !== '' && ! $isRemoteEvent)
                    <p class="flex items-start gap-2">
                        <svg class="mt-0.5 h-4 w-4 shrink-0 {{ $accentClass }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z" />
                        </svg>
                        <span class="line-clamp-2">{{ $eventLocation }}</span>
                    </p>
                @endif
            </div>

            <div class="mt-5 flex items-center justify-between border-t border-slate-100 pt-4">
                <span class="text-xs font-semibold text-slate-400">{{ __('Lihat maklumat penuh majlis') }}</span>
                <span class="grid h-9 w-9 place-items-center rounded-full {{ $arrowClass }} transition group-hover:translate-x-1 group-hover:text-white">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-5-5 5 5-5 5" />
                    </svg>
                </span>
            </div>
        </div>
    </div>
</a>
