@extends('layouts.app')

@php
    $pageKey = $pageKey ?? 'privacy';
    $content = trans('legal.'.$pageKey);
    $pageContent = is_array($content) ? $content : [];
    $pageTitle = (string) data_get($pageContent, 'meta.title', __('Privacy'));
    $pageIntro = (string) data_get($pageContent, 'intro', '');
    $sections = data_get($pageContent, 'sections', []);
    $channels = data_get($pageContent, 'channels', []);
    $summary = data_get($pageContent, 'summary', []);
    $lastUpdated = (string) data_get(trans('legal'), 'last_updated', '');
    $legalTabs = [
        ['key' => 'privacy', 'route' => 'privacy', 'label' => __('Privacy')],
        ['key' => 'terms', 'route' => 'terms', 'label' => __('Terms')],
        ['key' => 'contact', 'route' => 'contact', 'label' => __('Contact Us')],
    ];
@endphp

@section('title', $pageTitle . ' - ' . config('app.name'))
@section('meta_description', (string) data_get($pageContent, 'meta.description', $pageIntro))
@section('og_url', route($pageKey))
@section('og_image', asset('images/default-mosque-hero.png'))
@section('og_image_alt', $pageTitle)

@section('content')
    <div class="relative isolate overflow-hidden bg-[#f8f7f1] text-[#12372d]">
        <div class="pointer-events-none absolute inset-0 -z-10">
            <div class="absolute -left-32 -top-48 h-[34rem] w-[34rem] rounded-full bg-emerald-700/[0.07] blur-[90px]"></div>
            <div class="absolute -right-40 top-10 h-[30rem] w-[30rem] rounded-full bg-amber-400/[0.08] blur-[80px]"></div>
            <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-emerald-900/10 to-transparent"></div>
        </div>

        <section class="relative border-b border-emerald-900/[0.06]">
            <div class="mx-auto max-w-6xl px-5 pb-10 pt-14 sm:px-8 sm:pb-12 sm:pt-16 lg:px-10 lg:pt-20">
                <div class="max-w-3xl">
                    <p class="inline-flex items-center gap-2 rounded-full border border-emerald-800/15 bg-white/70 px-3.5 py-1.5 text-[0.66rem] font-bold uppercase tracking-[0.18em] text-emerald-800 shadow-sm backdrop-blur-sm">
                        <span class="h-1.5 w-1.5 rounded-full bg-amber-500" aria-hidden="true"></span>
                        {{ data_get($pageContent, 'eyebrow') }}
                    </p>

                    <h1 class="mt-5 text-balance font-heading text-4xl font-semibold leading-[1.04] tracking-[-0.035em] text-[#0b3b2e] sm:text-5xl lg:text-6xl">
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

                    @if (is_array($channels) && $channels !== [])
                        <div class="mt-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                            @foreach ($channels as $channel)
                                @php
                                    $channelType = (string) data_get($channel, 'type', 'route');
                                    $channelHref = $channelType === 'email'
                                        ? 'mailto:'.data_get($channel, 'mailto')
                                        : route((string) data_get($channel, 'route'));
                                @endphp
                                <a
                                    href="{{ $channelHref }}"
                                    @if ($channelType !== 'email') wire:navigate @endif
                                    class="group flex h-full flex-col rounded-2xl border border-[#dfe6da] bg-white p-5 transition hover:-translate-y-0.5 hover:border-emerald-700/30 hover:shadow-[0_18px_40px_-30px_rgba(11,59,46,0.55)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-800"
                                >
                                    <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-[#eef5ee] text-emerald-800">
                                        @if ($channelType === 'email')
                                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5v10.5H3.75V6.75Zm0 .5 8.25 6 8.25-6" />
                                            </svg>
                                        @elseif (data_get($channel, 'route') === 'contributions.index')
                                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="m16.5 4.5 3 3L8 19H5v-3L16.5 4.5Z" />
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M14.5 6.5 17.5 9.5" />
                                            </svg>
                                        @else
                                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3.75 8.25h16.5M5.25 5.25h13.5A1.5 1.5 0 0 1 20.25 6.75v12A1.5 1.5 0 0 1 18.75 20.25H5.25A1.5 1.5 0 0 1 3.75 18.75v-12A1.5 1.5 0 0 1 5.25 5.25Z" />
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 11.25v6m-3-3h6" />
                                            </svg>
                                        @endif
                                    </span>
                                    <span class="mt-4 text-[0.66rem] font-bold uppercase tracking-[0.18em] text-[#7b8f84]">{{ data_get($channel, 'label') }}</span>
                                    <span class="mt-1 break-words text-base font-bold leading-6 text-[#0b3b2e] group-hover:text-emerald-800">{{ data_get($channel, 'value') }}</span>
                                    <span class="mt-2 text-sm leading-6 text-[#5b7268]">{{ data_get($channel, 'description') }}</span>
                                </a>
                            @endforeach
                        </div>
                    @endif

                    <div class="mt-9">
                        @foreach ($sections as $index => $section)
                            <section id="seksyen-{{ $index + 1 }}" class="scroll-mt-28 border-t border-[#edf0e8] pt-8 first:border-0 first:pt-0 {{ $index > 0 ? 'mt-8' : '' }}">
                                <div class="flex items-start gap-3">
                                    <span class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full border border-emerald-800/15 bg-[#f2f7f1] text-xs font-bold text-emerald-800">{{ $index + 1 }}</span>
                                    <h2 class="font-heading text-xl font-semibold leading-8 tracking-[-0.015em] text-[#0b3b2e] sm:text-2xl">{{ data_get($section, 'title') }}</h2>
                                </div>

                                <div class="mt-3 pl-10">
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

                                    @if ($pageKey === 'contact' && $index === 2)
                                        <div class="mt-5 flex flex-wrap gap-2">
                                            @foreach ([
                                                ['label' => 'X', 'url' => 'https://x.com/ilmu360'],
                                                ['label' => 'Instagram', 'url' => 'https://www.instagram.com/ilmu.360'],
                                                ['label' => 'TikTok', 'url' => 'https://www.tiktok.com/@ilmu.360'],
                                                ['label' => 'YouTube', 'url' => 'https://www.youtube.com/'],
                                            ] as $social)
                                                <a href="{{ $social['url'] }}" target="_blank" rel="noopener noreferrer" class="inline-flex min-h-10 items-center gap-2 rounded-full border border-[#dfe6da] bg-white px-4 text-sm font-semibold text-[#2d5447] transition hover:border-emerald-700/30 hover:text-emerald-900">
                                                    {{ $social['label'] }}
                                                    <span aria-hidden="true">↗</span>
                                                </a>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            </section>
                        @endforeach
                    </div>
                </div>

                <div class="mt-8 flex flex-col gap-4 rounded-2xl border border-[#dfe6da] bg-white/70 p-5 sm:flex-row sm:items-center sm:justify-between sm:p-6">
                    <div>
                        <p class="font-heading text-lg font-semibold text-[#0b3b2e]">{{ __('Looking for a gathering?') }}</p>
                        <p class="mt-1 text-sm leading-6 text-[#5b7268]">{{ __('Browse upcoming majlis ilmu near you.') }}</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <a href="{{ route('events.index') }}" wire:navigate class="inline-flex min-h-10 items-center gap-2 rounded-xl bg-[#0e5b43] px-4 text-sm font-bold text-white transition hover:bg-[#0a4634]">
                            {{ __('Browse Majlis') }} <span aria-hidden="true">→</span>
                        </a>
                        <a href="{{ route('about') }}" wire:navigate class="inline-flex min-h-10 items-center gap-2 rounded-xl border border-[#dbe3d6] bg-white px-4 text-sm font-bold text-[#2d5447] transition hover:border-emerald-700/30 hover:text-emerald-900">
                            {{ __('About Us') }}
                        </a>
                    </div>
                </div>
            </article>
        </section>
    </div>
@endsection
