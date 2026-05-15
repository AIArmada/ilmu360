<?php

use App\Enums\PrayerReference;
use App\Models\Event;
use App\Support\Timezone\UserDateTimeFormatter;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    #[Computed]
    public function currentPrayerPeriod(): array
    {
        $now = UserDateTimeFormatter::userNow()->hour;

        if ($now >= 5 && $now < 7) {
            return ['key' => 'subuh', 'label' => 'Subuh Hari Ini', 'prayer' => PrayerReference::Fajr];
        } elseif ($now >= 7 && $now < 11) {
            return ['key' => 'dhuha', 'label' => 'Pagi & Dhuha', 'prayer' => null];
        } elseif ($now >= 11 && $now < 15) {
            return ['key' => 'zohor', 'label' => 'Zohor Hari Ini', 'prayer' => PrayerReference::Dhuhr];
        } elseif ($now >= 15 && $now < 18) {
            return ['key' => 'asar', 'label' => 'Asar Hari Ini', 'prayer' => PrayerReference::Asr];
        } elseif ($now >= 18 && $now < 20) {
            return ['key' => 'maghrib', 'label' => 'Maghrib Malam Ini', 'prayer' => PrayerReference::Maghrib];
        } elseif ($now >= 20 && $now < 24) {
            return ['key' => 'isyak', 'label' => 'Isyak Malam Ini', 'prayer' => PrayerReference::Isha];
        } else {
            return ['key' => 'subuh_next', 'label' => 'Subuh Esok', 'prayer' => PrayerReference::Fajr];
        }
    }

    #[Computed]
    public function upcomingEvents(): Collection
    {
        $period = $this->currentPrayerPeriod();

        $query = Event::active()
            ->orderBy('starts_at')
            ->with(['institution', 'venue', 'speakers', 'references'])
            ->take(6);

        // Simple time-based logic for now, utilizing the UTC timestamps in DB
        // We find events starting in the next 6 hours
        $start = UserDateTimeFormatter::userNow()->setTimezone('UTC');
        $end = UserDateTimeFormatter::userNow()->addHours(8)->setTimezone('UTC');

        return $query->whereBetween('starts_at', [$start, $end])->get();
    }
};
?>

@php
    $events = $this->upcomingEvents;
    $period = $this->currentPrayerPeriod();
@endphp

<section class="py-12 bg-white">
    <div class="container mx-auto px-6 lg:px-12">
        <div class="flex items-center justify-between mb-8">
            <div>
                <span class="text-emerald-600 font-bold uppercase tracking-wider text-sm">{{ __('Jadual Terkini') }}</span>
                <h2 class="text-3xl font-heading font-bold text-slate-900 mt-1">
                    {{ $period['label'] }}
                </h2>
            </div>
            <a href="{{ route('events.index') }}" wire:navigate class="text-slate-500 hover:text-emerald-600 font-medium transition-colors">
                {{ __('Lihat Semua Jadual') }} &rarr;
            </a>
        </div>

        @if($events->isEmpty())
            <div class="p-12 rounded-3xl bg-slate-50 border border-slate-100 text-center">
                <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-slate-100 mb-4">
                    <svg class="w-8 h-8 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
                <h3 class="text-lg font-bold text-slate-900 mb-2">{{ __('Tiada Kuliah Terdekat') }}</h3>
                <p class="text-slate-500 max-w-md mx-auto">{{ __('Belum ada kuliah dijadualkan untuk waktu ini. Cuba lihat kuliah waktu lain.') }}</p>
                <a href="{{ route('events.index') }}" class="inline-block mt-4 text-emerald-600 font-semibold hover:underline">{{ __('Semak Jadual Penuh') }}</a>
            </div>
        @else
            <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-6">
                @foreach($events as $event)
                    <a href="{{ route('events.show', $event) }}" wire:navigate class="group bg-slate-50 hover:bg-white border border-slate-100 hover:border-emerald-100 rounded-2xl p-5 transition-all hover:shadow-lg hover:-translate-y-1">
                        <div class="flex items-start justify-between mb-4">
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-white border border-slate-200 text-xs font-bold text-slate-700 shadow-sm">
                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                {{ \App\Support\Timezone\UserDateTimeFormatter::format($event->starts_at, 'h:i A') }}
                            </span>
                            @if($event->is_featured)
                                <span class="text-amber-500">
                                    <svg class="w-5 h-5 fill-current" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                                </span>
                            @endif
                        </div>
                        
                        <h3 class="font-heading font-bold text-lg text-slate-900 mb-2 line-clamp-2 group-hover:text-emerald-700 transition-colors">
                            {{ $event->title }}
                        </h3>
                        @if($event->reference_study_subtitle)
                            <p class="-mt-1 mb-3 pl-3 text-sm font-bold italic text-slate-500">
                                {{ $event->reference_study_subtitle }}
                            </p>
                        @endif
                        
                        <div class="flex items-center gap-2 text-sm text-slate-500 mb-4">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                            </svg>
                            <span class="truncate">{{ $event->venue?->name ?? $event->institution?->name ?? 'Online' }}</span>
                        </div>
                        
                        <div class="flex items-center gap-3 pt-4 border-t border-slate-200/60">
                            @if($event->speakers->isNotEmpty())
                                <div class="flex -space-x-2">
                                    @foreach($event->speakers->take(3) as $speaker)
                                        <div class="w-8 h-8 rounded-full border-2 border-white overflow-hidden shadow-sm bg-slate-100" title="{{ $speaker->name }}">
                                            <img src="{{ $speaker->avatar_url ?: $speaker->default_avatar_url }}" alt="{{ $speaker->name }}" class="w-full h-full object-cover" width="32" height="32" loading="lazy">
                                        </div>
                                    @endforeach
                                </div>
                                <span class="text-xs font-medium text-slate-600">
                                    {{ $event->speakers->first()->name }}
                                    @if($event->speakers->count() > 1) +{{ $event->speakers->count() - 1 }} @endif
                                </span>
                            @else
                                <span class="text-xs text-slate-400 italic">{{ __('Tiada penceramah') }}</span>
                            @endif
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</section>