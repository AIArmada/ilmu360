@section('title', __('notifications.inbox.page_title') . ' - ' . config('app.name'))

@php
    $channelOptions = collect(\App\Enums\NotificationChannel::userSelectable())
        ->mapWithKeys(fn (\App\Enums\NotificationChannel $channel): array => [$channel->value => $channel->label()])
        ->all();
@endphp

<div class="min-h-screen bg-slate-50 py-12 pb-32">
    <div class="container mx-auto px-6 lg:px-12">
        <div class="mx-auto max-w-5xl space-y-8">
            <section class="overflow-hidden rounded-3xl border border-slate-200/70 bg-white shadow-sm">
                <div class="border-b border-slate-100 bg-gradient-to-r from-emerald-50 via-white to-white px-6 py-8 md:px-8">
                    <div class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
                        <div class="max-w-3xl">
                            <p class="text-xs font-bold uppercase tracking-[0.2em] text-emerald-600">{{ __('notifications.inbox.eyebrow') }}</p>
                            <h1 class="mt-3 font-heading text-3xl font-bold text-slate-900">{{ __('notifications.inbox.heading') }}</h1>
                            <p class="mt-3 text-sm leading-6 text-slate-600">{{ __('notifications.inbox.description') }}</p>
                        </div>

                        <div class="flex flex-wrap items-center gap-3">
                            <span class="rounded-full bg-slate-100 px-4 py-2 text-sm font-semibold text-slate-700">
                                {{ trans_choice('notifications.inbox.unread_count', $this->unreadCount, ['count' => $this->unreadCount]) }}
                            </span>
                            <a
                                href="{{ route('dashboard.account-settings', ['tab' => 'notifications']) }}"
                                wire:navigate
                                class="inline-flex items-center rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:border-emerald-300 hover:text-emerald-700"
                            >
                                {{ __('notifications.inbox.manage_settings') }}
                            </a>
                            @if ($this->unreadCount > 0)
                                <button
                                    type="button"
                                    wire:click="markAllAsRead"
                                    class="inline-flex items-center rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-emerald-700"
                                >
                                    {{ __('notifications.inbox.mark_all_read') }}
                                </button>
                            @endif
                        </div>
                    </div>
                </div>

                <div class="border-b border-slate-100 px-6 py-5 md:px-8">
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="text-sm font-semibold text-slate-700" for="notification-family-filter">{{ __('notifications.inbox.family_filter') }}</label>
                            <select
                                id="notification-family-filter"
                                wire:model.live="family"
                                class="mt-2 h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-900 focus:border-emerald-500 focus:outline-none"
                            >
                                <option value="all">{{ __('notifications.inbox.all_families') }}</option>
                                @foreach ($this->familyOptions as $familyValue => $familyLabel)
                                    <option value="{{ $familyValue }}">{{ $familyLabel }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label class="text-sm font-semibold text-slate-700" for="notification-status-filter">{{ __('notifications.inbox.status_filter') }}</label>
                            <select
                                id="notification-status-filter"
                                wire:model.live="status"
                                class="mt-2 h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-900 focus:border-emerald-500 focus:outline-none"
                            >
                                <option value="unread">{{ __('notifications.inbox.status.unread') }}</option>
                                <option value="read">{{ __('notifications.inbox.status.read') }}</option>
                                <option value="all">{{ __('notifications.inbox.status.all') }}</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="px-6 py-8 md:px-8">
                    @if ($this->notifications->isEmpty())
                        <div class="rounded-3xl border border-dashed border-slate-200 bg-slate-50 px-6 py-12 text-center">
                            <h2 class="text-lg font-semibold text-slate-900">{{ __('notifications.inbox.empty.heading') }}</h2>
                            <p class="mt-3 text-sm leading-6 text-slate-600">{{ __('notifications.inbox.empty.description') }}</p>
                        </div>
                    @else
                        <div class="space-y-4">
                            @foreach ($this->notifications as $message)
                                <article @class([
                                    'rounded-3xl border p-5 transition',
                                    'border-emerald-200 bg-emerald-50/60' => $message->read_at === null,
                                    'border-slate-200 bg-white' => $message->read_at !== null,
                                ])>
                                    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                        <div class="space-y-3">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <span class="rounded-full bg-white px-3 py-1 text-xs font-semibold uppercase tracking-[0.16em] text-slate-600">
                                                    {{ __("notifications.options.priority.{$message->priority->value}") }}
                                                </span>
                                                <span class="rounded-full bg-white px-3 py-1 text-xs font-semibold text-slate-600">
                                                    {{ $this->familyOptions[$message->family->value] ?? $message->family->value }}
                                                </span>
                                                @if ($message->read_at === null)
                                                    <span class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700">
                                                        {{ __('notifications.inbox.status.unread') }}
                                                    </span>
                                                @endif
                                            </div>

                                            <div>
                                                <h2 class="text-lg font-semibold text-slate-900">{{ $message->title }}</h2>
                                                <p class="mt-2 text-sm leading-6 text-slate-600">{{ $message->body }}</p>
                                            </div>

                                            <div class="flex flex-wrap items-center gap-3 text-xs text-slate-500">
                                                <span>{{ \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($message->occurred_at, 'j M Y, g:i A') }}</span>
                                                @if (! empty($message->channels_attempted))
                                                    <span>•</span>
                                                    <span>{{ __('notifications.inbox.channels_attempted') }}: {{ collect($message->channels_attempted)->map(fn (string $channel): string => $channelOptions[$channel] ?? $channel)->implode(', ') }}</span>
                                                @endif
                                            </div>
                                        </div>

                                        <div class="flex flex-wrap gap-3 lg:justify-end">
                                            @if ($message->read_at === null)
                                                <button
                                                    type="button"
                                                    wire:click="markAsRead('{{ $message->id }}')"
                                                    class="inline-flex items-center rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:border-emerald-300 hover:text-emerald-700"
                                                >
                                                    {{ __('notifications.inbox.mark_read') }}
                                                </button>
                                            @endif

                                            @if ($message->action_url)
                                                <a
                                                    href="{{ $message->action_url }}"
                                                    wire:navigate
                                                    class="inline-flex items-center rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800"
                                                >
                                                    {{ __('notifications.inbox.open_link') }}
                                                </a>
                                            @endif
                                        </div>
                                    </div>
                                </article>
                            @endforeach
                        </div>

                        <div class="mt-8">
                            {{ $this->notifications->links(data: ['scrollTo' => '#notification-family-filter']) }}
                        </div>
                    @endif
                </div>
            </section>
        </div>
    </div>
</div>
