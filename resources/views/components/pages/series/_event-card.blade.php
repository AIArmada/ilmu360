@php
    $isPast = $past ?? false;
    $seriesCardMediaClass = 'w-full md:w-48 aspect-[16/9]';
@endphp

<article
    class="flex flex-col md:flex-row gap-6 bg-white rounded-3xl p-6 border border-slate-100 shadow-sm hover:shadow-xl hover:shadow-emerald-500/10 hover:-translate-y-1 transition-all group">
    <div class="{{ $seriesCardMediaClass }} rounded-2xl bg-slate-100 relative overflow-hidden flex-shrink-0"
        data-cover-aspect="16:9">
        @if($event->card_image_url)
            <img src="{{ $event->card_image_url }}"
                class="w-full h-full group-hover:scale-105 transition-transform duration-500 object-cover {{ $isPast ? 'grayscale' : '' }}">
        @else
            <div
                class="w-full h-full flex items-center justify-center bg-gradient-to-br {{ $isPast ? 'from-slate-100 to-slate-200' : 'from-emerald-50 to-teal-50' }}">
                <flux:icon.photo class="size-12 opacity-30" />
            </div>
        @endif
        <div
            class="absolute top-2 left-2 inline-flex flex-col items-center justify-center bg-white/90 backdrop-blur-sm rounded-lg px-2 py-1 shadow-sm border border-white/50 min-w-[3rem]">
            <span
                class="text-xs font-bold text-slate-400 uppercase tracking-wider">{{ \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($event->starts_at, 'M') }}</span>
            <span
                class="text-lg font-black text-slate-900 leading-none">{{ \App\Support\Timezone\UserDateTimeFormatter::format($event->starts_at, 'd') }}</span>
        </div>
        @if($isPast)
            <div
                class="absolute bottom-2 left-2 inline-flex items-center gap-1 bg-slate-900/70 text-white text-xs font-bold px-2 py-1 rounded-full backdrop-blur-sm">
                <flux:icon.check class="size-3" />
                {{ __('Selesai') }}
            </div>
        @endif
    </div>

    <div class="flex-grow flex flex-col justify-center">
        <h3
            class="font-heading text-xl font-bold mb-2 {{ $isPast ? 'text-slate-600 group-hover:text-slate-800' : 'text-slate-900 group-hover:text-emerald-600' }} transition-colors">
            <a href="{{ route('events.show', $event) }}" wire:navigate>{{ $event->title }}</a>
        </h3>
        @if($event->reference_study_subtitle)
            <p class="-mt-1 mb-3 pl-3 text-sm font-bold italic text-slate-500">
                {{ $event->reference_study_subtitle }}
            </p>
        @endif
        <div class="flex flex-wrap items-center gap-x-4 gap-y-1.5 text-sm text-slate-500 mb-4">
            <span class="flex items-center gap-1.5">
                <flux:icon.clock class="size-4 {{ $isPast ? 'text-slate-400' : 'text-emerald-500' }}" />
                {{ \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($event->starts_at, 'l, j M Y · h:i A') }}
            </span>

            @php
                $locationName = $event->resolvedLocationName();
                $locationAddress = $event->resolvedLocationAddress();
                $locationSubtitle = \App\Support\Location\AddressHierarchyFormatter::format($locationAddress);
            @endphp

            @if($locationName)
                <span class="flex items-center gap-1.5">
                    <flux:icon.map-pin class="size-4 {{ $isPast ? 'text-slate-400' : 'text-emerald-500' }}" />
                    {{ $locationName }}{{ $locationSubtitle ? ', ' . $locationSubtitle : '' }}
                </span>
            @endif
        </div>

        <p class="text-slate-600 line-clamp-2 mb-4">{{ Str::limit(strip_tags($event->description), 120) }}</p>

        <div class="mt-auto">
            <a href="{{ route('events.show', $event) }}" wire:navigate
                class="text-sm font-bold inline-flex items-center gap-1 transition-all {{ $isPast ? 'text-slate-500 hover:text-slate-700' : 'text-emerald-600 hover:text-emerald-700' }}">
                {{ __('View Details') }} &rarr;
            </a>
        </div>
    </div>
</article>
