<x-layouts.auth>
    <div class="flex flex-col gap-6">
        @php($authRedirectTarget = request('redirect'))

        <div class="flex w-full flex-col">
            <h1 class="font-heading text-2xl font-bold tracking-tight text-slate-900">{{ __('Forgot password') }}</h1>
            <p class="mt-1 text-sm text-slate-500">{{ __('No problem. Enter your email and we\'ll send a reset link.') }}</p>
        </div>

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('password.email') }}" class="flex flex-col gap-5">
            @csrf

            <!-- Email Address -->
            <flux:input name="email" :label="__('Email address')" type="email" required autofocus
                autocomplete="email" placeholder="email@example.com" />

            <flux:button variant="primary" type="submit" class="w-full" data-test="email-password-reset-link-button">
                {{ __('Send Reset Link') }}
            </flux:button>
        </form>

        <div class="space-x-1 rtl:space-x-reverse text-center text-sm text-slate-500">
            <span>{{ __('Remember your password?') }}</span>
            <flux:link :href="\App\Support\Auth\IntendedRedirect::loginUrl($authRedirectTarget)" wire:navigate class="font-bold!">{{ __('Sign in') }}</flux:link>
        </div>
    </div>
</x-layouts.auth>
