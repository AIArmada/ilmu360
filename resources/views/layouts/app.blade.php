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
        window.addEventListener('load', () => document.body.classList.add('is-loaded'));

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
    @signalsTracker(['properties' => ['surface' => 'public']])
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
                class="absolute bottom-[-10rem] left-[20%] h-[40rem] w-[40rem] rounded-full bg-emerald-600/5 blur-[120px] mi-bg-settled">
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
                $hasPersonDashboardAccess = $authenticatedUser?->persons()->exists() ?? false;
                $hasOrganizationDashboardAccess = $authenticatedUser?->organizations()->exists() ?? false;
                $hasManagedWorkspaceAccess = $hasInstitutionDashboardAccess || $hasPersonDashboardAccess || $hasOrganizationDashboardAccess;
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
                $organizationDashboardMenuLabel = __('Manage Organizations');
                $organizationCreateMenuLabel = __('Create organization');
            @endphp

            <!-- Premium Header -->
            <header
                class="sticky top-0 z-50 w-full border-b border-white/10 bg-white/70 backdrop-blur-md transition-all"
                x-data="{ mobileMenuOpen: false }">
                <nav class="container mx-auto flex h-20 items-center justify-between px-6 lg:px-12">
                    <a href="{{ route('home') }}" wire:navigate class="flex items-center">
                        <img src="{{ asset('images/logo.png') }}" alt="{{ config('app.name') }}" width="1227" height="276"
                            class="h-12 w-auto">
                    </a>

                    <!-- Desktop Menu -->
                    <div class="hidden md:flex items-center gap-8 text-sm font-medium text-slate-600">
                        <a href="{{ route('events.index') }}" wire:navigate
                            class="hover:text-emerald-600 transition-colors relative after:absolute after:bottom-[-4px] after:left-0 after:h-[2px] after:w-0 after:bg-emerald-500 after:transition-all hover:after:w-full">{{ __('Events') }}</a>
                        <a href="{{ route('institutions.index') }}" wire:navigate
                            class="hover:text-emerald-600 transition-colors relative after:absolute after:bottom-[-4px] after:left-0 after:h-[2px] after:w-0 after:bg-emerald-500 after:transition-all hover:after:w-full">{{ __('Institutions') }}</a>
                        <a href="{{ route('persons.index') }}" wire:navigate
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

                        <a href="{{ route('submit-event.create') }}" wire:navigate
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
                                        <a href="{{ route('dashboard.organizations.create') }}" wire:navigate
                                            class="block rounded-lg px-3 py-2 text-sm text-slate-700 transition-colors hover:bg-slate-50">
                                            {{ $organizationCreateMenuLabel }}
                                        </a>
                                        @if($hasOrganizationDashboardAccess)
                                            <a href="{{ route('dashboard.organizations.index') }}" wire:navigate
                                                class="block rounded-lg px-3 py-2 text-sm text-slate-700 transition-colors hover:bg-slate-50">
                                                {{ $organizationDashboardMenuLabel }}
                                            </a>
                                        @endif
                                        @if($hasManagedWorkspaceAccess && ! $hasOrganizationDashboardAccess)
                                            <a href="{{ route('dashboard.organizations.index') }}" wire:navigate
                                                class="block rounded-lg px-3 py-2 text-sm text-slate-700 transition-colors hover:bg-slate-50">
                                                {{ __('Manage Workspaces') }}
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
                            <a href="{{ route('persons.index') }}" wire:navigate
                                class="block py-2 text-base font-semibold text-slate-700 hover:text-emerald-600">{{ __('Speakers') }}</a>
                        </div>
                        <div class="border-t border-slate-100 pt-4 flex flex-col gap-3">
                            <a href="{{ route('submit-event.create') }}" wire:navigate
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
                                        <a href="{{ route('dashboard.organizations.create') }}" wire:navigate
                                            class="block rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700">
                                            {{ $organizationCreateMenuLabel }}
                                        </a>
                                        @if($hasOrganizationDashboardAccess)
                                            <a href="{{ route('dashboard.organizations.index') }}" wire:navigate
                                                class="block rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700">
                                                {{ $organizationDashboardMenuLabel }}
                                            </a>
                                        @endif
                                        @if($hasManagedWorkspaceAccess && ! $hasOrganizationDashboardAccess)
                                            <a href="{{ route('dashboard.organizations.index') }}" wire:navigate
                                                class="block rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700">
                                                {{ __('Manage Workspaces') }}
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

            @livewire('notifications')

            @include('components.ui.toast-stack')

            <!-- Living Majlis Footer -->
            <footer class="living-majlis-footer relative mt-20 overflow-hidden bg-emerald-950 text-emerald-50">
                <div class="living-majlis-footer-image pointer-events-none absolute inset-0 opacity-100"
                    style="background-image: url('{{ asset('images/footer-courtyard-v4.png') }}');">
                </div>
                <div class="pointer-events-none absolute inset-0 bg-[linear-gradient(90deg,rgba(2,33,22,0.08),rgba(2,33,22,0.18)_50%,rgba(2,33,22,0.08))]"></div>
                <div class="pointer-events-none absolute inset-0 opacity-[0.06]"
                    style="background-image: url('{{ asset('images/pattern-bg.png') }}'); background-size: 320px;">
                </div>
                <div class="pointer-events-none absolute inset-0 opacity-[0.055]"
                    style="background-image: radial-gradient(circle at 1.5px 1.5px, rgba(255,255,255,0.75) 1px, transparent 0); background-size: 22px 22px;">
                </div>
                <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(120%_150%_at_8%_0%,rgba(16,185,129,0.14),transparent_58%)]"></div>

                <div class="relative z-10 mx-auto flex min-h-[30rem] max-w-7xl flex-col px-5 pt-12 pb-8 sm:px-6 lg:px-8 lg:pt-12 lg:pb-8">
                    <div class="grid gap-10 lg:mx-auto lg:max-w-4xl lg:grid-cols-[1.35fr_0.8fr_1fr] lg:gap-16">
                        <div>
                            <span class="font-heading text-4xl font-bold tracking-tight"><span class="text-white">ilmu</span><span class="text-gold-300">360°</span></span>
                            <p class="mt-4 max-w-xs text-sm leading-6 text-emerald-100/70">
                                {{ __('Connecting the community through knowledge. Discover classes, lectures, and gatherings across Malaysia.') }}
                            </p>

                            <div class="mt-6 flex items-center gap-3">
                                <a href="#" aria-label="Facebook"
                                    class="text-emerald-100/60 transition-colors duration-200 hover:text-gold-300 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gold-300/60">
                                    <svg class="h-[1.05rem] w-[1.05rem]" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                        <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073Z" />
                                    </svg>
                                </a>
                                <a href="#" aria-label="Instagram"
                                    class="text-emerald-100/60 transition-colors duration-200 hover:text-gold-300 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gold-300/60">
                                    <svg class="h-[1.05rem] w-[1.05rem]" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                        <path d="M12 0C8.74 0 8.333.015 7.053.072 5.775.132 4.905.333 4.14.63c-.789.306-1.459.717-2.126 1.384S.935 3.35.63 4.14C.333 4.905.131 5.775.072 7.053.012 8.333 0 8.74 0 12s.015 3.667.072 4.947c.06 1.277.261 2.148.558 2.913.306.788.717 1.459 1.384 2.126.667.666 1.336 1.079 2.126 1.384.766.296 1.636.499 2.913.558C8.333 23.988 8.74 24 12 24s3.667-.015 4.947-.072c1.277-.06 2.148-.262 2.913-.558.788-.306 1.459-.718 2.126-1.384.666-.667 1.079-1.335 1.384-2.126.296-.765.499-1.636.558-2.913.06-1.28.072-1.687.072-4.947s-.015-3.667-.072-4.947c-.06-1.277-.262-2.149-.558-2.913-.306-.789-.718-1.459-1.384-2.126C21.319 1.347 20.651.935 19.86.63c-.765-.297-1.636-.499-2.913-.558C15.667.012 15.26 0 12 0Zm0 2.16c3.203 0 3.585.016 4.85.071 1.17.055 1.805.249 2.227.415.562.217.96.477 1.382.896.419.42.679.819.896 1.381.164.422.36 1.057.413 2.227.057 1.266.07 1.646.07 4.85s-.015 3.585-.074 4.85c-.061 1.17-.256 1.805-.421 2.227-.224.562-.479.96-.899 1.382-.419.419-.824.679-1.38.896-.42.164-1.065.36-2.235.413-1.274.057-1.649.07-4.859.07-3.211 0-3.586-.015-4.859-.074-1.171-.061-1.816-.256-2.236-.421-.569-.224-.96-.479-1.379-.899-.421-.419-.69-.824-.9-1.38-.165-.42-.359-1.065-.42-2.235-.045-1.26-.061-1.649-.061-4.844 0-3.196.016-3.586.061-4.861.061-1.17.255-1.814.42-2.234.21-.57.479-.96.9-1.381.419-.419.81-.689 1.379-.898.42-.166 1.051-.361 2.221-.421 1.275-.045 1.65-.06 4.859-.06l.045.03Zm0 3.678c-3.405 0-6.162 2.76-6.162 6.162 0 3.405 2.76 6.162 6.162 6.162 3.405 0 6.162-2.76 6.162-6.162 0-3.405-2.76-6.162-6.162-6.162ZM12 16c-2.21 0-4-1.79-4-4s1.79-4 4-4 4 1.79 4 4-1.79 4-4 4Zm7.846-10.405c0 .795-.646 1.44-1.44 1.44-.795 0-1.44-.646-1.44-1.44 0-.794.646-1.439 1.44-1.439.793-.001 1.44.645 1.44 1.439Z" />
                                    </svg>
                                </a>
                                <a href="#" aria-label="YouTube"
                                    class="text-emerald-100/60 transition-colors duration-200 hover:text-gold-300 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gold-300/60">
                                    <svg class="h-[1.05rem] w-[1.05rem]" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                        <path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814ZM9.545 15.568V8.432L15.818 12l-6.273 3.568Z" />
                                    </svg>
                                </a>
                                <a href="#" aria-label="TikTok"
                                    class="text-emerald-100/60 transition-colors duration-200 hover:text-gold-300 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gold-300/60">
                                    <svg class="h-[1.05rem] w-[1.05rem]" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                        <path d="M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.15 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07Z" />
                                    </svg>
                                </a>
                            </div>
                        </div>

                        <nav aria-label="{{ __('Menu') }}">
                            <h3 class="font-heading text-sm font-bold text-white">{{ __('Menu') }}</h3>
                            <ul class="mt-4 space-y-3 text-sm text-emerald-100/70">
                                <li><a href="{{ route('events.index') }}" wire:navigate
                                        class="transition-colors duration-200 hover:text-gold-300 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gold-300/60">{{ __('Upcoming Events') }}</a>
                                </li>
                                <li><a href="{{ route('institutions.index') }}" wire:navigate
                                        class="transition-colors duration-200 hover:text-gold-300 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gold-300/60">{{ __('Institutions') }}</a>
                                </li>
                                <li><a href="{{ route('persons.index') }}" wire:navigate
                                        class="transition-colors duration-200 hover:text-gold-300 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gold-300/60">{{ __('Speakers') }}</a>
                                </li>
                                <li><a href="{{ route('venues.index') }}" wire:navigate
                                        class="transition-colors duration-200 hover:text-gold-300 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gold-300/60">{{ __('Venue') }}</a>
                                </li>
                                <li><a href="{{ route('references.index') }}" wire:navigate
                                        class="transition-colors duration-200 hover:text-gold-300 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gold-300/60">{{ __('Reference') }}</a>
                                </li>
                            </ul>
                        </nav>

                        <nav aria-label="{{ __('Community') }}">
                            <h3 class="font-heading text-sm font-bold text-white">{{ __('Community') }}</h3>
                            <ul class="mt-4 space-y-3 text-sm text-emerald-100/70">
                                <li><a href="{{ route('about') }}" wire:navigate
                                        class="transition-colors duration-200 hover:text-gold-300 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gold-300/60">{{ __('About Us') }}</a>
                                </li>
                                <li><a href="{{ route('home') }}#submit" wire:navigate
                                        class="transition-colors duration-200 hover:text-gold-300 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gold-300/60">{{ __('Submit Event') }}</a>
                                </li>
                                <li><a href="{{ route('contributions.submit-person') }}" wire:navigate
                                        class="transition-colors duration-200 hover:text-gold-300 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gold-300/60">{{ __('Speaker suggestion') }}</a>
                                </li>
                                @php
                                    $supportEmail = config('mail.from.address');
                                    $hasSupportEmail = filled($supportEmail) && $supportEmail !== 'hello@example.com';
                                @endphp
                                <li>
                                    @if ($hasSupportEmail)
                                        <a href="mailto:{{ $supportEmail }}"
                                            class="transition-colors duration-200 hover:text-gold-300 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gold-300/60">{{ __('Contact Support') }}</a>
                                    @else
                                        <span>{{ __('Contact Support') }}</span>
                                    @endif
                                </li>
                            </ul>
                        </nav>

                    </div>

                    <div
                        class="mt-auto flex flex-col gap-4 pt-6 text-xs text-emerald-100/55 sm:flex-row sm:items-center sm:justify-between">
                        <p>&copy; {{ date('Y') }} ilmu360°. {{ __('All rights reserved.') }}</p>
                        <div class="flex items-center gap-3">
                            <span>{{ __('Privacy') }}</span>
                            <span aria-hidden="true">|</span>
                            <span>{{ __('Terms') }}</span>
                            <span aria-hidden="true">|</span>
                            @if ($hasSupportEmail)
                                <a href="mailto:{{ $supportEmail }}"
                                    class="transition-colors duration-200 hover:text-gold-300 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gold-300/60">{{ __('Contact Us') }}</a>
                            @else
                                <span>{{ __('Contact Us') }}</span>
                            @endif
                        </div>
                    </div>
                </div>
            </footer>
        </div>
    </div>

        @stack('prelivewire-scripts')
    @livewireScripts
    @fluxScripts
    @filamentScripts(['app', 'aiarmada/commerce-support'])
    @filamentScripts(['filament/support', 'filament/schemas', 'filament/forms', 'filament/actions', 'filament/notifications'])
    @stack('scripts')
</body>

</html>
