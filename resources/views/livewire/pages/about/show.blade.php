@section('title', (string) data_get($content, 'meta.title', __('About')))
@section('meta_description', (string) data_get($content, 'meta.description'))
@section('og_url', route('about'))
@section('og_image', asset('images/about/reference/page-hero.webp'))
@section('og_image_alt', (string) data_get($content, 'reference.hero.image_alt', data_get($content, 'meta.title')))
@section('og_image_width', '1902')
@section('og_image_height', '827')
@section('meta_og_type', 'website')

@push('head')
    <link rel="preload" as="image" href="{{ asset('images/about/reference/page-hero-mobile-v2.webp') }}" media="(max-width: 639px)" type="image/webp" fetchpriority="high">
    <link rel="preload" as="image" href="{{ asset('images/about/reference/page-hero.webp') }}" media="(min-width: 640px)" type="image/webp" fetchpriority="high">
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
    $reference = data_get($content, 'reference', []);
    $hero = data_get($reference, 'hero', []);
    $story = data_get($reference, 'story', []);
    $opportunity = data_get($reference, 'opportunity', []);
    $reality = data_get($reference, 'reality', []);
    $digital = data_get($reference, 'digital', []);
    $platform = data_get($reference, 'platform', []);
    $around = data_get($reference, 'around', []);
    $community = data_get($reference, 'community', []);
    $generation = data_get($reference, 'generation', []);
    $values = data_get($reference, 'values', []);
    $journey = data_get($reference, 'journey', []);
    $trust = data_get($reference, 'trust', []);
    $closing = data_get($reference, 'closing', []);

    $featureItems = data_get($platform, 'features', [
        ['title' => 'Cari majlis berdekatan', 'icon' => 'pin'],
        ['title' => 'Ikuti institusi', 'icon' => 'mosque'],
        ['title' => 'Kenali penceramah', 'icon' => 'person'],
        ['title' => 'Dapatkan kemas kini', 'icon' => 'bell'],
        ['title' => 'Bina komuniti ilmu', 'icon' => 'community'],
    ]);
    $aroundLabels = data_get($around, 'labels', [
        'At Home',
        'At Work',
        'On the Move',
        'At Nearby Masjids & Suraus',
        'In the Community & Neighbourhood',
        'Wherever We Go',
    ]);
    $communityItems = data_get($community, 'items', data_get($content, 'community.items', []));
    $valueItems = data_get($values, 'items', []) ?: [
        ['title' => 'Trust', 'body' => 'Clear and responsible information.'],
        ['title' => 'Knowledge', 'body' => 'Knowledge as a guide for good.'],
        ['title' => 'Connection', 'body' => 'Bring learners and organizers together.'],
        ['title' => 'Benefit', 'body' => 'Lasting benefit for the community.'],
    ];
    $journeyItems = data_get($journey, 'items', ['Temui', 'Hadir', 'Belajar', 'Berubah', 'Berkongsi']);
    $trustItems = data_get($trust, 'items', []) ?: [
        ['title' => 'Clear details', 'body' => 'Complete, organized information.', 'icon' => 'document'],
        ['title' => 'Community review', 'body' => 'Updates shared with the community.', 'icon' => 'community'],
        ['title' => 'Organized reference', 'body' => 'Easier search and discovery.', 'icon' => 'layers'],
    ];
    $realityItems = data_get($reality, 'items', [
        'Posters and WhatsApp announcements.',
        'Social media posts.',
        'Noticeboard schedules.',
        'Word of mouth.',
        'Updates that do not reach everyone.',
        'Schedule changes shared too late.',
    ]);

    $communityImages = [
        'images/about/reference/family-learning.webp',
        'images/about/reference/mosque-institution.webp',
        'images/about/reference/speaker-lecture.webp',
        'images/about/reference/community-gathering.webp',
    ];
    $aroundLabelPositions = [
        'sm:left-[3%] sm:top-[17%]',
        'sm:right-[3%] sm:top-[17%]',
        'sm:left-[3%] sm:top-1/2',
        'sm:right-[3%] sm:top-1/2',
        'sm:left-[3%] sm:top-[83%]',
        'sm:right-[3%] sm:top-[83%]',
    ];
    $journeyIcons = ['search', 'calendar', 'book', 'leaf', 'community'];
    $primaryButton = 'inline-flex min-h-11 items-center justify-center gap-2 rounded-full bg-[#006044] px-6 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-[#064d39] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#006044] focus-visible:ring-offset-2';
    $secondaryButton = 'inline-flex min-h-11 items-center justify-center gap-2 rounded-full border border-[#006044]/30 bg-white/85 px-6 py-2.5 text-sm font-semibold text-[#006044] transition hover:border-[#006044] hover:bg-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#006044] focus-visible:ring-offset-2';
    $sectionHeading = 'font-heading text-[clamp(1.8rem,3.2vw,2.6rem)] font-medium leading-[1.05] tracking-[-0.03em] text-[#183c2e] text-balance';
    $bodyText = 'text-[15px] leading-relaxed text-[#515d55] text-pretty';
@endphp

<main class="overflow-hidden bg-[#fbf9f4] text-[#20342b]">
    <section aria-labelledby="about-hero" class="relative isolate overflow-hidden bg-[#e9e0cc]">
        <picture class="absolute inset-0 -z-20">
            <source media="(max-width: 639px)" srcset="{{ asset('images/about/reference/page-hero-mobile-v2.webp') }}" type="image/webp">
            <img src="{{ asset('images/about/reference/page-hero.webp') }}" alt="" width="1902" height="827" fetchpriority="high" class="absolute inset-0 h-full w-full object-cover object-center">
        </picture>
        <div class="absolute inset-0 -z-10 bg-[linear-gradient(90deg,rgba(250,248,242,0.04)_0%,rgba(250,248,242,0.12)_34%,rgba(250,248,242,0.62)_58%,rgba(250,248,242,0.9)_100%)]"></div>
        <div class="mx-auto flex min-h-[36rem] max-w-[1240px] items-start px-5 pt-40 pb-12 sm:min-h-[460px] sm:items-center sm:px-8 sm:pt-36 lg:min-h-[520px] lg:px-10">
            <div class="mx-auto w-full max-w-[430px] text-center lg:ms-auto lg:me-0 lg:w-[58%] lg:text-right">
                <h1 id="about-hero" class="font-heading text-[clamp(2.1rem,4.6vw,3.6rem)] font-medium leading-[1.05] tracking-[-0.03em] text-[#173b2d] text-balance">{{ data_get($hero, 'title', data_get($content, 'hero.title', __('About Us'))) }}</h1>
                <p class="mx-auto mt-4 max-w-[42ch] text-sm leading-relaxed text-[#35433b] sm:text-[15px] lg:ms-auto lg:me-0 lg:text-base lg:text-right text-pretty">{{ data_get($hero, 'intro', data_get($content, 'hero.intro')) }}</p>
                <blockquote class="mx-auto mt-5 max-w-[34ch] border-s-2 border-[#d2a12f] ps-3 text-left font-heading text-[15px] font-semibold italic leading-snug text-[#14543e] sm:text-base lg:ms-auto lg:me-0 lg:border-s-0 lg:border-e-2 lg:pe-3 lg:ps-0 lg:text-right lg:text-lg">
                    “{{ data_get($hero, 'quote', data_get($content, 'hero.caption')) }}”
                </blockquote>
            </div>
        </div>
    </section>

    <section aria-labelledby="about-story" class="mx-auto grid max-w-[1240px] items-center gap-8 px-5 py-12 sm:px-8 sm:py-16 lg:grid-cols-[0.94fr_1.06fr] lg:gap-14 lg:px-10 lg:py-20">
        <div class="max-w-[36rem]">
            <h2 id="about-story" class="{{ $sectionHeading }}">{{ data_get($story, 'title', data_get($content, 'story.title')) }}</h2>
            <div class="mt-4 space-y-3 {{ $bodyText }}">
                @foreach (data_get($story, 'paragraphs', data_get($content, 'story.paragraphs', [])) as $paragraph)
                    <p>{{ $paragraph }}</p>
                @endforeach
            </div>
            <blockquote class="mt-5 flex gap-2 border-s-2 border-[#d2a12f] ps-3 font-heading text-[15px] font-semibold italic leading-snug text-[#14543e] sm:text-base lg:text-lg">
                <span class="text-2xl leading-[0.8] text-[#d2a12f]" aria-hidden="true">“</span>
                <span>{{ data_get($story, 'quote', data_get($content, 'story.pull_quote')) }}</span>
            </blockquote>
        </div>
        <img src="{{ asset('images/about/reference/story-arch-wide.webp') }}" alt="" width="1480" height="1063" loading="lazy" class="aspect-[1.42] w-full rounded-2xl object-cover object-center shadow-[0_18px_40px_-30px_rgba(33,48,39,0.55)]">
    </section>

    <section aria-labelledby="about-opportunity" class="relative isolate overflow-hidden bg-[#e9e0cc]">
        <img src="{{ asset('images/about/reference/opportunities-background.webp') }}" alt="" width="1903" height="826" loading="lazy" class="absolute inset-0 -z-20 h-full w-full object-cover object-[35%_center]">
        <div class="absolute inset-0 -z-10 bg-[#fbf9f4]/60"></div>
        <div class="mx-auto max-w-[1240px] px-5 py-14 text-center sm:px-8 sm:py-16 lg:px-10 lg:py-20">
            <h2 id="about-opportunity" class="{{ $sectionHeading }}">{{ data_get($opportunity, 'title', 'The Opportunity Is Huge.') }}</h2>
            <p class="mx-auto mt-3 max-w-[66ch] text-sm leading-relaxed text-[#48564d] sm:text-[15px] lg:text-base text-pretty">{{ data_get($opportunity, 'intro', data_get($content, 'meta.description')) }}</p>
            <div class="mx-auto mt-7 grid max-w-[520px] grid-cols-2 gap-4">
                <article class="rounded-2xl border border-[#b99648]/70 bg-[#064a35]/95 px-4 py-5 text-white shadow-md sm:px-6 sm:py-6">
                    <p class="font-heading text-[1.7rem] font-semibold leading-none tracking-tight text-[#e6c271] sm:text-[2.75rem]">{{ data_get($opportunity, 'mosques_value', '25,000+') }}</p>
                    <p class="mt-2 text-xs leading-snug text-white/90 sm:text-sm">{{ data_get($opportunity, 'mosques_label', 'Mosques and suraus across Malaysia') }}</p>
                </article>
                <article class="rounded-2xl border border-[#b99648]/70 bg-[#064a35]/95 px-4 py-5 text-white shadow-md sm:px-6 sm:py-6">
                    <p class="font-heading text-[1.7rem] font-semibold leading-none tracking-tight text-[#e6c271] sm:text-[2.75rem]">{{ data_get($opportunity, 'events_value', '300,000+') }}</p>
                    <p class="mt-2 text-xs leading-snug text-white/90 sm:text-sm">{{ data_get($opportunity, 'events_label', 'Learning opportunities each year') }}</p>
                </article>
            </div>
            <p class="mt-4 text-sm font-semibold text-[#14543e] sm:text-base">{{ data_get($opportunity, 'calculation', '25,000 masjids × 1 gathering a month × 12 months = 300,000+ learning opportunities a year') }}</p>
            <p class="mt-1.5 text-xs italic text-[#5d675e]">{{ data_get($opportunity, 'note', '* conservative estimate') }}</p>
        </div>
    </section>

    <section aria-label="{{ data_get($reality, 'title', 'Today\'s Reality.') }}" class="mx-auto grid max-w-[1240px] gap-5 px-5 py-10 sm:px-8 sm:py-12 lg:grid-cols-2 lg:px-10 lg:py-14">
        <article class="grid overflow-hidden rounded-2xl border border-[#eee7d9] bg-[#f5f1e8] shadow-[0_10px_30px_-26px_rgba(36,53,40,0.5)] sm:grid-cols-[0.85fr_1.15fr]">
            <img src="{{ asset('images/about/reference/bulletin-board.webp') }}" alt="" width="1536" height="1024" loading="lazy" class="aspect-[16/9] w-full object-cover sm:aspect-auto sm:h-full sm:min-h-[220px]">
            <div class="p-5 sm:p-6">
                <h2 class="font-heading text-xl font-semibold leading-tight text-[#203a2f] sm:text-2xl">{{ data_get($reality, 'title', 'Today\'s Reality.') }}</h2>
                <p class="mt-2 text-sm leading-relaxed text-[#606960]">{{ data_get($reality, 'intro', 'People want to attend, but information is scattered.') }}</p>
                <ul class="mt-4 space-y-2 text-[13px] leading-snug text-[#525d54]">
                    @foreach ($realityItems as $item)
                        <li class="flex gap-2"><span class="text-[#cc9b2e]" aria-hidden="true">•</span><span>{{ $item }}</span></li>
                    @endforeach
                </ul>
            </div>
        </article>
        <article class="grid overflow-hidden rounded-2xl border border-[#0a5640] bg-[#064a35] text-white shadow-[0_10px_30px_-26px_rgba(36,53,40,0.5)] sm:grid-cols-[0.85fr_1.15fr]">
            <img src="{{ asset('images/about/reference/digital-man.webp') }}" alt="" width="1136" height="1385" loading="lazy" class="aspect-[4/3] w-full object-cover object-top sm:aspect-auto sm:h-full sm:min-h-[220px] sm:object-center">
            <div class="flex flex-col justify-center p-5 sm:p-6">
                <h2 class="font-heading text-xl font-medium leading-tight sm:text-2xl">{{ data_get($digital, 'title', 'With ilmu360°.') }}</h2>
                <p class="mt-3 border-s-2 border-[#ddb249] ps-3 text-sm leading-relaxed text-white/85">{{ data_get($digital, 'body', 'A community platform to make learning events easier to find, follow and attend.') }}</p>
            </div>
        </article>
    </section>

    <section aria-labelledby="about-platform" class="mx-auto max-w-[1240px] px-5 pb-12 pt-2 text-center sm:px-8 sm:pb-16 lg:px-10 lg:pb-20">
        <h2 id="about-platform" class="{{ $sectionHeading }}">{{ data_get($platform, 'title', 'What Is ilmu360°?') }}</h2>
        <p class="mx-auto mt-3 max-w-[78ch] text-sm leading-relaxed text-[#555f56] sm:text-[15px] lg:text-base text-pretty">{{ data_get($platform, 'intro', data_get($content, 'meta.description')) }}</p>
        <div class="mt-8 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
            @foreach ($featureItems as $feature)
                <article class="flex min-h-[116px] flex-col items-center justify-center rounded-2xl border border-[#eee6d6] bg-white/70 px-3 py-4 shadow-[0_6px_20px_-18px_rgba(36,53,40,0.45)] {{ $loop->last && count($featureItems) % 2 !== 0 ? 'col-span-2 w-[calc(50%_-_0.375rem)] justify-self-center sm:col-span-1 sm:w-auto sm:justify-self-auto' : '' }}">
                    <svg class="h-7 w-7 shrink-0 text-[#bb8a23] sm:h-8 sm:w-8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        @if (data_get($feature, 'icon') === 'pin')
                            <path d="M20 10c0 5-8 11-8 11S4 15 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/>
                        @elseif (data_get($feature, 'icon') === 'mosque')
                            <path d="M3 20h18M5 20v-8h14v8M9 12V8l3-3 3 3v4M11 20v-4h2v4M3 12l9-8 9 8M12 2v2"/>
                        @elseif (data_get($feature, 'icon') === 'person')
                            <circle cx="12" cy="7" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2M9 21v-4h6v4"/>
                        @elseif (data_get($feature, 'icon') === 'bell')
                            <path d="M18 9a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9ZM10 21h4"/>
                        @else
                            <circle cx="9" cy="8" r="3"/><circle cx="17" cy="9" r="2.5"/><path d="M3 21v-1a6 6 0 0 1 12 0v1m2-5a4.5 4.5 0 0 1 4 4.5V21"/>
                        @endif
                    </svg>
                    <h3 class="mt-2 max-w-[18ch] font-heading text-[13px] font-semibold leading-snug text-[#344a3f] sm:text-sm">{{ data_get($feature, 'title') }}</h3>
                </article>
            @endforeach
        </div>
    </section>

    <section aria-labelledby="about-around" class="mx-auto grid max-w-[1480px] items-center gap-5 px-5 py-8 sm:gap-8 sm:px-8 sm:py-10 lg:grid-cols-[0.78fr_2.22fr] lg:gap-8 lg:px-3 lg:py-6">
        <div class="max-w-[22rem]">
            <h2 id="about-around" class="font-heading text-[clamp(2rem,3.7vw,3rem)] font-medium leading-[1.03] tracking-[-0.035em] text-[#183c2e] text-balance">{{ data_get($around, 'title', '360° in Everyday Life.') }}</h2>
            <p class="mt-4 max-w-[31ch] lg:text-base {{ $bodyText }}">{{ data_get($around, 'body', 'Explore learning opportunities across every part of daily life.') }}</p>
            <span class="mt-5 block h-[2px] w-14 bg-[#d2a12f]" aria-hidden="true"></span>
        </div>
        <div class="relative mx-auto w-full" role="group" aria-label="{{ data_get($around, 'title', '360° in Everyday Life.') }}">
            <div class="relative aspect-[3/4] w-full sm:aspect-[2.4/1]">
                <picture class="absolute inset-0">
                    <source media="(max-width: 639px)" srcset="{{ asset('images/about/reference/around-360-ribbons-mobile-v1.webp') }}" type="image/webp">
                    <img src="{{ asset('images/about/reference/around-360-ribbons.webp') }}" alt="" width="1944" height="809" loading="lazy" decoding="async" class="absolute inset-0 h-full w-full rounded-[1.25rem] object-cover">
                </picture>
            </div>

            <ul class="mt-4 grid grid-cols-2 gap-2 sm:absolute sm:inset-0 sm:z-20 sm:mt-0 sm:block" aria-label="{{ data_get($around, 'title', '360° in Everyday Life.') }}">
                @foreach ($aroundLabels as $index => $label)
                    <li class="flex min-h-11 items-center justify-center rounded-full border border-[#eee3c9] bg-[#fffdf7]/95 px-2 py-2 text-center font-sans text-xs font-bold leading-tight text-[#294638] shadow-[0_3px_10px_rgba(54,63,43,0.12)] sm:absolute sm:min-h-11 sm:w-[21%] sm:-translate-y-1/2 sm:px-1.5 sm:py-1 lg:min-h-16 lg:w-[18%] lg:px-3 lg:text-[15px] {{ $aroundLabelPositions[$index] ?? '' }}">
                        {{ $label }}
                    </li>
                @endforeach
            </ul>
        </div>
    </section>

    <section aria-labelledby="about-community" class="mx-auto max-w-[1240px] px-5 py-10 sm:px-8 sm:py-12 lg:px-10 lg:py-14">
        <h2 id="about-community" class="{{ $sectionHeading }}">{{ data_get($community, 'title', 'Who Is It For?') }}</h2>
        <div class="mt-6 grid gap-4 {{ count((array) $communityItems) >= 4 ? 'grid-cols-2 lg:grid-cols-4' : 'grid-cols-1 sm:grid-cols-3' }}">
            @foreach ($communityItems as $index => $item)
                <article class="overflow-hidden rounded-2xl border border-[#eee7d9] bg-white/75 shadow-[0_8px_24px_-22px_rgba(36,53,40,0.5)]">
                    <img src="{{ asset($communityImages[$index % count($communityImages)]) }}" alt="" width="640" height="420" loading="lazy" class="aspect-[1.75] w-full object-cover">
                    <div class="p-4 sm:p-5">
                        <h3 class="font-heading text-sm font-semibold leading-snug text-[#203a2f] sm:text-[15px]">{{ data_get($item, 'title') }}</h3>
                        <p class="mt-2 text-[13px] leading-relaxed text-[#606960]">{{ data_get($item, 'body') }}</p>
                    </div>
                </article>
            @endforeach
        </div>
    </section>

    <section aria-labelledby="about-generation" class="mx-auto grid max-w-[1240px] items-center gap-6 px-5 py-8 sm:px-8 sm:py-10 lg:grid-cols-[1.05fr_0.95fr] lg:gap-10 lg:px-10 lg:py-12">
        <img src="{{ asset('images/about/reference/digital-family-wide.webp') }}" alt="" width="2172" height="724" loading="lazy" class="aspect-[3.2] w-full rounded-2xl object-cover object-center shadow-[0_14px_30px_-24px_rgba(36,53,40,0.55)]">
        <div class="border-s-2 border-[#d2a12f] ps-4 sm:ps-5">
            <h2 id="about-generation" class="font-heading text-[clamp(1.6rem,2.8vw,2.25rem)] font-medium leading-[1.1] tracking-[-0.03em] text-[#183c2e] text-balance">{{ data_get($generation, 'title', 'Opening a path for a new generation.') }}</h2>
            <p class="mt-3 {{ $bodyText }}">{{ data_get($generation, 'body', 'ilmu360° brings learning events closer to a generation that lives online.') }}</p>
        </div>
    </section>

    <section aria-labelledby="about-values" class="bg-[#064a35] text-white">
        <div class="mx-auto grid max-w-[1240px] gap-8 px-5 py-12 sm:px-8 sm:py-14 lg:grid-cols-[0.85fr_2.15fr] lg:items-center lg:gap-12 lg:px-10 lg:py-16">
            <h2 id="about-values" class="whitespace-pre-line font-heading text-[clamp(1.8rem,2.8vw,2.5rem)] font-medium leading-[1.05] tracking-[-0.03em]">{{ data_get($values, 'title', 'Values that move us.') }}</h2>
            <div class="grid grid-cols-2 gap-6 sm:grid-cols-4 sm:gap-5">
                @foreach ($valueItems as $index => $item)
                    <article class="px-1 text-center sm:border-s sm:border-white/20 sm:px-4">
                        <span class="mx-auto flex h-8 w-9 items-center justify-center text-[#e4bb58] sm:h-9 sm:w-10" aria-hidden="true">
                            <svg class="h-full w-full" viewBox="0 0 32 28" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                @if ($index === 0)
                                    <path d="m3 10 4-4 6 1 3 3 3-3 6-1 4 4-4 5-4 2-3 4-3-3-3 3-3-4-4-2-4-5Z"/><path d="m11 14 4 4m-1-7 5 5m-1-6 5 4"/>
                                @elseif ($index === 1)
                                    <path d="M3 5c5-2 9-1 13 2 4-3 8-4 13-2v18c-5-2-9-1-13 2-4-3-8-4-13-2V5Z"/><path d="M16 7v18"/>
                                @elseif ($index === 2)
                                    <circle cx="12" cy="9" r="3"/><circle cx="22" cy="11" r="2.5"/><path d="M5 23v-1.5a7 7 0 0 1 14 0V23m1-6a5 5 0 0 1 7 4.5V23"/>
                                @else
                                    <path d="M16 25V12m0 7C9 19 6 15 6 9c6 0 10 2 10 7m0-1c0-6 3-9 9-9 0 6-3 10-9 10Z"/>
                                @endif
                            </svg>
                        </span>
                        <h3 class="mt-3 font-heading text-sm font-semibold leading-snug sm:text-[15px]">{{ data_get($item, 'title') }}</h3>
                        <p class="mx-auto mt-2 max-w-[28ch] text-xs leading-relaxed text-white/80 sm:text-[13px]">{{ data_get($item, 'body') }}</p>
                    </article>
                @endforeach
            </div>
        </div>
    </section>

    <section aria-labelledby="about-journey" class="mx-auto max-w-[1240px] px-5 py-7 sm:px-8 sm:py-8 lg:px-10">
        <h2 id="about-journey" class="sr-only">{{ data_get($journey, 'title', 'The impact keeps flowing.') }}</h2>

        <ol class="mx-auto grid w-full max-w-[760px] grid-cols-5 gap-2 sm:gap-5">
            @foreach ($journeyItems as $index => $item)
                <li class="relative rounded-[1.45rem] border border-[#eee5d2] bg-[#fffdf8]/85 px-0 py-3 text-center shadow-[0_8px_22px_-16px_rgba(36,53,40,0.5)] sm:px-2.5 sm:py-4">
                    <svg class="mx-auto h-5 w-5 text-[#bd8d28] sm:h-6 sm:w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        @if ($journeyIcons[$index % count($journeyIcons)] === 'search')
                            <circle cx="10.5" cy="10.5" r="6.5"/><path d="m15.5 15.5 5 5"/>
                        @elseif ($journeyIcons[$index % count($journeyIcons)] === 'calendar')
                            <rect x="3.5" y="5" width="17" height="16" rx="2"/><path d="M7.5 3.5v3M16.5 3.5v3M3.5 9h17M8 13h.01M12 13h.01M16 13h.01M8 17h.01M12 17h.01"/>
                        @elseif ($journeyIcons[$index % count($journeyIcons)] === 'book')
                            <path d="M3 5.5c3.3-1.2 6.5-.5 9 1.5 2.5-2 5.7-2.7 9-1.5v14c-3.3-1.2-6.5-.5-9 1.5-2.5-2-5.7-2.7-9-1.5v-14Z"/><path d="M12 7v14"/>
                        @elseif ($journeyIcons[$index % count($journeyIcons)] === 'leaf')
                            <path d="M20 4c-8 0-14 3-14 10a6 6 0 0 0 6 6c7 0 10-6 8-16ZM4 21 16 9"/>
                        @else
                            <circle cx="9" cy="8" r="3"/><circle cx="17" cy="9" r="2.5"/><path d="M3 21v-1a6 6 0 0 1 12 0v1m2-5a4.5 4.5 0 0 1 4 4.5V21"/>
                        @endif
                    </svg>
                    <h3 class="mt-1.5 font-heading text-[0.58rem] font-semibold leading-tight text-[#31483d] sm:text-xs">{{ $item }}</h3>
                    @if (! $loop->last)
                        <span class="absolute -end-2 top-1/2 z-10 -translate-y-1/2 text-lg text-[#c69832] sm:-end-4 sm:text-2xl" aria-hidden="true">›</span>
                    @endif
                </li>
            @endforeach
        </ol>

        <div class="mx-auto mt-3 flex w-full max-w-[760px] items-center justify-center gap-3 sm:mt-4 sm:gap-4">
            <span class="hidden h-3 flex-1 rounded-br-full border-b border-[#c9a853] sm:block" aria-hidden="true"></span>
            <p class="max-w-[56ch] text-center text-xs font-medium leading-relaxed text-[#4b5850] sm:text-sm">{{ data_get($journey, 'note', 'From one majlis, more people can know and practice good.') }}</p>
            <span class="hidden h-3 flex-1 rounded-bl-full border-b border-[#c9a853] sm:block" aria-hidden="true"></span>
        </div>

        <div class="mt-10 grid gap-6 border-t border-[#e8dfcd] pt-10 lg:grid-cols-[0.8fr_1.2fr] lg:items-start lg:gap-12">
            <div>
                <h2 class="font-heading text-[clamp(1.6rem,2.6vw,2.1rem)] font-medium leading-[1.1] tracking-[-0.03em] text-[#183c2e] text-balance">{{ data_get($trust, 'title', 'Our promise.') }}</h2>
                <p class="mt-3 {{ $bodyText }}">{{ data_get($trust, 'body', 'We keep majlis information organized, current and easy to understand.') }}</p>
            </div>
            <div class="grid gap-3 sm:grid-cols-3">
                @foreach ($trustItems as $index => $item)
                    <article class="rounded-xl border border-[#eee5d2] bg-white/70 p-4 sm:p-5">
                        <svg class="h-5 w-5 text-[#bd8d28] sm:h-6 sm:w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            @if (data_get($item, 'icon') === 'document')
                                <rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h5"/>
                            @elseif (data_get($item, 'icon') === 'community')
                                <circle cx="9" cy="8" r="3"/><circle cx="17" cy="9" r="2.5"/><path d="M3 21v-1a6 6 0 0 1 12 0v1m2-5a4.5 4.5 0 0 1 4 4.5V21"/>
                            @else
                                <path d="m12 3 9 5-9 5-9-5 9-5ZM3 12l9 5 9-5M3 16l9 5 9-5"/>
                            @endif
                        </svg>
                        <h3 class="mt-3 font-heading text-[13px] font-semibold leading-snug text-[#31483d] sm:text-sm">{{ data_get($item, 'title', $item) }}</h3>
                        <p class="mt-2 text-xs leading-relaxed text-[#626c63] sm:text-[13px]">{{ data_get($item, 'body') }}</p>
                    </article>
                @endforeach
            </div>
        </div>
    </section>

    <section aria-labelledby="about-closing" class="relative isolate overflow-hidden bg-[#f2f3e8]">
        <picture class="absolute inset-0 -z-20">
            <source media="(max-width: 639px)" srcset="{{ asset('images/about/reference/closing-mobile-v1.webp') }}" type="image/webp">
            <img src="{{ asset('images/about/reference/closing-continuous.webp') }}" alt="" width="2172" height="724" loading="lazy" class="absolute inset-0 h-full w-full object-cover object-center">
        </picture>
        <div class="absolute inset-0 -z-10 bg-[linear-gradient(to_bottom,#fafaf4_0%,rgba(250,250,244,0.6)_10%,transparent_28%,transparent_68%,rgba(242,243,232,0.6)_88%,#f2f3e8_100%)]"></div>
        <div class="mx-auto max-w-[1240px] px-5 py-16 text-center sm:px-8 sm:py-20 lg:px-10 lg:py-24">
            <h2 id="about-closing" class="mx-auto max-w-[18ch] font-heading text-[clamp(2rem,4vw,3.1rem)] font-medium leading-[1.05] tracking-[-0.03em] text-[#173b2d] text-balance">{{ data_get($closing, 'title', 'Let’s Explore 360° of Learning Opportunities.') }}</h2>
            <p class="mx-auto mt-4 max-w-[64ch] text-sm leading-relaxed text-[#49574e] sm:text-[15px] lg:text-base text-pretty">{{ data_get($closing, 'body', data_get($content, 'meta.description')) }}</p>
            <div class="mt-7 flex flex-wrap justify-center gap-3">
                <a href="{{ route('events.index') }}" wire:navigate class="{{ $primaryButton }}">{{ data_get($closing, 'primary', 'Explore majlis now') }} <svg class="h-4 w-4 rtl:rotate-180" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M4 10h12m-5-5 5 5-5 5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" /></svg></a>
                <a href="{{ route('submit-event.create') }}" wire:navigate class="{{ $secondaryButton }}">{{ data_get($closing, 'secondary', 'Submit a majlis') }}</a>
            </div>
        </div>
    </section>
</main>
