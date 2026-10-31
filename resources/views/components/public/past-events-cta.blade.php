@props([
    'count' => 0,
    'url',
    'description',
    'buttonLabel' => null,
    'testId' => 'past-events-cta',
    'signalComponent' => 'public_past_events',
    'signalControl' => 'view_all_past_events',
])

@if($count > 0)
    <div
        {{ $attributes->class('flex flex-col gap-4 rounded-2xl border border-slate-200 bg-slate-50/80 p-5 sm:flex-row sm:items-center sm:justify-between sm:gap-6 sm:p-6') }}
        data-testid="{{ $testId }}-panel"
    >
        <div class="min-w-0">
            <h3 class="font-heading text-xl font-bold text-emerald-950">{{ __('Majlis Lepas') }}</h3>
            <p class="mt-1 text-sm leading-6 text-slate-500">{{ $description }}</p>
        </div>

        <a
            href="{{ $url }}"
            wire:navigate
            data-signal-event="navigation.internal_link_clicked"
            data-signal-category="navigation"
            data-signal-component="{{ $signalComponent }}"
            data-signal-control="{{ $signalControl }}"
            data-testid="{{ $testId }}"
            class="inline-flex shrink-0 items-center justify-center gap-2 rounded-xl bg-emerald-800 px-5 py-3 text-sm font-bold text-white shadow-lg shadow-emerald-900/15 transition hover:-translate-y-0.5 hover:bg-emerald-700"
        >
            <span>{{ $buttonLabel ?? __('Lihat Semua Majlis Lepas') }}</span>
            <flux:icon.arrow-right class="size-4" />
        </a>
    </div>
@endif
