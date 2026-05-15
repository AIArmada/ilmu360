<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    dir="{{ in_array(app()->getLocale(), config('app.rtl_locales', []), true) ? 'rtl' : 'ltr' }}"
    class="h-full scroll-smooth">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Server Error') }} - {{ config('app.name') }}</title>
    <meta name="description" content="{{ __('Something went wrong while loading this page.') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>

<body class="min-h-screen bg-slate-950 font-sans text-white antialiased">
    <main class="relative isolate flex min-h-screen items-center overflow-hidden">
        <div class="absolute inset-0">
            <div class="absolute inset-0 bg-[radial-gradient(circle_at_top_left,_rgba(251,191,36,0.22),_transparent_34%),radial-gradient(circle_at_bottom_right,_rgba(14,165,233,0.2),_transparent_38%),linear-gradient(180deg,#020617_0%,#111827_100%)]"></div>
            <div class="absolute inset-0 opacity-[0.08]" style="background-image:url('{{ asset('images/pattern-bg.png') }}');background-size:360px"></div>
        </div>

        <div class="relative mx-auto flex w-full max-w-6xl flex-col gap-10 px-6 py-16 lg:flex-row lg:items-center lg:justify-between lg:px-12">
            <div class="max-w-2xl">
                <p class="text-sm font-semibold uppercase tracking-[0.35em] text-amber-300/90">{{ __('Error 500') }}</p>
                <h1 class="mt-6 font-heading text-5xl font-black tracking-tight text-balance text-white sm:text-6xl lg:text-7xl">
                    {{ __('The server hit an unexpected interruption.') }}
                </h1>
                <p class="mt-6 max-w-xl text-base leading-8 text-slate-300 sm:text-lg">
                    {{ __('We could not finish loading this page. The issue has likely been logged, and you can safely try again in a moment or continue browsing the rest of the platform.') }}
                </p>

                <div class="mt-10 flex flex-wrap gap-4">
                    <a href="{{ route('home') }}"
                        class="inline-flex items-center justify-center rounded-full bg-white px-6 py-3 text-sm font-semibold text-slate-950 shadow-lg shadow-black/20 transition hover:bg-slate-100">
                        {{ __('Go to Home') }}
                    </a>
                    <a href="{{ route('events.index') }}"
                        class="inline-flex items-center justify-center rounded-full border border-white/15 bg-white/5 px-6 py-3 text-sm font-semibold text-white transition hover:border-amber-300/40 hover:bg-white/10">
                        {{ __('Continue Browsing') }}
                    </a>
                </div>
            </div>

            <div class="w-full max-w-md shrink-0">
                <div class="rounded-[2rem] border border-white/10 bg-white/5 p-6 shadow-2xl shadow-black/30 backdrop-blur-xl">
                    <div class="rounded-[1.5rem] border border-white/10 bg-slate-900/70 p-8">
                        <div class="flex items-center justify-between gap-4 rounded-2xl border border-white/10 bg-slate-950/50 px-5 py-4">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-[0.25em] text-slate-400">{{ __('System Status') }}</p>
                                <p class="mt-2 text-lg font-semibold text-white">{{ __('Temporary disruption') }}</p>
                            </div>
                            <span class="inline-flex h-3.5 w-3.5 rounded-full bg-amber-400 shadow-[0_0_24px_rgba(251,191,36,0.7)]"></span>
                        </div>

                        <div class="mt-8 rounded-[1.5rem] border border-dashed border-white/10 bg-slate-950/60 p-8 text-center">
                            <p class="text-[5rem] font-black leading-none text-white/90">500</p>
                            <p class="mt-3 text-sm font-medium uppercase tracking-[0.25em] text-slate-400">{{ __('Server Error') }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</body>

</html>
