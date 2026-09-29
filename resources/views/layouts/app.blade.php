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
                style="background-image: url('{{ asset('images/pattern-bg.jpg') }}'); background-size: 400px;">
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
                $eventsNavActive = request()->routeIs('events.*');
                $institutionsNavActive = request()->routeIs('institutions.*');
                $personsNavActive = request()->routeIs('persons.*');
                $useHomeHeader = request()->routeIs('home');
                $useFloatingHeader = request()->routeIs('events.index', 'persons.index', 'institutions.index', 'references.index', 'submit-event.create');
                $useOverlayHeader = $useHomeHeader || $useFloatingHeader;
                $currentLocaleLabel = str_starts_with($currentLocale, 'ms')
                    ? 'BM'
                    : ($publicMenuLocales[$currentLocale] ?? $supportedLocales[$currentLocale] ?? strtoupper($currentLocale));
                $headerClass = $useOverlayHeader
                    ? 'absolute inset-x-0 top-0 z-50 w-full transition-all'
                    : 'sticky top-0 z-50 w-full border-b border-[#e5dccb] bg-[#f6f1e8]/95 backdrop-blur-md transition-all';
                $navClass = $useHomeHeader
                    ? 'relative mx-auto flex h-20 w-full max-w-[120rem] items-center gap-3 px-5 sm:px-8 lg:gap-5 lg:px-14 xl:px-16'
                    : ($useFloatingHeader
                    ? 'relative mx-auto mt-4 flex h-20 w-[calc(100%_-_1rem)] max-w-[120rem] items-center gap-3 rounded-[2rem] border border-[#eadfca]/90 bg-[#fffdf8]/90 px-5 shadow-[0_24px_55px_-30px_rgba(63,71,47,0.48)] backdrop-blur-xl sm:mt-6 sm:w-[calc(100%_-_3rem)] sm:px-8 lg:mt-8 lg:w-[94%] lg:gap-4 lg:px-8 xl:gap-6 xl:px-24'
                    : 'container mx-auto flex h-20 items-center justify-between px-6 lg:px-12');
                $logoClass = $useHomeHeader ? 'h-10 w-auto drop-shadow-[0_2px_10px_rgba(0,0,0,0.35)]' : 'h-12 w-auto';
                $desktopMenuClass = $useHomeHeader
                    ? 'hidden flex-1 items-center justify-center gap-6 text-sm font-medium text-white md:flex lg:gap-10'
                    : 'hidden flex-1 items-center justify-center gap-7 text-sm font-medium md:flex lg:gap-10';
                $eventsNavClass = $useHomeHeader
                    ? 'relative flex h-12 items-center px-2 text-sm font-medium text-white/90 transition-colors after:absolute after:bottom-1 after:left-1/2 after:h-1 after:-translate-x-1/2 after:rounded-full after:bg-[#e3b537] after:transition-all after:w-0 hover:text-white hover:after:w-9'
                    : 'relative flex h-12 items-center px-2 text-sm font-medium transition-colors after:absolute after:bottom-1 after:left-1/2 after:h-1 after:-translate-x-1/2 after:rounded-full after:bg-[#e3b537] after:transition-all '.($eventsNavActive ? 'text-[#005b3d] after:w-9' : 'text-[#37495a] after:w-0 hover:text-[#005b3d] hover:after:w-9');
                $institutionsNavClass = $useHomeHeader
                    ? 'relative flex h-12 items-center px-2 text-sm font-medium text-white/90 transition-colors after:absolute after:bottom-1 after:left-1/2 after:h-1 after:-translate-x-1/2 after:rounded-full after:bg-[#e3b537] after:transition-all after:w-0 hover:text-white hover:after:w-9'
                    : 'relative flex h-12 items-center px-2 text-sm font-medium transition-colors after:absolute after:bottom-1 after:left-1/2 after:h-1 after:-translate-x-1/2 after:rounded-full after:bg-[#e3b537] after:transition-all '.($institutionsNavActive ? 'text-[#005b3d] after:w-9' : 'text-[#37495a] after:w-0 hover:text-[#005b3d] hover:after:w-9');
                $personsNavClass = $useHomeHeader
                    ? 'relative flex h-12 items-center px-2 text-sm font-medium text-white/90 transition-colors after:absolute after:bottom-1 after:left-1/2 after:h-1 after:-translate-x-1/2 after:rounded-full after:bg-[#e3b537] after:transition-all after:w-0 hover:text-white hover:after:w-9'
                    : 'relative flex h-12 items-center px-2 text-sm font-medium transition-colors after:absolute after:bottom-1 after:left-1/2 after:h-1 after:-translate-x-1/2 after:rounded-full after:bg-[#e3b537] after:transition-all '.($personsNavActive ? 'text-[#005b3d] after:w-9' : 'text-[#37495a] after:w-0 hover:text-[#005b3d] hover:after:w-9');
                $languageButtonClass = $useHomeHeader
                    ? 'living-majlis-header-button living-majlis-header-button--glass flex items-center gap-2 whitespace-nowrap rounded-full px-3 py-1.5 text-xs font-semibold tracking-wider text-white transition-all'
                    : 'flex items-center gap-2 whitespace-nowrap rounded-full border border-[#dbe1dc] bg-white/50 px-3 py-1.5 text-xs font-semibold tracking-wider text-[#2d4b4c] transition-all group-hover:border-[#006044] group-hover:bg-[#006044] group-hover:text-white group-hover:shadow-[0_6px_16px_-8px_rgba(0,96,68,0.7)]';
                $addButtonClass = 'living-majlis-header-button living-majlis-header-button--gold hidden items-center justify-center gap-2 whitespace-nowrap rounded-full px-4 py-2 text-sm font-semibold sm:inline-flex';
                $userButtonClass = 'flex items-center gap-2 rounded-full border border-[#198663] bg-white/50 p-1 pr-3 text-[#006044] transition-all hover:bg-white/80';
                $guestLoginClass = $useHomeHeader
                    ? 'living-majlis-header-button living-majlis-header-button--glass hidden items-center gap-2 whitespace-nowrap rounded-full px-4 py-2 text-sm font-semibold text-white transition-all lg:inline-flex'
                    : 'hidden items-center gap-2 whitespace-nowrap rounded-full border border-[#198663] bg-white/45 px-3 py-1.5 text-sm font-semibold text-[#006044] transition-all hover:border-[#006044] hover:bg-[#006044] hover:text-white hover:shadow-[0_6px_16px_-8px_rgba(0,96,68,0.7)] lg:inline-flex';
                $guestRegisterClass = $useHomeHeader
                    ? 'living-majlis-header-button inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-full bg-[#0e8a63] px-5 py-2 text-sm font-semibold text-white shadow-lg shadow-black/20 transition-all hover:bg-[#13a574]'
                    : 'living-majlis-header-button living-majlis-header-button--emerald inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-full px-5 py-2 text-sm font-semibold';
                $mobileMenuClass = $useHomeHeader
                    ? 'mt-2 mx-4 rounded-3xl border border-white/20 bg-[#062e22]/95 text-white shadow-xl backdrop-blur-md md:hidden'
                    : ($useFloatingHeader
                    ? 'mt-2 mx-4 rounded-3xl border border-[#eadfca] bg-[#fffdf8]/95 shadow-xl backdrop-blur-md md:hidden'
                    : 'border-t border-[#e5dccb] bg-[#f6f1e8] md:hidden');
                $mobileNavLinkClass = $useHomeHeader
                    ? 'relative text-white after:absolute after:bottom-1 after:left-0 after:h-1 after:w-0 after:rounded-full after:bg-[#e3b537] after:transition-all hover:text-emerald-200 hover:after:w-9'
                    : 'relative text-slate-700 after:absolute after:bottom-1 after:left-0 after:h-1 after:w-0 after:rounded-full after:bg-[#e3b537] after:transition-all hover:text-emerald-600 hover:after:w-9';
                $mobileMenuButtonClass = $useHomeHeader
                    ? 'living-majlis-header-button border border-white/30 text-white'
                    : 'living-majlis-header-button border border-slate-200 text-slate-700';
                $mobileMenuDangerClass = $useHomeHeader
                    ? 'living-majlis-header-button border border-red-300/60 text-red-200'
                    : 'living-majlis-header-button border border-red-200 text-red-600';
                $mobileMenuStrongTextClass = $useHomeHeader
                    ? 'text-white'
                    : 'text-slate-900';
                $mobileMenuMutedTextClass = $useHomeHeader
                    ? 'text-white/70'
                    : 'text-slate-500';
            @endphp

            <!-- Premium Header -->
            <header
                class="{{ $headerClass }}"
                x-data="{ mobileMenuOpen: false }">
                <nav class="{{ $navClass }}">
                    @if($useFloatingHeader)
                        <span aria-hidden="true" class="pointer-events-none absolute left-0 top-1/2 hidden -translate-y-1/2 opacity-70 md:block">
                        <svg class="h-10 w-10 text-[#cda54a]" viewBox="0 0 56 56" fill="none">
                            <g stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M1 28h10M45 28h10" stroke-width="0.8" opacity=".5" />
                                <path d="M8 18c5-6 12-9 20-9s15 3 20 9M8 38c5 6 12 9 20 9s15-3 20-9" stroke-width="0.8" opacity=".42" />
                                <path d="m28 10 4 13 13-5-10 10 10 10-13-5-4 13-4-13-13 5 10-10-10-10 13 5 4-13Z" fill="currentColor" fill-opacity=".035" stroke-width="1" opacity=".82" />
                                <path d="M28 16c2 5 5 8 10 12-5 4-8 7-10 12-2-5-5-8-10-12 5-4 8-7 10-12Z" stroke-width="0.8" opacity=".72" />
                                <path d="M28 19v18M19 28h18" stroke-width="0.65" opacity=".4" />
                                <circle cx="28" cy="28" r="1.7" fill="currentColor" fill-opacity=".35" stroke="none" />
                            </g>
                        </svg>
                        </span>
                        <span aria-hidden="true" class="pointer-events-none absolute right-0 top-1/2 hidden -translate-y-1/2 opacity-70 md:block">
                        <svg class="h-10 w-10 text-[#cda54a]" viewBox="0 0 56 56" fill="none">
                            <g stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M1 28h10M45 28h10" stroke-width="0.8" opacity=".5" />
                                <path d="M8 18c5-6 12-9 20-9s15 3 20 9M8 38c5 6 12 9 20 9s15-3 20-9" stroke-width="0.8" opacity=".42" />
                                <path d="m28 10 4 13 13-5-10 10 10 10-13-5-4 13-4-13-13 5 10-10-10-10 13 5 4-13Z" fill="currentColor" fill-opacity=".035" stroke-width="1" opacity=".82" />
                                <path d="M28 16c2 5 5 8 10 12-5 4-8 7-10 12-2-5-5-8-10-12 5-4 8-7 10-12Z" stroke-width="0.8" opacity=".72" />
                                <path d="M28 19v18M19 28h18" stroke-width="0.65" opacity=".4" />
                                <circle cx="28" cy="28" r="1.7" fill="currentColor" fill-opacity=".35" stroke="none" />
                            </g>
                        </svg>
                        </span>
                    @endif

                    <a href="{{ route('home') }}" wire:navigate class="flex shrink-0 items-center">
                        <img src="{{ asset($useHomeHeader ? 'images/logo-ilmu360-footer.png' : 'images/logo-ilmu360.png') }}" alt="{{ config('app.name') }}" width="{{ $useHomeHeader ? 2167 : 2172 }}" height="{{ $useHomeHeader ? 726 : 724 }}"
                            class="{{ $logoClass }}">
                    </a>

                    <!-- Desktop Menu -->
                    <div class="{{ $desktopMenuClass }}">
                        <a href="{{ route('events.index') }}" wire:navigate
                            class="{{ $eventsNavClass }}">{{ __('Events') }}</a>
                        <a href="{{ route('institutions.index') }}" wire:navigate
                            class="{{ $institutionsNavClass }}">{{ __('Institutions') }}</a>
                        <a href="{{ route('persons.index') }}" wire:navigate
                            class="{{ $personsNavClass }}">{{ __('Speakers') }}</a>
                    </div>

                    <div class="flex items-center gap-3">
                        <!-- Mobile Menu Button -->
                        <button @click="mobileMenuOpen = !mobileMenuOpen"
                            class="living-majlis-header-button md:hidden rounded-lg p-2 {{ $useHomeHeader ? 'text-white hover:bg-white/10' : 'text-slate-600 hover:bg-slate-100' }} transition-colors">
                            <svg class="relative z-10 w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
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
                                aria-label="{{ __('Language') }}"
                                class="{{ $languageButtonClass }}">
                                    <svg class="relative z-10 h-4 w-4 {{ $useHomeHeader ? 'text-white' : 'text-[#087f4f] group-hover:text-white' }} transition-colors" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                    <circle cx="12" cy="12" r="9" stroke-width="1.8" />
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 12h18M12 3c2.2 2.4 3.3 5.4 3.3 9S14.2 18.6 12 21c-2.2-2.4-3.3-5.4-3.3-9S9.8 5.4 12 3Z" />
                                </svg>
                                <span class="relative z-10">{{ $currentLocaleLabel }}</span>
                                <svg class="relative z-10 h-3 w-3 {{ $useHomeHeader ? 'text-white/70 group-hover:text-white' : 'text-[#55706c] group-hover:text-white' }} transition-colors" fill="none"
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
                                        @if($locale === $currentLocale) aria-current="true" @endif
                                        class="flex items-center justify-between gap-2 rounded-lg px-3 py-2 text-xs font-semibold tracking-wider transition-colors {{ $locale === $currentLocale ? 'bg-[#006044] text-white shadow-[0_4px_10px_-4px_rgba(0,96,68,0.7)]' : 'text-slate-500 hover:bg-amber-100 hover:text-[#7e530f] hover:shadow-sm' }}">
                                        {{ $label }}
                                        @if($locale === $currentLocale)
                                            <svg class="h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" />
                                            </svg>
                                        @endif
                                    </a>
                                @endforeach
                            </div>
                        </div>

                        <a href="{{ route('submit-event.create') }}" wire:navigate
                            class="{{ $addButtonClass }}">
                            <svg class="relative z-10 h-4 w-4 rounded-full bg-[#087f4f] p-0.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-width="2.5" d="M12 6v12M6 12h12" />
                            </svg>
                            <span class="relative z-10">{{ __('Add Event') }}</span>
                        </a>

                        <span class="hidden h-8 w-px bg-[#dfd7c7] sm:block" aria-hidden="true"></span>

                        @auth
                            <!-- User Menu -->
                            <div class="relative group z-50 hidden sm:block">
                                <button
                                    class="{{ $userButtonClass }}">
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
                                    class="{{ $guestLoginClass }}">
                                    <svg class="relative z-10 h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 20v-1.5a4.5 4.5 0 0 0-4.5-4.5h-3A4.5 4.5 0 0 0 4 18.5V20M10 10a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm8-4v6m3-3h-6" />
                                    </svg>
                                    <span class="relative z-10">{{ __('Log In') }}</span>
                                </a>
                                <a href="{{ \App\Support\Auth\IntendedRedirect::registerUrl(request()->fullUrl()) }}" wire:navigate
                                    class="{{ $guestRegisterClass }}">
                                    <span class="relative z-10">{{ __('Sign Up') }}</span>
                                    <svg class="relative z-10 h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 12h14m-6-6 6 6-6 6" />
                                    </svg>
                                </a>
                            </div>
                        @endauth
                    </div>
                </nav>

                <!-- Mobile Menu Dropdown -->
                <div x-show="mobileMenuOpen" x-collapse x-cloak class="{{ $mobileMenuClass }}">
                    <div class="container mx-auto px-6 py-4 space-y-4">
                        <div class="flex flex-col gap-2">
                            <a href="{{ route('events.index') }}" wire:navigate
                                class="block py-2 text-base font-semibold {{ $mobileNavLinkClass }}">{{ __('Events') }}</a>
                            <a href="{{ route('institutions.index') }}" wire:navigate
                                class="block py-2 text-base font-semibold {{ $mobileNavLinkClass }}">{{ __('Institutions') }}</a>
                            <a href="{{ route('persons.index') }}" wire:navigate
                                class="block py-2 text-base font-semibold {{ $mobileNavLinkClass }}">{{ __('Speakers') }}</a>
                        </div>
                        <div class="border-t border-[#e5dccb] pt-4 flex flex-col gap-3">
                            <a href="{{ route('submit-event.create') }}" wire:navigate
                                class="living-majlis-header-button living-majlis-header-button--gold block w-full rounded-xl px-4 py-3 text-center text-sm font-semibold">
                                <span class="relative z-10">{{ __('Add Event') }}</span>
                            </a>
                            @guest
                                <div class="grid grid-cols-2 gap-3">
                                    <a href="{{ \App\Support\Auth\IntendedRedirect::loginUrl(request()->fullUrl()) }}" wire:navigate
                                        class="flex items-center justify-center rounded-lg px-4 py-3 text-sm font-semibold {{ $mobileMenuButtonClass }}">
                                        <span class="relative z-10">{{ __('Log In') }}</span>
                                    </a>
                                    <a href="{{ \App\Support\Auth\IntendedRedirect::registerUrl(request()->fullUrl()) }}" wire:navigate
                                        class="living-majlis-header-button living-majlis-header-button--emerald flex items-center justify-center rounded-xl px-4 py-3 text-sm font-semibold">
                                        <span class="relative z-10">{{ __('Sign Up') }}</span>
                                    </a>
                                </div>
                            @else
                                <div class="flex items-center gap-3 py-2">
                                    <div
                                        class="h-10 w-10 rounded-full bg-emerald-100 flex items-center justify-center text-emerald-700 font-bold uppercase">
                                        {{ substr(auth()->user()->name, 0, 1) }}
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-bold truncate {{ $mobileMenuStrongTextClass }}">{{ auth()->user()->name }}</p>
                                        <p class="text-xs truncate {{ $mobileMenuMutedTextClass }}">{{ auth()->user()->email }}</p>
                                    </div>
                                </div>
                                <div class="space-y-3 border-t border-slate-100 pt-4">
                                    <div class="space-y-2">
                                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">{{ $homeMenuHeading }}</p>
                                        <a href="{{ route('dashboard') }}" wire:navigate
                                            class="block rounded-lg px-4 py-2 text-sm font-semibold {{ $mobileMenuButtonClass }}">
                                            <span class="relative z-10">{{ $dashboardMenuLabel }}</span>
                                        </a>
                                        <a href="{{ route('dashboard.notifications') }}" wire:navigate
                                            class="flex items-center justify-between rounded-lg px-4 py-2 text-sm font-semibold {{ $mobileMenuButtonClass }}">
                                            <span class="relative z-10">{{ $inboxMenuLabel }}</span>
                                            @if($notificationUnreadCount > 0)
                                                <span class="relative z-10 inline-flex min-w-6 items-center justify-center rounded-full bg-emerald-600 px-2 py-0.5 text-xs font-semibold text-white">
                                                    {{ $notificationUnreadCount }}
                                                </span>
                                            @endif
                                        </a>
                                    </div>
                                    <div class="space-y-2 border-t border-slate-100 pt-4">
                                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">{{ $workspaceMenuHeading }}</p>
                                        <a href="{{ route('contributions.index') }}" wire:navigate
                                            class="block rounded-lg px-4 py-2 text-sm font-semibold {{ $mobileMenuButtonClass }}">
                                            <span class="relative z-10">{{ $contributionsMenuLabel }}</span>
                                        </a>
                                        @if($hasInstitutionDashboardAccess)
                                            <a href="{{ route('dashboard.institutions') }}" wire:navigate
                                                class="block rounded-lg px-4 py-2 text-sm font-semibold {{ $mobileMenuButtonClass }}">
                                                <span class="relative z-10">{{ $institutionDashboardMenuLabel }}</span>
                                            </a>
                                        @endif
                                        <a href="{{ route('dashboard.organizations.create') }}" wire:navigate
                                            class="block rounded-lg px-4 py-2 text-sm font-semibold {{ $mobileMenuButtonClass }}">
                                            <span class="relative z-10">{{ $organizationCreateMenuLabel }}</span>
                                        </a>
                                        @if($hasOrganizationDashboardAccess)
                                            <a href="{{ route('dashboard.organizations.index') }}" wire:navigate
                                                class="block rounded-lg px-4 py-2 text-sm font-semibold {{ $mobileMenuButtonClass }}">
                                                <span class="relative z-10">{{ $organizationDashboardMenuLabel }}</span>
                                            </a>
                                        @endif
                                        @if($hasManagedWorkspaceAccess && ! $hasOrganizationDashboardAccess)
                                            <a href="{{ route('dashboard.organizations.index') }}" wire:navigate
                                                class="block rounded-lg px-4 py-2 text-sm font-semibold {{ $mobileMenuButtonClass }}">
                                                <span class="relative z-10">{{ __('Manage Workspaces') }}</span>
                                            </a>
                                        @endif
                                    </div>
                                    <div class="space-y-2 border-t border-slate-100 pt-4">
                                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">{{ $accountMenuHeading }}</p>
                                        <a href="{{ route('dashboard.account-settings') }}" wire:navigate
                                            class="block rounded-lg px-4 py-2 text-sm font-semibold {{ $mobileMenuButtonClass }}">
                                            <span class="relative z-10">{{ $settingsMenuLabel }}</span>
                                        </a>
                                        <form method="POST" action="{{ route('logout') }}">
                                            @csrf
                                            <button type="submit"
                                                class="w-full rounded-lg px-4 py-2 text-sm font-semibold {{ $mobileMenuDangerClass }}">
                                                <span class="relative z-10">{{ __('Log Out') }}</span>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            @endguest
                        </div>
                        <!-- Mobile Language Switcher -->
                        <div class="border-t border-[#e5dccb] pt-4" data-language-switcher-case="title">
                            <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">
                                {{ __('Language') }}
                            </p>
                            <div class="flex flex-wrap gap-2">
                                @foreach ($publicMenuLocales as $locale => $label)
                                    @php
                                        $languagePillClass = $useHomeHeader
                                            ? ($locale === $currentLocale ? 'border-emerald-300/60 bg-emerald-400/20 text-emerald-100' : 'border-white/25 text-white/80')
                                            : ($locale === $currentLocale ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-slate-100 text-slate-600');
                                    @endphp
                                    <a href="{{ route('locale.switch', $locale) }}"
                                        data-language-switcher-option="{{ $locale }}"
                                        class="living-majlis-header-button px-3 py-1.5 rounded-full text-xs font-medium border {{ $languagePillClass }}">
                                        <span class="relative z-10">{{ $label }}</span>
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

            {{-- Tag invocation: @include renders Blaze-compiled components empty. --}}
            <x-ui.toast-stack />

            <!-- Living Majlis Footer -->
            <footer class="living-majlis-footer relative mt-20 overflow-hidden bg-emerald-950 text-emerald-50">
                <div class="living-majlis-footer-image pointer-events-none absolute inset-0 opacity-100"
                    style="background-image: url('{{ asset('images/footer-courtyard-v4.jpg') }}');">
                </div>
                <div class="pointer-events-none absolute inset-0 bg-[linear-gradient(90deg,rgba(2,33,22,0.08),rgba(2,33,22,0.18)_50%,rgba(2,33,22,0.08))]"></div>
                <div class="pointer-events-none absolute inset-0 opacity-[0.06]"
                    style="background-image: url('{{ asset('images/pattern-bg.jpg') }}'); background-size: 320px;">
                </div>
                <div class="pointer-events-none absolute inset-0 opacity-[0.055]"
                    style="background-image: radial-gradient(circle at 1.5px 1.5px, rgba(255,255,255,0.75) 1px, transparent 0); background-size: 22px 22px;">
                </div>
                <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(120%_150%_at_8%_0%,rgba(16,185,129,0.14),transparent_58%)]"></div>

                <div class="relative z-10 mx-auto flex min-h-[30rem] max-w-7xl flex-col px-5 pt-24 pb-8 sm:px-6 lg:px-8 lg:pt-24 lg:pb-8">
                    <div class="grid gap-10 lg:mx-auto lg:max-w-4xl lg:grid-cols-[1.35fr_0.8fr_1fr] lg:gap-16">
                        <div>
                            <img
                                src="{{ asset('images/logo-ilmu360-footer.png') }}"
                                alt="{{ config('app.name') }}"
                                width="2167"
                                height="726"
                                class="h-12 w-auto max-w-[14rem] drop-shadow-sm"
                            >
                            <p class="mt-4 max-w-xs text-sm leading-6 text-emerald-100/70">
                                {{ __('Connecting the community through knowledge. Discover classes, lectures, and gatherings across Malaysia.') }}
                            </p>

                            <div class="mt-6 flex items-center gap-3">
                                <a href="https://x.com/ilmu360" target="_blank" rel="noopener noreferrer" aria-label="X"
                                    class="text-emerald-100/60 transition-colors duration-200 hover:text-gold-300 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gold-300/60">
                                    <svg class="h-[1.05rem] w-[1.05rem]" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                        <path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817-5.966 6.817H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231 5.451-6.231Zm-1.161 17.52h1.833L7.084 4.126H5.117l11.966 15.644Z" />
                                    </svg>
                                </a>
                                <a href="https://www.instagram.com/ilmu.360" target="_blank" rel="noopener noreferrer" aria-label="Instagram"
                                    class="text-emerald-100/60 transition-colors duration-200 hover:text-gold-300 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gold-300/60">
                                    <svg class="h-[1.05rem] w-[1.05rem]" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                        <path d="M12 0C8.74 0 8.333.015 7.053.072 5.775.132 4.905.333 4.14.63c-.789.306-1.459.717-2.126 1.384S.935 3.35.63 4.14C.333 4.905.131 5.775.072 7.053.012 8.333 0 8.74 0 12s.015 3.667.072 4.947c.06 1.277.261 2.148.558 2.913.306.788.717 1.459 1.384 2.126.667.666 1.336 1.079 2.126 1.384.766.296 1.636.499 2.913.558C8.333 23.988 8.74 24 12 24s3.667-.015 4.947-.072c1.277-.06 2.148-.262 2.913-.558.788-.306 1.459-.718 2.126-1.384.666-.667 1.079-1.335 1.384-2.126.296-.765.499-1.636.558-2.913.06-1.28.072-1.687.072-4.947s-.015-3.667-.072-4.947c-.06-1.277-.262-2.149-.558-2.913-.306-.789-.718-1.459-1.384-2.126C21.319 1.347 20.651.935 19.86.63c-.765-.297-1.636-.499-2.913-.558C15.667.012 15.26 0 12 0Zm0 2.16c3.203 0 3.585.016 4.85.071 1.17.055 1.805.249 2.227.415.562.217.96.477 1.382.896.419.42.679.819.896 1.381.164.422.36 1.057.413 2.227.057 1.266.07 1.646.07 4.85s-.015 3.585-.074 4.85c-.061 1.17-.256 1.805-.421 2.227-.224.562-.479.96-.899 1.382-.419.419-.824.679-1.38.896-.42.164-1.065.36-2.235.413-1.274.057-1.649.07-4.859.07-3.211 0-3.586-.015-4.859-.074-1.171-.061-1.816-.256-2.236-.421-.569-.224-.96-.479-1.379-.899-.421-.419-.69-.824-.9-1.38-.165-.42-.359-1.065-.42-2.235-.045-1.26-.061-1.649-.061-4.844 0-3.196.016-3.586.061-4.861.061-1.17.255-1.814.42-2.234.21-.57.479-.96.9-1.381.419-.419.81-.689 1.379-.898.42-.166 1.051-.361 2.221-.421 1.275-.045 1.65-.06 4.859-.06l.045.03Zm0 3.678c-3.405 0-6.162 2.76-6.162 6.162 0 3.405 2.76 6.162 6.162 6.162 3.405 0 6.162-2.76 6.162-6.162 0-3.405-2.76-6.162-6.162-6.162ZM12 16c-2.21 0-4-1.79-4-4s1.79-4 4-4 4 1.79 4 4-1.79 4-4 4Zm7.846-10.405c0 .795-.646 1.44-1.44 1.44-.795 0-1.44-.646-1.44-1.44 0-.794.646-1.439 1.44-1.439.793-.001 1.44.645 1.44 1.439Z" />
                                    </svg>
                                </a>
                                <a href="https://www.tiktok.com/@ilmu.360" target="_blank" rel="noopener noreferrer" aria-label="TikTok"
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
                                        class="transition-colors duration-200 hover:text-gold-300 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gold-300/60">{{ __('Majlis') }}</a>
                                </li>
                                <li><a href="{{ route('institutions.index') }}" wire:navigate
                                        class="transition-colors duration-200 hover:text-gold-300 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gold-300/60">{{ __('Institutions') }}</a>
                                </li>
                                <li><a href="{{ route('persons.index') }}" wire:navigate
                                        class="transition-colors duration-200 hover:text-gold-300 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gold-300/60">{{ __('Speakers') }}</a>
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
