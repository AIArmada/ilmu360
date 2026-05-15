@extends('layouts.auth')

@section('title', __('Log in') . ' - ' . config('app.name'))

@section('content')
    @php($googleOauthConfigured = \App\Support\Auth\SocialiteProviderConfiguration::isConfigured('google'))
    @php($authRedirectTarget = $redirectTarget ?? null)

    <div class="space-y-6">
        <div class="text-center space-y-2">
            <h1 class="font-heading text-2xl font-bold tracking-tight text-emerald-950/90">{{ __('Welcome Back') }}</h1>
            <p class="text-slate-500 text-sm font-medium">{{ __('Please sign in to continue') }}</p>
        </div>

        @if ($googleOauthConfigured)
            <div>
                <a href="{{ \App\Support\Auth\IntendedRedirect::socialiteUrl('google', $authRedirectTarget) }}"
                    class="group relative flex h-12 w-full items-center justify-center gap-3 rounded-xl border border-slate-200 bg-white px-4 text-sm font-bold text-slate-700 hover:border-emerald-200 hover:bg-emerald-50/50 hover:text-emerald-800 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/20 active:scale-[0.98] transition-all duration-200 shadow-sm">
                    <svg class="h-5 w-5 shrink-0 transition-opacity opacity-80 group-hover:opacity-100" viewBox="0 0 24 24">
                        <path
                            d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"
                            fill="#4285F4" />
                        <path
                            d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"
                            fill="#34A853" />
                        <path
                            d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"
                            fill="#FBBC05" />
                        <path
                            d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"
                            fill="#EA4335" />
                    </svg>
                    <span>{{ __('Sign in with Google') }}</span>
                </a>
            </div>

            <div class="relative flex items-center py-2">
                <div class="flex-grow border-t border-slate-200"></div>
                <span class="mx-4 flex-shrink-0 text-xs font-bold uppercase tracking-widest text-slate-400">
                    {{ __('OR') }}
                </span>
                <div class="flex-grow border-t border-slate-200"></div>
            </div>
        @endif

        <form method="POST" action="{{ route('login') }}" class="space-y-5">
            @csrf

            <div class="space-y-1.5">
                <label for="email" class="block text-sm font-semibold text-slate-700">{{ __('Email address') }}</label>
                <div class="relative">
                    <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus
                        autocomplete="email"
                        class="block h-12 w-full appearance-none rounded-xl border border-slate-200 bg-white px-4 text-sm text-slate-900 placeholder-slate-400 shadow-sm transition-all focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/20"
                        placeholder="you@example.com" />
                </div>
                @error('email')
                    <p class="text-xs font-medium text-red-500">{{ $message }}</p>
                @enderror
            </div>

            <div class="space-y-1.5">
                <div class="flex items-center justify-between">
                    <label for="password" class="block text-sm font-semibold text-slate-700">{{ __('Password') }}</label>
                    @if (Route::has('password.request'))
                        <a href="{{ route('password.request', \App\Support\Auth\IntendedRedirect::queryParameters($authRedirectTarget)) }}"
                            class="text-xs font-semibold text-emerald-600 hover:text-amber-600 hover:underline transition-colors decoration-2 underline-offset-4">
                            {{ __('Forgot password?') }}
                        </a>
                    @endif
                </div>
                <div class="relative">
                    <input id="password" type="password" name="password" required autocomplete="current-password"
                        class="block h-12 w-full appearance-none rounded-xl border border-slate-200 bg-white px-4 text-sm text-slate-900 placeholder-slate-400 shadow-sm transition-all focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/20"
                        placeholder="••••••••" />
                </div>
                @error('password')
                    <p class="text-xs font-medium text-red-500">{{ $message }}</p>
                @enderror
            </div>

            <div class="flex items-center">
                <input id="remember_me" type="checkbox" name="remember"
                    class="h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-600 transition duration-150 ease-in-out" />
                <label for="remember_me" class="ml-2 block text-sm text-slate-600 font-medium">
                    {{ __('Keep me signed in') }}
                </label>
            </div>

            <button type="submit"
                class="group relative flex h-12 w-full items-center justify-center overflow-hidden rounded-xl text-sm font-bold text-white uppercase tracking-wider shadow-lg transition-all duration-300 hover:shadow-xl hover:-translate-y-0.5 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2 active:scale-[0.98]"
                style="background: linear-gradient(135deg, oklch(0.40 0.12 165), oklch(0.30 0.10 165));">
                <div
                    class="absolute inset-0 -translate-x-full bg-gradient-to-r from-transparent via-white/20 to-transparent transition-transform duration-1000 group-hover:animate-[shimmer_1.5s_infinite]">
                </div>

                <span class="relative flex items-center gap-2">
                    {{ __('Sign In') }}
                    <svg class="h-4 w-4 transition-transform duration-300 group-hover:translate-x-1" fill="none"
                        viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                            d="M13 7l5 5m0 0l-5 5m5-5H6" />
                    </svg>
                </span>
            </button>
        </form>

        @if (Route::has('register'))
            <div class="text-center pt-2">
                <p class="text-sm text-slate-500">
                    {{ __('Don\'t have an account?') }}
                    <a href="{{ \App\Support\Auth\IntendedRedirect::registerUrl($authRedirectTarget) }}"
                        class="ml-1 font-bold text-emerald-700 hover:text-amber-600 hover:underline transition-colors decoration-2 underline-offset-4">
                        {{ __('Create one') }}
                    </a>
                </p>
            </div>
        @endif
    </div>
@endsection
