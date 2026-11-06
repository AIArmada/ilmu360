@section('title', __('notifications.inbox.page_title') . ' - ' . config('app.name'))

@php
    $channelOptions = collect(\App\Enums\NotificationChannel::supported())
        ->mapWithKeys(fn (\App\Enums\NotificationChannel $channel): array => [$channel->value => $channel->label()])
        ->all();

    $statusOptions = [
        'unread' => __('notifications.inbox.status.unread'),
        'read' => __('notifications.inbox.status.read'),
        'all' => __('notifications.inbox.status.all'),
    ];
@endphp

<div class="notification-inbox min-h-screen bg-[#f7f3ea] pb-28 pt-8 [--color-accent:var(--color-emerald-600)] [--color-accent-foreground:var(--color-white)]">
    <div class="container mx-auto px-4 sm:px-6 lg:px-12">
        <div class="mx-auto max-w-5xl space-y-5">
            <section class="overflow-hidden rounded-3xl border border-[#eadfca] bg-[#fffdf8] shadow-[0_24px_60px_-40px_rgba(23,60,52,0.35)]">
                <div class="relative">
                    <div class="pointer-events-none absolute inset-0 opacity-[0.14]"
                        style="background-image: url('{{ asset('images/pattern-bg.jpg') }}'); background-position: center; background-size: 420px;"></div>

                    <div class="relative flex flex-col gap-6 px-5 py-7 sm:px-8 lg:flex-row lg:items-end lg:justify-between">
                        <div class="min-w-0 max-w-2xl">
                            <p class="text-[11px] font-bold uppercase tracking-[0.22em] text-emerald-700">{{ __('notifications.inbox.eyebrow') }}</p>
                            <flux:heading level="1" size="xl" class="mt-2 text-balance font-heading text-3xl text-[#0b2a42]">
                                {{ __('notifications.inbox.heading') }}
                            </flux:heading>
                            <flux:text class="mt-2 max-w-xl text-sm leading-6 text-[#55706c]">
                                {{ __('notifications.inbox.description') }}
                            </flux:text>
                        </div>

                        <div class="flex flex-col items-start gap-2 sm:items-end">
                            <span class="inline-flex items-center gap-2 rounded-full border border-emerald-200 bg-emerald-50 px-3.5 py-1.5 text-sm font-semibold text-emerald-900">
                                <x-heroicon-o-inbox class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ trans_choice('notifications.inbox.unread_count', $this->unreadCount, ['count' => $this->unreadCount]) }}
                            </span>

                            <flux:button
                                :href="route('account-settings', ['tab' => 'notifications'])"
                                variant="outline"
                                size="sm"
                                icon="cog-6-tooth"
                                wire:navigate
                                class="border-slate-200! bg-white! text-[#173c34]! shadow-xs! hover:border-slate-300! hover:bg-slate-50! hover:text-[#0b2a42]!"
                            >
                                {{ __('notifications.inbox.manage_settings') }}
                            </flux:button>

                            @if ($this->unreadCount > 0)
                                <flux:button
                                    type="button"
                                    variant="primary"
                                    size="sm"
                                    icon="check"
                                    wire:click="markAllAsRead"
                                >
                                    {{ __('notifications.inbox.mark_all_read') }}
                                </flux:button>
                            @endif
                        </div>
                    </div>
                </div>

                <div class="flex flex-col gap-3 border-t border-[#f0e7d6] bg-[#fbf7ee]/70 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-8">
                    <div class="flex items-center gap-2.5" role="group" aria-label="{{ __('notifications.inbox.status_filter') }}">
                        <span class="hidden text-[11px] font-bold uppercase tracking-[0.18em] text-[#8a9a93] sm:inline">
                            {{ __('notifications.inbox.status_filter') }}
                        </span>

                        <div class="inline-flex rounded-full border border-[#eadfca] bg-white/80 p-1">
                            @foreach ($statusOptions as $statusValue => $statusLabel)
                                <button
                                    type="button"
                                    wire:click="$set('status', '{{ $statusValue }}')"
                                    aria-pressed="{{ $this->status === $statusValue ? 'true' : 'false' }}"
                                    @class([
                                        'inline-flex min-h-8 items-center gap-1.5 rounded-full px-3 text-xs font-semibold transition',
                                        'bg-[#006044] text-white shadow-sm' => $this->status === $statusValue,
                                        'text-[#55706c] hover:bg-[#f3eee2] hover:text-[#005b3d]' => $this->status !== $statusValue,
                                    ])
                                >
                                    {{ $statusLabel }}

                                    @if ($statusValue === 'unread' && $this->unreadCount > 0)
                                        <span @class([
                                            'rounded-full px-1.5 py-0.5 text-[10px] font-bold leading-none',
                                            'bg-white/20 text-white' => $this->status === 'unread',
                                            'bg-emerald-100 text-emerald-800' => $this->status !== 'unread',
                                        ])>{{ $this->unreadCount }}</span>
                                    @endif
                                </button>
                            @endforeach
                        </div>
                    </div>

                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                        <label for="notification-family-filter" class="text-xs font-semibold text-[#55706c]">
                            {{ __('notifications.inbox.family_filter') }}
                        </label>

                        <flux:select
                            id="notification-family-filter"
                            wire:model.live="family"
                            size="sm"
                            class="w-full border-slate-200! bg-white! text-[#173c34]! [&_option]:bg-white! [&_option]:text-[#173c34]! sm:w-56"
                        >
                            <flux:select.option value="all">{{ __('notifications.inbox.all_families') }}</flux:select.option>
                            @foreach ($this->familyOptions as $familyValue => $familyLabel)
                                <flux:select.option value="{{ $familyValue }}">{{ $familyLabel }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>
                </div>
            </section>

            @if ($this->notifications->isEmpty())
                <section class="rounded-3xl border border-dashed border-[#d9cdb6] bg-[#fffdf8]/80 px-6 py-14 text-center">
                    <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-[#e7f2ec] text-[#006044]">
                        <x-heroicon-o-bell-slash class="h-7 w-7" aria-hidden="true" />
                    </span>

                    <flux:heading level="2" size="lg" class="mt-4 text-lg font-semibold font-heading text-[#0b2a42]">
                        {{ __('notifications.inbox.empty.heading') }}
                    </flux:heading>
                    <flux:text class="mx-auto mt-2 max-w-md text-sm leading-6 text-[#55706c]">
                        {{ __('notifications.inbox.empty.description') }}
                    </flux:text>

                    <div class="mt-6 flex flex-wrap items-center justify-center gap-2">
                        <flux:button :href="route('events.index')" variant="primary" icon="calendar-days" wire:navigate>
                            {{ __('notifications.inbox.empty.cta') }}
                        </flux:button>

                        @if ($this->status !== 'all' || $this->family !== 'all')
                            <flux:button :href="route('notifications', ['status' => 'all'])" variant="subtle" wire:navigate class="bg-transparent! text-[#173c34]! hover:bg-[#e7f2ec]!">
                                {{ __('notifications.inbox.empty.view_all') }}
                            </flux:button>
                        @endif
                    </div>
                </section>
            @else
                <div class="space-y-3">
                    @foreach ($this->notifications as $message)
                        @php
                            $appFamily = \App\Support\Communications\EnumMapper::toAppFamily($message->family);
                            $notificationDate = $this->notificationDate($message);

                            $familyTone = match ($appFamily) {
                                \App\Enums\NotificationFamily::FollowedContent => 'bg-emerald-50 text-emerald-700',
                                \App\Enums\NotificationFamily::SavedSearchMatches => 'bg-sky-50 text-sky-700',
                                \App\Enums\NotificationFamily::EventUpdates => 'bg-amber-50 text-amber-700',
                                \App\Enums\NotificationFamily::EventReminders => 'bg-orange-50 text-orange-700',
                                \App\Enums\NotificationFamily::RegistrationCheckin => 'bg-teal-50 text-teal-700',
                                \App\Enums\NotificationFamily::SubmissionWorkflow => 'bg-violet-50 text-violet-700',
                                \App\Enums\NotificationFamily::Sharing => 'bg-rose-50 text-rose-700',
                                default => 'bg-slate-100 text-slate-600',
                            };

                            $priorityTone = match ($this->priorityTone($message)) {
                                'red' => 'bg-red-50 text-red-700',
                                'amber' => 'bg-amber-50 text-amber-800',
                                'sky' => 'bg-sky-50 text-sky-800',
                                default => 'bg-slate-100 text-slate-600',
                            };
                        @endphp

                        <article
                            wire:key="notification-{{ $message->id }}"
                            @class([
                                'relative overflow-hidden rounded-2xl border shadow-sm transition hover:shadow-md',
                                'border-emerald-200/80 bg-[#f4fbf6]' => $message->read_at === null,
                                'border-[#eadfca] bg-[#fffdf8]' => $message->read_at !== null,
                            ])
                        >
                            @if ($message->read_at === null)
                                <span class="absolute inset-y-0 start-0 w-1 bg-emerald-500" aria-hidden="true"></span>
                            @endif

                            <div class="flex flex-col gap-4 p-4 sm:flex-row sm:items-start sm:gap-5 sm:p-5">
                                <span @class([
                                    'flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl',
                                    $familyTone,
                                ])>
                                    <x-dynamic-component :component="$this->familyIcon($message)" class="h-5 w-5" aria-hidden="true" />
                                </span>

                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        <span class="inline-flex items-center rounded-md bg-[#f3eee2] px-2 py-1 text-xs font-semibold text-[#4a5d57]">
                                            {{ $this->familyLabel($message) }}
                                        </span>
                                        <span class="inline-flex items-center rounded-md px-2 py-1 text-xs font-semibold {{ $priorityTone }}">
                                            {{ $this->priorityLabel($message) }}
                                        </span>

                                        @if ($message->read_at === null)
                                            <span class="inline-flex items-center gap-1.5 rounded-md bg-emerald-100 px-2 py-1 text-xs font-semibold text-emerald-800">
                                                <span class="h-1.5 w-1.5 rounded-full bg-emerald-600" aria-hidden="true"></span>
                                                {{ __('notifications.inbox.status.unread') }}
                                            </span>
                                        @endif
                                    </div>

                                    <h2 class="mt-2 break-words text-base font-semibold leading-6 text-[#0b2a42]">
                                        {{ $message->title }}
                                    </h2>

                                    @if (filled($message->body))
                                        <p class="mt-1.5 break-words text-sm leading-6 text-[#55706c]">
                                            {{ $message->body }}
                                        </p>
                                    @endif

                                    <div class="mt-3 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs font-medium text-[#5f736c]">
                                        <time datetime="{{ $notificationDate->toIso8601String() }}">
                                            {{ \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($notificationDate, 'j M Y, g:i A') }}
                                        </time>

                                        @if (! empty($message->data['channels_attempted'] ?? []))
                                            <span aria-hidden="true">·</span>
                                            <span>
                                                {{ __('notifications.inbox.channels_attempted') }}:
                                                {{ collect($message->data['channels_attempted'] ?? [])->map(fn (string $channel): string => $channelOptions[$channel] ?? $channel)->implode(', ') }}
                                            </span>
                                        @endif
                                    </div>
                                </div>

                                <div class="flex shrink-0 flex-wrap items-center gap-2 sm:flex-col sm:items-stretch">
                                    @if ($message->read_at === null)
                                        <flux:button
                                            type="button"
                                            size="sm"
                                            variant="subtle"
                                            icon="check"
                                            wire:click="markAsRead('{{ $message->id }}')"
                                            class="justify-center bg-transparent! text-[#173c34]! hover:bg-[#e7f2ec]!"
                                        >
                                            {{ __('notifications.inbox.mark_read') }}
                                        </flux:button>
                                    @endif

                                    @if ($message->data['action_url'] ?? null)
                                        <flux:button
                                            :href="$message->data['action_url']"
                                            size="sm"
                                            variant="primary"
                                            icon-trailing="arrow-up-right"
                                            wire:navigate
                                            class="justify-center"
                                        >
                                            {{ __('notifications.inbox.open_link') }}
                                        </flux:button>
                                    @endif
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>

                @if ($this->notifications->hasPages())
                    <nav
                        aria-label="{{ __('notifications.inbox.pagination.label') }}"
                        class="flex flex-col items-center justify-between gap-3 rounded-2xl border border-[#eadfca] bg-[#fffdf8] px-4 py-3 shadow-sm sm:flex-row"
                    >
                        <p class="text-xs font-medium text-[#55706c]">
                            {{ __('notifications.inbox.pagination.summary', [
                                'from' => $this->notifications->firstItem(),
                                'to' => $this->notifications->lastItem(),
                                'total' => $this->notifications->total(),
                            ]) }}
                        </p>

                        <div class="flex items-center gap-2">
                            <flux:button
                                type="button"
                                size="sm"
                                variant="outline"
                                icon="chevron-left"
                                icon:class="rtl:rotate-180"
                                :disabled="$this->notifications->onFirstPage()"
                                wire:click="previousPage('notifications_page')"
                                class="border-slate-200! bg-white! text-[#173c34]! hover:border-slate-300! hover:bg-slate-50!"
                            >
                                {{ __('notifications.inbox.pagination.previous') }}
                            </flux:button>

                            <span class="px-1 text-xs font-semibold text-[#173c34]">
                                {{ $this->notifications->currentPage() }} / {{ $this->notifications->lastPage() }}
                            </span>

                            <flux:button
                                type="button"
                                size="sm"
                                variant="outline"
                                icon-trailing="chevron-right"
                                icon-trailing:class="rtl:rotate-180"
                                :disabled="! $this->notifications->hasMorePages()"
                                wire:click="nextPage('notifications_page')"
                                class="border-slate-200! bg-white! text-[#173c34]! hover:border-slate-300! hover:bg-slate-50!"
                            >
                                {{ __('notifications.inbox.pagination.next') }}
                            </flux:button>
                        </div>
                    </nav>
                @endif
            @endif
        </div>
    </div>
</div>
