@section('title', (string) data_get($content, 'meta.title', __('About')) . ' - ' . config('app.name'))
@section('meta_description', (string) data_get($content, 'meta.description'))
@section('og_url', route('about'))
@section('og_image', asset('images/default-mosque-hero.png'))
@section('og_image_alt', data_get($content, 'meta.title', __('About')))
@section('og_image_width', '1024')
@section('og_image_height', '1024')

@push('head')
@endpush

@php
    $hero = data_get($content, 'hero', []);
    $heroPanel = data_get($content, 'hero_panel', []);
    $stats = data_get($content, 'stats', []);
    $sections = data_get($content, 'sections', []);
    $causes = data_get($content, 'causes', []);
    $losses = data_get($content, 'losses', []);
    $definition = data_get($content, 'definition', []);
    $proof = data_get($content, 'proof', []);
    $magnet = data_get($content, 'magnet', []);
    $impact = data_get($content, 'impact', []);
    $motivation = data_get($content, 'motivation', []);
    $images = data_get($content, 'images', []);
    $cta = data_get($content, 'cta', []);
    $sectionImages = [
        '/images/about/section_01.png',
        '/images/about/section_02.png',
        '/images/about/section_03.png',
    ];
@endphp

<div
    class="relative w-full overflow-hidden bg-white pb-24 font-sans selection:bg-emerald-200 selection:text-emerald-900">
    <!-- HERO SECTION -->
    <section class="relative min-h-[90vh] flex items-center justify-center overflow-hidden bg-slate-950 text-white">
        <!-- Abstract Background -->
        <div class="absolute inset-0 bg-slate-950">
            <div
                class="absolute inset-0 opacity-40 bg-cover bg-center mix-blend-screen mix-blend-luminosity" style="background-image: url('{{ asset('images/about/islamic_geometry.png') }}');">
            </div>
            <div
                class="absolute inset-0 bg-[radial-gradient(ellipse_80%_80%_at_50%_-20%,rgba(16,185,129,0.3),rgba(255,255,255,0))]">
            </div>
            <div class="absolute bottom-0 h-1/2 w-full bg-gradient-to-t from-slate-950 to-transparent"></div>
        </div>

        <div class="container relative z-10 mx-auto px-6 py-32 lg:px-12 lg:py-40">
            <div class="grid gap-16 lg:grid-cols-2 lg:items-center">
                <div class="max-w-2xl">
                    <div
                        class="inline-flex items-center gap-3 rounded-full border border-emerald-400/30 bg-emerald-400/10 px-5 py-2 text-sm font-bold uppercase tracking-widest text-emerald-300 backdrop-blur-md">
                        <span class="relative flex h-2 w-2">
                            <span
                                class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
                        </span>
                        {{ data_get($hero, 'page_label') }}
                    </div>

                    <h1
                        class="mt-8 font-heading text-6xl font-black leading-[1.1] tracking-tight text-white xl:text-7xl drop-shadow-2xl">
                        {{ data_get($hero, 'title') }}
                    </h1>

                    <p class="mt-8 text-2xl font-medium leading-relaxed text-emerald-100/90 text-pretty">
                        {{ data_get($hero, 'intro') }}
                    </p>

                    <div class="mt-8 text-lg leading-8 text-slate-300 space-y-4">
                        <p>{{ data_get($hero, 'lead') }}</p>
                        <p>{{ data_get($hero, 'body') }}</p>
                    </div>

                    <div class="mt-10 flex flex-wrap gap-4">
                        <a href="{{ route('register') }}" wire:navigate
                            class="group relative inline-flex items-center justify-center gap-2 overflow-hidden rounded-full bg-emerald-500 px-8 py-4 text-base font-bold text-slate-950 transition-all hover:bg-emerald-400 hover:scale-105 hover:shadow-[0_0_40px_-10px_rgba(52,211,153,0.8)] focus:outline-none focus:ring-4 focus:ring-emerald-500/30">
                            {{ data_get($cta, 'primary') }}
                            <svg class="h-4 w-4 transition-transform group-hover:translate-x-1" viewBox="0 0 20 20"
                                fill="currentColor">
                                <path fill-rule="evenodd"
                                    d="M3 10a.75.75 0 01.75-.75h10.638L10.23 5.29a.75.75 0 111.04-1.08l5.5 5.25a.75.75 0 010 1.08l-5.5 5.25a.75.75 0 11-1.04-1.08l4.158-3.96H3.75A.75.75 0 013 10z"
                                    clip-rule="evenodd" />
                            </svg>
                        </a>
                        <a href="{{ route('events.index') }}" wire:navigate
                            class="inline-flex items-center justify-center rounded-full border border-white/20 bg-white/5 px-8 py-4 text-base font-bold text-white backdrop-blur-md transition-all hover:bg-white/10 hover:border-white/40">
                            {{ data_get($cta, 'secondary') }}
                        </a>
                    </div>
                </div>

                <div class="relative hidden lg:block">
                    <!-- Image composite -->
                    <div
                        class="relative w-full aspect-[4/5] rounded-[2.5rem] overflow-hidden shadow-2xl ring-1 ring-white/20 transform md:-rotate-2 transition-transform duration-700 hover:rotate-0">
                        <img src="/images/about/hero_learning.png"
                            alt="{{ data_get($images, 'hero_alt', 'Islamic Learning') }}"
                            class="absolute inset-0 h-full w-full object-cover object-center filter saturate-110">
                        <div
                            class="absolute inset-0 bg-gradient-to-t from-slate-950/80 via-slate-950/20 to-transparent">
                        </div>

                        <!-- Floating Panel inside image -->
                        <div
                            class="absolute bottom-6 left-6 right-6 rounded-[1.5rem] border border-white/20 bg-slate-950/60 p-6 backdrop-blur-xl shadow-[0_20px_40px_-10px_rgba(0,0,0,0.5)]">
                            <div class="flex items-center justify-between mb-3">
                                <span
                                    class="text-xs font-bold uppercase tracking-widest text-emerald-400">{{ data_get($heroPanel, 'eyebrow') }}</span>
                                <span
                                    class="rounded-full bg-emerald-500/20 text-emerald-300 border border-emerald-400/30 px-3 py-1 text-[10px] font-black uppercase tracking-widest">
                                    {{ data_get($heroPanel, 'badge') }}
                                </span>
                            </div>
                            <h3 class="font-heading text-xl font-bold text-white">{{ data_get($heroPanel, 'title') }}
                            </h3>
                            <p class="mt-2 text-sm text-slate-300 line-clamp-2">{{ data_get($heroPanel, 'body') }}</p>
                        </div>
                    </div>

                    <!-- Decorative elements -->
                    <div class="absolute -right-12 -top-12 h-64 w-64 rounded-full bg-teal-500/20 blur-[80px]"></div>
                    <div class="absolute -left-12 -bottom-12 h-64 w-64 rounded-full bg-emerald-500/20 blur-[80px]">
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- STATS (Overlapping Hero) -->
    <section class="container relative z-20 mx-auto -mt-16 px-6 lg:px-12">
        <div class="grid gap-6 md:grid-cols-3">
            @foreach ($stats as $stat)
                <div
                    class="group relative overflow-hidden rounded-[2rem] bg-white p-8 shadow-[0_20px_40px_-15px_rgba(15,23,42,0.1)] ring-1 ring-slate-200 transition-all hover:-translate-y-2 hover:shadow-[0_40px_60px_-15px_rgba(15,23,42,0.15)]">
                    <div
                        class="absolute -right-4 -top-4 h-24 w-24 rounded-full bg-emerald-50 transition-transform group-hover:scale-[3]">
                    </div>
                    <div class="relative z-10">
                        <div class="text-5xl font-black tracking-tight text-emerald-600 drop-shadow-sm">
                            {{ data_get($stat, 'value') }}</div>
                        <h2 class="mt-2 font-heading text-xl font-bold tracking-tight text-slate-900">
                            {{ data_get($stat, 'title') }}</h2>
                        <p class="mt-3 text-sm leading-relaxed text-slate-600">{{ data_get($stat, 'body') }}</p>
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    <!-- MAIN VISION SECTIONS -->
    <section class="container mx-auto px-6 py-32 lg:px-12">
        <div class="grid gap-12 lg:gap-24">
            @foreach ($sections as $index => $section)
                <div
                    class="flex flex-col {{ $index % 2 == 1 ? 'lg:flex-row-reverse' : 'lg:flex-row' }} items-center gap-12 lg:gap-20">
                    <div class="flex-1 space-y-8">
                        <div>
                            <span
                                class="inline-block text-sm font-bold uppercase tracking-[0.3em] text-emerald-600 mb-4">{{ data_get($section, 'eyebrow') }}</span>
                            <h2
                                class="font-heading text-4xl font-black tracking-tight text-slate-900 sm:text-5xl drop-shadow-sm">
                                {{ data_get($section, 'title') }}
                            </h2>
                        </div>
                        <div class="space-y-6 text-lg leading-relaxed text-slate-600">
                            @foreach (data_get($section, 'paragraphs', []) as $paragraph)
                                <p>{{ $paragraph }}</p>
                            @endforeach
                        </div>
                    </div>
                    <div class="flex-1 w-full relative">
                        <div class="relative aspect-square w-full sm:aspect-video lg:aspect-[4/3] rounded-[2.5rem] overflow-hidden shadow-2xl ring-1 ring-slate-200/50 transform {{ $index % 2 == 1 ? 'md:rotate-2' : 'md:-rotate-2' }} transition-transform duration-700 hover:rotate-0">
                            <img src="{{ $sectionImages[$index] ?? '/images/about/islamic_geometry.png' }}"
                                alt="{{ data_get($section, 'title') }}"
                                class="absolute inset-0 h-full w-full object-cover">
                            <div class="absolute inset-0 bg-gradient-to-t from-slate-950/40 via-transparent to-transparent"></div>
                        </div>
                        <div class="absolute -bottom-4 {{ $index % 2 == 1 ? '-left-4' : '-right-4' }} h-32 w-32 rounded-full bg-emerald-400/20 blur-3xl"></div>
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    <!-- FULL BLEED PARALLAX BREAK -->
    <section
        class="relative flex min-h-[60vh] items-center justify-center overflow-hidden bg-slate-900 py-32 text-center shadow-inner">
        <div class="absolute inset-0">
            <img src="/images/about/majlis_gathering.png"
                alt="{{ data_get($images, 'gathering_alt', 'Majlis Gathering') }}"
                class="h-full w-full object-cover opacity-40 mix-blend-overlay">
            <div class="absolute inset-0 bg-gradient-to-b from-slate-950/80 via-slate-900/60 to-slate-950/90"></div>
        </div>

        <div class="container relative z-10 mx-auto px-6 lg:px-12 max-w-4xl">
            <span class="text-emerald-400 font-bold tracking-[0.3em] text-sm uppercase mb-6 block">
                {{ data_get($motivation, 'eyebrow') }}
            </span>
            <p class="font-heading text-3xl font-light leading-snug text-white sm:text-5xl lg:leading-tight">
                "{{ data_get($motivation, 'quote') }}" <br />
                <span class="text-xl sm:text-2xl mt-4 block text-emerald-100/70 font-medium">—
                    {{ data_get($motivation, 'attribution') }}</span>
            </p>
        </div>
    </section>

    <!-- CAUSES / WHY WE DO THIS -->
    <section class="relative isolate overflow-hidden bg-slate-950 py-32 text-white">
        <div
            class="absolute inset-0 bg-[size:400px] opacity-[0.05] mix-blend-screen" style="background-image: url('{{ asset('images/about/islamic_geometry.png') }}');">
        </div>
        <div class="absolute top-0 w-full h-px bg-gradient-to-r from-transparent via-emerald-500/50 to-transparent">
        </div>

        <div class="container relative z-10 mx-auto px-6 lg:px-12">
            <div class="max-w-3xl mb-16">
                <p class="text-sm font-bold uppercase tracking-[0.3em] text-emerald-400 mb-4 flex items-center gap-3">
                    <span class="h-px w-8 bg-emerald-400"></span>
                    {{ data_get($causes, 'eyebrow') }}
                </p>
                <h2 class="font-heading text-5xl font-black tracking-tight drop-shadow-lg">
                    {{ data_get($causes, 'title') }}</h2>
            </div>

            <div class="grid gap-6 md:grid-cols-2 xl:grid-cols-4">
                @foreach (data_get($causes, 'items', []) as $item)
                    <div
                        class="relative group rounded-[2rem] border border-white/10 bg-white/5 p-8 backdrop-blur-md transition-all hover:-translate-y-2 hover:bg-white/10 hover:border-emerald-500/30 hover:shadow-[0_20px_40px_-15px_rgba(16,185,129,0.3)]">
                        <div
                            class="absolute top-0 right-8 -translate-y-1/2 rounded-b-xl bg-emerald-500 px-3 py-1 font-heading text-lg font-black text-slate-950 shadow-lg">
                            {{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}
                        </div>
                        <h3 class="mt-4 font-heading text-2xl font-bold text-white">{{ data_get($item, 'title') }}</h3>
                        <p class="mt-4 text-base leading-relaxed text-slate-300">{{ data_get($item, 'body') }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <!-- PROBLEM VS SOLUTION (LOSSES VS DEFINITION) -->
    <section class="container mx-auto px-6 py-32 lg:px-12">
        <div class="grid gap-12 lg:grid-cols-2 lg:gap-8">
            <!-- Losses Structure -->
            <div
                class="flex flex-col rounded-[2.5rem] bg-gradient-to-br from-slate-50 to-slate-100 p-8 shadow-[0_30px_60px_-15px_rgba(15,23,42,0.1)] ring-1 ring-slate-200/50 sm:p-12">
                <p class="text-sm font-bold uppercase tracking-[0.3em] text-slate-500 mb-4 flex items-center gap-3">
                    <svg class="w-5 h-5 text-rose-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z">
                        </path>
                    </svg>
                    {{ data_get($losses, 'eyebrow') }}
                </p>
                <h2 class="font-heading text-4xl font-black tracking-tight text-slate-900 mb-8">
                    {{ data_get($losses, 'title') }}</h2>

                <div class="flex-1 space-y-4">
                    @foreach (data_get($losses, 'items', []) as $item)
                        <div
                            class="flex items-start gap-4 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-100 hover:shadow-md transition-shadow">
                            <div
                                class="mt-1 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-rose-100 text-rose-600">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </div>
                            <p class="text-lg leading-relaxed text-slate-700">{{ $item }}</p>
                        </div>
                    @endforeach
                </div>
            </div>

            <!-- Definition Structure -->
            <div
                class="relative flex flex-col overflow-hidden rounded-[2.5rem] bg-slate-950 p-8 text-white shadow-[0_30px_60px_-15px_rgba(15,23,42,0.5)] sm:p-12">
                <div
                    class="absolute top-0 right-0 w-full h-full opacity-20 bg-cover mix-blend-screen" style="background-image: url('{{ asset('images/about/islamic_geometry.png') }}');">
                </div>
                <!-- glow -->
                <div class="absolute -right-20 -top-20 w-80 h-80 bg-emerald-500/30 rounded-full blur-[100px]"></div>

                <div class="relative z-10 h-full flex flex-col">
                    <p
                        class="text-sm font-bold uppercase tracking-[0.3em] text-emerald-400 mb-4 flex items-center gap-3">
                        <svg class="w-5 h-5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        {{ data_get($definition, 'eyebrow') }}
                    </p>
                    <h2 class="font-heading text-4xl font-black tracking-tight mb-6">
                        {{ data_get($definition, 'title') }}</h2>
                    <p class="text-lg leading-relaxed text-slate-300 mb-10">{{ data_get($definition, 'body') }}</p>

                    <div class="mt-auto space-y-4">
                        @foreach (data_get($definition, 'shifts', []) as $shift)
                            <div
                                class="relative overflow-hidden rounded-2xl border border-white/10 bg-white/5 p-6 backdrop-blur-sm transition-colors hover:bg-white/10">
                                <div
                                    class="relative z-10 flex flex-col items-center gap-4 text-center md:flex-row md:justify-between md:text-left">
                                    <p
                                        class="flex-1 text-base font-medium text-slate-400 line-through decoration-rose-500/50 decoration-2">
                                        {{ data_get($shift, 'before') }}</p>
                                    <div
                                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-emerald-500/20 text-emerald-400 ring-1 ring-emerald-500/30">
                                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M14 5l7 7m0 0l-7 7m7-7H3" />
                                        </svg>
                                    </div>
                                    <p class="flex-1 text-lg font-bold text-white">{{ data_get($shift, 'after') }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- MAGNET & PROOF -->
    <section class="container mx-auto px-6 pb-32 lg:px-12">
        <div class="grid gap-8 lg:grid-cols-[1fr_1.2fr]">
            <!-- Magnet -->
            <div
                class="rounded-[2.5rem] bg-white p-8 sm:p-12 shadow-[0_20px_50px_-15px_rgba(15,23,42,0.1)] ring-1 ring-slate-200">
                <p class="text-sm font-bold uppercase tracking-[0.3em] text-emerald-600 mb-4">
                    {{ data_get($magnet, 'eyebrow') }}</p>
                <h2 class="font-heading text-4xl font-black tracking-tight text-slate-900 mb-8">
                    {{ data_get($magnet, 'title') }}</h2>
                <div class="space-y-6 text-lg leading-relaxed text-slate-600">
                    @foreach (data_get($magnet, 'paragraphs', []) as $paragraph)
                        <p>{{ $paragraph }}</p>
                    @endforeach
                </div>
            </div>

            <!-- Proof -->
            <div
                class="relative overflow-hidden rounded-[2.5rem] bg-emerald-50 p-8 sm:p-12 ring-1 ring-emerald-200 shadow-[inset_0_2px_4px_rgba(255,255,255,0.5)]">
                <!-- Bg pattern -->
                <div
                    class="absolute right-0 top-0 opacity-10 blur-sm scale-150 transform translate-x-1/3 -translate-y-1/3">
                    <img src="/images/about/islamic_geometry.png" class="w-full h-full object-cover"
                        alt="" />
                </div>

                <div class="relative z-10">
                    <p
                        class="text-sm font-bold uppercase tracking-[0.3em] text-emerald-700 mb-4 ring-1 ring-emerald-500/20 inline-block px-4 py-1.5 rounded-full bg-emerald-100/50 backdrop-blur-sm">
                        {{ data_get($proof, 'eyebrow') }}</p>
                    <h2 class="font-heading text-4xl font-black tracking-tight text-slate-900 mb-6">
                        {{ data_get($proof, 'title') }}</h2>
                    <p class="text-lg leading-relaxed text-slate-700 mb-10 max-w-2xl">{{ data_get($proof, 'body') }}</p>

                    <div class="grid gap-5 sm:grid-cols-2">
                        @foreach (data_get($proof, 'items', []) as $item)
                            <div
                                class="rounded-2xl border border-emerald-100 bg-white/80 p-6 backdrop-blur-md shadow-sm transition hover:shadow-md hover:scale-[1.02] transform origin-bottom">
                                <h3 class="font-heading text-xl font-bold flex items-center gap-3 text-slate-900">
                                    <span
                                        class="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-500 text-white shadow-md">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                                                d="M5 13l4 4L19 7" />
                                        </svg>
                                    </span>
                                    {{ data_get($item, 'title') }}
                                </h3>
                                <p class="mt-4 text-base leading-relaxed text-slate-600">{{ data_get($item, 'body') }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- IMPACT -->
    <section class="bg-slate-50 border-y border-slate-200">
        <div class="container mx-auto px-6 py-32 lg:px-12">
            <div class="text-center max-w-3xl mx-auto mb-16">
                <p class="text-sm font-bold uppercase tracking-[0.3em] text-emerald-600 mb-4">
                    {{ data_get($impact, 'eyebrow') }}</p>
                <h2 class="font-heading text-4xl font-black tracking-tight text-slate-900 sm:text-5xl drop-shadow-sm">
                    {{ data_get($impact, 'title') }}</h2>
            </div>

            <div class="grid gap-8 lg:grid-cols-3">
                @foreach (data_get($impact, 'items', []) as $item)
                    <div
                        class="group flex flex-col rounded-[2.5rem] bg-white p-10 shadow-[0_20px_50px_-15px_rgba(15,23,42,0.05)] ring-1 ring-slate-200 transition-all hover:-translate-y-2 hover:shadow-[0_40px_60px_-15px_rgba(15,23,42,0.1)]">
                        <div
                            class="mb-6 flex h-16 w-16 items-center justify-center rounded-2xl bg-gradient-to-br from-emerald-400 to-teal-500 font-heading text-2xl font-black text-white shadow-lg shadow-emerald-500/30">
                            {{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}
                        </div>
                        <h3 class="mb-6 font-heading text-3xl font-black tracking-tight text-slate-900">
                            {{ data_get($item, 'title') }}</h3>
                        <div class="mt-auto space-y-4 text-lg leading-relaxed text-slate-600">
                            @foreach (data_get($item, 'paragraphs', []) as $paragraph)
                                <p>{{ $paragraph }}</p>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <!-- CTA SECTION -->
    <section class="container mx-auto px-6 py-32 lg:px-12 relative">
        <div
            class="relative overflow-hidden rounded-[3rem] bg-slate-950 px-8 py-20 text-white shadow-[0_40px_100px_-20px_rgba(16,185,129,0.5)] sm:px-16 md:py-24 text-center md:text-left">
            <!-- Background Image parallax layer -->
            <div class="absolute inset-0 z-0">
                <img src="/images/about/islamic_geometry.png" alt=""
                    class="absolute w-full h-full object-cover transform scale-110 opacity-30 mix-blend-color-dodge filter saturate-200">
                <div class="absolute inset-0 bg-gradient-to-br from-emerald-950/90 via-slate-950/95 to-slate-950/90">
                </div>
            </div>

            <div class="relative z-10 grid gap-12 lg:grid-cols-[1fr_auto] lg:items-center">
                <div class="max-w-3xl mx-auto lg:mx-0">
                    <h2
                        class="font-heading text-5xl font-black tracking-tight sm:text-6xl drop-shadow-lg text-transparent bg-clip-text bg-gradient-to-r from-white to-emerald-200">
                        {{ data_get($cta, 'title') }}</h2>
                    <p class="mt-6 text-xl leading-relaxed text-emerald-100/90">{{ data_get($cta, 'body') }}</p>
                    <p class="mt-8 text-2xl font-bold text-white border-l-4 border-emerald-400 pl-6">
                        {{ data_get($cta, 'closing') }}</p>
                </div>

                <div class="flex flex-col items-center lg:items-end gap-4 w-full sm:w-auto">
                    <a href="{{ route('register') }}" wire:navigate
                        class="w-full sm:w-auto inline-flex items-center justify-center rounded-full bg-emerald-500 px-10 py-5 text-lg font-bold text-slate-950 transition-all hover:scale-105 hover:bg-emerald-400 hover:shadow-[0_0_40px_-10px_rgba(52,211,153,0.8)] focus:outline-none">
                        {{ data_get($cta, 'primary') }}
                    </a>
                    <a href="{{ route('events.index') }}" wire:navigate
                        class="w-full sm:w-auto inline-flex items-center justify-center rounded-full border border-white/20 bg-white/10 px-10 py-5 text-lg font-bold text-white backdrop-blur-md transition-all hover:bg-white/20 hover:border-white/40">
                        {{ data_get($cta, 'secondary') }}
                    </a>
                    <a href="{{ route('submit-event.create') }}" wire:navigate
                        class="mt-2 text-sm font-semibold text-emerald-400 transition hover:text-emerald-300 underline underline-offset-4 decoration-emerald-400/30 hover:decoration-emerald-300">
                        {{ data_get($cta, 'tertiary') }} &rarr;
                    </a>
                </div>
            </div>

            <!-- decorative circles -->
            <div class="absolute -top-24 -left-24 w-64 h-64 bg-emerald-500/20 rounded-full blur-[80px]"></div>
            <div class="absolute -bottom-24 -right-24 w-64 h-64 bg-teal-500/30 rounded-full blur-[80px]"></div>
        </div>
    </section>
</div>
