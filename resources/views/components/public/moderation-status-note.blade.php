@props([
    'showPending' => false,
    'showCancelled' => false,
])

@php
    $showPending = (bool) $showPending;
    $showCancelled = (bool) $showCancelled;
    $message = match (true) {
        $showPending && $showCancelled => __('Sesetengah rekod awam di sini masih menunggu kelulusan moderator atau telah dibatalkan. Semak lencana status pada setiap majlis sebelum hadir.'),
        $showPending => __('Sesetengah rekod awam di sini masih menunggu kelulusan moderator. Semak lencana status pada setiap majlis sebelum hadir.'),
        default => __('Sesetengah rekod awam di sini telah dibatalkan. Semak lencana status pada setiap majlis untuk maklumat terkini.'),
    };
@endphp

@if($showPending || $showCancelled)
    <div {{ $attributes->class('rounded-2xl border border-slate-200 bg-slate-50/90 p-4 shadow-sm') }}>
        <p class="text-[11px] font-bold uppercase tracking-[0.22em] text-slate-500">{{ __('Catatan Status') }}</p>

        <div class="mt-3 flex flex-wrap gap-2">
            @if($showPending)
                <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-800">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    {{ __('Menunggu Kelulusan') }}
                </span>
            @endif

            @if($showCancelled)
                <span class="inline-flex items-center gap-1 rounded-full bg-rose-100 px-3 py-1 text-xs font-semibold text-rose-800">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M18.364 5.636a9 9 0 010 12.728m-12.728 0a9 9 0 010-12.728m12.728 12.728L5.636 5.636" />
                    </svg>
                    {{ __('Dibatalkan') }}
                </span>
            @endif
        </div>

        <p class="mt-3 text-sm leading-6 text-slate-600">
            {{ $message }}
        </p>
    </div>
@endif
