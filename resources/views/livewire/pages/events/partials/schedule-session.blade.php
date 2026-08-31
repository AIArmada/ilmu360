@php
    $compactSession = $compactSession ?? false;
    $sessionOwnCover = $session->getFirstMedia('cover');
    $sessionOwnCoverUrl = $sessionOwnCover?->getAvailableUrl(['banner', 'thumb']) ?? '';
    $sessionCoverUrl = $sessionOwnCoverUrl !== ''
        ? $sessionOwnCoverUrl
        : (($inheritedCoverUrl ?? $eventCoverUrl) !== '' ? ($inheritedCoverUrl ?? $eventCoverUrl) : null);
    $sessionCoverIsInherited = $sessionOwnCoverUrl === '' && $sessionCoverUrl !== null;
    $sessionExpression = $detail->timeExpressionsFor($session)->first();
    $sessionPeople = $session->relationLoaded('involvements')
        ? $session->involvements
            ->map(fn ($involvement) => $involvement->display_name ?: $involvement->involveable?->name)
            ->filter()
            ->unique()
            ->values()
        : collect();
    $sessionTime = $session->starts_at
        ? \App\Support\Timezone\UserDateTimeFormatter::format($session->starts_at, 'h:i A')
        : __('TBC');
    if ($session->ends_at) {
        $sessionTime .= ' — ' . \App\Support\Timezone\UserDateTimeFormatter::format($session->ends_at, 'h:i A');
    }
    $sessionLocation = $detail->primaryLocationFor($session);
    $sessionLocationLabel = $detail->locationLabel($sessionLocation);
    $sessionCapacity = $detail->capacityFor($session);
    $sessionReserved = $detail->participantCount($session);
    $sessionLinks = $detail->linksFor($session);
    $sessionMaterials = $detail->materialsFor($session);
@endphp

<article
    data-testid="event-session-{{ $session->id }}"
    class="grid gap-4 rounded-2xl border border-slate-200 bg-[#fbfaf7] p-3 sm:grid-cols-[112px_minmax(0,1fr)] sm:p-4 {{ $compactSession ? 'lg:grid-cols-[96px_minmax(0,1fr)]' : '' }}"
>
    <div class="relative min-h-28 overflow-hidden rounded-xl bg-[#173c34]">
        @if($sessionCoverUrl)
            <img src="{{ $sessionCoverUrl }}" alt="{{ $session->title ?: __('Program majlis') }}" class="size-full min-h-28 object-cover" loading="lazy">
            <div class="absolute inset-0 bg-gradient-to-t from-[#0c211e]/75 to-transparent"></div>
            @if($sessionCoverIsInherited)
                <span class="absolute bottom-2 left-2 rounded-full bg-black/25 px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-[0.1em] text-white backdrop-blur-sm">{{ __('Majlis') }}</span>
            @endif
        @else
            <div class="flex min-h-28 flex-col items-center justify-center bg-[radial-gradient(circle_at_30%_20%,_rgba(242,200,103,0.4),_transparent_42%),#173c34] px-3 text-center text-white">
                <span class="font-mono text-[10px] font-bold uppercase tracking-[0.18em] text-[#f2c867]">{{ __('Program') }}</span>
                <span class="mt-1 font-heading text-2xl font-semibold">{{ str_pad((string) ($session->sort_order + 1), 2, '0', STR_PAD_LEFT) }}</span>
            </div>
        @endif
    </div>

    <div class="min-w-0 border-l-2 border-[#e1b24f] pl-4 sm:pl-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-[#b27b1b]">{{ __('Program') }}</p>
                <h4 class="mt-1 font-heading text-xl font-semibold leading-tight text-[#173c34]">{{ $session->title ?: __('Program majlis') }}</h4>
            </div>
            <time class="shrink-0 rounded-full bg-white px-2.5 py-1 font-mono text-[11px] font-bold text-slate-600" datetime="{{ $session->starts_at?->toIso8601String() }}">{{ $sessionTime }}</time>
        </div>

        @if($sessionExpression?->display_label)
            <p class="mt-2 text-sm font-semibold text-[#b27b1b]">{{ $sessionExpression->display_label }}</p>
        @endif

        @if(filled($session->summary))
            <p class="mt-3 text-sm leading-6 text-slate-600">{{ $session->summary }}</p>
        @endif

        @if($sessionPeople->isNotEmpty() || $sessionLocationLabel || $sessionCapacity !== null)
            <div class="mt-4 flex flex-wrap gap-x-4 gap-y-2 text-xs font-semibold text-slate-500">
                @if($sessionPeople->isNotEmpty())
                    <span class="inline-flex items-center gap-1.5"><svg class="size-3.5 text-[#b27b1b]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.06 9.06 0 01-6 0m6 0a9.06 9.06 0 006-8.128 9.06 9.06 0 00-18 0 9.06 9.06 0 006 8.128m6 0a9.06 9.06 0 01-6 0m3-11.25a3 3 0 11-6 0 3 3 0 016 0z" /></svg>{{ $sessionPeople->implode(', ') }}</span>
                @endif
                @if($sessionLocationLabel)
                    <span class="inline-flex items-center gap-1.5"><svg class="size-3.5 text-[#b27b1b]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" /></svg>{{ $sessionLocationLabel }}</span>
                @endif
                @if($sessionCapacity !== null)
                    <span class="inline-flex items-center gap-1.5"><svg class="size-3.5 text-[#b27b1b]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 7.5A2.5 2.5 0 016.5 5h11A2.5 2.5 0 0120 7.5v9a2.5 2.5 0 01-2.5 2.5h-11A2.5 2.5 0 014 16.5v-9z" /><path stroke-linecap="round" stroke-linejoin="round" d="M8 9.5h.01M8 14.5h.01M12 9.5h4M12 14.5h4" /></svg>{{ $sessionReserved }} / {{ $sessionCapacity }} {{ __('tempat') }}</span>
                @endif
            </div>
        @endif

        @if($sessionLinks->isNotEmpty() || $sessionMaterials->isNotEmpty())
            <div class="mt-4 flex flex-wrap gap-2 border-t border-slate-200 pt-3">
                @foreach($sessionLinks as $link)
                    <a href="{{ $link->url }}" target="_blank" rel="noopener" data-signal-event="navigation.external_link_clicked" data-signal-category="navigation" data-signal-component="event_detail_session" data-signal-control="event_link" data-signal-entity-type="event" data-signal-entity-id="{{ $event->id }}" class="inline-flex items-center gap-1 rounded-full border border-[#173c34]/15 bg-white px-2.5 py-1.5 text-xs font-bold text-[#173c34] transition hover:border-[#b27b1b]/50 hover:text-[#b27b1b]">{{ $detail->linkTypeLabel($link) }} <span aria-hidden="true">↗</span></a>
                @endforeach
                @foreach($sessionMaterials as $material)
                    <a href="{{ $material->url }}" target="_blank" rel="noopener" data-signal-event="navigation.external_link_clicked" data-signal-category="navigation" data-signal-component="event_detail_session" data-signal-control="material" data-signal-entity-type="event" data-signal-entity-id="{{ $event->id }}" class="inline-flex items-center gap-1 rounded-full border border-[#173c34]/15 bg-white px-2.5 py-1.5 text-xs font-bold text-[#173c34] transition hover:border-[#b27b1b]/50 hover:text-[#b27b1b]">{{ $material->title }} <span aria-hidden="true">↗</span></a>
                @endforeach
            </div>
        @endif
    </div>
</article>
