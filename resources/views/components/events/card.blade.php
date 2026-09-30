@props([
    'event',
    'url' => null,
    'title' => null,
    'startsAt' => null,
    'timingText' => null,
    'locationPrimary' => null,
    'locationSecondary' => null,
    'isSaved' => false,
    'statusLabel' => null,
    'statusClass' => '',
    'formatLabel' => null,
    'formatClass' => '',
    'distanceKm' => null,
    'signalComponent' => 'event_card',
    'signalEntityType' => 'event',
    'signalEntityId' => null,
    'testidPrefix' => null,
    'wireKey' => null,
])

@php
    $cardUrl = $url ?? route('events.show', $event);
    $cardTitle = $title ?? $event->title;
    $cardStartsAt = $startsAt ?? $event->starts_at;
    $cardSignalEntityType = $signalEntityType;
    $cardSignalEntityId = $signalEntityId ?? $event->getKey();
    $speakerAvatarsTestId = $testidPrefix !== null ? ' data-testid="'.$testidPrefix.'-speaker-avatars"' : '';
    $speakersTestId = $testidPrefix !== null ? ' data-testid="'.$testidPrefix.'-speakers"' : '';

    $eventSpeakers = $event->persons->unique('id');
    $eventSpeakerNames = $eventSpeakers->pluck('formatted_name')->filter()->join(', ');
    $speakerAvatarItems = $eventSpeakers
        ->map(fn (\App\Models\Person $person): array => [
            'name' => trim((string) ($person->formatted_name !== '' ? $person->formatted_name : $person->name)),
            'url' => $person->public_avatar_url,
        ])
        ->filter(fn (array $avatar): bool => $avatar['name'] !== '' && $avatar['url'] !== '')
        ->values();
    $speakerAvatarOverflow = max(0, $speakerAvatarItems->count() - 3);
    $speakerAvatarItems = $speakerAvatarItems->take(3)->values();
    $eventTopics = $event->classifications
        ->whereIn('taxonomy_code', [\App\Enums\EventTaxonomyCode::Discipline->value, \App\Enums\EventTaxonomyCode::Domain->value])
        ->sortBy(fn (\AIArmada\Events\Models\EventClassification $classification): array => [
            $classification->taxonomy_code === \App\Enums\EventTaxonomyCode::Domain->value ? 0 : 1,
            (int) $classification->sort_order,
        ])
        ->map(fn (\AIArmada\Events\Models\EventClassification $classification): string => (string) ($classification->term?->name ?? $classification->term_code))
        ->filter()
        ->unique()
        ->take(3)
        ->values();
    $eventThemes = $event->classifications
        ->where('taxonomy_code', \App\Enums\EventTaxonomyCode::Issue->value)
        ->sortBy('sort_order')
        ->map(fn (\AIArmada\Events\Models\EventClassification $classification): string => (string) ($classification->term?->name ?? $classification->term_code))
        ->filter()
        ->unique()
        ->take(2)
        ->values();
    $eventKitab = $event->reference_study_subtitle;
    $eventCardImageAspectRatio = $event->card_image_aspect_ratio;
    $eventCardFrameClass = $eventCardImageAspectRatio === '3:4' ? 'aspect-[3/4] sm:aspect-video' : 'aspect-video';
    $eventDate = $cardStartsAt ? \App\Support\Timezone\UserDateTimeFormatter::format($cardStartsAt, 'd') : '--';
    $eventMonth = $cardStartsAt ? \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($cardStartsAt, 'M') : '';
    $eventTime = $timingText !== null ? (string) $timingText : ($cardStartsAt ? (string) $event->timing_display : '');
    $viewerTimezone = \App\Support\Timezone\UserDateTimeFormatter::resolveTimezone();

    if ($timingText === null
        && $eventTime !== ''
        && $cardStartsAt instanceof \Carbon\CarbonInterface
        && $event->ends_at instanceof \Carbon\CarbonInterface
        && $event->ends_at->gt($cardStartsAt)
        && $event->ends_at->copy()->timezone($viewerTimezone)->isSameDay($cardStartsAt->copy()->timezone($viewerTimezone))
    ) {
        $eventTime .= ' — '.\App\Support\Timezone\UserDateTimeFormatter::format($event->ends_at, 'g:i A');
    }

    if ($locationPrimary !== null) {
        $eventLocation = $locationPrimary;
        $eventLocationSubtitle = $locationSecondary ?? '';
    } else {
        $eventLocation = $event->institution?->name ?? __('Seluruh Malaysia');
        $eventLocationSubtitle = \App\Support\Location\AddressHierarchyFormatter::format($event->institution?->primaryAddress(), ['city', 'state']);
    }
@endphp

<article wire:key="{{ $wireKey ?? 'event-card-'.$event->getKey() }}" class="group relative flex flex-col overflow-hidden rounded-2xl border border-emerald-200 bg-white shadow-[0_12px_30px_-18px_rgba(24,53,43,0.35)] transition hover:-translate-y-1 hover:border-amber-200 hover:bg-amber-100 hover:shadow-[0_20px_38px_-18px_rgba(217,165,20,0.35)]"><a href="{{ $cardUrl }}" wire:navigate class="flex flex-1 flex-col after:absolute after:inset-0"><div class="relative {{ $eventCardFrameClass }} overflow-hidden bg-[#dfe9df]" data-cover-aspect="{{ $eventCardImageAspectRatio }}">@if($eventCardImageAspectRatio !== '16:9')<img src="{{ $event->card_image_url }}" alt="" aria-hidden="true" loading="lazy" class="absolute inset-0 h-full w-full scale-110 object-cover opacity-70 blur-xl"><span class="absolute inset-0 bg-black/15" aria-hidden="true"></span>@endif<img src="{{ $event->card_image_url }}" alt="{{ $cardTitle }}" loading="lazy" class="relative h-full w-full {{ $eventCardImageAspectRatio === '16:9' ? 'object-cover' : 'object-contain' }}"><span class="absolute left-3 top-3 rounded-full bg-[#087f59] px-2.5 py-1 text-[0.63rem] font-bold text-white shadow" data-testid="event-card-type-badge">{{ $event->eventType?->name ?? __('Kuliah') }}</span>@if($formatLabel !== null)<span class="absolute bottom-3 left-3 rounded-lg px-2.5 py-1.5 text-[11px] font-bold shadow-sm {{ $formatClass }}">{{ $formatLabel }}</span>@endif@if($distanceKm !== null)<span class="absolute right-3 top-3 rounded-full bg-white/90 px-2.5 py-1 text-xs font-bold text-emerald-800 shadow-sm backdrop-blur">{{ number_format((float) $distanceKm, 1) }} km</span>@endif</div><div class="px-4 pt-4 pb-4"><div class="flex items-center gap-3"><div class="flex h-14 w-12 shrink-0 flex-col items-center justify-center rounded-xl bg-[#f2f8f1] text-[#087f59]" data-testid="event-card-date-badge"><span class="text-[0.58rem] font-bold uppercase tracking-widest">{{ $eventMonth }}</span><span class="font-heading text-xl font-bold leading-none">{{ $eventDate }}</span></div><div class="min-w-0"><h3 class="line-clamp-2 font-heading text-base font-bold leading-tight text-[#142f28]">{{ $cardTitle }}</h3></div></div>@if(filled($statusLabel) || $eventTopics->isNotEmpty() || $eventThemes->isNotEmpty())
<div class="mt-3 flex flex-wrap items-center gap-1.5">@if(filled($statusLabel))<span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[0.68rem] font-bold {{ $statusClass }}">{{ $statusLabel }}</span>@endif
@foreach($eventTopics as $eventTopic)
<span class="inline-flex items-center rounded-full bg-[#f2f8f1] px-2 py-0.5 text-[0.68rem] font-bold text-[#087f59]">{{ $eventTopic }}</span>
@endforeach
@foreach($eventThemes as $eventTheme)
<span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-[0.68rem] font-bold text-slate-600">{{ $eventTheme }}</span>
@endforeach
</div>
@endif<div class="mt-4 space-y-2 text-sm text-[#56716a]">@if(filled($eventKitab))<p class="flex items-start gap-2"><flux:icon.book-open class="mt-0.5 size-3.5 shrink-0 text-[#087f59]" /><span class="line-clamp-2">{{ $eventKitab }}</span></p>@endif<p class="flex items-start gap-2"><flux:icon.map-pin class="mt-0.5 size-3.5 shrink-0 text-[#087f59]" /><span class="line-clamp-2">{{ $eventLocation }}{{ $eventLocationSubtitle !== '' ? ', '.$eventLocationSubtitle : '' }}</span></p><p class="flex items-center gap-2">@if($speakerAvatarItems->isNotEmpty())<span class="flex shrink-0 -space-x-2"{!! $speakerAvatarsTestId !!} aria-label="{{ __('Penceramah') }}">@foreach($speakerAvatarItems as $avatar)<img src="{{ $avatar['url'] }}" alt="{{ $avatar['name'] }}" title="{{ $avatar['name'] }}" loading="lazy" class="h-9 w-9 rounded-full object-cover ring-2 ring-white shadow-sm" />@endforeach@if($speakerAvatarOverflow > 0)<span class="flex h-9 w-9 items-center justify-center rounded-full bg-[#f2f8f1] text-xs font-bold text-[#087f59] ring-2 ring-white shadow-sm">+{{ $speakerAvatarOverflow }}</span>@endif</span>@else<flux:icon.user class="size-5 shrink-0 text-[#087f59]" />@endif<span{!! $speakersTestId !!} class="line-clamp-2">{{ $eventSpeakerNames !== '' ? $eventSpeakerNames : __('Penceramah jemputan') }}</span></p></div></div></a><div class="mt-auto flex items-center justify-between gap-3 border-t border-slate-100 px-4 pb-4 pt-4 group-hover:border-amber-200"><p class="flex min-w-0 items-center gap-2"><flux:icon.clock class="size-4 shrink-0 text-[#087f59]" /><span class="truncate text-sm font-semibold text-[#087f59]">{{ $eventTime }}</span></p><div class="flex shrink-0 items-center gap-2"><button type="button" x-data="{ copied: false, async share() { const url = @js($cardUrl); const title = @js($cardTitle); if (navigator.share) { try { await navigator.share({ url, title, text: title }); return; } catch (error) {} } if (! navigator.clipboard) { window.prompt(@js(__('Copy this link:')), url); return; } await navigator.clipboard.writeText(url); this.copied = true; setTimeout(() => this.copied = false, 1800); } }" @click="share()" aria-label="{{ __('Kongsi') }}" title="{{ __('Kongsi') }}" data-signal-event="share.event_clicked" data-signal-category="share" data-signal-component="{{ $signalComponent }}" data-signal-control="share_event" data-signal-entity-type="{{ $cardSignalEntityType }}" data-signal-entity-id="{{ $cardSignalEntityId }}" class="relative z-10 grid h-9 w-9 shrink-0 place-items-center rounded-xl border border-slate-200 bg-white text-slate-400 transition-colors duration-200 group-hover:border-emerald-200 group-hover:text-[#087f59] hover:border-emerald-200 hover:bg-emerald-100 hover:text-[#087f59] hover:shadow-sm"><flux:icon.share class="size-4" x-show="!copied" /><flux:icon.check class="size-4 text-emerald-700" x-show="copied" x-cloak /></button><button type="button" wire:click.stop.prevent="toggleSave('{{ $event->getKey() }}')" wire:loading.attr="disabled" data-save-icon="event" data-save-state="{{ $isSaved ? 'saved' : 'unsaved' }}" aria-label="{{ $isSaved ? __('Disimpan') : __('Simpan') }}" aria-pressed="{{ $isSaved ? 'true' : 'false' }}" title="{{ $isSaved ? __('Disimpan') : __('Simpan') }}" data-signal-event="engagement.event_save_clicked" data-signal-category="engagement" data-signal-component="{{ $signalComponent }}" data-signal-control="save" data-signal-entity-type="{{ $cardSignalEntityType }}" data-signal-entity-id="{{ $cardSignalEntityId }}" data-signal-props='@json(['currently_saved' => $isSaved])' class="relative z-10 grid h-9 w-9 shrink-0 place-items-center rounded-xl border transition-colors duration-200 disabled:cursor-wait disabled:opacity-60 {{ $isSaved ? 'border-[#087f59] bg-[#087f59] text-white' : 'border-slate-200 bg-white text-slate-400 group-hover:border-emerald-200 group-hover:text-[#087f59] hover:border-emerald-200 hover:bg-emerald-100 hover:text-[#087f59] hover:shadow-sm' }}"><flux:icon.bookmark class="size-4" :variant="$isSaved ? 'solid' : 'outline'" /></button></div></div></article>
