<x-layouts.auth>
    <div class="flex flex-col gap-6">
        <div class="flex w-full flex-col">
            <h1 class="font-heading text-2xl font-bold tracking-tight text-slate-900">{{ __('Reset password') }}</h1>
            <p class="mt-1 text-sm text-slate-500">{{ __('Create a new secure password for your account') }}</p>
        </div>

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('password.update') }}" class="flex flex-col gap-5">
            @csrf
            <!-- Token -->
            <input type="hidden" name="token" value="{{ request()->route('token') }}">

            <!-- Email Address -->
            <flux:input name="email" value="{{ request('email') }}" :label="__('Email address')" type="email" required
                autocomplete="email" />

            <!-- Password -->
            <flux:input name="password" :label="__('New password')" type="password" required autocomplete="new-password"
                :placeholder="__('New password')" viewable />

            <!-- Confirm Password -->
            <flux:input name="password_confirmation" :label="__('Confirm new password')" type="password" required
                autocomplete="new-password" :placeholder="__('Confirm new password')" viewable />

            <div class="flex items-center justify-end">
                <flux:button type="submit" variant="primary" class="w-full" data-test="reset-password-button">
                    {{ __('Reset Password') }}
                </flux:button>
            </div>
        </form>
    </div>
</x-layouts.auth>