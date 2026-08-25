@props([
    'subjectType',
    'subjectId',
    'sharePanelId',
    'shareData' => [],
    'shareLinks' => [],
])

@php
    $feedbackHeadingKey = match ($subjectType) {
        'institusi' => 'Bantu Semak Institusi Ini',
        'penceramah' => 'Bantu Semak Penceramah Ini',
        default => 'Bantu Semak Maklumat Ini',
    };
@endphp

<section id="{{ $sharePanelId }}" class="scroll-reveal reveal-right revealed">
    <x-dawah-share-panel
        :share-data="$shareData"
        :share-links="$shareLinks"
    />
</section>

<section class="scroll-reveal reveal-right revealed rounded-[1.5rem] border border-slate-200 bg-white p-5 shadow-sm">
    <p class="text-[10px] font-black uppercase tracking-[0.22em] text-slate-400">{{ __('Ketepatan Maklumat') }}</p>
    <h2 class="mt-1 font-heading text-lg font-bold text-emerald-950">{{ __($feedbackHeadingKey) }}</h2>
    <p class="mt-2 text-sm leading-6 text-slate-500">{{ __('Nampak maklumat yang tidak tepat atau meragukan? Bantu komuniti dengan memaklumkan kepada kami.') }}</p>

    <div class="mt-4 grid grid-cols-2 gap-2">
        <a
            href="{{ route('contributions.suggest-update', ['subjectType' => $subjectType, 'subjectId' => $subjectId]) }}"
            wire:navigate
            class="group inline-flex h-11 items-center justify-center gap-2 rounded-xl border border-sky-200 bg-sky-50 px-4 text-xs font-bold text-sky-800 transition hover:-translate-y-0.5 hover:border-sky-300 hover:bg-sky-100 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-sky-600/10"
        >
            {{ __('Cadang Kemaskini') }}
        </a>
        <a
            href="{{ route('reports.create', ['subjectType' => $subjectType, 'subjectId' => $subjectId]) }}"
            wire:navigate
            class="group inline-flex h-11 items-center justify-center gap-2 rounded-xl border border-rose-200 bg-rose-50 px-4 text-xs font-bold text-rose-800 transition hover:-translate-y-0.5 hover:border-rose-300 hover:bg-rose-100 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rose-600/10"
        >
            {{ __('Lapor') }}
        </a>
    </div>
</section>
