@extends('layouts.auth')

@section('title', __('Reset Password') . ' - ' . config('app.name'))

@section('content')
    {{-- Header --}}
    <div class="auth-animate-up auth-delay-1">
        <h1 class="font-heading text-3xl font-bold text-slate-900 tracking-tight">{{ __('Reset Password') }}</h1>
        <p class="mt-2 text-slate-500 text-[15px]">{{ __('Create a new secure password for your account') }}</p>
    </div>

    {{-- Form --}}
    <form method="POST" action="{{ route('password.update') }}" class="mt-8 space-y-5">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        {{-- Email --}}
        <div class="auth-animate-up auth-delay-2">
            <label for="email" class="block text-sm font-semibold text-slate-700 mb-1.5">{{ __('Email address') }}</label>
            <input id="email" type="email" name="email" value="{{ old('email', $request->email) }}"
                required autofocus autocomplete="email"
                class="auth-input block w-full rounded-xl border border-slate-200 bg-white px-4 py-3.5 text-sm text-slate-900 placeholder-slate-400 transition-all focus:border-emerald-500 focus:outline-none" />
            @error('email')
                <p class="mt-1.5 text-xs font-medium text-red-500">{{ $message }}</p>
            @enderror
        </div>

        {{-- Password --}}
        <div class="auth-animate-up auth-delay-3">
            <label for="password" class="block text-sm font-semibold text-slate-700 mb-1.5">{{ __('New password') }}</label>
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
            <label for="password_confirmation" class="block text-sm font-semibold text-slate-700 mb-1.5">{{ __('Confirm new password') }}</label>
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
                {{ __('Reset Password') }}
            </button>
        </div>
    </form>
@endsection
