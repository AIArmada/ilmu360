<?php

use App\Models\Event;
use App\Support\Timezone\UserDateTimeFormatter;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    #[Computed]
    public function events(): Collection
    {
        $now = UserDateTimeFormatter::userNow();
        $start = $now->copy()->setTimezone('UTC');
        $end = $now->copy()->endOfDay()->setTimezone('UTC');

        return Event::active()
            ->whereBetween('starts_at', [$start, $end])
            ->orderBy('starts_at')
            ->with(['institution', 'venue', 'references'])
            ->take(4)
            ->get();
    }
};
?>

@php
    $hasEvents = $this->events->isNotEmpty();
    $todayDate = UserDateTimeFormatter::userNow()->toDateString();
    $todayFilterQuery = [
        'starts_after' => $todayDate,
        'starts_before' => $todayDate,
        'time_scope' => 'all',
    ];
@endphp

@placeholder
<section class="bg-gradient-to-r from-amber-500 via-orange-500 to-amber-500 py-8">
    <div class="container mx-auto px-6 lg:px-12">
        <div class="flex items-center justify-center py-8">
            <svg class="animate-spin h-8 w-8 text-white" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor"
                    d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                </path>
            </svg>
        </div>
    </div>
</section>
@endplaceholder

<section class="bg-gradient-to-r from-amber-500 via-orange-500 to-amber-500 py-8" @if(!$hasEvents)
style="display: none;" @endif>
    <div class="container mx-auto px-6 lg:px-12">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-4">
                <div class="p-3 rounded-xl bg-white/20 backdrop-blur-sm">
                    <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
                    </svg>
                </div>
                <div>
                    <h2 class="text-xl font-bold text-white">{{ __('Malam Ini') }}</h2>
                    <p class="text-white/80 text-sm">{{ $this->events->count() }} {{ __('majlis akan berlangsung') }}
                    </p>
                </div>
            </div>
            <a href="{{ route('events.index', $todayFilterQuery) }}" wire:navigate
                class="hidden sm:inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-white/20 text-white font-medium hover:bg-white/30 transition-colors">
                {{ __('Lihat Semua') }}
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                </svg>
            </a>
        </div>

        <div class="mt-6 flex gap-4 overflow-x-auto pb-4 snap-x snap-mandatory scrollbar-hide">
            @foreach($this->events as $event)
                <div wire:key="tonight-{{ $event->id }}" class="flex-shrink-0">
                    <a href="{{ route('events.show', $event) }}" wire:navigate
                        class="block w-72 bg-white rounded-xl p-4 shadow-lg hover:shadow-xl hover:-translate-y-1 transition-all snap-start">
                        <div class="flex items-start gap-3">
                            <div
                                class="flex-shrink-0 w-14 h-14 rounded-lg bg-amber-100 flex flex-col items-center justify-center">
                                <span
                                    class="text-lg font-bold text-amber-600">{{ \App\Support\Timezone\UserDateTimeFormatter::format($event->starts_at, 'h:i') }}</span>
                                <span class="text-xs text-amber-500">{{ \App\Support\Timezone\UserDateTimeFormatter::format($event->starts_at, 'A') }}</span>
                            </div>
                            <div class="min-w-0">
                                <h3 class="font-bold text-slate-900 truncate">{{ $event->title }}</h3>
                                @if($event->reference_study_subtitle)
                                    <p class="mt-1 pl-3 text-xs font-bold italic text-slate-500">
                                        {{ $event->reference_study_subtitle }}
                                    </p>
                                @endif
                                <p class="text-sm text-slate-500 truncate">
                                    {{ $event->venue?->name ?? $event->institution?->name ?? __('Online') }}
                                </p>
                            </div>
                        </div>
                    </a>
                </div>
            @endforeach
        </div>
    </div>
</section>