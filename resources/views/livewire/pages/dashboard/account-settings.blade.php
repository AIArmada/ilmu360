@section('title', __('Account Settings') . ' - ' . config('app.name'))

@include('partials.filament-assets', [
    'scripts' => ['filament/support', 'filament/schemas', 'filament/forms'],
])

@php
    $channelOptions = collect($this->notificationOptions['channels'] ?? [])
        ->mapWithKeys(fn (array $channel): array => [$channel['value'] => $channel['label']])
        ->all();
    $cadenceOptions = $this->notificationOptions['cadences'] ?? [];
    $fallbackOptions = $this->notificationOptions['fallback_strategies'] ?? [];
    $weeklyDayOptions = $this->notificationOptions['weekly_days'] ?? [];
    $localeOptions = $this->notificationOptions['locales'] ?? [];
    $notificationTimezone = $this->notificationSettingsState['timezone'] ?? config('app.timezone');
    $pushDestinations = $this->notificationDestinations['push'] ?? [];
@endphp

<div class="min-h-screen bg-slate-50 py-12 pb-32">
    <div class="container mx-auto px-6 lg:px-12">
        <div class="mx-auto max-w-6xl space-y-8">
            <section class="overflow-hidden rounded-3xl border border-slate-200/70 bg-white shadow-sm">
                <div class="border-b border-slate-100 bg-gradient-to-r from-emerald-50 via-white to-white px-6 py-8 md:px-8">
                    <div class="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
                        <div class="max-w-2xl">
                            <p class="text-xs font-bold uppercase tracking-[0.2em] text-emerald-600">{{ __('Account Settings') }}</p>
                            <h1 class="mt-3 font-heading text-3xl font-bold text-slate-900">{{ __('notifications.pages.settings.heading') }}</h1>
                            <p class="mt-3 text-sm leading-6 text-slate-600">
                                {{ __('notifications.pages.settings.description') }}
                            </p>
                        </div>

                        <a href="{{ route('dashboard.notifications') }}" wire:navigate
                            class="inline-flex items-center justify-center rounded-full border border-slate-200 bg-white px-5 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-emerald-200 hover:text-emerald-700">
                            {{ __('notifications.pages.inbox.cta') }}
                        </a>
                    </div>

                    <div class="mt-8 inline-flex rounded-2xl border border-slate-200 bg-white p-1 shadow-sm">
                        <button type="button" wire:click="switchTab('profile')"
                            class="rounded-2xl px-4 py-2 text-sm font-semibold transition {{ $tab === 'profile' ? 'bg-slate-900 text-white shadow-sm' : 'text-slate-600 hover:text-slate-900' }}">
                            {{ __('Profile Details') }}
                        </button>
                        <button type="button" wire:click="switchTab('notifications')"
                            class="rounded-2xl px-4 py-2 text-sm font-semibold transition {{ $tab === 'notifications' ? 'bg-slate-900 text-white shadow-sm' : 'text-slate-600 hover:text-slate-900' }}">
                            {{ __('notifications.pages.settings.tab') }}
                        </button>
                    </div>
                </div>

                @if ($tab === 'profile')
                    <div class="space-y-8 px-6 py-8 md:px-8">
                        <form wire:submit="saveAccountSettings" class="space-y-8">
                            {{ $this->form }}

                            <div class="flex justify-end">
                                <button type="submit"
                                    class="inline-flex items-center justify-center rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-emerald-700">
                                    {{ __('Save Account Settings') }}
                                </button>
                            </div>
                        </form>
                    </div>
                @else
                    <div class="space-y-8 px-6 py-8 md:px-8">
                        <div class="grid gap-4 md:grid-cols-3">
                            <div class="rounded-3xl border border-slate-200 bg-slate-50 p-5">
                                <p class="text-xs font-bold uppercase tracking-[0.18em] text-slate-500">{{ $channelOptions['email'] ?? __('Email') }}</p>
                                <p class="mt-3 text-lg font-bold text-slate-900">{{ $this->notificationDestinations['email']['address'] ?? __('notifications.destinations.not_available') }}</p>
                                <p class="mt-2 text-sm text-slate-600">
                                    {{ ($this->notificationDestinations['email']['verified'] ?? false) ? __('notifications.destinations.email_ready') : __('notifications.destinations.email_pending') }}
                                </p>
                            </div>

                            <div class="rounded-3xl border border-slate-200 bg-slate-50 p-5">
                                <p class="text-xs font-bold uppercase tracking-[0.18em] text-slate-500">{{ $channelOptions['whatsapp'] ?? __('WhatsApp') }}</p>
                                <p class="mt-3 text-lg font-bold text-slate-900">{{ $this->notificationDestinations['whatsapp']['address'] ?? __('notifications.destinations.not_available') }}</p>
                                <p class="mt-2 text-sm text-slate-600">
                                    {{ ($this->notificationDestinations['whatsapp']['verified'] ?? false) ? __('notifications.destinations.whatsapp_ready') : __('notifications.destinations.whatsapp_pending') }}
                                </p>
                            </div>

                            <div class="rounded-3xl border border-slate-200 bg-slate-50 p-5">
                                <p class="text-xs font-bold uppercase tracking-[0.18em] text-slate-500">{{ $channelOptions['push'] ?? __('Push Notification') }}</p>
                                <p class="mt-3 text-lg font-bold text-slate-900">
                                    {{ trans_choice('notifications.destinations.push_devices', count($pushDestinations), ['count' => count($pushDestinations)]) }}
                                </p>
                                <p class="mt-2 text-sm text-slate-600">
                                    {{ count($pushDestinations) > 0 ? __('notifications.destinations.push_ready') : __('notifications.destinations.push_pending') }}
                                </p>
                            </div>
                        </div>

                        <form wire:submit="saveNotificationPreferences" class="space-y-8">
                            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                                <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                    <div class="max-w-2xl">
                                        <h2 class="font-heading text-2xl font-bold text-slate-900">{{ __('notifications.pages.settings.delivery_heading') }}</h2>
                                        <p class="mt-2 text-sm leading-6 text-slate-600">
                                            {{ __('notifications.pages.settings.delivery_description') }}
                                        </p>
                                    </div>
                                    <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
                                        <span class="font-semibold text-slate-900">{{ __('notifications.pages.settings.timezone_label') }}:</span>
                                        {{ $notificationTimezone }}
                                    </div>
                                </div>

                                <div class="mt-6 grid gap-6 xl:grid-cols-2">
                                    <div class="grid gap-6 md:grid-cols-2">
                                        <div class="space-y-2">
                                            <label class="text-sm font-semibold text-slate-700" for="notification-locale">{{ __('notifications.pages.settings.language_label') }}</label>
                                            <select id="notification-locale" wire:model.live="notificationSettingsState.locale"
                                                class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-900 focus:border-emerald-500 focus:outline-none">
                                                @foreach ($localeOptions as $localeValue => $localeLabel)
                                                    <option value="{{ $localeValue }}">{{ $localeLabel }}</option>
                                                @endforeach
                                            </select>
                                        </div>

                                        <div class="space-y-2">
                                            <label class="text-sm font-semibold text-slate-700" for="fallback-strategy">{{ __('notifications.pages.settings.fallback_label') }}</label>
                                            <select id="fallback-strategy" wire:model.live="notificationSettingsState.fallback_strategy"
                                                class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-900 focus:border-emerald-500 focus:outline-none">
                                                @foreach ($fallbackOptions as $fallbackValue => $fallbackLabel)
                                                    <option value="{{ $fallbackValue }}">{{ $fallbackLabel }}</option>
                                                @endforeach
                                            </select>
                                        </div>

                                        <div class="space-y-2">
                                            <label class="text-sm font-semibold text-slate-700" for="digest-delivery-time">{{ __('notifications.pages.settings.digest_time_label') }}</label>
                                            <input id="digest-delivery-time" type="time" wire:model.live="notificationSettingsState.digest_delivery_time"
                                                class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-900 focus:border-emerald-500 focus:outline-none">
                                        </div>

                                        <div class="space-y-2">
                                            <label class="text-sm font-semibold text-slate-700" for="digest-weekly-day">{{ __('notifications.pages.settings.digest_day_label') }}</label>
                                            <select id="digest-weekly-day" wire:model.live="notificationSettingsState.digest_weekly_day"
                                                class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-900 focus:border-emerald-500 focus:outline-none">
                                                @foreach ($weeklyDayOptions as $dayValue => $dayLabel)
                                                    <option value="{{ $dayValue }}">{{ $dayLabel }}</option>
                                                @endforeach
                                            </select>
                                        </div>

                                        <div class="space-y-2">
                                            <label class="text-sm font-semibold text-slate-700" for="quiet-hours-start">{{ __('notifications.pages.settings.quiet_hours_start_label') }}</label>
                                            <input id="quiet-hours-start" type="time" wire:model.live="notificationSettingsState.quiet_hours_start"
                                                class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-900 focus:border-emerald-500 focus:outline-none">
                                        </div>

                                        <div class="space-y-2">
                                            <label class="text-sm font-semibold text-slate-700" for="quiet-hours-end">{{ __('notifications.pages.settings.quiet_hours_end_label') }}</label>
                                            <input id="quiet-hours-end" type="time" wire:model.live="notificationSettingsState.quiet_hours_end"
                                                class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-900 focus:border-emerald-500 focus:outline-none">
                                        </div>
                                    </div>

                                    <div class="space-y-5 rounded-3xl border border-slate-200 bg-slate-50 p-5">
                                        <div>
                                            <h3 class="text-sm font-bold uppercase tracking-[0.18em] text-slate-500">{{ __('notifications.pages.settings.preferred_channels_label') }}</h3>
                                            <p class="mt-2 text-sm leading-6 text-slate-600">
                                                {{ __('notifications.pages.settings.preferred_channels_description') }}
                                            </p>
                                        </div>

                                        <div class="grid gap-3 md:grid-cols-2">
                                            @foreach ($preferredChannelSlots as $slotIndex => $slotValue)
                                                <div class="space-y-2" wire:key="preferred-channel-slot-{{ $slotIndex }}">
                                                    <label class="text-sm font-semibold text-slate-700" for="preferred-channel-{{ $slotIndex }}">
                                                        {{ __('notifications.pages.settings.channel_slot_label', ['number' => $slotIndex + 1]) }}
                                                    </label>
                                                    <select id="preferred-channel-{{ $slotIndex }}" wire:model.live="preferredChannelSlots.{{ $slotIndex }}"
                                                        class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-900 focus:border-emerald-500 focus:outline-none">
                                                        <option value="">{{ __('notifications.pages.settings.skip_channel_option') }}</option>
                                                        @foreach ($channelOptions as $channelValue => $channelLabel)
                                                            <option value="{{ $channelValue }}">{{ $channelLabel }}</option>
                                                        @endforeach
                                                    </select>
                                                </div>
                                            @endforeach
                                        </div>

                                        <div class="border-t border-slate-200 pt-5">
                                            <h3 class="text-sm font-bold uppercase tracking-[0.18em] text-slate-500">{{ __('notifications.pages.settings.fallback_channels_label') }}</h3>
                                            <p class="mt-2 text-sm leading-6 text-slate-600">
                                                {{ __('notifications.pages.settings.fallback_channels_description') }}
                                            </p>

                                            <div class="mt-4 grid gap-3 md:grid-cols-2">
                                                @foreach ($fallbackChannelSlots as $slotIndex => $slotValue)
                                                    <div class="space-y-2" wire:key="fallback-channel-slot-{{ $slotIndex }}">
                                                        <label class="text-sm font-semibold text-slate-700" for="fallback-channel-{{ $slotIndex }}">
                                                            {{ __('notifications.pages.settings.channel_slot_label', ['number' => $slotIndex + 1]) }}
                                                        </label>
                                                        <select id="fallback-channel-{{ $slotIndex }}" wire:model.live="fallbackChannelSlots.{{ $slotIndex }}"
                                                            class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-900 focus:border-emerald-500 focus:outline-none">
                                                            <option value="">{{ __('notifications.pages.settings.skip_channel_option') }}</option>
                                                            @foreach ($channelOptions as $channelValue => $channelLabel)
                                                                <option value="{{ $channelValue }}">{{ $channelLabel }}</option>
                                                            @endforeach
                                                        </select>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>

                                        <label class="flex items-start gap-3 rounded-2xl border border-slate-200 bg-white p-4">
                                            <input type="checkbox" wire:model.live="notificationSettingsState.urgent_override"
                                                class="mt-1 size-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500">
                                            <span>
                                                <span class="block text-sm font-semibold text-slate-900">{{ __('notifications.pages.settings.urgent_override_label') }}</span>
                                                <span class="mt-1 block text-sm leading-6 text-slate-600">{{ __('notifications.pages.settings.urgent_override_description') }}</span>
                                            </span>
                                        </label>
                                    </div>
                                </div>
                            </section>

                            <section class="space-y-5">
                                <div class="max-w-3xl">
                                    <h2 class="font-heading text-2xl font-bold text-slate-900">{{ __('notifications.pages.settings.families_heading') }}</h2>
                                    <p class="mt-2 text-sm leading-6 text-slate-600">
                                        {{ __('notifications.pages.settings.families_description') }}
                                    </p>
                                </div>

                                @foreach ($this->notificationFamiliesState as $familyKey => $familyState)
                                    @php
                                        $triggerStates = $this->notificationGroupedTriggers[$familyKey] ?? [];
                                    @endphp

                                    <article wire:key="notification-family-{{ $familyKey }}"
                                        class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                                        <div class="border-b border-slate-100 bg-slate-50/80 px-6 py-5">
                                            <div class="flex flex-col gap-5 xl:flex-row xl:items-start xl:justify-between">
                                                <div class="max-w-2xl">
                                                    <label class="inline-flex items-center gap-3">
                                                        <input type="checkbox" wire:model.live="notificationFamiliesState.{{ $familyKey }}.enabled"
                                                            class="size-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500">
                                                        <span class="text-lg font-bold text-slate-900">{{ $familyState['label'] }}</span>
                                                    </label>
                                                    <p class="mt-3 text-sm leading-6 text-slate-600">{{ $familyState['description'] }}</p>
                                                </div>

                                                <div class="grid gap-4 md:min-w-[22rem] md:grid-cols-2">
                                                    <div class="space-y-2">
                                                        <label class="text-sm font-semibold text-slate-700" for="family-cadence-{{ $familyKey }}">{{ __('notifications.pages.settings.cadence_label') }}</label>
                                                        <select id="family-cadence-{{ $familyKey }}" wire:model.live="notificationFamiliesState.{{ $familyKey }}.cadence"
                                                            class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-900 focus:border-emerald-500 focus:outline-none"
                                                            @disabled(! $familyState['enabled'])>
                                                            @foreach ($cadenceOptions as $cadenceValue => $cadenceLabel)
                                                                <option value="{{ $cadenceValue }}">{{ $cadenceLabel }}</option>
                                                            @endforeach
                                                        </select>
                                                    </div>

                                                    <div class="space-y-2">
                                                        <span class="text-sm font-semibold text-slate-700">{{ __('notifications.pages.settings.channels_label') }}</span>
                                                        <div class="flex flex-wrap gap-2 rounded-2xl border border-slate-200 bg-white p-3">
                                                            @foreach ($familyState['allowed_channels'] as $channelValue)
                                                                <label class="inline-flex items-center gap-2 rounded-full border border-slate-200 px-3 py-1.5 text-sm text-slate-700">
                                                                    <input type="checkbox"
                                                                        wire:model.live="notificationFamiliesState.{{ $familyKey }}.channels"
                                                                        value="{{ $channelValue }}"
                                                                        class="size-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500"
                                                                        @disabled(! $familyState['enabled'])>
                                                                    <span>{{ $channelOptions[$channelValue] ?? $channelValue }}</span>
                                                                </label>
                                                            @endforeach
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="space-y-4 px-6 py-6">
                                            <div class="max-w-3xl">
                                                <h3 class="text-sm font-bold uppercase tracking-[0.18em] text-slate-500">{{ __('notifications.pages.settings.trigger_heading') }}</h3>
                                                <p class="mt-2 text-sm leading-6 text-slate-600">
                                                    {{ __('notifications.pages.settings.trigger_description') }}
                                                </p>
                                            </div>

                                            <div class="grid gap-4 xl:grid-cols-2">
                                                @foreach ($triggerStates as $triggerState)
                                                    <div wire:key="notification-trigger-{{ $familyKey }}-{{ $triggerState['scope_key'] }}"
                                                        class="rounded-3xl border border-slate-200 bg-slate-50 p-5">
                                                        <div class="max-w-xl">
                                                            <label class="inline-flex items-center gap-3">
                                                                <input type="checkbox"
                                                                    wire:model.live="notificationTriggersState.{{ $triggerState['scope_key'] }}.enabled"
                                                                    class="size-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500"
                                                                    @disabled(! $familyState['enabled'])>
                                                                <span class="text-base font-semibold text-slate-900">{{ $triggerState['label'] }}</span>
                                                            </label>
                                                            <p class="mt-3 text-sm leading-6 text-slate-600">{{ $triggerState['description'] }}</p>
                                                        </div>

                                                        <div class="mt-5 space-y-4">
                                                            <label class="flex items-start gap-3 rounded-2xl border border-slate-200 bg-white p-4">
                                                                <input type="checkbox"
                                                                    wire:model.live="notificationTriggersState.{{ $triggerState['scope_key'] }}.inherits_family"
                                                                    class="mt-1 size-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500"
                                                                    @disabled(! $familyState['enabled'] || ! ($this->notificationTriggersState[$triggerState['scope_key']]['enabled'] ?? false))>
                                                                <span>
                                                                    <span class="block text-sm font-semibold text-slate-900">{{ __('notifications.ui.triggers.use_family_defaults') }}</span>
                                                                    <span class="mt-1 block text-sm leading-6 text-slate-600">{{ __('notifications.ui.triggers.inherits_family_help') }}</span>
                                                                </span>
                                                            </label>

                                                            <div class="space-y-2">
                                                                <label class="text-sm font-semibold text-slate-700" for="trigger-cadence-{{ $triggerState['scope_key'] }}">{{ __('notifications.pages.settings.cadence_label') }}</label>
                                                                <select id="trigger-cadence-{{ $triggerState['scope_key'] }}" wire:model.live="notificationTriggersState.{{ $triggerState['scope_key'] }}.cadence"
                                                                    class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-900 focus:border-emerald-500 focus:outline-none"
                                                                    @disabled(! $familyState['enabled'] || ! ($this->notificationTriggersState[$triggerState['scope_key']]['enabled'] ?? false) || ($this->notificationTriggersState[$triggerState['scope_key']]['inherits_family'] ?? true))>
                                                                    @foreach ($cadenceOptions as $cadenceValue => $cadenceLabel)
                                                                        <option value="{{ $cadenceValue }}">{{ $cadenceLabel }}</option>
                                                                    @endforeach
                                                                </select>
                                                            </div>

                                                            <div class="space-y-2">
                                                                <span class="text-sm font-semibold text-slate-700">{{ __('notifications.pages.settings.channels_label') }}</span>
                                                                <div class="flex flex-wrap gap-2">
                                                                    @foreach ($triggerState['allowed_channels'] as $channelValue)
                                                                        <label class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-3 py-1.5 text-sm text-slate-700">
                                                                            <input type="checkbox"
                                                                                wire:model.live="notificationTriggersState.{{ $triggerState['scope_key'] }}.channels"
                                                                                value="{{ $channelValue }}"
                                                                                class="size-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500"
                                                                                @disabled(! $familyState['enabled'] || ! ($this->notificationTriggersState[$triggerState['scope_key']]['enabled'] ?? false) || ($this->notificationTriggersState[$triggerState['scope_key']]['inherits_family'] ?? true))>
                                                                            <span>{{ $channelOptions[$channelValue] ?? $channelValue }}</span>
                                                                        </label>
                                                                    @endforeach
                                                                </div>
                                                            </div>

                                                            @if ($triggerState['supports_urgent_override'] ?? false)
                                                                <label class="flex items-start gap-3 rounded-2xl border border-slate-200 bg-white p-4">
                                                                    <input type="checkbox"
                                                                        wire:model.live="notificationTriggersState.{{ $triggerState['scope_key'] }}.urgent_override"
                                                                        class="mt-1 size-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500"
                                                                        @disabled(! $familyState['enabled'] || ! ($this->notificationTriggersState[$triggerState['scope_key']]['enabled'] ?? false) || ($this->notificationTriggersState[$triggerState['scope_key']]['inherits_family'] ?? true))>
                                                                    <span>
                                                                        <span class="block text-sm font-semibold text-slate-900">{{ __('notifications.ui.triggers.urgent_override') }}</span>
                                                                    </span>
                                                                </label>
                                                            @endif
                                                        </div>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    </article>
                                @endforeach
                            </section>

                            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <p class="text-sm leading-6 text-slate-600">
                                    {{ __('notifications.pages.settings.footer_note') }}
                                </p>

                                <button type="submit"
                                    class="inline-flex items-center justify-center rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-emerald-700">
                                    {{ __('notifications.pages.settings.save_button') }}
                                </button>
                            </div>
                        </form>
                    </div>
                @endif
            </section>
        </div>
    </div>
</div>
