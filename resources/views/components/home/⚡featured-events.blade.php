<?php

use App\Models\Event;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    #[Computed]
    public function events(): Collection
    {
        $now = now();

        return Event::active()
            ->where('starts_at', '>=', $now)
            ->where('starts_at', '<=', $now->copy()->addDays(7))
            ->orderByDesc('is_featured')
            ->orderByRaw('(going_count * 5 + saves_count * 2 + views_count * 0.1) DESC')
            ->orderBy('starts_at')
            ->with([
                'references',
                'media' => fn ($query) => $query
                    ->where('collection_name', 'cover')
                    ->ordered(),
                'institution.media' => fn ($query) => $query
                    ->where('collection_name', 'logo')
                    ->ordered(),
                'speakers.media' => fn ($query) => $query
                    ->where('collection_name', 'avatar')
                    ->ordered(),
            ])
            ->take(8)
            ->get();
    }
};
?>

@php
    $hasEvents = $this->events->isNotEmpty();
@endphp

@placeholder
<section class="bg-slate-50 pt-20 pb-8">
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

<section class="bg-slate-50 pt-20 pb-8" @if(!$hasEvents) style="display: none;" @endif>
    <div class="container mx-auto px-6 lg:px-12">
        <div class="text-center mb-12">
            <span
                class="inline-flex items-center gap-2 rounded-full bg-emerald-100 text-emerald-700 px-4 py-1 text-sm font-medium mb-4">
                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                    <path
                        d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                </svg>
                {{ __('Pilihan Minggu Ini') }}
            </span>
            <h2 class="font-heading text-3xl lg:text-4xl font-bold text-slate-900">{{ __('Majlis Pilihan') }}</h2>
            <p class="text-slate-500 mt-3 max-w-xl mx-auto">
                {{ __('Kuliah dan majlis ilmu yang paling diminati minggu ini') }}
            </p>
        </div>

        <!-- Carousel -->
        <div class="relative" x-data="{ scroll: 0 }">
            <div class="flex gap-6 overflow-x-auto pb-6 snap-x snap-mandatory scrollbar-hide" id="featuredCarousel">
                @foreach($this->events as $event)
                    @php
                        $eventCoverAspectClass = 'aspect-[16/9]';
                    @endphp
                    <div wire:key="featured-{{ $event->id }}" class="flex-shrink-0">
                        <article class="w-80 lg:w-96 snap-start">
                            <div
                                class="group bg-white rounded-3xl overflow-hidden shadow-lg hover:shadow-xl transition-shadow border border-slate-100 h-full flex flex-col">
                                <!-- Image -->
                                <div
                                    class="relative overflow-hidden bg-slate-100 {{ $eventCoverAspectClass }}"
                                    data-cover-aspect="16:9">
                                    <img src="{{ $event->card_image_url }}" alt="{{ $event->title }}" loading="lazy"
                                        class="w-full h-full transition-transform duration-500 group-hover:scale-110 object-cover">
                                    <!-- Gradient Overlay -->
                                    <div class="absolute inset-0 bg-gradient-to-t from-black/40 to-transparent"></div>
                                    @if($event->isPrayerRelative() && $event->prayer_display_text)
                                        <div
                                            class="absolute top-4 right-4 bg-emerald-500 text-white text-xs font-bold px-2 py-1 rounded-full">
                                            {{ $event->prayer_display_text }}
                                        </div>
                                    @endif
                                </div>

                                <div class="p-5 flex flex-col flex-grow">
                                    <div class="mb-3 flex items-start justify-between gap-3" data-testid="homepage-featured-card-meta-row">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span
                                                class="inline-block rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700">
                                                {{ $event->eventType?->name ?? __('Kuliah') }}
                                            </span>
                                            @if($event->gender && $event->gender->value !== 'all')
                                                <span
                                                    class="inline-block rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-600">
                                                    {{ $event->gender->getLabel() }}
                                                </span>
                                            @endif
                                        </div>

                                        <div
                                            class="shrink-0 rounded-2xl border border-slate-200 bg-slate-50 px-3 py-2 text-center shadow-sm"
                                            data-testid="homepage-featured-card-date-badge">
                                            <div class="text-[11px] font-bold uppercase tracking-[0.18em] text-slate-500">
                                                {{ \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($event->starts_at, 'M') }}
                                            </div>
                                            <div class="mt-1 text-xl font-black leading-none text-slate-900">
                                                {{ \App\Support\Timezone\UserDateTimeFormatter::format($event->starts_at, 'd') }}
                                            </div>
                                        </div>
                                    </div>

                                    <h3 class="font-heading text-lg font-bold text-slate-900 line-clamp-2 mb-2">
                                        <a href="{{ route('events.show', $event) }}" wire:navigate
                                            class="hover:text-emerald-600 transition-colors" data-testid="homepage-featured-card-title-link">
                                            {{ $event->title }}
                                        </a>
                                    </h3>
                                    @if($event->reference_study_subtitle)
                                        <p class="-mt-0.5 mb-2 pl-3 text-sm font-bold italic text-slate-500">
                                            {{ $event->reference_study_subtitle }}
                                        </p>
                                    @endif

                                    @if($event->speakers->isNotEmpty())
                                        <p class="text-sm text-slate-500 mb-3 truncate">
                                            <span class="text-emerald-600">●</span> {{ $event->speakers->first()?->name }}
                                        </p>
                                    @endif

                                    <div class="mt-auto pt-4 border-t border-slate-100 flex items-center justify-between">
                                        <div class="flex items-center gap-1.5 text-sm text-slate-500">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                                            </svg>
                                            <span
                                                class="truncate max-w-[120px]">{{ $event->venue?->name ?? $event->institution?->name ?? __('Online') }}</span>
                                        </div>
                                        <span class="text-sm font-semibold text-emerald-600">
                                            {{ \App\Support\Timezone\UserDateTimeFormatter::format($event->starts_at, 'h:i A') }}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </article>
                    </div>
                @endforeach
            </div>
            <button onclick="document.getElementById('featuredCarousel').scrollBy({left: -400, behavior: 'smooth'})"
                class="hidden lg:flex absolute left-0 top-1/2 -translate-y-1/2 -translate-x-4 w-12 h-12 items-center justify-center rounded-full bg-white shadow-lg hover:shadow-xl hover:scale-110 transition-all z-10">
                <svg class="w-5 h-5 text-slate-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                </svg>
            </button>
            <button onclick="document.getElementById('featuredCarousel').scrollBy({left: 400, behavior: 'smooth'})"
                class="hidden lg:flex absolute right-0 top-1/2 -translate-y-1/2 translate-x-4 w-12 h-12 items-center justify-center rounded-full bg-white shadow-lg hover:shadow-xl hover:scale-110 transition-all z-10">
                <svg class="w-5 h-5 text-slate-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                </svg>
            </button>
        </div>
    </div>
</section>
