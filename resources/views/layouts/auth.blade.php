<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    dir="{{ in_array(app()->getLocale(), config('app.rtl_locales', []), true) ? 'rtl' : 'ltr' }}"
    class="h-full scroll-smooth light">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', $title ?? config('app.name'))</title>

    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('images/favicon-16x16.png') }}">
    <link rel="icon" type="image/x-icon" href="{{ asset('images/favicon.ico') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/apple-touch-icon.png') }}">

    @include('partials.font-links')

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @fluxAppearance
    @livewireStyles
    @signalsTracker(['properties' => ['surface' => 'public']])
    @stack('head')

    <style>
        .auth-pattern {
            background-image: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' stroke='%23d4a853' stroke-width='0.5' opacity='0.1'%3E%3Cpath d='M30 0 L60 30 L30 60 L0 30Z'/%3E%3Cpath d='M30 10 L50 30 L30 50 L10 30Z'/%3E%3C/g%3E%3C/svg%3E");
            background-size: 60px 60px;
        }

        @keyframes fade-up {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .entry-1 {
            animation: fade-up 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards;
            animation-delay: 0.1s;
            opacity: 0;
        }

        .entry-2 {
            animation: fade-up 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards;
            animation-delay: 0.2s;
            opacity: 0;
        }

        .entry-3 {
            animation: fade-up 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards;
            animation-delay: 0.3s;
            opacity: 0;
        }

        .entry-4 {
            animation: fade-up 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards;
            animation-delay: 0.4s;
            opacity: 0;
        }

        @media (prefers-reduced-motion: reduce) {
            .entry-1,
            .entry-2,
            .entry-3,
            .entry-4 {
                animation: none;
                opacity: 1;
                transform: none;
            }
        }

        input:-webkit-autofill,
        input:-webkit-autofill:hover,
        input:-webkit-autofill:focus,
        input:-webkit-autofill:active {
            -webkit-box-shadow: 0 0 0 1000px white inset !important;
            box-shadow: 0 0 0 1000px white inset !important;
            -webkit-text-fill-color: oklch(0.15 0.03 260) !important;
            background-clip: content-box !important;
            transition: background-color 5000s ease-in-out 0s;
        }
    </style>
</head>

<body class="min-h-full overflow-x-hidden bg-emerald-950 font-sans antialiased text-slate-900">
    <div class="relative min-h-screen overflow-x-hidden bg-emerald-950">
        {{-- Reference-derived courtyard scene. The form remains real HTML above it. --}}
        <img src="{{ asset('images/auth/login-background-v1.png') }}" alt="" aria-hidden="true"
            class="absolute inset-0 h-full w-full object-cover object-center">
        <div class="pointer-events-none absolute inset-0 bg-gradient-to-r from-emerald-950/35 via-transparent to-emerald-950/5"></div>
        <div class="pointer-events-none absolute inset-0 bg-gradient-to-b from-emerald-950/10 via-transparent to-emerald-950/40"></div>

        <div class="relative z-10 mx-auto flex min-h-screen w-full max-w-[1600px] items-start justify-center px-4 py-5 sm:px-6 sm:py-8 lg:px-10 lg:py-16">
            {{-- Editorial side note, intentionally hidden before very wide desktop widths. --}}
            <aside class="absolute left-[10%] top-[29%] hidden w-48 -translate-y-1/2 text-amber-100 xl:block">
                <p class="font-serif text-[2rem] leading-[1.05]">Ilmu<br>Menyinari<br>Kehidupan</p>
                <div class="my-5 h-px w-8 bg-amber-300/90"></div>
                <p class="max-w-[10rem] text-sm leading-6 text-amber-50/80">Lebih dekat dengan ilmu, lebih baik untuk esok.</p>
            </aside>

            <div class="relative w-full max-w-[32rem] entry-1">
                <div class="absolute -inset-1 rounded-[2rem] bg-gradient-to-b from-amber-300/50 via-emerald-200/20 to-emerald-950/40 blur-lg"></div>

                <div class="relative overflow-hidden rounded-[2rem] bg-[#fbfcf8]/95 shadow-[0_24px_80px_-24px_rgba(2,34,27,0.85)] ring-1 ring-white/75 backdrop-blur-xl">
                    <div class="absolute inset-x-0 top-0 h-[5px] bg-gradient-to-r from-emerald-600 via-amber-400 to-emerald-600"></div>
                    <div class="auth-pattern pointer-events-none absolute inset-0 opacity-35"></div>

                    <div class="relative px-6 py-8 sm:px-10 sm:py-9">
                        <div class="mb-8 text-center entry-2">
                            <a href="{{ route('home') }}" aria-label="{{ config('app.name') }}" class="inline-flex flex-col items-center leading-none">
                                <img src="{{ asset('images/logo-ilmu360.png') }}" alt="{{ config('app.name') }}"
                                    width="2172" height="724" class="h-16 w-auto max-w-[14rem]">
                                <span class="mt-2 text-[0.6rem] font-semibold tracking-[0.34em] text-[#637387]">ILMU · AMAL · KOMUNITI</span>
                            </a>

                            <div class="mt-7 space-y-2">
                                <h2 class="font-arabic text-[1.8rem] text-emerald-900" lang="ar" dir="rtl">بِسْمِ ٱللَّهِ ٱلرَّحْمَـٰنِ ٱلرَّحِيمِ</h2>
                                <div class="mx-auto h-1 w-10 rounded-full bg-amber-400"></div>
                            </div>
                        </div>

                        <div class="entry-3">
                            @yield('content')
                        </div>

                        <div data-toast-root class="hidden" aria-hidden="true"></div>
                        {{-- Tag invocation: @include renders Blaze-compiled components empty. --}}
                        <x-ui.toast-stack />
                    </div>

                    <div class="border-t border-emerald-950/[0.06] bg-[#f1f5f1]/80 px-6 py-4 text-center entry-4 sm:px-10">
                        <p class="text-xs font-medium text-slate-400">&copy; {{ date('Y') }} {{ config('app.name') }}. {{ __('All rights reserved.') }}</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @livewireScripts
    @fluxScripts
    @stack('scripts')
</body>

</html>
