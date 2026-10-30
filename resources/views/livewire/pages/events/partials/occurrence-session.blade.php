@php
    $sessionStartLocal = $session->starts_at?->copy()->timezone($viewerTimezone);
    $sessionEndLocal = $session->ends_at?->copy()->timezone($viewerTimezone);
    $sessionSingleDay = $sessionStartLocal !== null && $sessionEndLocal !== null
        ? $sessionStartLocal->isSameDay($sessionEndLocal)
        : true;
    $sessionTime = $sessionStartLocal
        ? \App\Support\Timezone\UserDateTimeFormatter::format($sessionStartLocal, 'h:i A')
        : __('TBC');
    if ($sessionEndLocal) {
        $sessionTime .= ' — '.\App\Support\Timezone\UserDateTimeFormatter::format($sessionEndLocal, $sessionSingleDay ? 'h:i A' : 'j M, h:i A');
    }
    $sessionDayLabel = $sessionStartLocal
        ? \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($sessionStartLocal, 'D')
        : null;
    $sessionDateNumber = $sessionStartLocal?->format('j');
    $sessionMonthLabel = $sessionStartLocal
        ? \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($sessionStartLocal, 'M')
        : null;
    $sessionFullDate = $sessionStartLocal
        ? \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($sessionStartLocal, 'l, j F Y')
        : null;
    $sessionExpression = $detail->timeExpressionsFor($session)->first();
    $sessionPeople = $session->relationLoaded('involvements')
        ? $session->involvements
            ->map(fn ($involvement): array => [
                'name' => $involvement->display_name ?: $involvement->involveable?->name,
                'role' => $involvement->role?->name,
            ])
            ->filter(fn (array $person): bool => filled($person['name']))
            ->unique('name')
            ->values()
        : collect();
    $sessionLocation = $detail->primaryLocationFor($session);
    $sessionLocationLabel = $detail->locationLabel($sessionLocation);
    $sessionCapacity = $detail->capacityFor($session);
    $sessionReserved = $detail->participantCount($session);
    $sessionLinks = $detail->linksFor($session);
    $sessionMaterials = $detail->materialsFor($session);
    $sessionCoverUrl = $session->getFirstMedia('cover')?->getAvailableUrl(['banner', 'thumb']);
@endphp

<li
    data-testid="event-session-{{ $session->id }}"
    class="grid gap-5 px-6 py-6 sm:grid-cols-[4.5rem_minmax(0,1fr)] sm:gap-6 {{ $isLast ? '' : 'border-b border-[#f0e8d9]' }}"
>
    <div class="flex items-start gap-4 sm:flex-col sm:items-stretch sm:gap-2.5">
        <div class="w-[4.5rem] shrink-0 rounded-[12px] bg-[#f3efe6] px-2 py-2.5 text-center">
            @if($sessionStartLocal)
                <p class="text-[10px] font-semibold uppercase tracking-[0.14em] text-[#6b645e]">{{ $sessionDayLabel }}</p>
                <p class="mt-0.5 font-heading text-[1.75rem] font-normal leading-none text-[#14342e]">{{ $sessionDateNumber }}</p>
                <p class="mt-1 text-[10px] font-semibold uppercase tracking-[0.14em] text-[#8a5f13]">{{ $sessionMonthLabel }}</p>
            @else
                <p class="font-mono text-[10px] font-semibold uppercase tracking-[0.14em] text-[#6b645e]">{{ __('TBC') }}</p>
            @endif
        </div>
        <p class="pt-1 font-mono text-[10px] uppercase tracking-[0.16em] text-[#b3a893] sm:pt-0 sm:text-center">{{ str_pad((string) $number, 2, '0', STR_PAD_LEFT) }}</p>
    </div>

    <div class="min-w-0">
        @if($sessionCoverUrl)
            <img src="{{ $sessionCoverUrl }}" alt="" class="mb-4 w-full rounded-[14px] border border-[#e6dccb] object-cover" loading="lazy">
        @endif

        <div class="flex flex-wrap items-start justify-between gap-x-5 gap-y-2">
            <h3 class="min-w-0 font-heading text-xl font-normal leading-snug text-balance text-[#14342e]">{{ $session->title ?: __('Program majlis') }}</h3>
            <time datetime="{{ $session->starts_at?->toIso8601String() }}" class="inline-flex shrink-0 items-center gap-2 rounded-full bg-[#14342e] px-3.5 py-1.5 font-mono text-[11px] tracking-[0.02em] text-[#fdfaf2]">
                <span class="size-1.5 rounded-full bg-[#f2c867]" aria-hidden="true"></span>
                {{ $sessionTime }}
            </time>
        </div>

        @if($sessionFullDate)
            <p class="mt-1.5 text-[13px] text-[#6b645e]">{{ $sessionFullDate }}</p>
        @endif

        @if($sessionExpression?->display_label)
            <p class="mt-2 text-[13px] font-semibold text-[#8a5f13]">{{ $sessionExpression->display_label }}</p>
        @endif

        @if(filled($session->summary))
            <p class="mt-3 max-w-[60ch] text-sm leading-7 text-[#4c5b56]">{{ $session->summary }}</p>
        @endif

        @if($sessionPeople->isNotEmpty())
            <ul class="mt-4 flex flex-wrap gap-2">
                @foreach($sessionPeople as $person)
                    <li class="inline-flex items-center gap-2 rounded-full bg-[#f3efe6] py-1 pl-1 pr-3 text-[12px]">
                        <span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-[#14342e] font-heading text-[10px] text-[#f2c867]" aria-hidden="true">{{ mb_strtoupper(mb_substr((string) $person['name'], 0, 1)) }}</span>
                        <span class="font-semibold text-[#14342e]">{{ $person['name'] }}</span>
                        @if($person['role'])
                            <span class="text-[11px] text-[#6b645e]">{{ $person['role'] }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif

        @if($sessionLocationLabel || $sessionCapacity !== null)
            <div class="mt-3.5 flex flex-wrap gap-x-5 gap-y-1 text-[12px] font-medium text-[#6b645e]">
                @if($sessionLocationLabel)
                    <span class="inline-flex items-center gap-1.5">
                        <svg class="size-3.5 text-[#c9962f]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" /></svg>
                        {{ $sessionLocationLabel }}
                    </span>
                @endif
                @if($sessionCapacity !== null)
                    <span class="inline-flex items-center gap-1.5">
                        <svg class="size-3.5 text-[#c9962f]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.06 9.06 0 01-6 0m6 0a9.06 9.06 0 006-8.128 9.06 9.06 0 00-18 0 9.06 9.06 0 006 8.128m6 0a9.06 9.06 0 01-6 0m3-11.25a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                        {{ $sessionReserved }} / {{ $sessionCapacity }} {{ __('tempat') }}
                    </span>
                @endif
            </div>
        @endif

        @if($sessionLinks->isNotEmpty() || $sessionMaterials->isNotEmpty())
            <div class="mt-4 flex flex-wrap gap-2">
                @foreach($sessionLinks as $link)
                    <a href="{{ $link->url }}" target="_blank" rel="noopener" data-signal-event="navigation.external_link_clicked" data-signal-category="navigation" data-signal-component="event_detail_session" data-signal-control="event_link" data-signal-entity-type="event" data-signal-entity-id="{{ $event->id }}" class="inline-flex items-center gap-1.5 rounded-full border border-[#14342e]/15 px-3 py-1.5 text-[12px] font-semibold text-[#14342e] transition duration-300 ease-[cubic-bezier(0.22,1,0.36,1)] hover:border-[#c9962f]/60 hover:bg-[#faf6ed]">
                        {{ $detail->linkTypeLabel($link) }}
                        <svg class="size-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M7 17L17 7M9 7h8v8" /></svg>
                    </a>
                @endforeach
                @foreach($sessionMaterials as $material)
                    <a href="{{ $material->url }}" target="_blank" rel="noopener" data-signal-event="navigation.external_link_clicked" data-signal-category="navigation" data-signal-component="event_detail_session" data-signal-control="material" data-signal-entity-type="event" data-signal-entity-id="{{ $event->id }}" class="inline-flex items-center gap-1.5 rounded-full border border-[#14342e]/15 px-3 py-1.5 text-[12px] font-semibold text-[#14342e] transition duration-300 ease-[cubic-bezier(0.22,1,0.36,1)] hover:border-[#c9962f]/60 hover:bg-[#faf6ed]">
                        {{ $material->title }}
                        <svg class="size-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M7 17L17 7M9 7h8v8" /></svg>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</li>
