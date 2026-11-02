@section('title', (string) data_get($content, 'meta.title', __('About')) . ' - ' . config('app.name'))
@section('meta_description', (string) data_get($content, 'meta.description'))
@section('og_url', route('about'))
@section('og_image', asset('images/about/surau-window.png'))
@section('og_image_alt', (string) data_get($content, 'hero.image_alt'))
@section('og_image_width', '1086')
@section('og_image_height', '1448')
@section('meta_og_type', 'website')

@push('head')
    <link rel="preload" as="image" href="{{ asset('images/about/surau-window.png') }}" fetchpriority="high">
    <link rel="canonical" href="{{ route('about') }}">
    <script type="application/ld+json">
        {!! json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'AboutPage',
            'name' => (string) data_get($content, 'meta.title'),
            'description' => (string) data_get($content, 'meta.description'),
            'url' => route('about'),
            'inLanguage' => app()->getLocale(),
            'about' => [
                '@type' => 'Organization',
                'name' => 'ilmu360°',
                'url' => url('/'),
                'logo' => asset('images/logo-ilmu360.png'),
                'sameAs' => [
                    'https://x.com/ilmu360',
                    'https://www.instagram.com/ilmu.360',
                    'https://www.tiktok.com/@ilmu.360',
                ],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}
    </script>
@endpush

@php
    $hero = data_get($content, 'hero', []);
    $gap = data_get($content, 'gap', []);
    $cost = data_get($content, 'cost', []);
    $work = data_get($content, 'work', []);
    $path = data_get($content, 'path', []);
    $audience = data_get($content, 'audience', []);
    $pageStats = data_get($content, 'stats', []);
    $hadith = data_get($content, 'hadith', []);
    $cta = data_get($content, 'cta', []);

    $primaryButton = 'inline-flex min-h-12 items-center justify-center rounded-full bg-[#006044] px-7 text-[15px] font-semibold text-[#fbf6ea] transition-colors duration-200 hover:bg-[#065f46] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#006044] focus-visible:ring-offset-2 focus-visible:ring-offset-[#f2e9d5]';
    $textLink = 'inline-flex min-h-12 items-center border-b border-[#006044]/30 text-[15px] font-semibold text-[#006044] transition-colors duration-200 hover:border-[#006044] hover:text-[#054d38] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#006044] focus-visible:ring-offset-2 focus-visible:ring-offset-[#f2e9d5]';
    $sheet = 'rounded-3xl border border-[#e5dccb] bg-[#fbf6ea] shadow-[0_1px_2px_rgba(45,44,43,0.05),0_12px_32px_rgba(45,44,43,0.08)]';

    // Serif italic reads as voice in the Latin-script locales; synthetic obliques on
    // Chinese and Tamil glyphs are a typographic defect, so those stay upright.
    $emphasisClass = in_array(app()->getLocale(), ['ms', 'en', 'jv'], true) ? 'italic' : '';
@endphp

<div class="w-full bg-[#f2e9d5] text-[#2d2c2b]">

    {{-- Hero ---------------------------------------------------------------- --}}
    <section aria-labelledby="about-hero" class="mx-auto max-w-[1240px] px-5 pt-12 sm:px-6 lg:px-10 lg:pt-20">
        <div class="grid items-end gap-10 lg:grid-cols-[minmax(0,1.1fr)_minmax(0,0.9fr)] lg:gap-16">
            <div class="max-w-[36rem]">
                <h1 id="about-hero"
                    class="font-heading text-[clamp(2.4rem,6.2vw,4.6rem)] font-normal leading-[1.04] tracking-[-0.02em] text-[#17191c] text-balance">
                    {{ data_get($hero, 'title') }}
                    <span class="mt-1 block {{ $emphasisClass }} text-[#006044]">{{ data_get($hero, 'emphasis') }}</span>
                </h1>

                <p class="mt-7 max-w-[54ch] text-[17px] leading-[28px] text-[#2d2c2b] text-pretty">
                    {{ data_get($hero, 'intro') }}
                </p>

                <div class="mt-9 flex flex-wrap items-center gap-x-8 gap-y-3">
                    <a href="{{ route('events.index') }}" wire:navigate class="{{ $primaryButton }}">
                        {{ data_get($hero, 'primary') }}
                    </a>
                    <a href="{{ route('register') }}" wire:navigate class="{{ $textLink }}">
                        {{ data_get($hero, 'secondary') }}
                    </a>
                </div>
            </div>

            <figure class="relative">
                <div class="overflow-hidden rounded-xl border border-[#e5dccb] bg-[#fbf6ea] p-2 shadow-[0_1px_2px_rgba(45,44,43,0.05),0_18px_40px_-12px_rgba(45,44,43,0.22)]">
                    <img src="{{ asset('images/about/surau-window.png') }}"
                        alt="{{ data_get($hero, 'image_alt') }}" width="1086" height="1448" fetchpriority="high"
                        class="h-[420px] w-full rounded-lg object-cover object-[50%_45%] sm:h-[520px] lg:h-auto lg:aspect-[3/4]">
                </div>
            </figure>
        </div>

        <div class="mt-12 h-px w-full bg-[#e3b537] lg:mt-16" aria-hidden="true"></div>
    </section>

    {{-- The gap ------------------------------------------------------------- --}}
    <section aria-labelledby="about-gap" class="mx-auto mt-16 max-w-[1240px] px-5 sm:px-6 lg:mt-24 lg:px-10">
        <div class="{{ $sheet }} grid gap-10 p-7 sm:p-10 lg:grid-cols-[minmax(0,1.15fr)_minmax(0,0.85fr)] lg:gap-16 lg:p-14">
            <div>
                <p class="font-heading text-[17px] {{ $emphasisClass }} leading-6 text-[#6b645e]">{{ data_get($gap, 'label') }}</p>

                <h2 id="about-gap"
                    class="mt-4 font-heading text-[clamp(1.85rem,3.4vw,2.5rem)] font-normal leading-[1.12] tracking-[-0.01em] text-[#17191c] text-balance">
                    {{ data_get($gap, 'title') }}
                </h2>

                <div class="mt-6 space-y-5 text-[17px] leading-[28px] text-[#2d2c2b] text-pretty">
                    @foreach (data_get($gap, 'paragraphs', []) as $paragraph)
                        <p>{{ $paragraph }}</p>
                    @endforeach
                </div>
            </div>

            <div class="self-center">
                <p class="font-heading text-[17px] {{ $emphasisClass }} leading-6 text-[#6b645e]">
                    {{ data_get($gap, 'chain.title') }}</p>

                <ol class="relative mt-5 space-y-3">
                    <span class="pointer-events-none absolute inset-y-3 left-1/2 w-px -translate-x-1/2 bg-[#e3b537]/40"
                        aria-hidden="true"></span>

                    @foreach (data_get($gap, 'chain.items', []) as $item)
                        <li
                            class="relative rounded-xl border border-[#e5dccb] bg-white px-6 py-5 shadow-[0_1px_2px_rgba(45,44,43,0.05)]">
                            <span
                                class="absolute left-1/2 top-0 h-2.5 w-2.5 -translate-x-1/2 -translate-y-1/2 rounded-full bg-[radial-gradient(circle_at_32%_30%,#e8c87a,#9a7a2e)] ring-2 ring-[#f0e3c4]"
                                aria-hidden="true"></span>
                            <p class="text-center font-heading text-[17px] leading-[1.55] text-[#2d2c2b]">
                                {{ $item }}</p>
                        </li>
                    @endforeach
                </ol>
            </div>
        </div>
    </section>

    {{-- What it costs ------------------------------------------------------- --}}
    <section aria-labelledby="about-cost" class="mt-20 lg:mt-28">
        <div class="relative h-[38vh] min-h-[260px] w-full overflow-hidden lg:h-[46vh]">
            <img src="{{ asset('images/about/surau-hall.png') }}" alt="{{ data_get($cost, 'image_alt') }}"
                width="1536" height="1024" loading="lazy"
                class="h-full w-full object-cover object-center">
            <div class="absolute inset-0 bg-[linear-gradient(to_top,#f2e9d5_0%,rgba(242,233,213,0.72)_14%,rgba(242,233,213,0)_52%)]"
                aria-hidden="true"></div>
        </div>

        <div class="relative z-10 mx-auto -mt-20 max-w-[1240px] px-5 sm:px-6 lg:-mt-28 lg:px-10">
            <div class="{{ $sheet }} p-7 sm:p-10 lg:p-14">
                <h2 id="about-cost"
                    class="max-w-[24ch] font-heading text-[clamp(1.85rem,3.8vw,2.85rem)] font-normal leading-[1.1] tracking-[-0.01em] text-[#17191c] text-balance">
                    {{ data_get($cost, 'title') }}
                </h2>

                <p class="mt-5 max-w-[62ch] text-[17px] leading-[28px] text-[#6b645e] text-pretty">
                    {{ data_get($cost, 'intro') }}
                </p>

                <dl class="mt-10 grid gap-x-14 sm:grid-cols-2">
                    @foreach (data_get($cost, 'items', []) as $item)
                        <div class="border-t border-[#e5dccb] py-6">
                            <dt class="font-heading text-xl leading-7 text-[#006044]">{{ data_get($item, 'title') }}</dt>
                            <dd class="mt-2 text-[17px] leading-[28px] text-[#2d2c2b] text-pretty">
                                {{ data_get($item, 'body') }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        </div>
    </section>

    {{-- What it does -------------------------------------------------------- --}}
    <section aria-labelledby="about-work" class="mx-auto mt-20 max-w-[1240px] px-5 sm:px-6 lg:mt-28 lg:px-10">
        <div class="grid gap-10 lg:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)] lg:gap-20">
            <div class="lg:sticky lg:top-28 lg:self-start">
                <h2 id="about-work"
                    class="font-heading text-[clamp(1.85rem,3.4vw,2.5rem)] font-normal leading-[1.12] tracking-[-0.01em] text-[#17191c] text-balance">
                    {{ data_get($work, 'title') }}
                </h2>
                <p class="mt-5 max-w-[42ch] text-[17px] leading-[28px] text-[#2d2c2b] text-pretty">
                    {{ data_get($work, 'intro') }}
                </p>
            </div>

            <div>
                <ul>
                    @foreach (data_get($work, 'items', []) as $item)
                        <li class="flex gap-4 border-t border-[#e5dccb] py-6">
                            <svg class="mt-1 h-5 w-5 shrink-0 text-[#006044]" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                            </svg>
                            <div>
                                <h3 class="font-heading text-xl leading-7 text-[#17191c]">{{ data_get($item, 'title') }}
                                </h3>
                                <p class="mt-1.5 text-[17px] leading-[28px] text-[#2d2c2b] text-pretty">
                                    {{ data_get($item, 'body') }}</p>
                            </div>
                        </li>
                    @endforeach
                </ul>

                <div class="mt-6 flex gap-3 rounded-2xl border border-[#006044]/20 bg-white p-5 shadow-[0_1px_2px_rgba(45,44,43,0.05)]">
                    <svg class="mt-0.5 h-5 w-5 shrink-0 text-[#006044]" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" />
                    </svg>
                    <p class="text-sm leading-6 text-[#2d2c2b]">
                        {{ data_get($work, 'note') }}
                    </p>
                </div>
            </div>
        </div>
    </section>

    {{-- The calm path ------------------------------------------------------- --}}
    <section aria-labelledby="about-path" class="mx-auto mt-20 max-w-[1240px] px-5 sm:px-6 lg:mt-28 lg:px-10">
        <div class="{{ $sheet }} p-7 sm:p-10 lg:p-14">
            <p class="font-heading text-[17px] {{ $emphasisClass }} leading-6 text-[#6b645e]">
                {{ data_get($path, 'intro') }}</p>

            <h2 id="about-path"
                class="mt-4 max-w-[26ch] font-heading text-[clamp(1.85rem,3.4vw,2.5rem)] font-normal leading-[1.12] tracking-[-0.01em] text-[#17191c] text-balance">
                {{ data_get($path, 'title') }}
            </h2>

            <ol class="mt-10 grid gap-10 sm:grid-cols-2 sm:gap-8 lg:grid-cols-4">
                @foreach (data_get($path, 'items', []) as $item)
                    <li class="border-t-2 border-[#e3b537] pt-5">
                        <h3 class="font-heading text-xl leading-7 text-[#17191c]">{{ data_get($item, 'title') }}</h3>
                        <p class="mt-3 text-[17px] leading-[28px] text-[#2d2c2b] text-pretty">
                            {{ data_get($item, 'body') }}</p>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>

    {{-- Who it is for ------------------------------------------------------- --}}
    <section aria-labelledby="about-audience"
        class="mx-auto mt-20 max-w-[1240px] px-5 sm:px-6 lg:mt-28 lg:px-10">
        <h2 id="about-audience"
            class="max-w-[26ch] font-heading text-[clamp(1.85rem,3.4vw,2.5rem)] font-normal leading-[1.12] tracking-[-0.01em] text-[#17191c] text-balance">
            {{ data_get($audience, 'title') }}
        </h2>

        <div class="mt-10 grid gap-10 sm:grid-cols-2 lg:grid-cols-3 sm:gap-8">
            @foreach (data_get($audience, 'items', []) as $item)
                <div class="border-t-2 border-[#e3b537] pt-5">
                    <h3 class="font-heading text-xl leading-7 text-[#17191c]">{{ data_get($item, 'title') }}</h3>
                    <p class="mt-3 text-[17px] leading-[28px] text-[#2d2c2b] text-pretty">{{ data_get($item, 'body') }}
                    </p>
                </div>
            @endforeach
        </div>
    </section>

    @if (($stats['events'] ?? 0) + ($stats['institutions'] ?? 0) + ($stats['speakers'] ?? 0) > 0)
        {{-- Living proof -------------------------------------------------------- --}}
        <section aria-labelledby="about-stats" class="mx-auto mt-20 max-w-[1240px] px-5 sm:px-6 lg:mt-28 lg:px-10">
            <div class="{{ $sheet }} p-7 text-center sm:p-10 lg:p-14">
                <h2 id="about-stats"
                    class="mx-auto max-w-[26ch] font-heading text-[clamp(1.85rem,3.4vw,2.5rem)] font-normal leading-[1.12] tracking-[-0.01em] text-[#17191c] text-balance">
                    {{ data_get($pageStats, 'title') }}
                </h2>

                <p class="mx-auto mt-4 max-w-[62ch] text-[17px] leading-[28px] text-[#6b645e] text-pretty">
                    {{ data_get($pageStats, 'intro') }}
                </p>

                <dl
                    class="mx-auto mt-10 grid max-w-[54rem] grid-cols-1 gap-8 sm:grid-cols-3 sm:gap-6 sm:divide-x sm:divide-[#e5dccb]">
                    <div class="px-2">
                        <dd
                            class="font-heading text-[clamp(2rem,4vw,2.9rem)] font-normal leading-none text-[#006044] tabular-nums">
                            {{ number_format((int) ($stats['events'] ?? 0)) }}</dd>
                        <dt class="mt-2 text-[15px] font-semibold leading-6 text-[#2d2c2b]">
                            {{ data_get($pageStats, 'events') }}</dt>
                    </div>
                    <div class="px-2">
                        <dd
                            class="font-heading text-[clamp(2rem,4vw,2.9rem)] font-normal leading-none text-[#006044] tabular-nums">
                            {{ number_format((int) ($stats['institutions'] ?? 0)) }}</dd>
                        <dt class="mt-2 text-[15px] font-semibold leading-6 text-[#2d2c2b]">
                            {{ data_get($pageStats, 'institutions') }}</dt>
                    </div>
                    <div class="px-2">
                        <dd
                            class="font-heading text-[clamp(2rem,4vw,2.9rem)] font-normal leading-none text-[#006044] tabular-nums">
                            {{ number_format((int) ($stats['speakers'] ?? 0)) }}</dd>
                        <dt class="mt-2 text-[15px] font-semibold leading-6 text-[#2d2c2b]">
                            {{ data_get($pageStats, 'speakers') }}</dt>
                    </div>
                </dl>
            </div>
        </section>
    @endif

    {{-- Hadith -------------------------------------------------------------- --}}
    <section aria-labelledby="about-hadith"
        class="mx-auto mt-20 max-w-[1240px] px-5 sm:px-6 lg:mt-28 lg:px-10">
        <div
            class="relative mx-auto max-w-[68rem] overflow-hidden rounded-3xl bg-[#006044] px-7 py-14 text-center shadow-[0_2px_4px_rgba(45,44,43,0.06),0_18px_40px_-18px_rgba(0,96,68,0.45)] sm:px-12 lg:py-20">
            <svg class="pointer-events-none absolute inset-0 h-full w-full text-[#e3b537] opacity-[0.13]"
                aria-hidden="true" focusable="false">
                <defs>
                    <pattern id="about-girih" width="120" height="120" patternUnits="userSpaceOnUse">
                        <g fill="none" stroke="currentColor" stroke-width="1">
                            <rect x="24" y="24" width="72" height="72" />
                            <rect x="24" y="24" width="72" height="72" transform="rotate(45 60 60)" />
                            <circle cx="60" cy="60" r="51" />
                            <circle cx="60" cy="60" r="22" />
                        </g>
                    </pattern>
                </defs>
                <rect width="100%" height="100%" fill="url(#about-girih)" />
            </svg>

            <figure class="relative mx-auto max-w-[46rem]">
                <p class="font-heading text-[15px] {{ $emphasisClass }} leading-6 text-[#e3b537]">
                    {{ data_get($hadith, 'title') }}</p>

                <blockquote
                    class="mt-6 font-heading text-[clamp(1.6rem,3.6vw,2.5rem)] font-normal {{ $emphasisClass }} leading-[1.25] text-[#fbf6ea] text-balance">
                    “{{ data_get($hadith, 'quote') }}”
                </blockquote>

                <figcaption class="mt-7 text-sm leading-6 text-[#fbf6ea]/70">
                    {{ data_get($hadith, 'attribution') }}
                </figcaption>

                <p class="mt-6 font-heading text-[15px] leading-6 tracking-wide text-[#e3b537]">
                    Dengar. Faham. Amal.</p>
            </figure>
        </div>
    </section>

    {{-- Closing ------------------------------------------------------------- --}}
    <section aria-labelledby="about-cta" class="mt-20 pb-24 lg:mt-28">
        <div class="relative h-[46vh] min-h-[300px] w-full overflow-hidden lg:h-[54vh]">
            <img src="{{ asset('images/about/surau-congregation.png') }}" alt="{{ data_get($cta, 'image_alt') }}"
                width="1536" height="1024" loading="lazy"
                class="h-full w-full object-cover object-[50%_32%]">
            <div class="absolute inset-0 bg-[linear-gradient(to_top,#f2e9d5_0%,rgba(242,233,213,0.72)_14%,rgba(242,233,213,0)_52%)]"
                aria-hidden="true"></div>
        </div>

        <div class="relative z-10 mx-auto -mt-24 max-w-[1240px] px-5 sm:px-6 lg:-mt-28 lg:px-10">
            <div class="{{ $sheet }} max-w-[42rem] p-7 sm:p-10 lg:p-14">
                <h2 id="about-cta"
                    class="font-heading text-[clamp(1.85rem,3.8vw,2.75rem)] font-normal leading-[1.1] tracking-[-0.01em] text-[#17191c] text-balance">
                    {{ data_get($cta, 'title') }}
                </h2>

                <p class="mt-5 max-w-[52ch] text-[17px] leading-[28px] text-[#2d2c2b] text-pretty">
                    {{ data_get($cta, 'body') }}
                </p>

                <div class="mt-8 flex flex-wrap items-center gap-4">
                    <a href="{{ route('register') }}" wire:navigate
                        class="{{ $primaryButton }} w-full sm:w-auto">
                        {{ data_get($cta, 'primary') }}
                    </a>
                    <a href="{{ route('events.index') }}" wire:navigate
                        class="inline-flex min-h-12 w-full items-center justify-center rounded-full border border-[#006044]/30 px-7 text-[15px] font-semibold text-[#006044] transition-colors duration-200 hover:border-[#006044] hover:bg-[#006044]/5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#006044] focus-visible:ring-offset-2 focus-visible:ring-offset-[#fbf6ea] sm:w-auto">
                        {{ data_get($cta, 'secondary') }}
                    </a>
                </div>

                <a href="{{ route('submit-event.create') }}" wire:navigate
                    class="mt-6 inline-flex min-h-11 items-center border-b border-[#006044]/30 text-sm font-semibold text-[#006044] transition-colors duration-200 hover:border-[#006044] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#006044] focus-visible:ring-offset-2 focus-visible:ring-offset-[#fbf6ea]">
                    {{ data_get($cta, 'tertiary') }}
                </a>
            </div>
        </div>
    </section>

</div>
