<?php

use App\Models\Event;
use App\Models\Speaker;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;
use App\Support\Timezone\UserDateTimeFormatter;

new class extends Component {
    #[Computed]
    public function nextGoingEvent(): ?Event
    {
        $user = auth()->user();

        if (!$user) {
            return null;
        }

        return $user->goingEvents()
            ->with(['institution', 'venue', 'speakers'])
            ->where('starts_at', '>=', now())
            ->orderBy('starts_at')
            ->first();
    }

    #[Computed]
    public function upcomingGoingEvents(): Collection
    {
        $user = auth()->user();

        if (!$user) {
            return new Collection();
        }

        $nextId = $this->nextGoingEvent?->id;

        return $user->goingEvents()
            ->with(['institution', 'venue', 'speakers'])
            ->where('starts_at', '>=', now())
            ->when($nextId, fn($q) => $q->where('events.id', '!=', $nextId))
            ->orderBy('starts_at')
            ->take(3)
            ->get();
    }

    #[Computed]
    public function followedSpeakersEvents(): Collection
    {
        $user = auth()->user();

        if (!$user) {
            return new Collection();
        }

        $followedSpeakerIds = $user->followingSpeakers()->pluck('speakers.id');

        if ($followedSpeakerIds->isEmpty()) {
            return new Collection();
        }

        return Event::active()
            ->where('starts_at', '>=', now())
            ->whereHas('speakers', fn($q) => $q->whereIn('speakers.id', $followedSpeakerIds))
            ->with(['institution', 'venue', 'speakers'])
            ->orderBy('starts_at')
            ->take(4)
            ->get();
    }

    #[Computed]
    public function savedEvents(): Collection
    {
        $user = auth()->user();

        if (!$user) {
            return new Collection();
        }

        return $user->savedEvents()
            ->with(['institution', 'venue', 'speakers'])
            ->where('starts_at', '>=', now())
            ->orderBy('starts_at')
            ->take(3)
            ->get();
    }

    #[Computed]
    public function counts(): array
    {
        $user = auth()->user();

        if (!$user) {
            return ['going' => 0, 'saved' => 0, 'following' => 0];
        }

        return [
            'going' => $user->goingEvents()->where('starts_at', '>=', now())->count(),
            'saved' => $user->savedEvents()->where('starts_at', '>=', now())->count(),
            'following' => $user->followingSpeakers()->count(),
        ];
    }
};
?>

<div>
    @auth
        @php
            $user = auth()->user();
            $nextEvent = $this->nextGoingEvent;
            $upcomingEvents = $this->upcomingGoingEvents;
            $speakerEvents = $this->followedSpeakersEvents;
            $saved = $this->savedEvents;
            $counts = $this->counts;
            $firstName = explode(' ', $user->name)[0];
        @endphp

        {{-- Personalized Hero Banner --}}
        <section
            class="relative overflow-hidden bg-gradient-to-br from-slate-900 via-emerald-950 to-slate-900 py-12 border-b border-white/5">
            {{-- Background texture --}}
            <div
                class="absolute inset-0 bg-[linear-gradient(rgba(255,255,255,0.02)_1px,transparent_1px),linear-gradient(90deg,rgba(255,255,255,0.02)_1px,transparent_1px)] bg-[size:48px_48px]">
            </div>
            <div class="absolute top-0 right-0 w-[600px] h-[400px] bg-emerald-600/10 rounded-full blur-[100px]"></div>

            <div class="container relative z-10 mx-auto px-6 lg:px-12">
                <div class="flex flex-col lg:flex-row items-start lg:items-center gap-8">

                    {{-- Left: Greeting & Stats --}}
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-3 mb-3">
                            <div
                                class="w-10 h-10 rounded-full bg-emerald-500/20 border border-emerald-500/30 flex items-center justify-center">
                                <svg class="w-5 h-5 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                </svg>
                            </div>
                            <span
                                class="text-sm font-medium text-emerald-400 uppercase tracking-wider">{{ __('Ruang Peribadi Anda') }}</span>
                        </div>

                        <h2 class="font-heading text-2xl sm:text-3xl font-bold text-white mb-4 leading-tight">
                            {{ __('Assalamualaikum,') }}
                            <span
                                class="text-transparent bg-clip-text bg-gradient-to-r from-emerald-400 to-gold-300">{{ $firstName }}!</span>
                        </h2>

                        {{-- Activity Stats --}}
                        <div class="flex flex-wrap gap-4">
                            <div class="flex items-center gap-2 px-4 py-2 rounded-xl bg-white/5 border border-white/10">
                                <svg class="w-4 h-4 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
                                </svg>
                                <span class="text-sm text-slate-300"><span
                                        class="font-bold text-white">{{ $counts['going'] }}</span>
                                    {{ __('Menghadiri') }}</span>
                            </div>
                            <div class="flex items-center gap-2 px-4 py-2 rounded-xl bg-white/5 border border-white/10">
                                <svg class="w-4 h-4 text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M5 5a2 2 0 012-2h10a2 2 0 012 2v16l-7-3.5L5 21V5z" />
                                </svg>
                                <span class="text-sm text-slate-300"><span
                                        class="font-bold text-white">{{ $counts['saved'] }}</span>
                                    {{ __('Disimpan') }}</span>
                            </div>
                            <div class="flex items-center gap-2 px-4 py-2 rounded-xl bg-white/5 border border-white/10">
                                <svg class="w-4 h-4 text-purple-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                                </svg>
                                <span class="text-sm text-slate-300"><span
                                        class="font-bold text-white">{{ $counts['following'] }}</span>
                                    {{ __('Penceramah Diikuti') }}</span>
                            </div>
                        </div>
                    </div>

                    {{-- Right: Next Event Spotlight --}}
                    @if($nextEvent)
                        @php
                            $startsAt = $nextEvent->starts_at;
                            $diffInSeconds = now()->diffInSeconds($startsAt, false);
                            $diffInDays = now()->diffInDays($startsAt, false);
                            $diffInHours = now()->diffInHours($startsAt, false);
                            $diffInMinutes = now()->diffInMinutes($startsAt, false);
                            $isToday = $startsAt->isToday();
                            $isTomorrow = $startsAt->isTomorrow();

                            if ($diffInSeconds <= 0) {
                                $timeLabel = __('Sedang berlangsung');
                                $timeBadgeClass = 'bg-red-500/20 text-red-300 border-red-500/30';
                            } elseif ($diffInMinutes < 60) {
                                $timeLabel = __(':min minit lagi', ['min' => intval($diffInMinutes)]);
                                $timeBadgeClass = 'bg-amber-500/20 text-amber-300 border-amber-500/30';
                            } elseif ($isToday) {
                                $timeLabel = __('Hari ini, :time', ['time' => UserDateTimeFormatter::format($startsAt, 'h:i A')]);
                                $timeBadgeClass = 'bg-emerald-500/20 text-emerald-300 border-emerald-500/30';
                            } elseif ($isTomorrow) {
                                $timeLabel = __('Esok, :time', ['time' => UserDateTimeFormatter::format($startsAt, 'h:i A')]);
                                $timeBadgeClass = 'bg-blue-500/20 text-blue-300 border-blue-500/30';
                            } else {
                                $timeLabel = UserDateTimeFormatter::translatedFormat($startsAt, 'd M, h:i A');
                                $timeBadgeClass = 'bg-slate-500/20 text-slate-300 border-slate-500/30';
                            }
                        @endphp

                        <a href="{{ route('events.show', $nextEvent) }}" wire:navigate
                            class="group flex-shrink-0 w-full lg:w-80 xl:w-96">
                            <div
                                class="relative bg-white/5 backdrop-blur border border-white/10 rounded-2xl p-5 hover:bg-white/10 hover:border-emerald-500/30 transition-all">
                                {{-- Label --}}
                                <div class="flex items-center justify-between mb-4">
                                    <span
                                        class="text-xs font-bold text-emerald-400 uppercase tracking-wider flex items-center gap-1.5">
                                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                                        {{ __('Majlis Seterusnya') }}
                                    </span>
                                    <span
                                        class="inline-flex items-center px-2.5 py-1 text-xs font-bold rounded-full border {{ $timeBadgeClass }}">
                                        {{ $timeLabel }}
                                    </span>
                                </div>

                                <h3
                                    class="font-heading font-bold text-white text-lg line-clamp-2 group-hover:text-emerald-300 transition-colors mb-3">
                                    {{ $nextEvent->title }}
                                </h3>

                                <div class="flex items-center gap-3 text-sm text-slate-400">
                                    <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                                    </svg>
                                    <span
                                        class="truncate">{{ $nextEvent->venue?->name ?? $nextEvent->institution?->name ?? __('Online') }}</span>
                                </div>

                                @if($nextEvent->speakers->isNotEmpty())
                                    <div class="flex items-center gap-2 mt-3 pt-3 border-t border-white/5">
                                        <div class="flex -space-x-2">
                                            @foreach($nextEvent->speakers->take(3) as $speaker)
                                                <div
                                                    class="w-7 h-7 rounded-full border-2 border-slate-800 overflow-hidden bg-slate-700">
                                                    <img src="{{ $speaker->avatar_url ?: $speaker->default_avatar_url }}"
                                                        alt="{{ $speaker->name }}" class="w-full h-full object-cover">
                                                </div>
                                            @endforeach
                                        </div>
                                        <span class="text-xs text-slate-400">{{ $nextEvent->speakers->first()->name }}</span>
                                    </div>
                                @endif
                            </div>
                        </a>
                    @endif
                </div>
            </div>
        </section>

        {{-- Personal Content Sections --}}
        <section class="py-12 bg-slate-50">
            <div class="container mx-auto px-6 lg:px-12">
                <div class="grid lg:grid-cols-3 gap-8">

                    {{-- Upcoming events I'm going to --}}
                    <div class="lg:col-span-2">
                        <div class="flex items-center justify-between mb-6">
                            <div>
                                <h3 class="font-heading text-lg font-bold text-slate-900">
                                    {{ __('Majlis Yang Akan Saya Hadiri') }}</h3>
                                <p class="text-slate-500 text-sm">{{ __('Jadual kehadiran anda yang akan datang') }}</p>
                            </div>
                            <a href="{{ route('events.index') }}" wire:navigate
                                class="text-sm font-medium text-emerald-600 hover:text-emerald-700">{{ __('Terokai lebih') }}
                                →</a>
                        </div>

                        @if($upcomingEvents->isEmpty() && !$nextEvent)
                            <div
                                class="flex flex-col items-center justify-center py-14 bg-white rounded-2xl border border-dashed border-slate-200 text-center">
                                <div class="w-16 h-16 rounded-full bg-emerald-50 flex items-center justify-center mb-4">
                                    <svg class="w-8 h-8 text-emerald-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                            d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                    </svg>
                                </div>
                                <p class="font-semibold text-slate-700 mb-1">{{ __('Belum ada majlis dalam jadual') }}</p>
                                <p class="text-slate-400 text-sm mb-4 max-w-xs">
                                    {{ __('Tandai "Menghadiri" pada mana-mana majlis untuk ia muncul di sini.') }}</p>
                                <a href="{{ route('events.index') }}" wire:navigate
                                    class="inline-flex items-center gap-2 px-5 py-2.5 bg-emerald-600 text-white text-sm font-semibold rounded-xl hover:bg-emerald-700 transition-colors">
                                    {{ __('Cari Majlis') }}
                                </a>
                            </div>
                        @else
                            <div class="space-y-3">
                                @foreach($upcomingEvents as $event)
                                    <a href="{{ route('events.show', $event) }}" wire:navigate wire:key="going-{{ $event->id }}"
                                        class="group flex items-center gap-4 bg-white rounded-xl border border-slate-100 hover:border-emerald-200 hover:shadow-md transition-all p-4">
                                        {{-- Date block --}}
                                        <div
                                            class="flex-shrink-0 w-14 h-14 rounded-xl bg-emerald-50 flex flex-col items-center justify-center text-center">
                                            <span
                                                class="text-[10px] font-bold text-emerald-500 uppercase leading-none">{{ UserDateTimeFormatter::translatedFormat($event->starts_at, 'M') }}</span>
                                            <span
                                                class="text-xl font-black text-emerald-700 leading-tight">{{ UserDateTimeFormatter::format($event->starts_at, 'd') }}</span>
                                        </div>
                                        {{-- Details --}}
                                        <div class="flex-1 min-w-0">
                                            <h4
                                                class="font-bold text-slate-900 group-hover:text-emerald-700 transition-colors line-clamp-1">
                                                {{ $event->title }}</h4>
                                            <div class="flex items-center gap-3 mt-1 text-xs text-slate-500">
                                                <span>{{ UserDateTimeFormatter::format($event->starts_at, 'h:i A') }}</span>
                                                <span>•</span>
                                                <span
                                                    class="truncate">{{ $event->venue?->name ?? $event->institution?->name ?? __('Online') }}</span>
                                            </div>
                                        </div>
                                        <svg class="w-4 h-4 text-slate-300 group-hover:text-emerald-500 flex-shrink-0 transition-colors"
                                            fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M9 5l7 7-7 7" />
                                        </svg>
                                    </a>
                                @endforeach

                                @if($counts['going'] > $upcomingEvents->count() + ($nextEvent ? 1 : 0))
                                    <a href="{{ route('events.index') }}" wire:navigate
                                        class="flex items-center justify-center gap-2 py-3 rounded-xl border border-dashed border-slate-200 text-sm text-slate-500 hover:text-emerald-600 hover:border-emerald-200 transition-all">
                                        {{ __('Lihat :count lagi', ['count' => $counts['going'] - $upcomingEvents->count() - ($nextEvent ? 1 : 0)]) }}
                                    </a>
                                @endif
                            </div>
                        @endif
                    </div>

                    {{-- Right Sidebar: Saved & Following Speaker Events --}}
                    <div class="space-y-8">
                        {{-- Saved Events --}}
                        <div>
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="font-heading text-base font-bold text-slate-900 flex items-center gap-2">
                                    <svg class="w-4 h-4 text-amber-500" fill="currentColor" viewBox="0 0 20 20">
                                        <path d="M5 4a2 2 0 012-2h6a2 2 0 012 2v14l-5-2.5L5 18V4z" />
                                    </svg>
                                    {{ __('Disimpan') }}
                                    @if($counts['saved'] > 0)
                                        <span
                                            class="text-xs bg-amber-100 text-amber-700 font-bold px-2 py-0.5 rounded-full">{{ $counts['saved'] }}</span>
                                    @endif
                                </h3>
                            </div>

                            @if($saved->isEmpty())
                                <div class="py-6 text-center bg-white rounded-xl border border-dashed border-slate-200">
                                    <p class="text-sm text-slate-400">{{ __('Tiada majlis disimpan') }}</p>
                                </div>
                            @else
                                <div class="space-y-2">
                                    @foreach($saved as $event)
                                        <a href="{{ route('events.show', $event) }}" wire:navigate wire:key="saved-{{ $event->id }}"
                                            class="group flex items-center gap-3 bg-white rounded-xl border border-slate-100 hover:border-amber-200 hover:shadow-sm transition-all p-3">
                                            <div
                                                class="flex-shrink-0 w-10 h-10 rounded-lg bg-amber-50 flex flex-col items-center justify-center">
                                                <span
                                                    class="text-[9px] font-bold text-amber-500 uppercase">{{ UserDateTimeFormatter::translatedFormat($event->starts_at, 'M') }}</span>
                                                <span
                                                    class="text-sm font-black text-amber-700 leading-none">{{ UserDateTimeFormatter::format($event->starts_at, 'd') }}</span>
                                            </div>
                                            <div class="min-w-0 flex-1">
                                                <p
                                                    class="text-sm font-semibold text-slate-800 group-hover:text-amber-700 transition-colors line-clamp-1">
                                                    {{ $event->title }}</p>
                                                <p class="text-xs text-slate-400 truncate">
                                                    {{ $event->venue?->name ?? $event->institution?->name ?? __('Online') }}</p>
                                            </div>
                                        </a>
                                    @endforeach
                                </div>
                            @endif
                        </div>

                        {{-- Following Speaker Events --}}
                        @if($speakerEvents->isNotEmpty())
                            <div>
                                <div class="flex items-center justify-between mb-4">
                                    <h3 class="font-heading text-base font-bold text-slate-900 flex items-center gap-2">
                                        <svg class="w-4 h-4 text-purple-500" fill="none" viewBox="0 0 24 24"
                                            stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z" />
                                        </svg>
                                        {{ __('Dari Penceramah Diikuti') }}
                                    </h3>
                                </div>

                                <div class="space-y-2">
                                    @foreach($speakerEvents as $event)
                                        <a href="{{ route('events.show', $event) }}" wire:navigate
                                            wire:key="speaker-{{ $event->id }}"
                                            class="group flex items-center gap-3 bg-white rounded-xl border border-slate-100 hover:border-purple-200 hover:shadow-sm transition-all p-3">
                                            <div
                                                class="flex-shrink-0 w-10 h-10 rounded-lg bg-purple-50 flex flex-col items-center justify-center">
                                                <span
                                                    class="text-[9px] font-bold text-purple-500 uppercase">{{ UserDateTimeFormatter::translatedFormat($event->starts_at, 'M') }}</span>
                                                <span
                                                    class="text-sm font-black text-purple-700 leading-none">{{ UserDateTimeFormatter::format($event->starts_at, 'd') }}</span>
                                            </div>
                                            <div class="min-w-0 flex-1">
                                                <p
                                                    class="text-sm font-semibold text-slate-800 group-hover:text-purple-700 transition-colors line-clamp-1">
                                                    {{ $event->title }}</p>
                                                @if($event->speakers->isNotEmpty())
                                                    <p class="text-xs text-purple-500 truncate font-medium">
                                                        {{ $event->speakers->first()->name }}</p>
                                                @endif
                                            </div>
                                        </a>
                                    @endforeach
                                </div>
                            </div>
                        @elseif($counts['following'] === 0)
                            <div class="pt-2">
                                <a href="{{ route('speakers.index') }}" wire:navigate
                                    class="group flex items-center gap-3 p-4 bg-gradient-to-r from-purple-50 to-purple-50/50 border border-purple-100 rounded-xl hover:border-purple-200 transition-all">
                                    <div
                                        class="w-10 h-10 rounded-xl bg-purple-100 flex items-center justify-center flex-shrink-0 group-hover:bg-purple-200 transition-colors">
                                        <svg class="w-5 h-5 text-purple-600" fill="none" viewBox="0 0 24 24"
                                            stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M12 4v16m8-8H4" />
                                        </svg>
                                    </div>
                                    <div>
                                        <p class="text-sm font-semibold text-slate-900">{{ __('Ikuti Penceramah') }}</p>
                                        <p class="text-xs text-slate-500">{{ __('Dapatkan notifikasi majlis mereka') }}</p>
                                    </div>
                                </a>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </section>
    @endauth
</div>
