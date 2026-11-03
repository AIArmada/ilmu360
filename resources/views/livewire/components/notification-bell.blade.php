@php
    $triggerClass = $variant === 'glass'
        ? 'living-majlis-header-button living-majlis-header-button--glass text-white'
        : 'border border-[#dbe1dc] bg-white/60 text-[#005b3d] hover:border-[#006044] hover:bg-white hover:shadow-[0_6px_16px_-8px_rgba(0,96,68,0.45)]';

    $hasUnread = $unreadCount > 0;

    $triggerLabel = $hasUnread
        ? __('notifications.bell.unread_aria', ['count' => $unreadCount])
        : __('notifications.bell.label');

    $tabLabels = [
        'all' => __('notifications.bell.tabs.all'),
        'unread' => __('notifications.bell.tabs.unread'),
    ];
@endphp

<div
    class="relative"
    x-data="{ open: false, menuOpen: false }"
    x-on:notification-bell-close.window="open = false; menuOpen = false"
    x-on:keydown.escape.window="if (open) { open = false; menuOpen = false; $refs.trigger?.focus() }"
    x-on:click.outside="open = false; menuOpen = false"
>
    <button
        type="button"
        x-ref="trigger"
        x-on:click="open = ! open; menuOpen = false; if (open) { $dispatch('notification-bell-opened'); $wire.loadNotifications() }"
        x-bind:aria-expanded="open.toString()"
        aria-haspopup="dialog"
        aria-controls="notification-panel"
        aria-label="{{ $triggerLabel }}"
        title="{{ $triggerLabel }}"
        data-testid="notification-bell"
        class="relative flex h-11 w-11 shrink-0 items-center justify-center rounded-full transition-all focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#e3b537] focus-visible:ring-offset-2 {{ $triggerClass }}"
    >
        @if ($hasUnread)
            <x-heroicon-s-bell data-testid="notification-bell-icon-filled" class="h-5 w-5" aria-hidden="true" />
        @else
            <x-heroicon-o-bell
                x-show="! open"
                data-testid="notification-bell-icon-outline"
                class="h-5 w-5"
                aria-hidden="true"
            />
            <x-heroicon-s-bell
                x-show="open"
                x-cloak
                data-testid="notification-bell-icon-filled"
                class="h-5 w-5"
                aria-hidden="true"
            />
        @endif
    </button>

    @if ($hasUnread)
        <span
            data-testid="notification-unread-count"
            aria-hidden="true"
            class="pointer-events-none absolute -right-1 -top-1 z-10 inline-flex min-w-[1.15rem] items-center justify-center rounded-full border border-[#fffdf8] bg-[#e3b537] px-1 text-[10px] font-bold leading-[1.15rem] text-[#063b27] shadow-sm"
        >
            {{ $unreadCount > 99 ? '99+' : $unreadCount }}
        </span>
    @endif

    <div
        id="notification-panel"
        x-show="open"
        x-cloak
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="origin-top-right scale-95 opacity-0"
        x-transition:enter-end="origin-top-right scale-100 opacity-100"
        x-transition:leave="transition ease-in duration-100"
        x-transition:leave-start="origin-top-right scale-100 opacity-100"
        x-transition:leave-end="origin-top-right scale-95 opacity-0"
        role="dialog"
        aria-modal="false"
        aria-label="{{ __('notifications.bell.heading') }}"
        data-testid="notification-panel"
        class="absolute right-0 top-full z-[60] mt-3 flex max-h-[calc(100vh_-_7rem)] w-[22rem] max-w-[calc(100vw_-_2rem)] origin-top-right flex-col overflow-hidden rounded-[1.65rem] border border-[#eadfca] bg-[#fffdf8] text-[#173c34] shadow-[0_30px_80px_-30px_rgba(23,60,52,0.55)] max-sm:-right-14 sm:max-h-[32rem]"
    >
        <div class="flex shrink-0 items-center justify-between gap-3 px-4 pb-2 pt-4">
            <h2 class="font-heading text-xl font-semibold tracking-tight">{{ __('notifications.bell.heading') }}</h2>

            <div class="relative" x-on:click.outside="menuOpen = false">
                <button
                    type="button"
                    x-on:click="menuOpen = ! menuOpen"
                    x-bind:aria-expanded="menuOpen.toString()"
                    aria-haspopup="menu"
                    aria-controls="notification-panel-menu"
                    aria-label="{{ __('notifications.bell.menu_label') }}"
                    class="flex h-9 w-9 items-center justify-center rounded-full text-[#55706c] transition-colors hover:bg-[#f3eee2] hover:text-[#005b3d] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#e3b537]"
                >
                    <x-heroicon-o-ellipsis-horizontal class="h-5 w-5" aria-hidden="true" />
                </button>

                <div
                    id="notification-panel-menu"
                    x-show="menuOpen"
                    x-cloak
                    x-transition:enter="transition ease-out duration-100"
                    x-transition:enter-start="origin-top-right scale-95 opacity-0"
                    x-transition:enter-end="origin-top-right scale-100 opacity-100"
                    x-transition:leave="transition ease-in duration-75"
                    x-transition:leave-start="origin-top-right scale-100 opacity-100"
                    x-transition:leave-end="origin-top-right scale-95 opacity-0"
                    role="menu"
                    class="absolute right-0 top-full z-10 mt-2 w-72 origin-top-right overflow-hidden rounded-2xl border border-[#eadfca] bg-[#fffdf8] p-1.5 shadow-[0_22px_50px_-22px_rgba(23,60,52,0.5)]"
                >
                    <button
                        type="button"
                        role="menuitem"
                        wire:click="markAllAsRead"
                        x-on:click="menuOpen = false"
                        @disabled($unreadCount === 0)
                        class="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left text-sm font-medium text-[#173c34] transition-colors hover:bg-[#f3eee2] disabled:cursor-not-allowed disabled:opacity-40"
                    >
                        <x-heroicon-o-check class="h-4 w-4 shrink-0 text-[#006044]" aria-hidden="true" />
                        {{ __('notifications.bell.mark_all_read') }}
                    </button>

                    <a
                        href="{{ route('account-settings', ['tab' => 'notifications']) }}"
                        wire:navigate
                        role="menuitem"
                        x-on:click="menuOpen = false; open = false"
                        class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium text-[#173c34] transition-colors hover:bg-[#f3eee2]"
                    >
                        <x-heroicon-o-cog-6-tooth class="h-4 w-4 shrink-0 text-[#55706c]" aria-hidden="true" />
                        {{ __('notifications.bell.settings') }}
                    </a>

                    <a
                        href="{{ route('notifications') }}"
                        wire:navigate
                        role="menuitem"
                        x-on:click="menuOpen = false; open = false"
                        class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium text-[#173c34] transition-colors hover:bg-[#f3eee2]"
                    >
                        <x-heroicon-o-inbox class="h-4 w-4 shrink-0 text-[#55706c]" aria-hidden="true" />
                        {{ __('notifications.bell.open') }}
                    </a>
                </div>
            </div>
        </div>

        <div class="flex shrink-0 items-center gap-1 px-4 pb-3" role="tablist" aria-label="{{ __('notifications.bell.tabs.label') }}">
            @foreach ($tabLabels as $tabValue => $tabLabel)
                <button
                    type="button"
                    id="notification-tab-{{ $tabValue }}"
                    role="tab"
                    aria-selected="{{ $filter === $tabValue ? 'true' : 'false' }}"
                    aria-controls="notification-list"
                    wire:click="setFilter('{{ $tabValue }}')"
                    wire:loading.attr="disabled"
                    wire:target="setFilter"
                    class="rounded-full px-3.5 py-1.5 text-xs font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#e3b537] {{ $filter === $tabValue ? 'bg-[#006044] text-white shadow-sm' : 'text-[#55706c] hover:bg-[#f3eee2] hover:text-[#005b3d]' }}"
                >
                    {{ $tabLabel }}
                </button>
            @endforeach
        </div>

        <div
            id="notification-list"
            role="tabpanel"
            aria-labelledby="notification-tab-{{ $filter }}"
            data-testid="notification-list"
            class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-2 pb-2"
            wire:loading.class="opacity-60"
            wire:loading.delay.class="opacity-60"
            wire:target="loadNotifications, setFilter, markAllAsRead, openNotification"
        >
            @if (! $loaded)
                <div class="space-y-2 p-2" aria-live="polite" aria-busy="true">
                    @foreach (range(1, 3) as $placeholder)
                        <div class="flex animate-pulse gap-3 rounded-2xl p-2.5" wire:key="notification-skeleton-{{ $placeholder }}">
                            <div class="h-10 w-10 shrink-0 rounded-full bg-[#f0ece2]"></div>
                            <div class="flex-1 space-y-2 py-1">
                                <div class="h-3 w-4/5 rounded-full bg-[#f0ece2]"></div>
                                <div class="h-3 w-3/5 rounded-full bg-[#f0ece2]"></div>
                            </div>
                        </div>
                    @endforeach
                    <span class="sr-only">{{ __('notifications.bell.loading') }}</span>
                </div>
            @elseif ($loadFailed)
                <div class="flex flex-col items-center gap-3 px-6 py-10 text-center" role="alert">
                    <span class="flex h-12 w-12 items-center justify-center rounded-full bg-[#f3eee2] text-[#55706c]">
                        <x-heroicon-o-exclamation-triangle class="h-6 w-6" aria-hidden="true" />
                    </span>
                    <p class="text-sm font-medium text-[#173c34]">{{ __('notifications.bell.load_failed') }}</p>
                    <button
                        type="button"
                        wire:click="loadNotifications"
                        class="rounded-full bg-[#006044] px-4 py-2 text-xs font-semibold text-white transition-colors hover:bg-[#00523a] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#e3b537]"
                    >
                        {{ __('notifications.bell.retry') }}
                    </button>
                </div>
            @elseif ($notifications === [])
                <div class="flex flex-col items-center gap-3 px-6 py-10 text-center">
                    <span class="flex h-12 w-12 items-center justify-center rounded-full bg-[#edf4ef] text-[#006044]">
                        <x-heroicon-o-bell-slash class="h-6 w-6" aria-hidden="true" />
                    </span>
                    <p class="text-sm font-semibold text-[#173c34]">
                        {{ $filter === 'unread' ? __('notifications.bell.empty_unread.heading') : __('notifications.bell.empty.heading') }}
                    </p>
                    <p class="max-w-[16rem] text-xs leading-5 text-[#55706c]">
                        {{ $filter === 'unread' ? __('notifications.bell.empty_unread.description') : __('notifications.bell.empty.description') }}
                    </p>
                </div>
            @else
                @php $currentGroup = null; @endphp

                <div class="space-y-0.5">
                    @foreach ($notifications as $notification)
                        @if ($notification['group'] !== $currentGroup)
                            @php $currentGroup = $notification['group']; @endphp
                            <p class="px-2 pb-1 pt-3 text-[11px] font-semibold uppercase tracking-[0.16em] text-[#8a9a93]">
                                {{ $notification['group'] === 'today' ? __('notifications.bell.today') : __('notifications.bell.earlier') }}
                            </p>
                        @endif

                        <button
                            type="button"
                            wire:key="notification-{{ $notification['id'] }}"
                            wire:click="openNotification('{{ $notification['id'] }}')"
                            data-testid="notification-item"
                            class="flex w-full items-start gap-3 rounded-2xl p-2.5 text-left transition-colors {{ $notification['read'] ? 'hover:bg-[#f6f1e8]' : 'bg-[#eff7f2] hover:bg-[#e7f2ec]' }}"
                        >
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-[#e7f2ec] text-[#006044]" aria-hidden="true">
                                <x-dynamic-component :component="$notification['icon']" class="h-5 w-5" />
                            </span>

                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-semibold leading-5 text-[#173c34]">{{ $notification['title'] }}</span>

                                @if ($notification['body'])
                                    <span class="mt-0.5 block line-clamp-2 text-xs leading-5 text-[#55706c]">{{ $notification['body'] }}</span>
                                @endif

                                <span class="mt-1 flex items-center gap-2 text-[11px] font-medium text-[#8a9a93]">
                                    <time datetime="{{ $notification['created_at_iso'] }}" title="{{ $notification['absolute_time'] }}">{{ $notification['relative_time'] }}</time>
                                    <span class="text-[#c3cec8]" aria-hidden="true">·</span>
                                    <span class="truncate">{{ $notification['family_label'] }}</span>
                                </span>
                            </span>

                            @if (! $notification['read'])
                                <span class="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full bg-[#0e8a63]" aria-hidden="true"></span>
                                <span class="sr-only">{{ __('notifications.bell.unread_dot') }}</span>
                            @endif
                        </button>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="shrink-0 border-t border-[#eadfca] bg-[#fffdf8] p-2">
            <a
                href="{{ route('notifications', ['status' => 'all']) }}"
                wire:navigate
                x-on:click="open = false"
                class="flex min-h-10 items-center justify-center gap-1.5 rounded-xl px-3 text-xs font-semibold text-[#006044] transition-colors hover:bg-[#f3eee2] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#e3b537]"
            >
                {{ __('notifications.bell.see_all') }}
                <x-heroicon-o-arrow-right class="h-3.5 w-3.5" aria-hidden="true" />
            </a>
        </div>
    </div>
</div>
