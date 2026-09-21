<?php

use App\Models\Event;
use App\Models\Person;
use App\Support\Timezone\UserDateTimeFormatter;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new
    #[Title('ilmu360° - Cari Kuliah & Majlis Ilmu di Malaysia')]
    class extends Component
    {
        #[Computed]
        public function featuredEvents(): Collection
        {
            return Event::active()
                ->whereBetween('starts_at', [now(), now()->copy()->addDays(14)])
                ->with([
                    'institution',
                    'persons.media',
                    'media' => fn ($query) => $query
                        ->where('collection_name', 'cover')
                        ->ordered(),
                ])
                ->orderBy('starts_at')
                ->take(3)
                ->get();
        }

        #[Computed]
        public function featuredPersons(): Collection
        {
            return Person::active()
                ->whereHas('events', fn ($query) => $query
                    ->active()
                    ->where('starts_at', '>=', now()))
                ->with('media')
                ->publicDirectoryOrder()
                ->take(5)
                ->get();
        }

        #[Computed]
        public function eventDateLinks(): array
        {
            $today = UserDateTimeFormatter::userNow()->startOfDay();
            $friday = $today->isFriday() ? $today : $today->copy()->next(Carbon::FRIDAY);

            if ($today->isSaturday()) {
                $weekendStart = $today;
                $weekendEnd = $today->copy()->next(Carbon::SUNDAY);
            } elseif ($today->isSunday()) {
                $weekendStart = $today;
                $weekendEnd = $today;
            } else {
                $weekendStart = $today->copy()->next(Carbon::SATURDAY);
                $weekendEnd = $weekendStart->copy()->next(Carbon::SUNDAY);
            }

            return [
                'today' => $this->dateRangeEventIndexQuery($today, $today),
                'tomorrow' => $this->dateRangeEventIndexQuery($today->copy()->addDay(), $today->copy()->addDay()),
                'friday' => $this->dateRangeEventIndexQuery($friday, $friday),
                'this_week' => $this->dateRangeEventIndexQuery($today, $today->copy()->endOfWeek()),
                'weekend' => $this->dateRangeEventIndexQuery($weekendStart, $weekendEnd),
            ];
        }

        /**
         * @return array{starts_after: string, starts_before: string, time_scope: string}
         */
        private function dateRangeEventIndexQuery(CarbonInterface $startDate, CarbonInterface $endDate): array
        {
            return [
                'starts_after' => $startDate->toDateString(),
                'starts_before' => $endDate->toDateString(),
                'time_scope' => 'all',
            ];
        }
    };
?>

@section('title', __('ilmu360° - Cari Kuliah & Majlis Ilmu di Malaysia'))
@section('meta_description', __('Platform terbesar untuk mencari kuliah, ceramah, tazkirah, dan majlis ilmu di seluruh Malaysia. Cari yang berdekatan dengan anda.'))
@section('og_url', route('home'))
@section('og_image', asset('images/home/hero-mosque-v3.png'))
@section('og_image_alt', __('ilmu360°'))
@section('og_image_width', '1891')
@section('og_image_height', '831')

@php
    $eventDateLinks = $this->eventDateLinks();
    $quickFilters = [
        ['label' => __('Malam Ini'), 'query' => $eventDateLinks['today'], 'icon' => 'moon'],
        ['label' => __('Esok'), 'query' => $eventDateLinks['tomorrow'], 'icon' => 'calendar'],
        ['label' => __('Jumaat Ini'), 'query' => $eventDateLinks['friday'], 'icon' => 'calendar'],
        ['label' => __('Minggu Ini'), 'query' => $eventDateLinks['this_week'], 'icon' => 'calendar'],
        ['label' => __('Hujung Minggu'), 'query' => $eventDateLinks['weekend'], 'icon' => 'spark'],
        ['label' => __('Berdekatan'), 'query' => ['nearby' => 1], 'icon' => 'pin'],
        ['label' => __('Popular'), 'query' => ['sort' => 'popular'], 'icon' => 'flame'],
    ];
@endphp

<div class="bg-[#f8f7f1] text-[#102c25]">
    {{-- Hero --}}
    <section class="relative isolate min-h-[43rem] overflow-hidden bg-[#09251c] sm:min-h-[48rem] lg:min-h-[43rem]">
        <img src="{{ asset('images/home/hero-mosque-v3.png') }}" alt="" class="absolute inset-0 h-full w-full object-cover object-[74%_center]" fetchpriority="high">
        <div class="absolute inset-0 bg-[linear-gradient(90deg,rgba(4,20,15,0.78)_0%,rgba(4,20,15,0.5)_26%,rgba(4,20,15,0.04)_66%,rgba(4,20,15,0.18)_100%)]"></div>
        <div class="absolute inset-0 bg-[linear-gradient(0deg,rgba(3,24,17,0.45),transparent_45%,rgba(3,24,17,0.08))]"></div>

        <div class="relative z-10 mx-auto flex min-h-[43rem] max-w-[120rem] items-end px-5 pb-12 pt-28 sm:min-h-[48rem] sm:px-8 sm:pb-16 lg:min-h-[43rem] lg:px-14 lg:pb-14 xl:px-16">
            <div class="w-full max-w-[42rem]">
                <p class="text-[0.68rem] font-bold uppercase tracking-[0.28em] text-[#f5d98a] sm:text-xs">{{ __('Satu platform. Ribuan majlis.') }}</p>
                <h1 class="mt-4 max-w-[38rem] font-serif text-5xl font-semibold leading-[0.96] tracking-[-0.045em] text-white sm:text-7xl lg:text-[5.4rem]">
                    {{ __('Dekatkan Diri,') }}<br>
                    {{ __('Dekat dengan') }} <span class="text-[#e7bb57]">{{ __('Ilmu') }}</span>
                </h1>
                <p class="mt-5 max-w-xl text-sm leading-6 text-white/85 sm:text-base sm:leading-7">{{ __('Cari kuliah, tazkirah, kelas, dan majlis ilmu di seluruh Malaysia. Temui tempat, topik dan penceramah yang memberi makna.') }}</p>

                <form action="{{ route('events.index') }}" method="GET" class="mt-8 max-w-3xl rounded-2xl border border-white/40 bg-white p-1.5 shadow-2xl shadow-black/30 sm:flex sm:items-center" data-signal-submit-event="search.submitted" data-signal-category="search" data-signal-component="home_hero" data-signal-control="hero_search">
                    <label class="flex min-h-12 flex-1 items-center gap-3 rounded-xl px-3 text-sm text-[#667b7a] sm:px-4">
                        <svg class="h-5 w-5 shrink-0 text-[#174f46]" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><circle cx="11" cy="11" r="6.75" stroke-width="1.8"/><path stroke-linecap="round" stroke-width="1.8" d="m16 16 4.5 4.5"/></svg>
                        <input type="search" name="search" placeholder="{{ __('Cari majlis, topik, penceramah, atau lokasi...') }}" class="min-w-0 flex-1 border-0 bg-transparent text-sm text-[#153c35] outline-none placeholder:text-[#8c9a97] focus:ring-0">
                    </label>
                    <button type="submit" class="mt-1 inline-flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-[#087f59] px-7 text-sm font-bold text-white transition hover:bg-[#056747] sm:mt-0 sm:w-auto">{{ __('Cari') }}<span aria-hidden="true">→</span></button>
                </form>

                <div class="mt-5 flex max-w-4xl flex-wrap gap-2">
                    @foreach($quickFilters as $filter)
                        <a href="{{ route('events.index', $filter['query']) }}" wire:navigate class="inline-flex items-center gap-2 rounded-full border border-white/25 bg-black/20 px-3.5 py-2 text-xs font-semibold text-white/90 backdrop-blur-sm transition hover:border-[#e7bb57] hover:bg-[#e7bb57] hover:text-[#183128]">
                            @if($filter['icon'] === 'moon')
                                <span class="text-[#f5d98a]">◐</span>
                            @elseif($filter['icon'] === 'pin')
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-width="1.8" d="M12 21s7-5.2 7-11a7 7 0 1 0-14 0c0 5.8 7 11 7 11Z"/><circle cx="12" cy="10" r="2.2" stroke-width="1.8"/></svg>
                            @elseif($filter['icon'] === 'flame')
                                <span class="text-[#f5d98a]">✦</span>
                            @else
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><rect x="4" y="5" width="16" height="15" rx="2" stroke-width="1.7"/><path stroke-linecap="round" stroke-width="1.7" d="M8 3v4M16 3v4M4 10h16"/></svg>
                            @endif
                            {{ $filter['label'] }}
                        </a>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    {{-- Geography filter --}}
    <section class="relative z-20 mx-auto max-w-7xl px-5 py-4 sm:px-6 lg:px-8">
        <livewire:components.event-filters />
    </section>

    {{-- Featured events + nearby map --}}
    <section class="bg-[#f8f7f1] py-12 sm:py-16 lg:py-20">
        <div class="mx-auto grid max-w-[120rem] gap-8 px-5 sm:px-8 lg:grid-cols-[minmax(0,1.6fr)_minmax(21rem,0.85fr)] lg:px-14 xl:px-16">
            <div>
                <div class="flex items-end justify-between gap-4"><div><p class="flex items-center gap-2 text-[0.68rem] font-bold uppercase tracking-[0.2em] text-[#087f59]"><span class="h-2 w-2 rounded-full bg-[#087f59]"></span>{{ __('Majlis Akan Datang') }}</p><h2 class="mt-2 font-heading text-3xl font-bold tracking-tight text-[#112c26] sm:text-4xl">{{ __('Pilihan Majlis Minggu Ini') }}</h2></div><a href="{{ route('events.index') }}" wire:navigate class="hidden items-center gap-2 text-xs font-bold text-[#087f59] sm:inline-flex">{{ __('Lihat semua majlis') }} <span aria-hidden="true">→</span></a></div>
                @if($this->featuredEvents->isNotEmpty())
                    <div class="mt-7 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach($this->featuredEvents as $event)
                            @php
                                $eventPerson = $event->persons->first();
                                $eventDate = $event->starts_at ? UserDateTimeFormatter::format($event->starts_at, 'd') : '--';
                                $eventMonth = $event->starts_at ? UserDateTimeFormatter::translatedFormat($event->starts_at, 'M') : '';
                                $eventTime = $event->starts_at ? UserDateTimeFormatter::format($event->starts_at, 'g:i A') : '';
                                $eventLocation = $event->institution?->name ?? __('Seluruh Malaysia');
                            @endphp
                            <a href="{{ route('events.show', $event) }}" wire:navigate wire:key="home-featured-{{ $event->id }}" class="group overflow-hidden rounded-2xl border border-[#e5e8df] bg-white shadow-[0_12px_30px_-18px_rgba(24,53,43,0.35)] transition hover:-translate-y-1 hover:shadow-[0_20px_38px_-18px_rgba(24,53,43,0.45)]"><div class="relative aspect-[1.35] overflow-hidden bg-[#dfe9df]"><img src="{{ $event->card_image_url }}" alt="{{ $event->title }}" loading="lazy" class="h-full w-full object-cover transition duration-500 group-hover:scale-[1.02]"><span class="absolute left-3 top-3 rounded-full bg-[#087f59] px-2.5 py-1 text-[0.63rem] font-bold text-white shadow">{{ $event->eventType?->name ?? __('Kuliah') }}</span></div><div class="p-4"><div class="flex gap-3"><div class="flex h-14 w-12 shrink-0 flex-col items-center justify-center rounded-xl bg-[#f2f8f1] text-[#087f59]"><span class="text-[0.58rem] font-bold uppercase tracking-widest">{{ $eventMonth }}</span><span class="font-heading text-xl font-bold leading-none">{{ $eventDate }}</span></div><div class="min-w-0"><h3 class="line-clamp-2 font-heading text-base font-bold leading-tight text-[#142f28]">{{ $event->title }}</h3><p class="mt-1 truncate text-xs text-[#5b716d]">{{ $eventPerson?->formatted_name ?? $eventPerson?->name ?? __('Penceramah jemputan') }}</p></div></div><div class="mt-4 space-y-2 text-xs text-[#56716a]"><p class="flex items-start gap-2"><svg class="mt-0.5 h-3.5 w-3.5 shrink-0 text-[#087f59]" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-width="1.8" d="M12 21s7-5.2 7-11a7 7 0 1 0-14 0c0 5.8 7 11 7 11Z"/><circle cx="12" cy="10" r="2.2" stroke-width="1.8"/></svg><span class="line-clamp-2">{{ $eventLocation }}</span></p><p class="flex items-center gap-2"><svg class="h-3.5 w-3.5 text-[#087f59]" fill="none" viewBox="0 0 24 24" stroke="currentColor"><circle cx="12" cy="12" r="8" stroke-width="1.8"/><path stroke-linecap="round" stroke-width="1.8" d="M12 7v5l3 2"/></svg><span class="font-semibold text-[#087f59]">{{ $eventTime }}</span></p></div></div></a>
                        @endforeach
                    </div>
                @else
                    <div class="mt-7 rounded-2xl border border-dashed border-[#cad9cf] bg-white/70 p-10 text-center text-sm text-[#5b716d]">{{ __('Majlis akan datang akan dipaparkan di sini.') }}</div>
                @endif
            </div>
            <aside class="relative min-h-[22rem] overflow-hidden rounded-[1.75rem] border border-[#dce8dc] bg-[#deefe3] shadow-[0_20px_45px_-28px_rgba(20,69,52,0.5)]"><div class="absolute inset-0 opacity-80" style="background-image: linear-gradient(30deg, transparent 46%, rgba(87,155,127,0.25) 47%, transparent 49%), linear-gradient(-18deg, transparent 54%, rgba(87,155,127,0.24) 55%, transparent 57%), linear-gradient(90deg, rgba(255,255,255,0.55) 1px, transparent 1px), linear-gradient(rgba(255,255,255,0.55) 1px, transparent 1px); background-size: 170px 130px, 210px 170px, 34px 34px, 34px 34px;"></div><div class="absolute inset-0 bg-[radial-gradient(circle_at_58%_58%,rgba(255,255,255,0.8),transparent_28%)]"></div><div class="absolute left-[17%] top-[32%] h-4 w-4 rounded-full border-4 border-white bg-[#086548] shadow-lg"></div><div class="absolute left-[37%] top-[58%] h-4 w-4 rounded-full border-4 border-white bg-[#086548] shadow-lg"></div><div class="absolute right-[25%] top-[25%] h-4 w-4 rounded-full border-4 border-white bg-[#d49c25] shadow-lg"></div><div class="absolute right-[34%] bottom-[20%] h-4 w-4 rounded-full border-4 border-white bg-[#086548] shadow-lg"></div><div class="relative z-10 flex h-full min-h-[22rem] flex-col justify-between p-6 sm:p-7"><div><p class="flex items-center gap-2 text-[0.65rem] font-bold uppercase tracking-[0.2em] text-[#087f59]"><span class="h-2 w-2 rounded-full bg-[#087f59]"></span>{{ __('Dekat dengan anda') }}</p><h2 class="mt-2 font-serif text-3xl font-semibold italic leading-none text-[#12342a]">{{ __('Majlis Berdekatan') }}</h2></div><div class="rounded-2xl border border-white/80 bg-white/90 p-3 shadow-lg shadow-[#759a81]/20 backdrop-blur-sm"><div class="flex items-center gap-3"><div class="h-12 w-12 shrink-0 rounded-xl bg-[#c8d9ca]" style="background-image: url('{{ asset('images/events/majlis-hero-background-v1.png') }}'); background-size: cover; background-position: center;"></div><div class="min-w-0 flex-1"><p class="truncate text-xs font-bold text-[#19392f]">{{ __('Kuliah Maghrib') }}</p><p class="truncate text-[0.68rem] text-[#687d76]">{{ __('Masjid pilihan berdekatan') }}</p><p class="mt-1 text-[0.68rem] font-semibold text-[#087f59]">{{ __('Lihat di Peta') }} →</p></div><span class="flex h-7 w-7 items-center justify-center rounded-full border border-[#bdd3c5] text-[#087f59]">→</span></div></div></div></aside>
        </div>
    </section>

    {{-- Speakers --}}
    <section class="relative overflow-hidden bg-[#0c3b30] py-14 text-white sm:py-20"><div class="absolute inset-0 bg-cover bg-center opacity-35" style="background-image: url('{{ asset('images/footer-courtyard-v4.jpg') }}');"></div><div class="absolute inset-0 bg-[linear-gradient(90deg,rgba(4,35,27,0.98),rgba(4,35,27,0.76)_52%,rgba(4,35,27,0.8))]"></div><div class="relative mx-auto grid max-w-[120rem] gap-8 px-5 sm:px-8 lg:grid-cols-[18rem_minmax(0,1fr)_11rem] lg:items-center lg:px-14 xl:px-16"><div><p class="flex items-center gap-2 text-[0.68rem] font-bold uppercase tracking-[0.2em] text-[#e8c463]"><span class="h-2 w-2 rounded-full bg-[#e8c463]"></span>{{ __('Ikuti penceramah kegemaran anda') }}</p><h2 class="mt-4 max-w-xs font-serif text-4xl font-semibold leading-[0.98] sm:text-5xl">{{ __('Tokoh Ilmu, Lebih Dekat') }}</h2><p class="mt-4 max-w-xs text-sm leading-6 text-white/70">{{ __('Dapatkan notifikasi majlis terbaru daripada penceramah pilihan anda.') }}</p><a href="{{ route('persons.index') }}" wire:navigate class="mt-6 inline-flex items-center gap-3 rounded-full bg-[#e8c463] px-5 py-3 text-xs font-bold text-[#16372d] shadow-lg shadow-black/20 transition hover:bg-[#f4d98b]">{{ __('Terokai Penceramah') }} <span aria-hidden="true">→</span></a></div><div class="grid grid-cols-2 gap-3 sm:grid-cols-5">@forelse($this->featuredPersons as $person)<a href="{{ route('persons.show', $person) }}" wire:navigate wire:key="home-speaker-{{ $person->id }}" class="group relative aspect-[0.78] min-h-[14rem] overflow-hidden rounded-2xl border border-white/20 bg-white/10 sm:min-h-[12rem]"><img src="{{ $person->public_avatar_url ?: $person->default_avatar_url }}" alt="{{ $person->formatted_name }}" loading="lazy" class="absolute inset-0 h-full w-full object-cover object-top opacity-90 transition duration-500 group-hover:scale-[1.03]"><div class="absolute inset-0 bg-gradient-to-t from-[#05251d] via-transparent to-transparent"></div><span class="absolute inset-x-3 bottom-3 text-xs font-bold leading-tight text-white">{{ $person->formatted_name }}</span></a>@empty<div class="col-span-full rounded-2xl border border-white/15 bg-white/10 p-8 text-sm text-white/70">{{ __('Penceramah pilihan akan dipaparkan di sini.') }}</div>@endforelse</div><p class="hidden font-serif text-2xl italic leading-tight text-[#f3e4bf] lg:block">“{{ __('Bersama ulama, hidup menjadi lebih berkat.') }}”</p></div></section>

    {{-- Stats --}}
    <section class="relative z-10 -mt-1 bg-[#f8f7f1] px-5 py-8 sm:px-8 lg:-mt-7 lg:px-14 xl:px-16"><livewire:home.stats /></section>

    {{-- Community CTA --}}
    <section class="relative overflow-hidden bg-[#082c24] py-16 text-white sm:py-24"><div class="absolute inset-0 bg-cover bg-center opacity-65" style="background-image: url('{{ asset('images/footer-courtyard-v4.jpg') }}');"></div><div class="absolute inset-0 bg-[linear-gradient(90deg,rgba(3,25,19,0.92),rgba(3,25,19,0.55)_58%,rgba(3,25,19,0.7))]"></div><div class="relative mx-auto flex max-w-[120rem] flex-col gap-8 px-5 sm:px-8 lg:flex-row lg:items-end lg:justify-between lg:px-14 xl:px-16"><div><p class="font-serif text-4xl italic leading-none text-[#f1d98d] sm:text-5xl">{{ __('Ilmu Menghubungkan Kita') }}</p><p class="mt-4 max-w-xl text-sm text-white/80">{{ __('Sertai komuniti ilmu360° dan jangan terlepas majlis ilmu berhampiran anda.') }}</p><div class="mt-6 flex flex-wrap gap-3"><a href="{{ route('register') }}" class="inline-flex items-center gap-3 rounded-full bg-[#f1cb64] px-5 py-3 text-xs font-bold text-[#15372d] transition hover:bg-[#ffe39b]">{{ __('Daftar Sekarang') }} <span aria-hidden="true">→</span></a><a href="{{ route('about') }}" wire:navigate class="inline-flex items-center gap-3 rounded-full border border-white/70 px-5 py-3 text-xs font-bold text-white transition hover:bg-white/10">{{ __('Ketahui Lebih Lanjut') }} <span aria-hidden="true">→</span></a></div></div><div class="grid grid-cols-2 gap-4 text-center sm:grid-cols-4 sm:gap-7 lg:max-w-xl">@foreach([['icon' => '⌂', 'label' => __('Lebih Dekat dengan Masjid')], ['icon' => '✦', 'label' => __('Lebih Dekat dengan Ulama')], ['icon' => '♧', 'label' => __('Lebih Dekat dengan Komuniti')], ['icon' => '♡', 'label' => __('Lebih Dekat dengan Allah')]] as $benefit)<div class="flex flex-col items-center gap-2 text-[0.68rem] leading-4 text-white/75"><span class="font-serif text-3xl text-white">{{ $benefit['icon'] }}</span><span>{{ $benefit['label'] }}</span></div>@endforeach</div></div></section>
</div>
