@extends('layouts.auth')

@section('title', __('Log in') . ' - ' . config('app.name'))

@section('content')
    @php($googleOauthConfigured = \App\Support\Auth\SocialiteProviderConfiguration::isConfigured('google'))
    @php($authRedirectTarget = $redirectTarget ?? null)

    <div class="space-y-6">
        <div class="space-y-2 text-center">
            <h1 class="font-heading text-[2rem] font-bold leading-none tracking-[-0.035em] text-[#1d3042]">{{ __('Welcome Back') }}</h1>
            <p class="mx-auto max-w-[18rem] text-sm font-medium leading-5 text-[#6b7d8f]">{{ __('Please sign in to continue your journey with ilmu360°.') }}</p>
        </div>

        @if ($googleOauthConfigured)
            <div>
                <a href="{{ \App\Support\Auth\IntendedRedirect::socialiteUrl('google', $authRedirectTarget) }}"
                    class="group relative flex h-12 w-full items-center justify-center gap-3 rounded-xl border border-slate-200 bg-white/80 px-4 text-sm font-bold text-slate-700 shadow-sm transition-all duration-200 hover:border-emerald-200 hover:bg-emerald-50/50 hover:text-emerald-800 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/20 active:scale-[0.98]">
                    <svg class="h-5 w-5 shrink-0 transition-opacity opacity-80 group-hover:opacity-100" viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4" />
                        <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853" />
                        <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05" />
                        <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335" />
                    </svg>
                    <span>{{ __('Sign in with Google') }}</span>
                </a>
            </div>

            <div class="relative flex items-center py-1">
                <div class="grow border-t border-slate-200"></div>
                <span class="mx-4 shrink-0 text-xs font-bold uppercase tracking-widest text-slate-400">{{ __('OR') }}</span>
                <div class="grow border-t border-slate-200"></div>
            </div>
        @endif

        <form method="POST" action="{{ route('login') }}" class="space-y-5">
            @csrf

            <div class="space-y-1.5">
                <label for="email" class="block text-sm font-semibold text-[#25384a]">{{ __('Email address') }}</label>
                <div class="relative">
                    <svg class="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-emerald-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 8.25 12 14l9-5.75M4.5 19.5h15A1.5 1.5 0 0 0 21 18V6a1.5 1.5 0 0 0-1.5-1.5h-15A1.5 1.5 0 0 0 3 6v12a1.5 1.5 0 0 0 1.5 1.5Z" />
                    </svg>
                    <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus
                        autocomplete="email"
                        class="block h-12 w-full appearance-none rounded-xl border border-slate-200 bg-white/90 pl-12 pr-4 text-sm text-slate-900 placeholder-slate-400 shadow-sm transition-all focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/20"
                        placeholder="you@example.com" />
                </div>
                @error('email')
                    <p class="text-xs font-medium text-red-500">{{ $message }}</p>
                @enderror
            </div>

            <div class="space-y-1.5">
                <div class="flex items-center justify-between gap-4">
                    <label for="password" class="block text-sm font-semibold text-[#25384a]">{{ __('Password') }}</label>
                    @if (Route::has('password.request'))
                        <a href="{{ route('password.request', \App\Support\Auth\IntendedRedirect::queryParameters($authRedirectTarget)) }}"
                            class="text-xs font-semibold text-emerald-700 transition-colors decoration-2 underline-offset-4 hover:text-amber-600 hover:underline">
                            {{ __('Forgot password?') }}
                        </a>
                    @endif
                </div>
                <div class="relative">
                    <svg class="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-emerald-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 10V7.5a4.5 4.5 0 0 1 9 0V10m-10.5 0h12A1.5 1.5 0 0 1 19.5 11.5v7A1.5 1.5 0 0 1 18 20H6a1.5 1.5 0 0 1-1.5-1.5v-7A1.5 1.5 0 0 1 6 10h.0Z" />
                    </svg>
                    <input id="password" type="password" name="password" required autocomplete="current-password"
                        class="block h-12 w-full appearance-none rounded-xl border border-slate-200 bg-white/90 pl-12 pr-4 text-sm text-slate-900 placeholder-slate-400 shadow-sm transition-all focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/20"
                        placeholder="••••••••" />
                </div>
                @error('password')
                    <p class="text-xs font-medium text-red-500">{{ $message }}</p>
                @enderror
            </div>

            <div class="flex items-center">
                <input id="remember_me" type="checkbox" name="remember"
                    class="h-4 w-4 rounded border-slate-300 text-emerald-700 transition duration-150 ease-in-out focus:ring-emerald-600" />
                <label for="remember_me" class="ml-2 block text-sm font-medium text-slate-600">{{ __('Keep me signed in') }}</label>
            </div>

            <button type="submit"
                class="group relative flex h-[3.65rem] w-full items-center justify-center overflow-hidden rounded-xl bg-gradient-to-br from-[#087f4f] to-[#005b3d] text-sm font-bold uppercase tracking-[0.14em] text-white shadow-[0_10px_22px_-8px_rgba(0,92,62,0.65)] transition-all duration-300 hover:-translate-y-0.5 hover:shadow-xl focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2 active:scale-[0.98]">
                <span class="relative flex items-center gap-3">
                    {{ __('Sign In') }}
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-5-5 5 5-5 5" />
                    </svg>
                </span>
            </button>
        </form>

        @if (Route::has('register'))
            <div class="pt-1 text-center">
                <p class="text-sm text-slate-500">
                    {{ __('Don\'t have an account?') }}
                    <a href="{{ \App\Support\Auth\IntendedRedirect::registerUrl($authRedirectTarget) }}"
                        class="ml-1 font-bold text-emerald-700 transition-colors decoration-2 underline-offset-4 hover:text-amber-600 hover:underline">
                        {{ __('Create one') }}
                    </a>
                </p>
            </div>
        @endif

        <p class="pt-1 text-center font-amiri text-[0.95rem] italic text-[#8da0af]">“Menuntut ilmu adalah perjalanan seumur hidup.”</p>
    </div>
@endsection
