<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    dir="{{ in_array(app()->getLocale(), config('app.rtl_locales', []), true) ? 'rtl' : 'ltr' }}"
    class="h-full scroll-smooth light">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? config('app.name') }}</title>

    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('images/favicon-16x16.png') }}">
    <link rel="icon" type="image/x-icon" href="{{ asset('images/favicon.ico') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/apple-touch-icon.png') }}">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=Amiri:wght@400;700&display=swap"
        rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @fluxAppearance
    @livewireStyles
    @stack('head')

    <style>
        .font-amiri {
            font-family: 'Amiri', serif;
        }

        /* Islamic geometric diamond pattern */
        .auth-pattern {
            background-image: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' stroke='%23d4a853' stroke-width='0.5' opacity='0.1'%3E%3Cpath d='M30 0 L60 30 L30 60 L0 30Z'/%3E%3Cpath d='M30 10 L50 30 L30 50 L10 30Z'/%3E%3C/g%3E%3C/svg%3E");
            background-size: 60px 60px;
        }

        /* Subtle noise texture for depth */
        .bg-noise {
            background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noiseFilter'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.65' numOctaves='3' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noiseFilter)' opacity='0.05'/%3E%3C/svg%3E");
        }

        /* Animations */
        @keyframes float {

            0%,
            100% {
                transform: translateY(0);
            }

            50% {
                transform: translateY(-10px);
            }
        }

        @keyframes pulse-glow {

            0%,
            100% {
                opacity: 0.3;
                transform: scale(1);
            }

            50% {
                opacity: 0.6;
                transform: scale(1.05);
            }
        }

        .animate-float {
            animation: float 6s ease-in-out infinite;
        }

        .animate-pulse-glow {
            animation: pulse-glow 4s ease-in-out infinite;
        }

        /* Entrance Animations */
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
            opacity: 0;
            animation-delay: 0.1s;
        }

        .entry-2 {
            animation: fade-up 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards;
            opacity: 0;
            animation-delay: 0.2s;
        }

        .entry-3 {
            animation: fade-up 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards;
            opacity: 0;
            animation-delay: 0.3s;
        }

        .entry-4 {
            animation: fade-up 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards;
            opacity: 0;
            animation-delay: 0.4s;
        }

        /* Override browser autofill/autocomplete styling */
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

        /* Custom Scrollbar */
        ::-webkit-scrollbar {
            width: 6px;
        }

        ::-webkit-scrollbar-track {
            background: transparent;
        }

        ::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.2);
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: rgba(255, 255, 255, 0.3);
        }
    </style>
</head>

<body class="h-full font-sans antialiased text-slate-900 overflow-x-hidden">

    {{-- Main Background Container --}}
    <div class="relative min-h-screen w-full flex items-center justify-center p-4 sm:p-6 lg:p-8 overflow-x-hidden overflow-y-auto select-none"
        style="background: radial-gradient(circle at top center, oklch(0.25 0.1 165), oklch(0.18 0.08 165), oklch(0.12 0.05 165));">

        {{-- Background Layers --}}
        <div class="absolute inset-0 block bg-noise opacity-30 mix-blend-overlay pointer-events-none"></div>
        <div class="absolute inset-0 auth-pattern pointer-events-none"></div>

        {{-- Ambient Light Orb (Top) --}}
        <div class="absolute top-[-20%] left-1/2 -translate-x-1/2 w-[800px] h-[800px] rounded-full blur-3xl pointer-events-none opacity-40 animate-pulse-glow"
            style="background: radial-gradient(circle, oklch(0.65 0.18 85 / 0.4), transparent 70%);"></div>

        {{-- Floating Geometric Shapes (Background Decor) --}}
        <div class="absolute top-10 left-10 md:top-20 md:left-20 w-32 h-32 border border-white/5 rounded-full blur-sm animate-float pointer-events-none"
            style="animation-delay: 0s;"></div>
        <div class="absolute bottom-10 right-10 md:bottom-20 md:right-20 w-48 h-48 border border-white/5 rounded-full blur-sm animate-float pointer-events-none"
            style="animation-delay: 2s;"></div>

        {{-- Main Card --}}
        <div class="relative w-full max-w-[460px] entry-1">

            {{-- Card Glow Effect --}}
            <div
                class="absolute -inset-1 bg-gradient-to-b from-amber-400/30 to-emerald-600/30 rounded-[2rem] blur-md opacity-75">
            </div>

            {{-- Card Actual --}}
            <div
                class="relative bg-white/95 backdrop-blur-xl rounded-[1.75rem] shadow-2xl overflow-hidden ring-1 ring-white/50">

                {{-- Decorative Top Arch/Pattern --}}
                <div
                    class="absolute top-0 left-0 right-0 h-2 bg-gradient-to-r from-emerald-600 via-amber-400 to-emerald-600">
                </div>

                {{-- Content Container --}}
                <div class="px-8 py-10 sm:px-10 sm:py-12">

                    {{-- Header --}}
                    <div class="text-center mb-8 entry-2">
                        <a href="{{ route('home') }}" wire:navigate class="inline-block group relative">
                            <div
                                class="absolute -inset-4 bg-emerald-500/10 rounded-full blur-lg opacity-0 group-hover:opacity-100 transition-opacity duration-500">
                            </div>
                            <img src="{{ asset('images/logo.png') }}" alt="{{ config('app.name') }}"
                                class="relative h-16 w-auto mx-auto transform transition-transform duration-500 group-hover:scale-105">
                        </a>

                        <div class="mt-6 space-y-2">
                            <h2 class="font-amiri text-2xl text-emerald-900" style="direction: rtl;">بِسْمِ ٱللَّهِ
                                ٱلرَّحْمَـٰنِ ٱلرَّحِيمِ</h2>
                            <p class="text-slate-500 text-sm font-medium tracking-wide uppercase">
                                {{ __('ILMU ITU CAHAYA') }}
                            </p>
                        </div>
                    </div>

                    {{-- Main Content (Form) --}}
                    <div class="entry-3">
                        {{ $slot }}
                    </div>

                    <div data-toast-root class="hidden" aria-hidden="true"></div>

                    @include('components.ui.toast-stack')

                </div>

                {{-- Footer / Bottom Decor --}}
                <div class="bg-slate-50 px-8 py-4 text-center border-t border-slate-100 entry-4">
                    <p class="text-xs text-slate-400 font-medium">
                        &copy; {{ date('Y') }} {{ config('app.name') }}. {{ __('All rights reserved.') }}
                    </p>
                </div>
            </div>
        </div>
    </div>

    @livewireScriptConfig
    @fluxScripts
    @stack('scripts')
</body>

</html>
