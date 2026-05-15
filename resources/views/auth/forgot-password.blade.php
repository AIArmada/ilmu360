@extends('layouts.auth')

@section('title', __('Forgot Password') . ' - ' . config('app.name'))

@section('content')
    {{-- Header --}}
    <div class="auth-animate-up auth-delay-1">
        <h1 class="font-heading text-3xl font-bold text-slate-900 tracking-tight">{{ __('Forgot Password') }}</h1>
        <p class="mt-2 text-slate-500 text-[15px]">
            {{ __('No problem. Enter your email and we\'ll send you a reset link.') }}
        </p>
    </div>

    {{-- Form --}}
    <form method="POST" action="{{ route('password.email') }}" class="mt-8 space-y-5">
        @csrf

        {{-- Email --}}
        <div class="auth-animate-up auth-delay-3">
            <label for="email" class="block text-sm font-semibold text-slate-700 mb-1.5">{{ __('Email address') }}</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}"
                required autofocus autocomplete="email"
                placeholder="you@example.com"
                class="auth-input block w-full rounded-xl border border-slate-200 bg-white px-4 py-3.5 text-sm text-slate-900 placeholder-slate-400 transition-all focus:border-emerald-500 focus:outline-none" />
            @error('email')
                <p class="mt-1.5 text-xs font-medium text-red-500">{{ $message }}</p>
            @enderror
        </div>

        {{-- Submit --}}
        <div class="auth-animate-up auth-delay-4 pt-2">
            <button type="submit"
                class="btn-gold-shimmer group flex w-full items-center justify-center gap-2 rounded-xl px-6 py-3.5 text-sm font-bold text-white uppercase tracking-wider transition-all duration-300 shadow-lg hover:shadow-xl hover:-translate-y-0.5 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2"
                style="background: linear-gradient(135deg, oklch(0.48 0.18 165), oklch(0.38 0.14 165)); box-shadow: 0 8px 24px -4px oklch(0.48 0.18 165 / 0.4);">
                {{ __('Send Reset Link') }}
            </button>
        </div>
    </form>

    {{-- Back to login --}}
    <div class="mt-8 text-center auth-animate-up auth-delay-5">
        <a href="{{ route('login') }}"
            class="inline-flex items-center gap-1.5 text-sm font-semibold text-slate-500 hover:text-emerald-600 transition-colors">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
            </svg>
            {{ __('Back to Sign In') }}
        </a>
    </div>
@endsection
