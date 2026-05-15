@extends('layouts.auth')

@section('title', __('Register') . ' - ' . config('app.name'))

@section('content')
    @php($googleOauthConfigured = \App\Support\Auth\SocialiteProviderConfiguration::isConfigured('google'))

    {{-- Header --}}
    <div class="auth-animate-up auth-delay-1">
        <h1 class="font-heading text-3xl font-bold text-slate-900 tracking-tight">{{ __('Create Account') }}</h1>
        <p class="mt-2 text-slate-500 text-[15px]">{{ __('Join our community of knowledge seekers') }}</p>
    </div>

    @if ($googleOauthConfigured)
        {{-- Social Login --}}
        <div class="mt-8 auth-animate-up auth-delay-2">
            <a href="{{ route('socialite.redirect', 'google') }}"
                class="group flex w-full items-center justify-center gap-3 rounded-xl border-2 border-slate-200 bg-white px-4 py-3.5 text-sm font-semibold text-slate-700 transition-all hover:border-slate-300 hover:bg-slate-50 hover:shadow-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2">
                <svg class="h-5 w-5" viewBox="0 0 24 24">
                    <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4" />
                    <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853" />
                    <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05" />
                    <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335" />
                </svg>
                {{ __('Sign up with Google') }}
            </a>
        </div>

        {{-- Divider --}}
        <div class="relative my-8 auth-animate-up auth-delay-2">
            <div class="absolute inset-0 flex items-center">
                <div class="w-full border-t border-slate-200"></div>
            </div>
            <div class="relative flex justify-center">
                <span class="bg-transparent px-4 text-xs font-medium uppercase tracking-widest text-slate-400"
                    style="background: linear-gradient(180deg, oklch(0.99 0.002 85), oklch(0.97 0.005 85));">
                    {{ __('or register with email') }}
                </span>
            </div>
        </div>
    @endif

    {{-- Form --}}
    <form method="POST" action="{{ route('register') }}" class="space-y-5">
        @csrf

        {{-- Name --}}
        <div class="auth-animate-up auth-delay-3">
            <label for="name" class="block text-sm font-semibold text-slate-700 mb-1.5">{{ __('Full name') }}</label>
            <input id="name" type="text" name="name" value="{{ old('name') }}"
                required autofocus autocomplete="name"
                placeholder="{{ __('Your full name') }}"
                class="auth-input block w-full rounded-xl border border-slate-200 bg-white px-4 py-3.5 text-sm text-slate-900 placeholder-slate-400 transition-all focus:border-emerald-500 focus:outline-none" />
            @error('name')
                <p class="mt-1.5 text-xs font-medium text-red-500">{{ $message }}</p>
            @enderror
        </div>

        {{-- Email --}}
        <div class="auth-animate-up auth-delay-3">
            <label for="email" class="block text-sm font-semibold text-slate-700 mb-1.5">{{ __('Email address') }}</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}"
                required autocomplete="email"
                placeholder="you@example.com"
                class="auth-input block w-full rounded-xl border border-slate-200 bg-white px-4 py-3.5 text-sm text-slate-900 placeholder-slate-400 transition-all focus:border-emerald-500 focus:outline-none" />
            @error('email')
                <p class="mt-1.5 text-xs font-medium text-red-500">{{ $message }}</p>
            @enderror
        </div>

        {{-- Password --}}
        <div class="auth-animate-up auth-delay-4">
            <label for="password" class="block text-sm font-semibold text-slate-700 mb-1.5">{{ __('Password') }}</label>
            <input id="password" type="password" name="password"
                required autocomplete="new-password"
                placeholder="••••••••"
                class="auth-input block w-full rounded-xl border border-slate-200 bg-white px-4 py-3.5 text-sm text-slate-900 placeholder-slate-400 transition-all focus:border-emerald-500 focus:outline-none" />
            @error('password')
                <p class="mt-1.5 text-xs font-medium text-red-500">{{ $message }}</p>
            @enderror
        </div>

        {{-- Confirm Password --}}
        <div class="auth-animate-up auth-delay-4">
            <label for="password_confirmation" class="block text-sm font-semibold text-slate-700 mb-1.5">{{ __('Confirm password') }}</label>
            <input id="password_confirmation" type="password" name="password_confirmation"
                required autocomplete="new-password"
                placeholder="••••••••"
                class="auth-input block w-full rounded-xl border border-slate-200 bg-white px-4 py-3.5 text-sm text-slate-900 placeholder-slate-400 transition-all focus:border-emerald-500 focus:outline-none" />
            @error('password_confirmation')
                <p class="mt-1.5 text-xs font-medium text-red-500">{{ $message }}</p>
            @enderror
        </div>

        {{-- Submit --}}
        <div class="auth-animate-up auth-delay-5 pt-2">
            <button type="submit"
                class="btn-gold-shimmer group flex w-full items-center justify-center gap-2 rounded-xl px-6 py-3.5 text-sm font-bold text-white uppercase tracking-wider transition-all duration-300 shadow-lg hover:shadow-xl hover:-translate-y-0.5 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2"
                style="background: linear-gradient(135deg, oklch(0.48 0.18 165), oklch(0.38 0.14 165)); box-shadow: 0 8px 24px -4px oklch(0.48 0.18 165 / 0.4);">
                {{ __('Create Account') }}
                <svg class="h-4 w-4 transition-transform group-hover:translate-x-0.5" fill="none" viewBox="0 0 24 24"
                    stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5m0 0l-5 5m5-5H6" />
                </svg>
            </button>
        </div>
    </form>

    {{-- Login link --}}
    <div class="mt-8 text-center auth-animate-up auth-delay-5">
        <span class="text-sm text-slate-500">{{ __('Already have an account?') }}</span>
        <a href="{{ route('login') }}"
            class="ml-1 text-sm font-bold text-emerald-700 hover:text-emerald-600 transition-colors">
            {{ __('Sign in') }}
        </a>
    </div>
@endsection
