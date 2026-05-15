<x-layouts.auth>
    <div class="flex flex-col gap-6">
        @php($googleOauthConfigured = \App\Support\Auth\SocialiteProviderConfiguration::isConfigured('google'))
        @php($authRedirectTarget = $redirectTarget ?? null)

        <div class="flex w-full flex-col">
            <h1 class="font-heading text-2xl font-bold tracking-tight text-slate-900">{{ __('Create an account') }}</h1>
            <p class="mt-1 text-sm text-slate-500">{{ __('Join our community of knowledge seekers') }}</p>
        </div>

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        @if ($googleOauthConfigured)
            <!-- Social Login First -->
            <a href="{{ \App\Support\Auth\IntendedRedirect::socialiteUrl('google', $authRedirectTarget) }}"
                class="group flex w-full items-center justify-center gap-3 rounded-xl border-2 border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 transition-all hover:border-slate-300 hover:bg-slate-50 hover:shadow-sm focus:outline-none focus:ring-2 focus:ring-emerald-500/30 focus:ring-offset-1">
                <svg class="h-5 w-5" viewBox="0 0 24 24">
                    <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4" />
                    <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853" />
                    <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05" />
                    <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335" />
                </svg>
                {{ __('Sign up with Google') }}
            </a>

            <!-- Divider -->
            <div class="relative">
                <div class="absolute inset-0 flex items-center">
                    <div class="w-full border-t border-slate-200"></div>
                </div>
                <div class="relative flex justify-center">
                    <span class="px-4 text-xs font-medium uppercase tracking-widest text-slate-400"
                        style="background: linear-gradient(180deg, oklch(0.995 0.002 85), oklch(0.975 0.005 85));">
                        {{ __('or register with email') }}
                    </span>
                </div>
            </div>
        @endif

        <form method="POST" action="{{ route('register.store') }}" class="flex flex-col gap-5">
            @csrf

            <!-- Name -->
            <flux:input name="name" :label="__('Full name')" :value="old('name')" type="text" required autofocus
                autocomplete="name" :placeholder="__('Your full name')" />

            <!-- Email Address -->
            <flux:input name="email" :label="__('Email address')" :value="old('email')" type="email" required
                autocomplete="email" placeholder="email@example.com" />

            <!-- Password -->
            <flux:input name="password" :label="__('Password')" type="password" required autocomplete="new-password"
                :placeholder="__('Password')" viewable />

            <!-- Confirm Password -->
            <flux:input name="password_confirmation" :label="__('Confirm password')" type="password" required
                autocomplete="new-password" :placeholder="__('Confirm password')" viewable />

            <div class="flex items-center justify-end">
                <flux:button type="submit" variant="primary" class="w-full">
                    {{ __('Create Account') }}
                </flux:button>
            </div>
        </form>

        <div class="space-x-1 rtl:space-x-reverse text-center text-sm text-slate-500">
            <span>{{ __('Already have an account?') }}</span>
            <flux:link :href="\App\Support\Auth\IntendedRedirect::loginUrl($authRedirectTarget)" wire:navigate class="font-bold!">{{ __('Sign in') }}</flux:link>
        </div>
    </div>
</x-layouts.auth>
