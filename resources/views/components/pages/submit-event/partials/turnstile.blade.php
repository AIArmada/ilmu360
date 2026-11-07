@if(config('services.turnstile.enabled') && filled(config('services.turnstile.site_key')) && filled(config('services.turnstile.secret_key')))
    <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4">
        <p class="mb-3 text-sm font-semibold text-slate-700">{{ __('Pengesahan Keselamatan') }}</p>
        <p class="mb-3 text-xs text-slate-500">
            {{ __('Sila sahkan anda bukan robot sebelum menghantar majlis.') }}
        </p>
        <input id="submit-event-captcha-token" type="hidden" wire:model="data.captcha_token">
        <div id="submit-event-turnstile" wire:ignore></div>

        @error('data.captcha_token')
            <p class="mt-2 text-sm text-danger-600">{{ $message }}</p>
        @enderror
    </div>
@endif
