@extends('layouts.app')

@php
    $pageKey = $pageKey ?? 'privacy';
    $content = trans('legal.'.$pageKey);
    $pageContent = is_array($content) ? $content : [];
    $pageTitle = (string) data_get($pageContent, 'meta.title', __('Privacy'));
    $pageIntro = (string) data_get($pageContent, 'intro', '');
    $sections = data_get($pageContent, 'sections', []);
    $summary = data_get($pageContent, 'summary', []);
    $lastUpdated = (string) data_get(trans('legal'), 'last_updated', '');
    $legalTabs = [
        ['key' => 'privacy', 'route' => 'privacy', 'label' => __('Privacy')],
        ['key' => 'terms', 'route' => 'terms', 'label' => __('Terms')],
        ['key' => 'contact', 'route' => 'contact', 'label' => __('Contact Us')],
    ];
    $heroArt = match ($pageKey) {
        'terms' => [
            'desktopPng' => 'images/legal/terms-hero-desktop-v1.png',
            'desktopWebp' => 'images/legal/terms-hero-desktop-v1.webp',
            'mobileWebp' => 'images/legal/terms-hero-mobile-v1.webp',
            'width' => '1672',
            'height' => '941',
        ],
        'contact' => [
            'desktopPng' => 'images/legal/contact-hero-desktop-v1.png',
            'desktopWebp' => 'images/legal/contact-hero-desktop-v1.webp',
            'mobileWebp' => 'images/legal/contact-hero-mobile-v1.webp',
            'width' => '1672',
            'height' => '941',
        ],
        default => [
            'desktopPng' => 'images/legal/privacy-hero-desktop-v1.png',
            'desktopWebp' => 'images/legal/privacy-hero-desktop-v1.webp',
            'mobileWebp' => 'images/legal/privacy-hero-mobile-v1.webp',
            'width' => '1672',
            'height' => '941',
        ],
    };
@endphp

@section('title', $pageTitle . ' - ' . config('app.name'))
@section('meta_description', (string) data_get($pageContent, 'meta.description', $pageIntro))
@section('og_url', route($pageKey))
@section('og_image', asset($heroArt['desktopPng']))
@section('og_image_alt', $pageTitle)
@section('og_image_width', $heroArt['width'])
@section('og_image_height', $heroArt['height'])

@section('content')
    <div data-art-direction="living-majlis" class="living-majlis-field relative isolate overflow-x-clip text-[#12372d]">
        <section class="relative isolate overflow-hidden border-b border-emerald-900/[0.06]">
            <div data-material="hero-field" class="absolute inset-0 overflow-hidden bg-[#f7f3e8]">
                <picture class="absolute inset-0">
                    <source media="(max-width: 639px)" srcset="{{ asset($heroArt['mobileWebp']) }}" type="image/webp">
                    <img
                        srcset="{{ asset($heroArt['desktopWebp']) }}"
                        src="{{ asset($heroArt['desktopPng']) }}"
                        alt=""
                        aria-hidden="true"
                        class="absolute inset-0 h-full w-full object-cover object-center sm:object-[42%_center] lg:object-center"
                        width="{{ $heroArt['width'] }}"
                        height="{{ $heroArt['height'] }}"
                        loading="eager"
                        fetchpriority="high"
                        decoding="async"
                    >
                </picture>
                <div class="absolute inset-0 bg-gradient-to-r from-[#fffdf8]/78 via-[#fffdf8]/24 to-transparent"></div>
                <div class="absolute inset-0 sm:hidden" style="background: linear-gradient(90deg, rgba(255,253,248,.97) 0%, rgba(255,253,248,.92) 50%, rgba(255,253,248,.65) 78%, rgba(255,253,248,.28) 94%, rgba(255,253,248,.12) 100%);"></div>
            </div>

            <div class="relative mx-auto max-w-6xl px-5 pb-10 pt-48 sm:px-8 sm:pb-12 sm:pt-52 lg:px-10 lg:pt-52">
                <div class="max-w-3xl">
                    <h1 class="text-balance font-heading text-4xl font-semibold leading-[1.04] tracking-[-0.035em] text-[#0b3b2e] sm:text-5xl lg:text-6xl">
                        {{ $pageTitle }}
                    </h1>

                    <p class="mt-5 max-w-2xl text-base leading-8 text-[#4b635c] sm:text-lg">
                        {{ $pageIntro }}
                    </p>

                    @if (filled($lastUpdated))
                        <p class="mt-6 inline-flex items-center gap-2 rounded-full border border-[#dfe5d9] bg-white/75 px-3.5 py-1.5 text-xs font-semibold text-[#5d7169]">
                            <svg class="h-3.5 w-3.5 text-emerald-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l2.5 1.5M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                            </svg>
                            {{ $lastUpdated }}
                        </p>
                    @endif
                </div>

                <nav class="mt-8 inline-flex max-w-full flex-wrap gap-1 rounded-2xl border border-[#dde4d7] bg-white/70 p-1.5 shadow-sm backdrop-blur-sm" aria-label="{{ __('Legal pages') }}">
                    @foreach ($legalTabs as $tab)
                        <a
                            href="{{ route($tab['route']) }}"
                            wire:navigate
                            @if ($tab['key'] === $pageKey) aria-current="page" @endif
                            class="inline-flex min-h-10 items-center rounded-xl px-4 text-sm font-semibold transition {{ $tab['key'] === $pageKey ? 'bg-[#0e5b43] text-white shadow-sm' : 'text-[#3e5f54] hover:bg-white hover:text-[#0b3b2e]' }}"
                        >
                            {{ $tab['label'] }}
                        </a>
                    @endforeach
                </nav>
            </div>
        </section>

        <section class="mx-auto grid max-w-6xl gap-10 px-5 py-12 sm:px-8 sm:py-16 lg:grid-cols-[15rem_minmax(0,1fr)] lg:gap-14 lg:px-10">
            <aside class="hidden lg:block">
                <div class="sticky top-28">
                    <p class="text-[0.66rem] font-bold uppercase tracking-[0.2em] text-[#7b8f84]">{{ __('On this page') }}</p>
                    <nav class="mt-4 border-l border-[#dbe3d6]" aria-label="{{ __('On this page') }}">
                        <ul class="space-y-1">
                            @foreach ($sections as $index => $section)
                                <li>
                                    <a href="#seksyen-{{ $index + 1 }}" class="block border-l-2 border-transparent py-1.5 pl-4 text-sm leading-5 text-[#5b7268] transition hover:border-emerald-700 hover:text-emerald-900">
                                        {{ data_get($section, 'title') }}
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </nav>
                </div>
            </aside>

            <article class="min-w-0">
                <div class="rounded-[1.75rem] border border-[#e1e6dc] bg-white/85 p-6 shadow-[0_28px_70px_-48px_rgba(15,23,42,0.45)] backdrop-blur-sm sm:p-9 lg:p-11">
                    @if (is_array($summary) && $summary !== [])
                        <div class="rounded-2xl border border-emerald-800/10 bg-[#eef5ee] p-5 sm:p-6">
                            <ul class="grid gap-3 sm:grid-cols-3">
                                @foreach ($summary as $item)
                                    <li class="flex items-start gap-2.5 text-sm font-medium leading-6 text-[#2d5447]">
                                        <span class="mt-1 flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-emerald-700 text-white">
                                            <svg class="h-2.5 w-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="m5 13 4 4L19 7" />
                                            </svg>
                                        </span>
                                        <span>{{ $item }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @if ($pageKey === 'contact')
                        <div
                            x-data="{ emailAddress: '', emailRevealed: false }"
                            aria-live="polite"
                            class="mt-6"
                        >
                            <button
                                type="button"
                                x-show="!emailRevealed"
                                @click="emailAddress = String.fromCharCode(97, 100, 109, 105, 110, 64, 105, 108, 109, 117, 51, 54, 48, 46, 99, 111, 109); emailRevealed = true"
                                class="inline-flex items-center font-semibold text-[#0b3b2e] underline decoration-emerald-700/30 underline-offset-4 transition hover:text-emerald-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-800"
                            >
                                {{ data_get($pageContent, 'reveal_email') }}
                            </button>
                            <a
                                x-cloak
                                x-show="emailRevealed"
                                x-bind:href="'mailto:' + emailAddress"
                                x-text="emailAddress"
                                class="font-semibold text-[#0b3b2e] underline decoration-emerald-700/30 underline-offset-4 transition hover:text-emerald-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-800"
                            ></a>
                        </div>
                    @endif

                    <div class="mt-9">
                        @foreach ($sections as $index => $section)
                            <section id="seksyen-{{ $index + 1 }}" class="scroll-mt-28 border-t border-[#edf0e8] pt-8 first:border-0 first:pt-0 {{ $index > 0 ? 'mt-8' : '' }}">
                                <div class="flex items-start gap-3">
                                    @if ($pageKey !== 'contact')
                                        <span class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full border border-emerald-800/15 bg-[#f2f7f1] text-xs font-bold text-emerald-800">{{ $index + 1 }}</span>
                                    @endif
                                    <h2 class="font-heading text-xl font-semibold leading-8 tracking-[-0.015em] text-[#0b3b2e] sm:text-2xl">{{ data_get($section, 'title') }}</h2>
                                </div>

                                <div class="mt-3 {{ $pageKey === 'contact' ? '' : 'pl-10' }}">
                                    @foreach ((array) data_get($section, 'paragraphs', []) as $paragraph)
                                        <p class="mt-3 text-[0.95rem] leading-7 text-[#4b5f59] first:mt-0">{{ $paragraph }}</p>
                                    @endforeach

                                    @php($bullets = (array) data_get($section, 'bullets', []))
                                    @if ($bullets !== [])
                                        <ul class="mt-4 space-y-2.5">
                                            @foreach ($bullets as $bullet)
                                                <li class="flex items-start gap-2.5 text-[0.95rem] leading-7 text-[#4b5f59]">
                                                    <span class="mt-2.5 h-1.5 w-1.5 shrink-0 rounded-full bg-amber-500" aria-hidden="true"></span>
                                                    <span>{{ $bullet }}</span>
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif

                                    @if ($pageKey === 'contact')
                                        <div class="mt-5 flex flex-wrap gap-2">
                                            @foreach ([
                                                ['label' => 'X', 'icon' => 'x.svg', 'url' => 'https://x.com/ilmu360'],
                                                ['label' => 'Facebook', 'icon' => 'facebook.svg', 'url' => 'https://www.facebook.com/ilmu360'],
                                                ['label' => 'Instagram', 'icon' => 'instagram.svg', 'url' => 'https://www.instagram.com/ilmu.360'],
                                                ['label' => 'Threads', 'icon' => 'threads.svg', 'url' => 'https://www.threads.com/@ilmu.360'],
                                                ['label' => 'TikTok', 'icon' => 'tiktok.svg', 'url' => 'https://www.tiktok.com/@ilmu.360'],
                                            ] as $social)
                                                <a href="{{ $social['url'] }}" target="_blank" rel="noopener noreferrer" title="{{ $social['label'] }}" aria-label="{{ $social['label'] }}" class="inline-flex min-h-10 items-center gap-2 rounded-full border border-[#dfe6da] bg-white px-4 text-sm font-semibold text-[#2d5447] transition hover:border-emerald-700/30 hover:text-emerald-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-800">
                                                    <img src="{{ \App\Support\Media\SocialIcon::url($social['icon']) }}" alt="" class="h-5 w-5 object-contain" loading="lazy">
                                                    {{ $social['label'] }}
                                                </a>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            </section>
                        @endforeach
                    </div>
                </div>

            </article>
        </section>
    </div>
@endsection
