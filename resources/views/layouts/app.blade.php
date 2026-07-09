<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    dir="{{ in_array(app()->getLocale(), config('app.rtl_locales', []), true) ? 'rtl' : 'ltr' }}" class="scroll-smooth">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @php
        $decodeMeta = static fn (string $value): string => html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $defaultMetaDescription = __('Platform terbesar untuk mencari kuliah, ceramah, tazkirah, dan majlis ilmu di seluruh Malaysia. Cari yang berdekatan dengan anda.');
        $pageTitle = $decodeMeta(trim($__env->yieldContent('title', config('app.name'))));
        $pageDescription = $decodeMeta(trim($__env->yieldContent('meta_description', $defaultMetaDescription)));
        $defaultOgImage = asset('images/default-mosque-hero.png');
        $pageUrl = trim($__env->yieldContent('og_url', url()->current()));
        $pageOgImage = trim($__env->yieldContent('og_image', $defaultOgImage));
        $pageOgImageAlt = $decodeMeta(trim($__env->yieldContent('og_image_alt', $pageTitle !== '' ? $pageTitle : config('app.name'))));
        $pageOgImageWidth = trim($__env->yieldContent('og_image_width', '1024'));
        $pageOgImageHeight = trim($__env->yieldContent('og_image_height', '1024'));
    @endphp
    <title>{{ $pageTitle }}</title>
    <meta name="description" content="{{ $pageDescription }}">
    <meta name="robots" content="@yield('meta_robots', 'index, follow')">
    <meta property="og:title" content="{{ $pageTitle }}">
    <meta property="og:description" content="{{ $pageDescription }}">
    <meta property="og:site_name" content="{{ config('app.name') }}">
    <meta property="og:type" content="@yield('meta_og_type', 'website')">
    <meta property="og:url" content="{{ $pageUrl }}">
    <meta property="og:image" content="{{ $pageOgImage }}">
    <meta property="og:image:secure_url" content="{{ $pageOgImage }}">
    <meta property="og:image:width" content="{{ $pageOgImageWidth }}">
    <meta property="og:image:height" content="{{ $pageOgImageHeight }}">
    <meta property="og:image:alt" content="{{ $pageOgImageAlt }}">
    <meta name="twitter:card" content="@yield('twitter_card', 'summary_large_image')">
    <meta name="twitter:title" content="{{ $pageTitle }}">
    <meta name="twitter:description" content="{{ $pageDescription }}">
    <meta name="twitter:image" content="{{ $pageOgImage }}">
    <meta name="twitter:image:alt" content="{{ $pageOgImageAlt }}">

    <!-- Favicon -->
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('images/favicon-16x16.png') }}">
    <link rel="icon" type="image/x-icon" href="{{ asset('images/favicon.ico') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/apple-touch-icon.png') }}">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script>
        (() => {
            const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;

            if (!timezone) {
                return;
            }

            document.cookie = `user_timezone=${encodeURIComponent(timezone)}; path=/; max-age=31536000; SameSite=Lax`;
        })();

        window.ilmu360 = window.ilmu360 || {};
        window.ilmu360.geolocationPermission = ({
            initiallyGranted = false,
            cookieName = 'public_geolocation_permission',
        } = {}) => ({
            geolocationPermitted: Boolean(initiallyGranted),
            geolocationPermissionCookieName: cookieName,
            init() {
                this.syncGeolocationPermission();
            },
            persistGeolocationPermission(granted) {
                document.cookie = `${this.geolocationPermissionCookieName}=${granted ? '1' : '0'}; path=/; max-age=31536000; SameSite=Lax`;
            },
            setGeolocationPermission(granted) {
                this.geolocationPermitted = Boolean(granted);
                this.persistGeolocationPermission(this.geolocationPermitted);
            },
            async syncGeolocationPermission() {
                if (!('geolocation' in navigator)) {
                    this.setGeolocationPermission(false);

                    return;
                }

                if (!navigator.permissions || typeof navigator.permissions.query !== 'function') {
                    return;
                }

                try {
                    const permissionStatus = await navigator.permissions.query({ name: 'geolocation' });
                    const applyPermission = () => this.setGeolocationPermission(permissionStatus.state === 'granted');

                    applyPermission();

                    if (typeof permissionStatus.addEventListener === 'function') {
                        permissionStatus.addEventListener('change', applyPermission);

                        return;
                    }

                    permissionStatus.onchange = applyPermission;
                } catch (error) {
                    this.setGeolocationPermission(false);
                }
            },
        });
    </script>
    @livewireStyles
    @filamentStyles(['app'])
    @filamentStyles(['filament/filament'])
    @include('components.analytics.signals-tracker', ['surface' => 'public'])
    @stack('head')
</head>

<body
    class="min-h-screen bg-background text-text-main font-sans antialiased selection:bg-emerald-500/30 selection:text-emerald-900">
    <div class="relative min-h-screen overflow-hidden">
        <!-- Background Gradients -->
        <div class="pointer-events-none absolute inset-0 z-0">
            <div class="absolute inset-0 opacity-[0.03]"
                style="background-image: url('{{ asset('images/pattern-bg.png') }}'); background-size: 400px;">
            </div>
            <div
                class="absolute -top-40 left-[10%] h-[35rem] w-[35rem] rounded-full bg-emerald-500/10 blur-[100px] animate-pulse">
            </div>
            <div class="absolute top-20 right-[5%] h-[30rem] w-[30rem] rounded-full bg-teal-500/10 blur-[100px]"></div>
            <div
                class="absolute bottom-[-10rem] left-[20%] h-[40rem] w-[40rem] rounded-full bg-emerald-600/5 blur-[120px]">
            </div>
        </div>

        <div class="relative z-10 flex flex-col min-h-screen">
            @php
                $supportedLocales = config('app.supported_locales', []);
                $publicMenuLocaleKeys = config('app.public_menu_locales', ['ms', 'en', 'jv']);
                $publicMenuLocales = collect($supportedLocales)
                    ->only($publicMenuLocaleKeys)
                    ->all();
                $currentLocale = app()->getLocale();
                $authenticatedUser = auth()->user();
                $hasInstitutionDashboardAccess = $authenticatedUser?->institutions()->exists() ?? false;
                $notificationUnreadCount = $authenticatedUser
                    ? $authenticatedUser
                        ->notificationInboxes()
                        ->whereNull('archived_at')
                        ->whereNull('read_at')
                        ->count()
                    : 0;
                $dashboardMenuLabel = __('My Dashboard');
                $homeMenuHeading = __('Home');
                $workspaceMenuHeading = __('Workspaces');
                $accountMenuHeading = __('Account');
                $inboxMenuLabel = __('Inbox');
                $contributionsMenuLabel = __('My Contributions');
                $settingsMenuLabel = __('Settings');
                $institutionDashboardMenuLabel = __('Manage Institution');
            @endphp

            <!-- Premium Header -->
            <header
                class="sticky top-0 z-50 w-full border-b border-white/10 bg-white/70 backdrop-blur-md transition-all"
                x-data="{ mobileMenuOpen: false }">
                <nav class="container mx-auto flex h-20 items-center justify-between px-6 lg:px-12">
                    <a href="{{ route('home') }}" wire:navigate class="flex items-center group">
                        <img src="{{ asset('images/logo.png') }}" alt="{{ config('app.name') }}"
                            class="h-12 w-auto transition-transform group-hover:scale-105">
                    </a>

                    <!-- Desktop Menu -->
                    <div class="hidden md:flex items-center gap-8 text-sm font-medium text-slate-600">
                        <a href="{{ route('events.index') }}" wire:navigate
                            class="hover:text-emerald-600 transition-colors relative after:absolute after:bottom-[-4px] after:left-0 after:h-[2px] after:w-0 after:bg-emerald-500 after:transition-all hover:after:w-full">{{ __('Events') }}</a>
                        <a href="{{ route('institutions.index') }}" wire:navigate
                            class="hover:text-emerald-600 transition-colors relative after:absolute after:bottom-[-4px] after:left-0 after:h-[2px] after:w-0 after:bg-emerald-500 after:transition-all hover:after:w-full">{{ __('Institutions') }}</a>
                        <a href="{{ route('speakers.index') }}" wire:navigate
                            class="hover:text-emerald-600 transition-colors relative after:absolute after:bottom-[-4px] after:left-0 after:h-[2px] after:w-0 after:bg-emerald-500 after:transition-all hover:after:w-full">{{ __('Speakers') }}</a>
                    </div>

                    <div class="flex items-center gap-3">
                        <!-- Mobile Menu Button -->
                        <button @click="mobileMenuOpen = !mobileMenuOpen"
                            class="md:hidden p-2 rounded-lg text-slate-600 hover:bg-slate-100 transition-colors">
                            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path x-show="!mobileMenuOpen" stroke-linecap="round" stroke-linejoin="round"
                                    stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                                <path x-show="mobileMenuOpen" x-cloak stroke-linecap="round" stroke-linejoin="round"
                                    stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>

                        <!-- Language Switcher -->
                        <div class="relative group z-50 hidden sm:block" data-language-switcher-case="title">
                            <button
                                data-language-switcher-trigger="desktop"
                                class="flex items-center gap-2 rounded-full border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold tracking-wider text-slate-600 hover:border-emerald-500 hover:text-emerald-600 transition-all">
                                {{ $publicMenuLocales[$currentLocale] ?? $supportedLocales[$currentLocale] ?? strtoupper($currentLocale) }}
                                <svg class="h-3 w-3 text-slate-400 group-hover:text-emerald-500" fill="none"
                                    viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M19 9l-7 7-7-7" />
                                </svg>
                            </button>
                            <div
                                class="absolute right-0 top-full mt-2 w-32 origin-top-right scale-95 opacity-0 invisible group-hover:scale-100 group-hover:opacity-100 group-hover:visible transition-all duration-200 rounded-xl border border-slate-100 bg-white p-1.5 shadow-xl shadow-slate-200/50">
                                @foreach ($publicMenuLocales as $locale => $label)
                                    <a href="{{ route('locale.switch', $locale) }}"
                                        data-language-switcher-option="{{ $locale }}"
                                        class="flex items-center justify-between rounded-lg px-3 py-2 text-xs font-semibold tracking-wider {{ $locale === $currentLocale ? 'bg-emerald-50 text-emerald-700' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-900' }}">
                                        {{ $label }}
                                    </a>
                                @endforeach
                            </div>
                        </div>

                        <a href="{{ route('submit-event.landing') }}" wire:navigate
                            class="hidden sm:inline-flex items-center justify-center rounded-full bg-slate-100 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-200 transition-colors">
                            {{ __('Tambah Majlis') }}
                        </a>

                        @auth
                            <!-- User Menu -->
                            <div class="relative group z-50 hidden sm:block">
                                <button
                                    class="flex items-center gap-2 rounded-full border border-slate-200 bg-white p-1 pr-3 hover:border-emerald-500 transition-all">
                                    <div
                                        class="h-8 w-8 rounded-full bg-emerald-100 flex items-center justify-center text-emerald-700 font-bold uppercase">
                                        {{ substr(auth()->user()->name, 0, 1) }}
                                    </div>
                                    <span
                                        class="hidden lg:inline text-xs font-semibold text-slate-700 max-w-[80px] truncate">{{ explode(' ', auth()->user()->name)[0] }}</span>
                                    <svg class="h-3 w-3 text-slate-400" fill="none" viewBox="0 0 24 24"
                                        stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M19 9l-7 7-7-7" />
                                    </svg>
                                </button>
                                <div
                                    class="absolute right-0 top-full mt-2 w-56 origin-top-right scale-95 opacity-0 invisible group-hover:scale-100 group-hover:opacity-100 group-hover:visible transition-all duration-200 rounded-xl border border-slate-100 bg-white p-1.5 shadow-xl shadow-slate-200/50">
                                    <div class="px-3 py-2 border-b border-slate-50 mb-1">
                                        <p class="text-xs text-slate-500">{{ __('Signed in as') }}</p>
                                        <p class="text-sm font-bold text-slate-900 truncate">{{ auth()->user()->email }}</p>
                                    </div>
                                    <div class="border-b border-slate-50 pb-1">
                                        <p class="px-3 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-400">{{ $homeMenuHeading }}</p>
                                        <a href="{{ route('dashboard') }}" wire:navigate
                                            class="block rounded-lg px-3 py-2 text-sm text-slate-700 transition-colors hover:bg-slate-50">
                                            {{ $dashboardMenuLabel }}
                                        </a>
                                        <a href="{{ route('dashboard.notifications') }}" wire:navigate
                                            class="flex items-center justify-between rounded-lg px-3 py-2 text-sm text-slate-700 transition-colors hover:bg-slate-50">
                                            <span>{{ $inboxMenuLabel }}</span>
                                            @if($notificationUnreadCount > 0)
                                                <span class="inline-flex min-w-6 items-center justify-center rounded-full bg-emerald-600 px-2 py-0.5 text-xs font-semibold text-white">
                                                    {{ $notificationUnreadCount }}
                                                </span>
                                            @endif
                                        </a>
                                    </div>
                                    <div class="border-b border-slate-50 py-1">
                                        <p class="px-3 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-400">{{ $workspaceMenuHeading }}</p>
                                        <a href="{{ route('contributions.index') }}" wire:navigate
                                            class="block rounded-lg px-3 py-2 text-sm text-slate-700 transition-colors hover:bg-slate-50">
                                            {{ $contributionsMenuLabel }}
                                        </a>
                                        @if($hasInstitutionDashboardAccess)
                                            <a href="{{ route('dashboard.institutions') }}" wire:navigate
                                                class="block rounded-lg px-3 py-2 text-sm text-slate-700 transition-colors hover:bg-slate-50">
                                                {{ $institutionDashboardMenuLabel }}
                                            </a>
                                        @endif
                                    </div>
                                    <div class="pt-1">
                                        <p class="px-3 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-400">{{ $accountMenuHeading }}</p>
                                        <a href="{{ route('dashboard.account-settings') }}" wire:navigate
                                            class="block rounded-lg px-3 py-2 text-sm text-slate-700 transition-colors hover:bg-slate-50">
                                            {{ $settingsMenuLabel }}
                                        </a>
                                        <form method="POST" action="{{ route('logout') }}">
                                            @csrf
                                            <button type="submit"
                                                class="w-full text-left rounded-lg px-3 py-2 text-sm text-red-600 transition-colors hover:bg-red-50">
                                                {{ __('Log Out') }}
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        @else
                            <div class="flex items-center gap-2 hidden sm:flex">
                                <a href="{{ \App\Support\Auth\IntendedRedirect::loginUrl(request()->fullUrl()) }}" wire:navigate
                                    class="hidden lg:inline-flex text-sm font-semibold text-slate-600 hover:text-emerald-600 transition-colors px-3">
                                    {{ __('Log In') }}
                                </a>
                                <a href="{{ \App\Support\Auth\IntendedRedirect::registerUrl(request()->fullUrl()) }}" wire:navigate
                                    class="inline-flex items-center justify-center rounded-full bg-emerald-600 px-5 py-2 text-sm font-semibold text-white shadow-lg shadow-emerald-500/30 hover:bg-emerald-700 hover:shadow-emerald-500/40 hover:-translate-y-0.5 transition-all duration-300">
                                    {{ __('Sign Up') }}
                                </a>
                            </div>
                        @endauth
                    </div>
                </nav>

                <!-- Mobile Menu Dropdown -->
                <div x-show="mobileMenuOpen" x-collapse x-cloak class="md:hidden border-t border-slate-100 bg-white">
                    <div class="container mx-auto px-6 py-4 space-y-4">
                        <div class="flex flex-col gap-2">
                            <a href="{{ route('events.index') }}" wire:navigate
                                class="block py-2 text-base font-semibold text-slate-700 hover:text-emerald-600">{{ __('Events') }}</a>
                            <a href="{{ route('institutions.index') }}" wire:navigate
                                class="block py-2 text-base font-semibold text-slate-700 hover:text-emerald-600">{{ __('Institutions') }}</a>
                            <a href="{{ route('speakers.index') }}" wire:navigate
                                class="block py-2 text-base font-semibold text-slate-700 hover:text-emerald-600">{{ __('Speakers') }}</a>
                        </div>
                        <div class="border-t border-slate-100 pt-4 flex flex-col gap-3">
                            <a href="{{ route('submit-event.landing') }}" wire:navigate
                                class="block w-full text-center rounded-lg bg-slate-100 px-4 py-3 text-sm font-semibold text-slate-700">
                                {{ __('Tambah Majlis') }}
                            </a>
                            @guest
                                <div class="grid grid-cols-2 gap-3">
                                    <a href="{{ \App\Support\Auth\IntendedRedirect::loginUrl(request()->fullUrl()) }}" wire:navigate
                                        class="flex items-center justify-center rounded-lg border border-slate-200 px-4 py-3 text-sm font-semibold text-slate-700">
                                        {{ __('Log In') }}
                                    </a>
                                    <a href="{{ \App\Support\Auth\IntendedRedirect::registerUrl(request()->fullUrl()) }}" wire:navigate
                                        class="flex items-center justify-center rounded-lg bg-emerald-600 px-4 py-3 text-sm font-semibold text-white">
                                        {{ __('Sign Up') }}
                                    </a>
                                </div>
                            @else
                                <div class="flex items-center gap-3 py-2">
                                    <div
                                        class="h-10 w-10 rounded-full bg-emerald-100 flex items-center justify-center text-emerald-700 font-bold uppercase">
                                        {{ substr(auth()->user()->name, 0, 1) }}
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-bold text-slate-900 truncate">{{ auth()->user()->name }}</p>
                                        <p class="text-xs text-slate-500 truncate">{{ auth()->user()->email }}</p>
                                    </div>
                                </div>
                                <div class="space-y-3 border-t border-slate-100 pt-4">
                                    <div class="space-y-2">
                                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">{{ $homeMenuHeading }}</p>
                                        <a href="{{ route('dashboard') }}" wire:navigate
                                            class="block rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700">
                                            {{ $dashboardMenuLabel }}
                                        </a>
                                        <a href="{{ route('dashboard.notifications') }}" wire:navigate
                                            class="flex items-center justify-between rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700">
                                            <span>{{ $inboxMenuLabel }}</span>
                                            @if($notificationUnreadCount > 0)
                                                <span class="inline-flex min-w-6 items-center justify-center rounded-full bg-emerald-600 px-2 py-0.5 text-xs font-semibold text-white">
                                                    {{ $notificationUnreadCount }}
                                                </span>
                                            @endif
                                        </a>
                                    </div>
                                    <div class="space-y-2 border-t border-slate-100 pt-4">
                                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">{{ $workspaceMenuHeading }}</p>
                                        <a href="{{ route('contributions.index') }}" wire:navigate
                                            class="block rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700">
                                            {{ $contributionsMenuLabel }}
                                        </a>
                                        @if($hasInstitutionDashboardAccess)
                                            <a href="{{ route('dashboard.institutions') }}" wire:navigate
                                                class="block rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700">
                                                {{ $institutionDashboardMenuLabel }}
                                            </a>
                                        @endif
                                    </div>
                                    <div class="space-y-2 border-t border-slate-100 pt-4">
                                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">{{ $accountMenuHeading }}</p>
                                        <a href="{{ route('dashboard.account-settings') }}" wire:navigate
                                            class="block rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700">
                                            {{ $settingsMenuLabel }}
                                        </a>
                                        <form method="POST" action="{{ route('logout') }}">
                                            @csrf
                                            <button type="submit"
                                                class="w-full rounded-lg border border-red-200 px-4 py-2 text-sm font-semibold text-red-600">
                                                {{ __('Log Out') }}
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            @endguest
                        </div>
                        <!-- Mobile Language Switcher -->
                        <div class="border-t border-slate-100 pt-4" data-language-switcher-case="title">
                            <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">
                                {{ __('Language') }}
                            </p>
                            <div class="flex flex-wrap gap-2">
                                @foreach ($publicMenuLocales as $locale => $label)
                                    <a href="{{ route('locale.switch', $locale) }}"
                                        data-language-switcher-option="{{ $locale }}"
                                        class="px-3 py-1.5 rounded-full text-xs font-medium border {{ $locale === $currentLocale ? 'bg-emerald-50 border-emerald-200 text-emerald-700' : 'border-slate-100 text-slate-600' }}">
                                        {{ $label }}
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <main class="flex-grow">
                {{ $slot ?? '' }}
                @yield('content')
            </main>

            <div data-toast-root class="hidden" aria-hidden="true"></div>

            @include('components.ui.toast-stack')

            <!-- Modern Footer -->
            <footer class="mt-20 border-t border-slate-200 bg-white/50 backdrop-blur-sm">
                <div class="container mx-auto px-6 py-12 lg:px-12">
                    <div class="grid gap-12 lg:grid-cols-4">
                        <div class="lg:col-span-2 flex flex-col gap-4">
                            <div class="flex items-center gap-3">
                                <img src="{{ asset('images/milogo.webp') }}" alt="ilmu360°"
                                    class="h-10 w-10 rounded-lg">
                                <span class="font-heading text-lg font-bold text-slate-900">ilmu360°</span>
                            </div>
                            <p class="text-slate-500 max-w-sm leading-relaxed">
                                {{ __('Connecting the community through knowledge. Discover classes, lectures, and gatherings across Malaysia.') }}
                            </p>
                        </div>

                        <div>
                            <h3 class="font-heading font-semibold text-slate-900 mb-4">{{ __('Discover') }}</h3>
                            <ul class="space-y-3 text-slate-500">
                                <li><a href="{{ route('events.index') }}" wire:navigate
                                        class="hover:text-emerald-600 transition-colors">{{ __('Upcoming Events') }}</a>
                                </li>
                                <li><a href="{{ route('institutions.index') }}" wire:navigate
                                        class="hover:text-emerald-600 transition-colors">{{ __('Institutions') }}</a>
                                </li>
                                <li><a href="{{ route('speakers.index') }}" wire:navigate
                                        class="hover:text-emerald-600 transition-colors">{{ __('Speakers') }}</a></li>
                                <li><a href="{{ route('venues.index') }}" wire:navigate
                                        class="hover:text-emerald-600 transition-colors">{{ __('Venue') }}</a></li>
                                <li><a href="{{ route('references.index') }}" wire:navigate
                                        class="hover:text-emerald-600 transition-colors">{{ __('Reference') }}</a></li>
                            </ul>
                        </div>

                        <div>
                            <h3 class="font-heading font-semibold text-slate-900 mb-4">{{ __('Community') }}</h3>
                            <ul class="space-y-3 text-slate-500">
                                <li><a href="{{ route('about') }}" wire:navigate
                                        class="hover:text-emerald-600 transition-colors">{{ __('About Us') }}</a></li>
                                <li><a href="{{ route('home') }}#submit" wire:navigate
                                        class="hover:text-emerald-600 transition-colors">{{ __('Submit Event') }}</a>
                                </li>
                                @php
                                    $supportEmail = config('mail.from.address');
                                    $hasSupportEmail = filled($supportEmail) && $supportEmail !== 'hello@example.com';
                                @endphp
                                <li>
                                    @if ($hasSupportEmail)
                                        <a href="mailto:{{ $supportEmail }}"
                                            class="hover:text-emerald-600 transition-colors">{{ __('Contact Support') }}</a>
                                    @else
                                        <span>{{ __('Contact Support') }}</span>
                                    @endif
                                </li>
                            </ul>
                        </div>
                    </div>

                    <div
                        class="mt-12 flex flex-col gap-6 border-t border-slate-200 pt-8 md:flex-row md:items-center md:justify-between text-sm text-slate-400">
                        <span>&copy; {{ date('Y') }} ilmu360°. {{ __('All rights reserved.') }}</span>
                        <div class="flex gap-6">
                            <span>{{ __('Privacy') }}</span>
                            <span>{{ __('Terms') }}</span>
                        </div>
                    </div>
                </div>
            </footer>
        </div>
    </div>

        @stack('prelivewire-scripts')
    @livewireScripts
    @filamentScripts(['app'])
    @filamentScripts(['filament/support', 'filament/schemas', 'filament/forms', 'filament/actions', 'filament/notifications'])
    @stack('scripts')
</body>

</html>
