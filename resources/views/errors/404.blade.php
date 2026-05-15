<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    dir="{{ in_array(app()->getLocale(), config('app.rtl_locales', []), true) ? 'rtl' : 'ltr' }}"
    class="h-full scroll-smooth">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Page Not Found') }} - {{ config('app.name') }}</title>
    <meta name="description" content="{{ __('The page you are looking for could not be found.') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>

<body class="min-h-screen bg-slate-950 font-sans text-white antialiased">
    <main class="relative isolate flex min-h-screen items-center overflow-hidden">
        <div class="absolute inset-0">
            <div class="absolute inset-0 bg-[radial-gradient(circle_at_top,_rgba(16,185,129,0.25),_transparent_42%),radial-gradient(circle_at_bottom_right,_rgba(20,184,166,0.18),_transparent_34%),linear-gradient(180deg,#020617_0%,#0f172a_100%)]"></div>
            <div class="absolute inset-0 opacity-[0.08]" style="background-image:url('{{ asset('images/pattern-bg.png') }}');background-size:360px"></div>
        </div>

        <div class="relative mx-auto flex w-full max-w-6xl flex-col gap-10 px-6 py-16 lg:flex-row lg:items-center lg:justify-between lg:px-12">
            <div class="max-w-2xl">
                <p class="text-sm font-semibold uppercase tracking-[0.35em] text-emerald-300/90">{{ __('Error 404') }}</p>
                <h1 class="mt-6 font-heading text-5xl font-black tracking-tight text-balance text-white sm:text-6xl lg:text-7xl">
                    {{ __('This page wandered off the map.') }}
                </h1>
                <p class="mt-6 max-w-xl text-base leading-8 text-slate-300 sm:text-lg">
                    {{ __('The link may be outdated, the page may have moved, or the address may have been typed incorrectly. You can head back to the homepage or continue browsing upcoming majlis.') }}
                </p>

                <div class="mt-10 flex flex-wrap gap-4">
                    <a href="{{ route('home') }}"
                        class="inline-flex items-center justify-center rounded-full bg-emerald-500 px-6 py-3 text-sm font-semibold text-slate-950 shadow-lg shadow-emerald-500/20 transition hover:bg-emerald-400">
                        {{ __('Back to Home') }}
                    </a>
                    <a href="{{ route('events.index') }}"
                        class="inline-flex items-center justify-center rounded-full border border-white/15 bg-white/5 px-6 py-3 text-sm font-semibold text-white transition hover:border-emerald-300/40 hover:bg-white/10">
                        {{ __('Browse Events') }}
                    </a>
                </div>
            </div>

            <div class="w-full max-w-md shrink-0">
                <div class="rounded-[2rem] border border-white/10 bg-white/5 p-6 shadow-2xl shadow-black/30 backdrop-blur-xl">
                    <div class="rounded-[1.5rem] border border-white/10 bg-slate-900/70 p-8">
                        <div class="flex items-center gap-4">
                            <img src="{{ asset('images/milogo.webp') }}" alt="{{ config('app.name') }}" class="h-14 w-14 rounded-2xl shadow-lg">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-[0.3em] text-emerald-300/80">{{ config('app.name') }}</p>
                                <p class="mt-1 text-sm text-slate-300">{{ __('Discover lectures, classes, and gatherings across Malaysia.') }}</p>
                            </div>
                        </div>

                        <div class="mt-10 rounded-[1.5rem] border border-dashed border-white/10 bg-slate-950/60 p-8 text-center">
                            <p class="text-[5rem] font-black leading-none text-white/90">404</p>
                            <p class="mt-3 text-sm font-medium uppercase tracking-[0.25em] text-slate-400">{{ __('Not Found') }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</body>

</html>
