@php
    $toasts = [];

    $pushToast = static function (string $message, string $type = 'success', ?string $title = null) use (&$toasts): void {
        if (trim($message) === '') {
            return;
        }

        $toasts[] = [
            'id' => (string) str()->uuid(),
            'message' => $message,
            'type' => $type,
            'title' => $title,
            'timeout' => $type === 'error' ? 5200 : 4200,
        ];
    };

    $status = session('status');
    if (is_string($status) && $status !== '') {
        $message = $status === 'verification-link-sent'
            ? __('A new verification link has been sent to the email address you provided during registration.')
            : $status;

        $pushToast($message, 'success');
    }

    foreach ([
        'account_settings_status' => 'success',
        'notification_preferences_status' => 'success',
        'institution_dashboard_message' => 'success',
        'institution_dashboard_error' => 'error',
    ] as $key => $type) {
        $value = session($key);

        if (is_string($value) && $value !== '') {
            $pushToast($value, $type);
        }
    }

    $singleToast = session('toast');
    if (is_array($singleToast)) {
        $pushToast(
            (string) ($singleToast['message'] ?? ''),
            (string) ($singleToast['type'] ?? 'success'),
            isset($singleToast['title']) ? (string) $singleToast['title'] : null,
        );
    }

    $multipleToasts = session('toasts');
    if (is_array($multipleToasts)) {
        foreach ($multipleToasts as $toast) {
            if (! is_array($toast)) {
                continue;
            }

            $pushToast(
                (string) ($toast['message'] ?? ''),
                (string) ($toast['type'] ?? 'success'),
                isset($toast['title']) ? (string) $toast['title'] : null,
            );
        }
    }
@endphp

<style>
    @keyframes toast-shrink {
        from {
            width: 100%;
        }

        to {
            width: 0%;
        }
    }
</style>

<div
    id="app-toast-stack"
    data-toast-root
    x-data="{
        toasts: @js($toasts),
        push(detail = {}) {
            if (!detail.message) {
                return;
            }

            const toast = {
                id: detail.id ?? `${Date.now()}-${Math.random().toString(16).slice(2)}`,
                type: detail.type ?? 'success',
                title: detail.title ?? null,
                message: detail.message,
                timeout: Number(detail.timeout ?? (detail.type === 'error' ? 5200 : 4200)),
            };

            this.toasts = [...this.toasts, toast];
            this.scheduleRemoval(toast.id, toast.timeout);
        },
        remove(id) {
            this.toasts = this.toasts.filter((toast) => toast.id !== id);
        },
        scheduleRemoval(id, timeout) {
            if (!timeout || timeout < 1) {
                return;
            }

            window.setTimeout(() => this.remove(id), timeout);
        },
        init() {
            this.toasts.forEach((toast) => this.scheduleRemoval(toast.id, toast.timeout));
        },
    }"
    x-on:app-toast.window="push($event.detail ?? {})"
    class="pointer-events-none fixed inset-x-0 top-4 z-[100] flex justify-center px-4 sm:justify-end sm:px-6"
>
    <div class="flex w-full max-w-sm flex-col gap-3 sm:max-w-md">
        <template x-for="toast in toasts" :key="toast.id">
            <div
                x-cloak
                x-transition:enter="transform ease-out duration-300"
                x-transition:enter-start="translate-y-3 opacity-0 sm:translate-x-8 sm:translate-y-0"
                x-transition:enter-end="translate-y-0 opacity-100 sm:translate-x-0"
                x-transition:leave="transform ease-in duration-200"
                x-transition:leave-start="translate-y-0 opacity-100"
                x-transition:leave-end="-translate-y-2 opacity-0"
                class="pointer-events-auto overflow-hidden rounded-2xl border shadow-2xl backdrop-blur-xl"
                :class="toast.type === 'error'
                    ? 'border-rose-200 bg-white/95 shadow-rose-500/10'
                    : toast.type === 'warning'
                        ? 'border-amber-200 bg-white/95 shadow-amber-500/10'
                        : 'border-emerald-200 bg-white/95 shadow-emerald-500/10'"
            >
                <div class="flex items-start gap-3 p-4">
                    <div class="mt-0.5 flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl"
                        :class="toast.type === 'error'
                            ? 'bg-rose-100 text-rose-600'
                            : toast.type === 'warning'
                                ? 'bg-amber-100 text-amber-600'
                                : 'bg-emerald-100 text-emerald-600'">
                        <svg x-show="toast.type !== 'error' && toast.type !== 'warning'" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                        </svg>
                        <svg x-show="toast.type === 'warning'" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86l-7.55 13.09A1 1 0 003.61 18h16.78a1 1 0 00.87-1.5L13.71 3.86a1 1 0 00-1.74 0z" />
                        </svg>
                        <svg x-show="toast.type === 'error'" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M4.93 19h14.14c1.54 0 2.5-1.67 1.73-3L13.73 4c-.77-1.33-2.69-1.33-3.46 0L3.2 16c-.77 1.33.19 3 1.73 3z" />
                        </svg>
                    </div>

                    <div class="min-w-0 flex-1">
                        <p x-show="toast.title" x-text="toast.title" class="text-sm font-bold text-slate-900"></p>
                        <p x-text="toast.message" class="text-sm leading-6 text-slate-700"></p>
                    </div>

                    <button
                        type="button"
                        x-on:click="remove(toast.id)"
                        class="rounded-xl p-2 text-slate-400 transition hover:bg-slate-100 hover:text-slate-600"
                        aria-label="Dismiss notification"
                    >
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="h-1 w-full bg-slate-100">
                    <div class="h-full"
                        :class="toast.type === 'error'
                            ? 'bg-rose-500'
                            : toast.type === 'warning'
                                ? 'bg-amber-500'
                                : 'bg-emerald-500'"
                        :style="`animation: toast-shrink ${toast.timeout}ms linear forwards;`"></div>
                </div>
            </div>
        </template>
    </div>
</div>