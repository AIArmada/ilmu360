<?php

use AIArmada\Events\Models\EventTaxonomy;
use AIArmada\Events\Models\EventTerm;
use App\Support\Timezone\UserDateTimeFormatter;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new
    #[Title('ilmu360° - Cari Kuliah & Majlis Ilmu di Malaysia')]
    class extends Component
    {
        #[Computed]
        public function categoryTagIds(): array
        {
            $taxonomyId = EventTaxonomy::query()->where('code', 'domain')->value('id');

            if ($taxonomyId === null) {
                return ['aqidah' => null, 'syariah' => null, 'akhlak' => null];
            }

            return [
                'aqidah' => EventTerm::query()->where('event_taxonomy_id', $taxonomyId)->where('code', 'aqidah')->value('id'),
                'syariah' => EventTerm::query()->where('event_taxonomy_id', $taxonomyId)->where('code', 'syariah')->value('id'),
                'akhlak' => EventTerm::query()->where('event_taxonomy_id', $taxonomyId)->where('code', 'akhlak')->value('id'),
            ];
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
@section('og_image', asset('images/default-mosque-hero.png'))
@section('og_image_alt', __('ilmu360°'))
@section('og_image_width', '1024')
@section('og_image_height', '1024')

@push('head')
@endpush

@php
    $showsGeolocationControls = app(\App\Support\Location\PublicGeolocationPermission::class)->isGranted();
    $eventDateLinks = $this->eventDateLinks();
@endphp

<div>
    {{-- ═══════════════════════════════════════════════════════ --}}
    {{-- HERO SECTION — Shared, but adapts for auth vs guest     --}}
    {{-- ═══════════════════════════════════════════════════════ --}}
    <section class="relative min-h-[85vh] flex items-center justify-center overflow-hidden pt-12 pb-12">
        {{-- Background Layer --}}
        <div class="absolute inset-0 z-0 bg-slate-950">
            {{-- Islamic Pattern Base --}}
            <div class="absolute inset-0 bg-pattern-islamic opacity-5 mix-blend-overlay" style="background-image: url('{{ asset('images/pattern-bg.png') }}');"></div>

            {{-- Aurora Gradients --}}
            <div class="absolute top-[-20%] left-[-10%] w-[70vw] h-[70vw] rounded-full bg-emerald-900/40 blur-[120px] mix-blend-screen animate-float"></div>
            {{-- Golden Glow (Nur) --}}
            <div class="absolute top-[20%] left-[30%] w-[40vw] h-[40vw] rounded-full bg-gold-600/10 blur-[100px] mix-blend-screen animate-float" style="animation-delay: -3s"></div>
            <div class="absolute bottom-[-20%] right-[-10%] w-[60vw] h-[60vw] rounded-full bg-teal-900/30 blur-[100px] mix-blend-screen animate-float" style="animation-delay: -5s"></div>

            {{-- Noise Texture --}}
            <div class="absolute inset-0 opacity-20 brightness-100 contrast-150 mix-blend-overlay" style="background-image: url('{{ asset('images/noise.svg') }}');"></div>

            {{-- Subtle Grid --}}
            <div class="absolute inset-0 bg-[linear-gradient(rgba(255,255,255,0.03)_1px,transparent_1px),linear-gradient(90deg,rgba(255,255,255,0.03)_1px,transparent_1px)] bg-[size:64px_64px] [mask-image:radial-gradient(ellipse_60%_60%_at_50%_50%,#000_70%,transparent_100%)]"></div>
        </div>

        <div class="container relative z-10 px-6 mx-auto">
            <div class="flex flex-col items-center max-w-5xl mx-auto text-center stagger-children" x-data="{}">

                {{-- Eyebrow badge --}}
                @guest
                    <div class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-white/5 border border-white/10 text-slate-300 text-sm font-medium mb-8">
                        <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                        {{ __('Platform ilmu360° Terbesar di Malaysia') }}
                    </div>
                @endguest

                @auth
                    <div class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-emerald-500/10 border border-emerald-500/20 text-emerald-300 text-sm font-medium mb-8">
                        <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                        </svg>
                        {{ __('Selamat Kembali ke ilmu360°') }}
                    </div>
                @endauth

                {{-- Main Heading --}}
                <h1 class="font-heading font-extrabold text-4xl sm:text-6xl lg:text-8xl tracking-tight text-white mb-3 leading-tight whitespace-nowrap">
                    <span class="text-transparent bg-clip-text bg-gradient-to-b from-white to-white/60">{{ __('Jom ke') }}</span>
                    <span class="text-transparent bg-clip-text bg-gradient-to-r from-emerald-400 via-gold-300 to-emerald-400 animate-gradient-x bg-[length:200%_auto]">{{ __('Majlis Ilmu!') }}</span>
                </h1>

                {{-- Subheading --}}
                <p class="max-w-2xl mx-auto mb-12 text-lg sm:text-xl text-slate-300/90 font-light leading-relaxed">
                    {{ __('Ketahui & hadiri majlis ilmu berdekatan atau di mana-mana sahaja pada bila-bila masa. Pilih penceramah atau topik kegemaran anda.') }}
                </p>

                {{-- Search Interface --}}
                <div class="w-full max-w-3xl relative group" x-on:mi-home-nearby.window="locate()" x-data="{
                                ...window.ilmu360.geolocationPermission({
                                    initiallyGranted: @js($showsGeolocationControls),
                                    cookieName: @js(\App\Support\Location\PublicGeolocationPermission::COOKIE_NAME),
                                }),
                                locating: false,
                                locationNotice: null,
                                setLocationNotice(message) {
                                    this.locationNotice = message;
                                },
                                clearLocationNotice() {
                                    this.locationNotice = null;
                                },
                                submitSearchWithoutLocation() {
                                    this.locating = false;
                                    this.clearLocationNotice();
                                    this.$refs.lat.value = '';
                                    this.$refs.lng.value = '';
                                    this.$refs.form.submit();
                                },
                                async locate() {
                                    if (this.locating) return;
                                    this.clearLocationNotice();
                                    if (!navigator.geolocation) {
                                        this.setGeolocationPermission(false);
                                        this.setLocationNotice('{{ __("Pelayar anda tidak menyokong geolokasi.") }}');
                                        return;
                                    }

                                    if (navigator.permissions && typeof navigator.permissions.query === 'function') {
                                        try {
                                            const permissionStatus = await navigator.permissions.query({ name: 'geolocation' });

                                            if (permissionStatus.state === 'denied') {
                                                this.setGeolocationPermission(false);
                                            }
                                        } catch (error) {
                                        }
                                    }

                                    this.locating = true;
                                    navigator.geolocation.getCurrentPosition((position) => {
                                        this.clearLocationNotice();
                                        this.setGeolocationPermission(true);
                                        this.$refs.lat.value = position.coords.latitude;
                                        this.$refs.lng.value = position.coords.longitude;
                                        this.$refs.form.submit();
                                    }, (error) => {
                                        this.locating = false;
                                        if (error?.code === 1) {
                                            this.setGeolocationPermission(false);
                                            this.submitSearchWithoutLocation();

                                            return;
                                        }

                                        this.setLocationNotice('{{ __("Tidak dapat mendapatkan lokasi anda. Sila aktifkan perkhidmatan lokasi.") }}');
                                    });
                                }
                            }">
                    <div class="absolute -inset-1 bg-gradient-to-r from-emerald-500 via-gold-400 to-emerald-500 rounded-2xl opacity-50 group-hover:opacity-100 blur transition duration-500 group-hover:duration-200 animate-tilt"></div>
                    <form action="{{ route('search.index') }}" method="GET" x-ref="form"
                        data-signal-submit-event="search.submitted"
                        data-signal-category="search"
                        data-signal-component="home_hero"
                        data-signal-control="hero_search"
                        data-signal-props='@json(['destination' => 'unified_search'])'
                        class="relative bg-slate-900/90 rounded-2xl p-2 flex flex-col sm:flex-row items-center gap-2 border border-white/10 shadow-2xl backdrop-blur-xl">

                        {{-- Search Input --}}
                        <div class="relative flex-1 w-full h-14 bg-white/5 rounded-xl border border-white/5 focus-within:bg-white/10 focus-within:border-emerald-500/50 transition-all group/input">
                            <div class="absolute inset-y-0 left-4 flex items-center pointer-events-none">
                                <svg class="w-5 h-5 text-slate-400 group-focus-within/input:text-emerald-400 transition-colors" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                                </svg>
                            </div>
                            <input type="text" name="search" placeholder="{{ __('Cari majlis, penceramah, atau institusi...') }}"
                                data-signal-include-value="true"
                                class="w-full h-full bg-transparent border-none pl-12 pr-4 text-white placeholder-slate-400 focus:ring-0 focus:outline-none text-base">
                        </div>

                        {{-- Locate Button --}}
                        <button type="button" @click="locate" :disabled="locating"
                            data-testid="near-me-button"
                            data-signal-event="search.nearby_requested"
                            data-signal-category="search"
                            data-signal-component="home_hero"
                            data-signal-control="near_me"
                            title="{{ __('Cari majlis berdekatan anda') }}"
                            class="hidden sm:flex items-center justify-center w-14 h-14 rounded-xl bg-white/5 hover:bg-white/10 border border-white/5 text-slate-400 hover:text-emerald-400 transition-all">
                            <div x-show="!locating">
                                <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                                </svg>
                            </div>
                            <div x-show="locating" x-cloak>
                                <svg class="animate-spin w-5 h-5 text-emerald-500" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                </svg>
                            </div>
                        </button>

                        {{-- Search Button --}}
                        <button type="submit"
                            data-signal-event="search.submit_clicked"
                            data-signal-category="search"
                            data-signal-component="home_hero"
                            data-signal-control="search_button"
                            class="w-full sm:w-auto px-8 h-14 bg-emerald-600 hover:bg-emerald-500 text-white font-semibold rounded-xl transition-all shadow-lg hover:shadow-emerald-500/25 flex items-center justify-center gap-2">
                            <span>{{ __('Cari') }}</span>
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/>
                            </svg>
                        </button>

                        <input type="hidden" name="lat" x-ref="lat">
                        <input type="hidden" name="lng" x-ref="lng">
                    </form>

                    <p x-show="locationNotice" x-cloak x-text="locationNotice"
                        class="mt-3 rounded-xl border border-amber-500/20 bg-amber-500/10 px-4 py-3 text-sm text-amber-100"></p>
                </div>

                {{-- Quick Links --}}
                <div class="mt-10 flex flex-wrap justify-center gap-3 text-sm font-medium">
                    <a href="{{ route('events.index', $eventDateLinks['today']) }}" wire:navigate
                        data-signal-event="navigation.quick_filter_clicked"
                        data-signal-category="navigation"
                        data-signal-component="home_quick_links"
                        data-signal-control="today"
                        data-signal-props='@json(['filter' => 'today'])'
                        class="text-slate-400 hover:text-gold-400 transition-colors flex items-center gap-1.5 px-4 py-2 rounded-full bg-white/5 hover:bg-white/10 border border-white/5 hover:border-gold-500/30">
                        <span class="w-2 h-2 rounded-full bg-amber-400 animate-pulse"></span>
                        {{ __('Malam Ini') }}
                    </a>
                    <a href="{{ route('events.index', $eventDateLinks['friday']) }}" wire:navigate
                        data-signal-event="navigation.quick_filter_clicked"
                        data-signal-category="navigation"
                        data-signal-component="home_quick_links"
                        data-signal-control="friday"
                        data-signal-props='@json(['filter' => 'friday'])'
                        class="text-slate-400 hover:text-emerald-400 transition-colors flex items-center gap-1.5 px-4 py-2 rounded-full bg-white/5 hover:bg-white/10 border border-white/5 hover:border-emerald-500/30">
                        <span class="w-2 h-2 rounded-full bg-emerald-400"></span>
                        {{ __('Jumaat Ini') }}
                    </a>
                    <a href="{{ route('events.index', $eventDateLinks['this_week']) }}" wire:navigate
                        data-signal-event="navigation.quick_filter_clicked"
                        data-signal-category="navigation"
                        data-signal-component="home_quick_links"
                        data-signal-control="this_week"
                        data-signal-props='@json(['filter' => 'this_week'])'
                        class="text-slate-400 hover:text-white transition-colors flex items-center gap-1.5 px-4 py-2 rounded-full bg-white/5 hover:bg-white/10 border border-white/5">
                        {{ __('Minggu Ini') }}
                    </a>
                    <a href="{{ route('events.index', $eventDateLinks['weekend']) }}" wire:navigate
                        data-signal-event="navigation.quick_filter_clicked"
                        data-signal-category="navigation"
                        data-signal-component="home_quick_links"
                        data-signal-control="weekend"
                        data-signal-props='@json(['filter' => 'weekend'])'
                        class="text-slate-400 hover:text-white transition-colors flex items-center gap-1.5 px-4 py-2 rounded-full bg-white/5 hover:bg-white/10 border border-white/5">
                        {{ __('Hujung Minggu') }}
                    </a>
                    <a href="{{ route('events.index', ['domain_tag_ids' => [$this->categoryTagIds['aqidah']]]) }}" wire:navigate
                        data-signal-event="navigation.quick_filter_clicked"
                        data-signal-category="navigation"
                        data-signal-component="home_quick_links"
                        data-signal-control="domain_aqidah"
                        data-signal-props='@json(['filter' => 'domain', 'tag' => 'aqidah'])'
                        class="text-slate-400 hover:text-emerald-300 transition-colors flex items-center gap-1.5 px-4 py-2 rounded-full bg-white/5 hover:bg-white/10 border border-white/5">
                        #Aqidah
                    </a>
                    <a href="{{ route('events.index', ['domain_tag_ids' => [$this->categoryTagIds['syariah']]]) }}" wire:navigate
                        data-signal-event="navigation.quick_filter_clicked"
                        data-signal-category="navigation"
                        data-signal-component="home_quick_links"
                        data-signal-control="domain_syariah"
                        data-signal-props='@json(['filter' => 'domain', 'tag' => 'syariah'])'
                        class="text-slate-400 hover:text-emerald-300 transition-colors flex items-center gap-1.5 px-4 py-2 rounded-full bg-white/5 hover:bg-white/10 border border-white/5">
                        #Syariah
                    </a>
                    <a href="{{ route('events.index', ['domain_tag_ids' => [$this->categoryTagIds['akhlak']]]) }}" wire:navigate
                        data-signal-event="navigation.quick_filter_clicked"
                        data-signal-category="navigation"
                        data-signal-component="home_quick_links"
                        data-signal-control="domain_akhlak"
                        data-signal-props='@json(['filter' => 'domain', 'tag' => 'akhlak'])'
                        class="text-slate-400 hover:text-emerald-300 transition-colors flex items-center gap-1.5 px-4 py-2 rounded-full bg-white/5 hover:bg-white/10 border border-white/5">
                        #Akhlak
                    </a>

                    <button type="button" @click="$dispatch('mi-home-nearby')"
                        data-signal-event="search.nearby_requested"
                        data-signal-category="search"
                        data-signal-component="home_quick_links"
                        data-signal-control="near_me_mobile"
                        class="sm:hidden text-slate-400 hover:text-emerald-400 transition-colors flex items-center gap-1.5 px-4 py-2 rounded-full bg-white/5 hover:bg-white/10 border border-white/5">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                        </svg>
                        {{ __('Berdekatan Saya') }}
                    </button>
                </div>

                {{-- Live Stats (Floating) --}}
                <div class="mt-10 sm:mt-12">
                    <livewire:home.stats lazy.bundle />
                </div>
            </div>
        </div>
    </section>

    {{-- ═══════════════════════════════════════════════════════ --}}
    {{-- PERSONAL DASHBOARD — Only for authenticated users       --}}
    {{-- ═══════════════════════════════════════════════════════ --}}
    <livewire:home.my-majlis lazy.bundle />

    {{-- ═══════════════════════════════════════════════════════ --}}
    {{-- PLATFORM VALUE PROPOSITION — Only for guests            --}}
    {{-- ═══════════════════════════════════════════════════════ --}}
    @guest
        <section class="py-20 bg-white">
            <div class="container mx-auto px-6 lg:px-12">
                {{-- Section Header --}}
                <div class="text-center mb-16">
                    <span class="inline-block text-sm font-bold text-emerald-600 uppercase tracking-widest mb-3">{{ __('Mengapa Majlis Ilmu?') }}</span>
                    <h2 class="font-heading text-3xl lg:text-4xl font-bold text-slate-900 mb-4">{{ __('Satu Platform, Semua Majlis Malaysia') }}</h2>
                    <p class="text-slate-500 max-w-2xl mx-auto">{{ __('Kami menghubungkan pencari ilmu dengan majlis, institusi, dan penceramah di seluruh Malaysia — percuma dan mudah.') }}</p>
                </div>

                {{-- Features Grid --}}
                <div class="grid md:grid-cols-3 gap-8">
                    {{-- Feature 1: Majlis --}}
                    <a href="{{ route('events.index') }}" wire:navigate
                        data-signal-event="navigation.home_feature_clicked"
                        data-signal-category="navigation"
                        data-signal-component="home_value_cards"
                        data-signal-control="events"
                        class="group p-8 rounded-3xl bg-gradient-to-br from-emerald-50 to-teal-50 border border-emerald-100 hover:border-emerald-300 hover:shadow-xl hover:shadow-emerald-100 transition-all hover:-translate-y-1">
                        <div class="w-14 h-14 rounded-2xl bg-emerald-500 flex items-center justify-center mb-6 group-hover:scale-110 transition-transform shadow-lg shadow-emerald-500/30">
                            <svg class="w-7 h-7 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                            </svg>
                        </div>
                        <h3 class="font-heading text-xl font-bold text-slate-900 mb-3 group-hover:text-emerald-700 transition-colors">{{ __('Cari Majlis') }}</h3>
                        <p class="text-slate-600 leading-relaxed text-sm">{{ __('Ratusan kuliah, tazkirah, dan majlis ilmu daripada seluruh Malaysia — ditapis mengikut waktu, lokasi, dan topik pilihan anda.') }}</p>
                        <div class="mt-6 flex items-center gap-2 text-sm font-semibold text-emerald-600 group-hover:gap-3 transition-all">
                            {{ __('Terokai Majlis') }}
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                            </svg>
                        </div>
                    </a>

                    {{-- Feature 2: Institusi --}}
                    <a href="{{ route('institutions.index') }}" wire:navigate
                        data-signal-event="navigation.home_feature_clicked"
                        data-signal-category="navigation"
                        data-signal-component="home_value_cards"
                        data-signal-control="institutions"
                        class="group p-8 rounded-3xl bg-gradient-to-br from-blue-50 to-sky-50 border border-blue-100 hover:border-blue-300 hover:shadow-xl hover:shadow-blue-100 transition-all hover:-translate-y-1">
                        <div class="w-14 h-14 rounded-2xl bg-blue-500 flex items-center justify-center mb-6 group-hover:scale-110 transition-transform shadow-lg shadow-blue-500/30">
                            <svg class="w-7 h-7 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                            </svg>
                        </div>
                        <h3 class="font-heading text-xl font-bold text-slate-900 mb-3 group-hover:text-blue-700 transition-colors">{{ __('Terokai Institusi') }}</h3>
                        <p class="text-slate-600 leading-relaxed text-sm">{{ __('Direktori lengkap masjid, surau, dan pusat tahfiz di Malaysia. Cari institusi aktif berdekatan kawasan anda.') }}</p>
                        <div class="mt-6 flex items-center gap-2 text-sm font-semibold text-blue-600 group-hover:gap-3 transition-all">
                            {{ __('Lihat Institusi') }}
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                            </svg>
                        </div>
                    </a>

                    {{-- Feature 3: Penceramah --}}
                    <a href="{{ route('persons.index') }}" wire:navigate
                        data-signal-event="navigation.home_feature_clicked"
                        data-signal-category="navigation"
                        data-signal-component="home_value_cards"
                        data-signal-control="speakers"
                        class="group p-8 rounded-3xl bg-gradient-to-br from-purple-50 to-violet-50 border border-purple-100 hover:border-purple-300 hover:shadow-xl hover:shadow-purple-100 transition-all hover:-translate-y-1">
                        <div class="w-14 h-14 rounded-2xl bg-purple-500 flex items-center justify-center mb-6 group-hover:scale-110 transition-transform shadow-lg shadow-purple-500/30">
                            <svg class="w-7 h-7 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z"/>
                            </svg>
                        </div>
                        <h3 class="font-heading text-xl font-bold text-slate-900 mb-3 group-hover:text-purple-700 transition-colors">{{ __('Ikuti Penceramah') }}</h3>
                        <p class="text-slate-600 leading-relaxed text-sm">{{ __('Temui dan ikuti asatizah pilihan anda. Dapatkan notifikasi setiap kali mereka menghoskan majlis ilmu baharu.') }}</p>
                        <div class="mt-6 flex items-center gap-2 text-sm font-semibold text-purple-600 group-hover:gap-3 transition-all">
                            {{ __('Cari Penceramah') }}
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                            </svg>
                        </div>
                    </a>
                </div>

                {{-- CTA for guests to register --}}
                <div class="mt-16 relative overflow-hidden rounded-3xl bg-gradient-to-r from-emerald-600 to-teal-600 p-10 text-center">
                    {{-- Background pattern --}}
                    <div class="absolute inset-0 opacity-10" style="background-image: url('{{ asset('images/pattern-bg.png') }}');"></div>
                    <div class="relative z-10">
                        <h3 class="font-heading text-2xl sm:text-3xl font-bold text-white mb-3">{{ __('Daftar Sekarang — Percuma Selamanya') }}</h3>
                        <p class="text-emerald-100 mb-8 max-w-xl mx-auto">{{ __('Simpan majlis kegemaran, ikuti penceramah, dan dapatkan peringatan majlis ilmu berdekatan anda.') }}</p>
                        <div class="flex flex-col sm:flex-row items-center justify-center gap-4">
                            <a href="{{ route('register') }}" wire:navigate
                                data-signal-event="auth.signup_intent"
                                data-signal-category="auth"
                                data-signal-component="home_guest_cta"
                                data-signal-control="register"
                                class="inline-flex items-center gap-2 px-8 py-4 bg-white text-emerald-700 font-bold rounded-xl hover:bg-emerald-50 transition-all shadow-xl hover:-translate-y-0.5">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/>
                                </svg>
                                {{ __('Buat Akaun Percuma') }}
                            </a>
                            <a href="{{ route('login') }}" wire:navigate
                                data-signal-event="auth.login_intent"
                                data-signal-category="auth"
                                data-signal-component="home_guest_cta"
                                data-signal-control="login"
                                class="text-emerald-100 hover:text-white font-medium transition-colors">
                                {{ __('Sudah ada akaun? Log Masuk →') }}
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    @endguest

    {{-- ═══════════════════════════════════════════════════════ --}}
    {{-- UPCOMING CONTEXTUAL EVENTS (Prayer-time based)          --}}
    {{-- ═══════════════════════════════════════════════════════ --}}
    <livewire:home.upcoming-prayer-events lazy.bundle />

    {{-- ═══════════════════════════════════════════════════════ --}}
    {{-- FEATURED EVENTS                                         --}}
    {{-- ═══════════════════════════════════════════════════════ --}}
    <livewire:home.featured-events lazy.bundle />

    {{-- ═══════════════════════════════════════════════════════ --}}
    {{-- BROWSE BY DATE                                          --}}
    {{-- ═══════════════════════════════════════════════════════ --}}
    <section class="bg-slate-50 py-0">
        <div class="container mx-auto px-6 lg:px-12">
            <div class="p-2 bg-white border shadow-sm rounded-3xl border-slate-100">
                <livewire:home.date-filter defer.bundle />
            </div>
        </div>
    </section>


    {{-- ═══════════════════════════════════════════════════════ --}}
    {{-- UPCOMING EVENTS GRID                                    --}}
    {{-- ═══════════════════════════════════════════════════════ --}}
    <livewire:home.upcoming-events lazy.bundle />

    {{-- ═══════════════════════════════════════════════════════ --}}
    {{-- CTA SECTION                                             --}}
    {{-- ═══════════════════════════════════════════════════════ --}}
    <section class="relative py-24 overflow-hidden bg-slate-950">
        {{-- Background Effects --}}
        <div class="absolute inset-0 bg-pattern-islamic opacity-5 mix-blend-overlay" style="background-image: url('{{ asset('images/pattern-bg.png') }}');"></div>
        <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[800px] h-[800px] bg-emerald-900/20 rounded-full blur-[120px]"></div>
        <div class="absolute top-1/2 left-1/3 -translate-x-1/2 -translate-y-1/2 w-[600px] h-[600px] bg-gold-900/10 rounded-full blur-[100px]"></div>
        <div class="absolute inset-0 opacity-20 mix-blend-overlay" style="background-image: url('{{ asset('images/noise.svg') }}');"></div>

        <div class="container relative z-10 px-6 mx-auto">
            <div class="max-w-4xl mx-auto overflow-hidden text-center border shadow-2xl bg-white/5 backdrop-blur-2xl rounded-3xl border-white/10 ring-1 ring-white/10">
                <div class="px-8 py-16 sm:px-16 sm:py-20">
                    <h2 class="mb-6 text-3xl font-extrabold tracking-tight text-white font-heading sm:text-4xl lg:text-5xl">
                        {{ __('Ada Majlis Ilmu?') }}
                    </h2>
                    <p class="max-w-2xl mx-auto mb-10 text-lg leading-relaxed text-slate-300">
                        {{ __('Kongsikan kebaikan dengan masyarakat. Platform ini percuma untuk semua masjid, surau, dan penganjur majlis ilmu.') }}
                    </p>

                    <div class="flex flex-col items-center justify-center gap-4 sm:flex-row">
                        <a href="{{ route('submit-event.create') }}" wire:navigate
                            data-signal-event="submission.event_start_clicked"
                            data-signal-category="submission"
                            data-signal-component="home_submit_event_cta"
                            data-signal-control="submit_event"
                            class="inline-flex items-center gap-2 px-8 py-4 font-bold text-white transition-all transform rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 shadow-xl shadow-emerald-900/40 hover:shadow-emerald-500/25 hover:-translate-y-1 group border border-gold-500/20">
                            <svg class="w-5 h-5 transition-transform group-hover:rotate-12" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                            </svg>
                            {{ __('Hantar Majlis Sekarang') }}
                        </a>
                        <a href="#"
                            class="inline-flex items-center gap-2 px-8 py-4 font-medium transition-all rounded-xl text-slate-300 hover:text-white hover:bg-white/5">
                            {{ __('Ketahui Lanjut') }}
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>
