<?php

use App\Models\Event;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    #[Computed]
    public function events(): Collection
    {
        return Event::active()
            ->where('starts_at', '>=', now())
            ->orderBy('starts_at')
            ->with(['institution', 'venue', 'speakers', 'references'])
            ->take(9)
            ->get();
    }
};
?>

@placeholder
<section class="bg-slate-50 pt-8 pb-20">
    <div class="container mx-auto px-6 lg:px-12">
        <div class="flex items-center justify-center py-12">
            <svg class="animate-spin h-8 w-8 text-emerald-600" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor"
                    d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                </path>
            </svg>
        </div>
    </div>
</section>
@endplaceholder

<section class="bg-slate-50 pt-8 pb-20">
    <div class="container mx-auto px-6 lg:px-12">
        <div class="flex items-end justify-between mb-12">
            <div>
                <h2 class="font-heading text-3xl font-bold text-slate-900">{{ __('Majlis Akan Datang') }}</h2>
                <p class="text-slate-500 mt-2">{{ __('Sertai pertemuan ilmu berhampiran anda') }}</p>
            </div>
            <a href="{{ route('events.index') }}" wire:navigate
                class="hidden md:inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-emerald-600 text-white font-semibold hover:bg-emerald-700 transition-colors data-loading:opacity-50">
                {{ __('Lihat Semua') }}
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M17 8l4 4m0 0l-4 4m4-4H3" />
                </svg>
            </a>
        </div>

        @if($this->events->isEmpty())
            <div class="text-center py-20 rounded-3xl bg-white border border-slate-100">
                <div
                    class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-slate-100 text-slate-400 mb-4">
                    <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                    </svg>
                </div>
                <h3 class="text-lg font-bold text-slate-900">{{ __('Tiada majlis akan datang') }}</h3>
                <p class="text-slate-500 mt-2">{{ __('Sila semak semula nanti.') }}</p>
            </div>
        @else
            <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-6">
                @foreach($this->events as $event)
                    <div wire:key="upcoming-{{ $event->id }}">
                        <article
                            class="group bg-white rounded-2xl border border-slate-100 shadow-sm hover:shadow-lg hover:border-emerald-200 transition-all overflow-hidden">
                            <div class="p-6">
                                <!-- Header -->
                                <div class="flex items-start gap-4">
                                    <div
                                        class="flex-shrink-0 w-14 h-14 rounded-xl bg-emerald-50 flex flex-col items-center justify-center">
                                        <span
                                            class="text-sm font-bold text-emerald-600 uppercase">{{ \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($event->starts_at, 'M') }}</span>
                                        <span
                                            class="text-lg font-black text-emerald-700 leading-none">{{ \App\Support\Timezone\UserDateTimeFormatter::format($event->starts_at, 'd') }}</span>
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <h3
                                            class="font-bold text-slate-900 group-hover:text-emerald-600 transition-colors line-clamp-2">
                                            <a href="{{ route('events.show', $event) }}" wire:navigate>{{ $event->title }}</a>
                                        </h3>
                                        @if($event->reference_study_subtitle)
                                            <p class="mt-1 pl-3 text-sm font-bold italic text-slate-500">
                                                {{ $event->reference_study_subtitle }}
                                            </p>
                                        @endif
                                        <div class="flex items-center gap-1.5 mt-1 text-sm text-slate-500">
                                            <svg class="w-4 h-4 text-slate-400" fill="none" viewBox="0 0 24 24"
                                                stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                            <span>
                                                @if($event->isPrayerRelative() && $event->prayer_display_text)
                                                    {{ $event->prayer_display_text }}
                                                @else
                                                    {{ \App\Support\Timezone\UserDateTimeFormatter::format($event->starts_at, 'h:i A') }}
                                                @endif
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Location -->
                                <div class="mt-4 flex items-center gap-2 text-sm text-slate-500">
                                    <svg class="w-4 h-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                                    </svg>
                                    <span
                                        class="truncate">{{ $event->venue?->name ?? $event->institution?->name ?? __('Online') }}</span>
                                </div>

                                <!-- Tags -->
                                <div class="mt-4 flex flex-wrap gap-2">
                                    <span
                                        class="inline-block rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-600">
                                        {{ $event->eventType?->name ?? __('Kuliah') }}
                                    </span>
                                    @if($event->speakers->isNotEmpty())
                                        <span
                                            class="inline-block rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700">
                                            {{ $event->speakers->first()?->name }}
                                        </span>
                                    @endif
                                </div>
                            </div>

                            <!-- Footer -->
                            <div class="px-6 py-4 bg-slate-50 border-t border-slate-100 flex items-center justify-between">
                                <span class="text-xs text-slate-400">{{ $event->starts_at?->diffForHumans() }}</span>
                                <a href="{{ route('events.show', $event) }}" wire:navigate
                                    class="text-sm font-semibold text-emerald-600 hover:text-emerald-700 flex items-center gap-1">
                                    {{ __('Butiran') }}
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M9 5l7 7-7 7" />
                                    </svg>
                                </a>
                            </div>
                        </article>
                    </div>
                @endforeach
            </div>

            <!-- Mobile CTA -->
            <div class="mt-12 text-center md:hidden">
                <a href="{{ route('events.index') }}" wire:navigate
                    class="inline-flex items-center justify-center gap-2 h-12 px-8 rounded-full bg-emerald-600 text-white font-semibold shadow-lg shadow-emerald-500/20 hover:bg-emerald-700 transition-colors">
                    {{ __('Lihat Semua Majlis') }}
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M17 8l4 4m0 0l-4 4m4-4H3" />
                    </svg>
                </a>
            </div>
        @endif
    </div>
</section>