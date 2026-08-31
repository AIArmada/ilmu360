@php
    $multiOccurrence = $multiOccurrence ?? false;
    $occurrenceSessions = $detail->sessionsFor($occurrence);
    $occurrenceOwnCover = $occurrence->getFirstMedia('cover');
    $occurrenceOwnCoverUrl = $occurrenceOwnCover?->getAvailableUrl(['banner', 'thumb']) ?? '';
    $occurrenceCoverUrl = $occurrenceOwnCoverUrl !== ''
        ? $occurrenceOwnCoverUrl
        : ($eventCoverUrl !== '' ? $eventCoverUrl : null);
    $occurrenceCoverIsInherited = $occurrenceOwnCoverUrl === '' && $occurrenceCoverUrl !== null;
    $occurrenceExpression = $detail->timeExpressionsFor($occurrence)->first();
    $occurrenceTime = $occurrence->starts_at
        ? \App\Support\Timezone\UserDateTimeFormatter::format($occurrence->starts_at, 'h:i A')
        : __('TBC');
    if ($occurrence->ends_at) {
        $occurrenceTime .= ' — ' . \App\Support\Timezone\UserDateTimeFormatter::format($occurrence->ends_at, 'h:i A');
    }
    $occurrenceLocation = $detail->primaryLocationFor($occurrence);
    $occurrenceLocationLabel = $detail->locationLabel($occurrenceLocation);
    $occurrenceCapacity = $detail->capacityFor($occurrence);
    $occurrenceReserved = $detail->participantCount($occurrence);
    $occurrenceLinks = $detail->linksFor($occurrence);
    $occurrenceMaterials = $detail->materialsFor($occurrence);
    $occurrenceTitle = trim((string) ($occurrence->title ?: $event->title));
@endphp

<article data-testid="event-occurrence-{{ $occurrence->id }}" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
    <div class="grid md:grid-cols-[190px_minmax(0,1fr)]">
        <div class="relative min-h-48 overflow-hidden bg-[#173c34]">
            @if($occurrenceCoverUrl)
                <img src="{{ $occurrenceCoverUrl }}" alt="{{ $occurrenceTitle }}" class="size-full min-h-48 object-cover" loading="lazy">
                <div class="absolute inset-0 bg-gradient-to-t from-[#0c211e]/85 via-[#0c211e]/10 to-transparent"></div>
                <div class="absolute inset-x-4 bottom-4 text-white">
                    <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-[#f2c867]">{{ __('Tarikh') }}</p>
                    @if($occurrence->starts_at)
                        <p class="mt-1 font-heading text-3xl font-semibold leading-none">{{ \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($occurrence->starts_at, 'd M') }}</p>
                        <p class="mt-1 text-sm text-white/70">{{ \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($occurrence->starts_at, 'Y') }}</p>
                    @else
                        <p class="mt-1 font-heading text-2xl font-semibold">{{ __('TBC') }}</p>
                    @endif
                    @if($occurrenceCoverIsInherited)<span class="mt-3 inline-flex rounded-full bg-black/25 px-2 py-1 text-[10px] font-bold uppercase tracking-[0.12em] backdrop-blur-sm">{{ __('Majlis') }}</span>@endif
                </div>
            @else
                <div class="flex min-h-48 h-full flex-col justify-between bg-[radial-gradient(circle_at_top_right,_rgba(242,200,103,0.35),_transparent_48%),linear-gradient(145deg,#173c34,#285b4e)] p-5 text-white">
                    <div class="flex items-center justify-between"><span class="font-mono text-xs font-bold tracking-[0.18em] text-[#f2c867]">●</span><span class="size-2 rounded-full bg-[#f2c867]"></span></div>
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-[#f2c867]">{{ __('Tarikh') }}</p>
                        @if($occurrence->starts_at)<p class="mt-2 font-heading text-3xl font-semibold leading-none">{{ \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($occurrence->starts_at, 'd M') }}</p><p class="mt-1 text-sm text-white/70">{{ \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($occurrence->starts_at, 'Y') }}</p>@else<p class="mt-2 font-heading text-2xl font-semibold">{{ __('TBC') }}</p>@endif
                    </div>
                </div>
            @endif
        </div>

        <div class="p-5 sm:p-6">
            <div class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-100 pb-4">
                <div class="min-w-0">
                    <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-[#b27b1b]">{{ $multiOccurrence ? __('Tarikh program') : __('Program') }}</p>
                    <h3 class="mt-2 font-heading text-2xl font-semibold leading-tight tracking-tight text-[#173c34]">{{ $occurrenceTitle }}</h3>
                    <time class="mt-2 block text-sm font-semibold text-slate-600" datetime="{{ $occurrence->starts_at?->toIso8601String() }}">{{ $occurrenceTime }}</time>
                    @if($occurrenceExpression?->display_label)<p class="mt-1 text-sm font-semibold text-[#b27b1b]">{{ $occurrenceExpression->display_label }}</p>@endif
                </div>
                @if($occurrence->status)<span class="rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-bold text-slate-600">{{ \Illuminate\Support\Str::headline((string) $occurrence->status) }}</span>@endif
            </div>

            @if($occurrenceLocationLabel || $occurrenceCapacity !== null)
                <div class="mt-4 flex flex-wrap gap-x-4 gap-y-2 text-xs font-semibold text-slate-500">
                    @if($occurrenceLocationLabel)<span class="inline-flex items-center gap-1.5"><svg class="size-3.5 text-[#b27b1b]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" /></svg>{{ $occurrenceLocationLabel }}</span>@endif
                    @if($occurrenceCapacity !== null)<span class="inline-flex items-center gap-1.5"><svg class="size-3.5 text-[#b27b1b]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 7.5A2.5 2.5 0 016.5 5h11A2.5 2.5 0 0120 7.5v9a2.5 2.5 0 01-2.5 2.5h-11A2.5 2.5 0 014 16.5v-9z" /><path stroke-linecap="round" stroke-linejoin="round" d="M8 9.5h.01M8 14.5h.01M12 9.5h4M12 14.5h4" /></svg>{{ $occurrenceReserved }} / {{ $occurrenceCapacity }} {{ __('tempat') }}</span>@endif
                </div>
            @endif

            @if($occurrenceSessions->count() === 1)
                <div class="mt-5">
                    @include('livewire.pages.events.partials.schedule-session', ['session' => $occurrenceSessions->first(), 'inheritedCoverUrl' => $occurrenceCoverUrl, 'compactSession' => true])
                </div>
            @elseif($occurrenceSessions->isNotEmpty())
                <div class="mt-5 space-y-3">
                    <div class="flex items-center justify-between gap-3">
                        <p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-400">{{ __('Atur cara') }}</p>
                        <span class="font-mono text-xs text-slate-400">{{ $occurrenceSessions->count() }} {{ __('segmen') }}</span>
                    </div>
                    @foreach($occurrenceSessions as $session)
                        @include('livewire.pages.events.partials.schedule-session', ['session' => $session, 'inheritedCoverUrl' => $occurrenceCoverUrl, 'compactSession' => true])
                    @endforeach
                </div>
            @endif

            @if($occurrenceLinks->isNotEmpty() || $occurrenceMaterials->isNotEmpty())
                <div class="mt-5 flex flex-wrap gap-2 border-t border-slate-100 pt-4">
                    @foreach($occurrenceLinks as $link)
                        <a href="{{ $link->url }}" target="_blank" rel="noopener" data-signal-event="navigation.external_link_clicked" data-signal-category="navigation" data-signal-component="event_detail_occurrence" data-signal-control="event_link" data-signal-entity-type="event" data-signal-entity-id="{{ $event->id }}" class="inline-flex items-center gap-1 rounded-full border border-[#173c34]/15 bg-[#fbfaf7] px-2.5 py-1.5 text-xs font-bold text-[#173c34] transition hover:border-[#b27b1b]/50 hover:text-[#b27b1b]">{{ $detail->linkTypeLabel($link) }} <span aria-hidden="true">↗</span></a>
                    @endforeach
                    @foreach($occurrenceMaterials as $material)
                        <a href="{{ $material->url }}" target="_blank" rel="noopener" data-signal-event="navigation.external_link_clicked" data-signal-category="navigation" data-signal-component="event_detail_occurrence" data-signal-control="material" data-signal-entity-type="event" data-signal-entity-id="{{ $event->id }}" class="inline-flex items-center gap-1 rounded-full border border-[#173c34]/15 bg-[#fbfaf7] px-2.5 py-1.5 text-xs font-bold text-[#173c34] transition hover:border-[#b27b1b]/50 hover:text-[#b27b1b]">{{ $material->title }} <span aria-hidden="true">↗</span></a>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</article>
