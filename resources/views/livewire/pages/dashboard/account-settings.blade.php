@section('title', __('notifications.pages.settings.heading') . ' - ' . config('app.name'))

@include('partials.filament-assets', [
    'scripts' => ['filament/support', 'filament/schemas', 'filament/forms'],
])

@php
    $cadenceOptions = $this->notificationOptions['cadences'] ?? [];
    $email = $this->notificationDestinations['email'] ?? [];
    $pushDevices = $this->notificationDestinations['push'] ?? [];
    $pushCount = count($pushDevices);
    $emailAvailable = (bool) ($email['available'] ?? false) && (bool) ($email['verified'] ?? false);
    $pushAvailable = $pushCount > 0;
    $myEventsEnabled = (bool) ($this->notificationGroupsState['my_events']['enabled'] ?? true);
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

                        <a href="{{ route('notifications') }}" wire:navigate
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
                                <button type="submit" wire:loading.attr="disabled" wire:target="saveAccountSettings"
                                    class="inline-flex items-center justify-center gap-2 rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-70">
                                    <svg wire:loading wire:target="saveAccountSettings" class="h-4 w-4 flex-shrink-0 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                                    </svg>
                                    <span wire:loading.remove wire:target="saveAccountSettings">{{ __('Save Account Settings') }}</span>
                                    <span wire:loading wire:target="saveAccountSettings">{{ __('Saving…') }}</span>
                                </button>
                            </div>
                        </form>
                    </div>
                @else
                    <div class="notification-settings space-y-6 px-6 py-8 [--color-accent:var(--color-emerald-600)] md:px-8 dark:[--color-accent:var(--color-emerald-500)]">
                        <div class="rounded-3xl border border-amber-200 bg-amber-50 p-5">
                            <div class="flex gap-3">
                                <span class="mt-0.5 flex h-8 w-8 flex-none items-center justify-center rounded-full bg-amber-100 text-amber-800">
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true">
                                        <rect x="4.5" y="10" width="15" height="10.5" rx="2"></rect>
                                        <path d="M8 10V7.5a4 4 0 0 1 8 0V10"></path>
                                    </svg>
                                </span>
                                <div class="min-w-0">
                                    <p class="text-sm font-bold text-amber-900">{{ __('notifications.pages.settings.mandatory_heading') }}</p>
                                    <p class="mt-1 text-sm leading-6 text-amber-800">{{ __('notifications.pages.settings.mandatory_body') }}</p>
                                </div>
                            </div>
                        </div>

                        <form wire:submit="saveNotificationPreferences" class="space-y-6">
                            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                                <div class="max-w-2xl">
                                    <h2 class="font-heading text-2xl font-bold text-slate-900">{{ __('notifications.pages.settings.channels_heading') }}</h2>
                                    <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('notifications.pages.settings.channels_description') }}</p>
                                </div>

                                <div class="mt-5 divide-y divide-slate-100">
                                    <div class="flex items-center gap-4 py-4 first:pt-0">
                                        <span class="flex h-10 w-10 flex-none items-center justify-center rounded-xl bg-emerald-50 text-emerald-700">
                                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                                <path d="M4 5.5h16v13H4z"></path>
                                                <path d="m4.5 6.5 7.5 6 7.5-6"></path>
                                            </svg>
                                        </span>
                                        <div class="min-w-0 flex-1">
                                            <p class="text-sm font-semibold text-slate-900">{{ __('notifications.pages.settings.channel_in_app') }}</p>
                                            <p class="mt-1 break-words text-sm text-slate-600">{{ __('notifications.pages.settings.channel_in_app_help') }}</p>
                                        </div>
                                        <span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700">{{ __('notifications.pages.settings.channel_in_app_status') }}</span>
                                    </div>
                                    <div class="flex items-center gap-4 py-4">
                                        <span class="flex h-10 w-10 flex-none items-center justify-center rounded-xl bg-slate-100 text-slate-700">
                                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                                <path d="M4 5.5h16v13H4z"></path>
                                                <path d="m4.5 6.5 7.5 6 7.5-6"></path>
                                            </svg>
                                        </span>
                                        <div class="min-w-0 flex-1">
                                            <p class="text-sm font-semibold text-slate-900">{{ __('notifications.pages.settings.channel_email') }}</p>
                                            <p class="mt-1 break-words text-sm text-slate-600">
                                                {{ $email['address'] ?? __('notifications.destinations.not_available') }}
                                                @if ($emailAvailable)
                                                    <span class="ml-1 font-semibold text-emerald-700">· {{ __('notifications.destinations.email_ready') }}</span>
                                                @else
                                                    <span class="ml-1 font-semibold text-amber-700">· {{ __('notifications.pages.settings.channel_unavailable') }}</span>
                                                @endif
                                            </p>
                                        </div>
                                        <flux:switch wire:model.live="notificationSettingsState.channels" value="email"
                                            :disabled="! $emailAvailable" aria-label="{{ __('notifications.pages.settings.channel_email') }}" />
                                    </div>

                                    <div class="flex items-center gap-4 py-4 last:pb-0">
                                        <span class="flex h-10 w-10 flex-none items-center justify-center rounded-xl bg-slate-100 text-slate-700">
                                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                                <rect x="7" y="2.5" width="10" height="19" rx="2.5"></rect>
                                                <path d="M10.5 18.5h3"></path>
                                            </svg>
                                        </span>
                                        <div class="min-w-0 flex-1">
                                            <p class="text-sm font-semibold text-slate-900">{{ __('notifications.pages.settings.channel_push') }}</p>
                                            <p class="mt-1 break-words text-sm text-slate-600">
                                                @if ($pushAvailable)
                                                    {{ trans_choice('notifications.destinations.push_devices', $pushCount, ['count' => $pushCount]) }}
                                                    · <a href="#peranti" class="font-semibold text-emerald-700 underline decoration-2 underline-offset-2">{{ __('notifications.pages.settings.manage_devices') }}</a>
                                                @else
                                                    <span class="font-semibold text-amber-700">{{ __('notifications.pages.settings.channel_push_unavailable') }}</span>
                                                @endif
                                            </p>
                                        </div>
                                        <flux:switch wire:model.live="notificationSettingsState.channels" value="push"
                                            :disabled="! $pushAvailable" aria-label="{{ __('notifications.pages.settings.channel_push') }}" />
                                    </div>
                                </div>
                            </section>

                            <section class="space-y-4">
                                <div class="max-w-2xl">
                                    <h2 class="font-heading text-2xl font-bold text-slate-900">{{ __('notifications.pages.settings.groups_heading') }}</h2>
                                    <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('notifications.pages.settings.groups_description') }}</p>
                                </div>

                                <article class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                                    <div class="flex items-start justify-between gap-4">
                                        <div class="max-w-2xl">
                                            <h3 class="text-lg font-bold text-slate-900">{{ __('notifications.pages.settings.discovery_heading') }}</h3>
                                            <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('notifications.pages.settings.discovery_description') }}</p>
                                        </div>
                                        <flux:switch wire:model.live="notificationGroupsState.discovery.enabled"
                                            aria-label="{{ __('notifications.pages.settings.discovery_heading') }}" />
                                    </div>

                                    <div class="mt-5 flex flex-col gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4 sm:flex-row sm:items-center sm:justify-between">
                                        <label for="discovery-cadence" class="text-sm font-semibold text-slate-700">{{ __('notifications.pages.settings.cadence_label') }}</label>
                                        <flux:select id="discovery-cadence" wire:model="notificationGroupsState.discovery.cadence" class="sm:w-64">
                                            @foreach ($cadenceOptions as $cadenceValue => $cadenceLabel)
                                                @if ($cadenceValue !== 'off')
                                                    <flux:select.option value="{{ $cadenceValue }}">{{ $cadenceLabel }}</flux:select.option>
                                                @endif
                                            @endforeach
                                        </flux:select>
                                    </div>
                                </article>

                                <article class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                                    <div class="flex items-start justify-between gap-4">
                                        <div class="max-w-2xl">
                                            <h3 class="text-lg font-bold text-slate-900">{{ __('notifications.pages.settings.my_events_heading') }}</h3>
                                            <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('notifications.pages.settings.my_events_description') }}</p>
                                        </div>
                                        <flux:switch wire:model.live="notificationGroupsState.my_events.enabled"
                                            aria-label="{{ __('notifications.pages.settings.my_events_heading') }}" />
                                    </div>

                                    <div class="mt-5 grid gap-3 sm:grid-cols-2">
                                        @foreach ($this->notificationTriggersState as $triggerKey => $trigger)
                                            <label class="flex items-start gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                                <flux:switch wire:model.live="notificationTriggersState.{{ $triggerKey }}.enabled"
                                                    :disabled="! $myEventsEnabled" class="mt-0.5 shrink-0" />
                                                <span>
                                                    <span class="block text-sm font-semibold text-slate-900">{{ $trigger['label'] }}</span>
                                                    <span class="mt-1 block text-sm leading-6 text-slate-600">{{ $trigger['description'] }}</span>
                                                </span>
                                            </label>
                                        @endforeach
                                    </div>
                                </article>

                                @if ($this->showSubmissionGroup)
                                    <article class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                                        <div class="flex items-start justify-between gap-4">
                                            <div class="max-w-2xl">
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <h3 class="text-lg font-bold text-slate-900">{{ __('notifications.pages.settings.submission_heading') }}</h3>
                                                    <span class="rounded-full bg-amber-100 px-2.5 py-1 text-[0.65rem] font-bold uppercase tracking-wide text-amber-800">{{ __('notifications.pages.settings.organizer_badge') }}</span>
                                                </div>
                                                <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('notifications.pages.settings.submission_description') }}</p>
                                            </div>
                                            <flux:switch wire:model.live="notificationGroupsState.submission.enabled"
                                                aria-label="{{ __('notifications.pages.settings.submission_heading') }}" />
                                        </div>
                                    </article>
                                @endif
                            </section>

                            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                                <div class="flex items-start justify-between gap-4">
                                    <div class="max-w-2xl">
                                        <h2 class="font-heading text-2xl font-bold text-slate-900">{{ __('notifications.pages.settings.quiet_hours_heading') }}</h2>
                                        <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('notifications.pages.settings.quiet_hours_description') }}</p>
                                    </div>
                                    <div class="mt-1 flex items-center gap-2">
                                        <span class="text-sm font-semibold text-slate-700">{{ __('notifications.pages.settings.quiet_hours_label') }}</span>
                                        <flux:switch wire:model.live="quietHoursEnabled"
                                            aria-label="{{ __('notifications.pages.settings.quiet_hours_label') }}" />
                                    </div>
                                </div>

                                @if ($this->quietHoursEnabled)
                                    <div class="mt-5 grid gap-4 sm:grid-cols-2">
                                        <div class="space-y-2">
                                            <label for="quiet-hours-start" class="text-sm font-semibold text-slate-700">{{ __('notifications.pages.settings.quiet_hours_start') }}</label>
                                            <flux:input id="quiet-hours-start" type="time" wire:model="notificationSettingsState.quiet_hours_start" />
                                        </div>
                                        <div class="space-y-2">
                                            <label for="quiet-hours-end" class="text-sm font-semibold text-slate-700">{{ __('notifications.pages.settings.quiet_hours_end') }}</label>
                                            <flux:input id="quiet-hours-end" type="time" wire:model="notificationSettingsState.quiet_hours_end" />
                                        </div>
                                    </div>
                                @endif

                                <p class="mt-3 text-xs leading-5 text-slate-500">{{ __('notifications.pages.settings.quiet_hours_help') }}</p>
                            </section>

                            <section id="peranti" class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                                <div class="max-w-2xl">
                                    <h2 class="font-heading text-2xl font-bold text-slate-900">{{ __('notifications.pages.settings.devices_heading') }}</h2>
                                    <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('notifications.pages.settings.devices_description') }}</p>
                                </div>

                                <div class="mt-4">
                                    @forelse ($pushDevices as $device)
                                        <div class="flex items-center gap-4 border-t border-slate-100 py-4 first:border-t-0 first:pt-0">
                                            <span class="flex h-10 w-10 flex-none items-center justify-center rounded-xl bg-slate-100 text-slate-700">
                                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                                    <rect x="7" y="2.5" width="10" height="19" rx="2.5"></rect>
                                                    <path d="M10.5 18.5h3"></path>
                                                </svg>
                                            </span>
                                            <div class="min-w-0 flex-1">
                                                <p class="text-sm font-semibold text-slate-900">{{ $device['device_label'] }}</p>
                                                <p class="mt-1 text-xs text-slate-500">
                                                    {{ ucfirst((string) ($device['platform'] ?? '')) }}
                                                    @if (filled($device['last_seen_at']))
                                                        · {{ __('notifications.pages.settings.device_last_seen', ['time' => \Carbon\Carbon::parse($device['last_seen_at'])->diffForHumans()]) }}
                                                    @endif
                                                </p>
                                            </div>
                                            <flux:button type="button" size="sm" variant="subtle" wire:click="removePushDestination('{{ $device['id'] }}')"
                                                wire:confirm="{{ __('notifications.pages.settings.device_remove_confirm') }}"
                                                class="text-red-600 hover:text-red-700">
                                                {{ __('notifications.pages.settings.device_remove') }}
                                            </flux:button>
                                        </div>
                                    @empty
                                        <p class="rounded-2xl border border-dashed border-slate-200 bg-slate-50 px-4 py-6 text-center text-sm text-slate-600">
                                            {{ __('notifications.pages.settings.devices_empty') }}
                                        </p>
                                    @endforelse
                                </div>
                            </section>

                            <flux:button type="submit" variant="primary">
                                {{ __('notifications.pages.settings.save_button') }}
                            </flux:button>

                            <p class="text-xs leading-5 text-slate-500">{{ __('notifications.pages.settings.footer_note') }}</p>
                        </form>
                    </div>
                @endif
            </section>
        </div>
    </div>
</div>
