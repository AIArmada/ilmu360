<?php

use Livewire\Component;

new class extends Component {};
?>

@section('title', __('Tambah Majlis') . ' - ' . config('app.name'))
@section('meta_description', __('Hantar maklumat majlis ilmu untuk semakan komuniti. Muat naik poster, isi manual, atau wakili institusi dengan mudah.'))

@php
    $submitUrl = route('submit-event.create');
    $posterSubmitUrl = route('submit-event.create', ['mode' => 'poster']);
    $manualSubmitUrl = route('submit-event.create', ['mode' => 'manual']);
    $institutionSubmitUrl = route('submit-event.create', ['mode' => 'institution']);

    $entryCards = [
        [
            'title' => __('Hantar dengan poster'),
            'description' => __('Muat naik poster. AI bantu isi maklumat untuk anda.'),
            'url' => $posterSubmitUrl,
            'accent' => 'emerald',
            'icon' => 'image',
            'signal' => 'poster',
        ],
        [
            'title' => __('Isi manual'),
            'description' => __('Isi maklumat satu persatu dengan mudah.'),
            'url' => $manualSubmitUrl,
            'accent' => 'amber',
            'icon' => 'pencil',
            'signal' => 'manual',
        ],
        [
            'title' => __('Saya AJK / wakil institusi'),
            'description' => __('Hantar untuk pihak masjid, surau, atau institusi.'),
            'url' => $institutionSubmitUrl,
            'accent' => 'amber',
            'icon' => 'users',
            'signal' => 'institution',
        ],
    ];

    $posterSteps = [
        ['number' => '1', 'label' => __('Muat naik poster')],
        ['number' => '2', 'label' => __('AI ekstrak maklumat')],
        ['number' => '3', 'label' => __('Semak & sunting')],
        ['number' => '4', 'label' => __('Semak & hantar')],
    ];

    $manualFields = [
        ['label' => __('Tajuk majlis'), 'placeholder' => __('Contoh: Kuliah Maghrib Kitab Riyadhus Solihin'), 'wide' => true],
        ['label' => __('Penceramah'), 'placeholder' => __('Contoh: Ustaz Ahmad Nuruddin'), 'wide' => false],
        ['label' => __('Tarikh'), 'placeholder' => __('Pilih tarikh'), 'wide' => false],
        ['label' => __('Masa'), 'placeholder' => __('Contoh: 8:30 malam'), 'wide' => false],
        ['label' => __('Institusi / Lokasi'), 'placeholder' => __('Contoh: Masjid Al-Hidayah, Taman Ilmu'), 'wide' => true],
        ['label' => __('Catatan'), 'placeholder' => __('Maklumat tambahan seperti semua dijemput hadir'), 'wide' => true],
    ];
@endphp

<div class="min-h-screen overflow-hidden bg-[#fffaf1] text-slate-900">
    <section class="relative isolate overflow-hidden border-b border-amber-100 bg-[#fffdf8]">
        <div class="absolute inset-0 bg-[radial-gradient(circle_at_17%_19%,rgba(5,95,70,0.08),transparent_28%),radial-gradient(circle_at_63%_14%,rgba(245,158,11,0.12),transparent_34%)]"></div>
        <div class="absolute inset-y-0 right-0 hidden w-[42%] overflow-hidden lg:block">
            <div class="absolute inset-0 rounded-bl-[10rem] bg-emerald-950/10">
                <img src="{{ asset('images/hero-bg.png') }}" alt="{{ __('Masjid dengan kubah pada waktu senja') }}" class="h-full w-full object-cover">
                <div class="absolute inset-0 bg-gradient-to-l from-transparent via-white/5 to-[#fffdf8]/80"></div>
            </div>
        </div>
        <div class="pointer-events-none absolute right-[31%] top-10 hidden size-72 rounded-full border border-amber-200/60 opacity-50 lg:block"></div>

        <div class="container relative z-10 mx-auto px-6 py-14 lg:px-12 lg:py-20">
            <div class="grid gap-10 lg:grid-cols-[minmax(0,1fr)_25rem] lg:items-end">
                <div class="max-w-4xl">
                    <p class="text-xs font-bold uppercase tracking-[0.26em] text-emerald-800">{{ __('Tambah Majlis') }}</p>
                    <h1 class="mt-5 max-w-4xl font-heading text-5xl font-bold leading-[0.98] tracking-normal text-emerald-950 md:text-7xl">
                        {{ __('Ada majlis ilmu') }}
                        <span class="block">{{ __('yang patut orang tahu?') }}</span>
                        <span class="block text-[#082f57]">{{ __('Bantu buka jalan.') }}</span>
                    </h1>
                    <p class="mt-6 max-w-2xl text-lg leading-8 text-slate-700">
                        {{ __('Tak perlu jadi admin rasmi untuk membantu. Hantar maklumat yang anda tahu. Pasukan ilmu360') }}&deg; {{ __('dan komuniti akan bantu semak.') }}
                    </p>

                    <div class="mt-9 grid gap-4 sm:grid-cols-3">
                        @foreach($entryCards as $card)
                            <a href="{{ $card['url'] }}" wire:navigate
                                data-signal-event="submission.entry_selected"
                                data-signal-category="submission"
                                data-signal-component="tambah_majlis"
                                data-signal-control="{{ $card['signal'] }}"
                                class="group relative flex min-h-52 flex-col justify-between rounded-2xl border border-amber-200/80 bg-white/88 p-6 shadow-sm backdrop-blur transition hover:-translate-y-1 hover:border-emerald-200 hover:shadow-[0_24px_70px_-45px_rgba(6,95,70,0.55)]">
                                <span class="flex size-14 items-center justify-center rounded-2xl {{ $card['accent'] === 'emerald' ? 'bg-emerald-50 text-emerald-800' : 'bg-amber-50 text-amber-700' }}">
                                    @if($card['icon'] === 'image')
                                        <svg class="size-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M3.75 19.5h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Z" />
                                        </svg>
                                    @elseif($card['icon'] === 'pencil')
                                        <svg class="size-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125" />
                                        </svg>
                                    @else
                                        <svg class="size-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a8.25 8.25 0 0 0 3.75-6.907 8.25 8.25 0 0 0-15-4.728M6 18.72A8.25 8.25 0 0 1 2.25 11.813a8.25 8.25 0 0 1 15-4.728M15 11.25a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                        </svg>
                                    @endif
                                </span>
                                <span>
                                    <span class="block font-heading text-2xl font-bold leading-tight text-emerald-950">{{ $card['title'] }}</span>
                                    <span class="mt-3 block text-sm leading-6 text-slate-600">{{ $card['description'] }}</span>
                                </span>
                                <span class="absolute bottom-5 right-5 flex size-10 items-center justify-center rounded-full border border-amber-300 text-emerald-800 transition group-hover:border-emerald-700 group-hover:bg-emerald-800 group-hover:text-white">
                                    <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
                                    </svg>
                                </span>
                            </a>
                        @endforeach
                    </div>
                </div>

                <aside class="relative hidden lg:block">
                    <div class="rounded-[2rem] border border-amber-100 bg-white/92 p-6 shadow-[0_28px_90px_-52px_rgba(15,23,42,0.65)] backdrop-blur">
                        <div class="flex items-start gap-4">
                            <span class="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-800">
                                <svg class="size-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M12 3.75l7.5 3v5.25c0 4.477-2.873 8.27-7.5 9.75-4.627-1.48-7.5-5.273-7.5-9.75V6.75l7.5-3Z" />
                                </svg>
                            </span>
                            <div>
                                <h2 class="font-heading text-2xl font-bold leading-tight text-emerald-950">{{ __('Setiap hantar, setiap ilmu sampai') }}</h2>
                                <p class="mt-4 text-sm leading-6 text-slate-600">{{ __('Bantu sebarkan ilmu. Pahala berpanjangan.') }}</p>
                            </div>
                        </div>
                        <div class="mt-6 flex -space-x-2">
                            @foreach(['A', 'H', 'S', 'N', 'M', 'F'] as $initial)
                                <span class="flex size-8 items-center justify-center rounded-full border-2 border-white bg-emerald-100 text-xs font-bold text-emerald-800">{{ $initial }}</span>
                            @endforeach
                        </div>
                        <p class="mt-5 text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Komuniti yang membantu') }}</p>
                        <p class="mt-1 font-heading text-2xl font-bold text-emerald-950">{{ __('1,245+ penyumbang aktif') }}</p>
                    </div>
                </aside>
            </div>
        </div>
    </section>

    <main class="container mx-auto px-6 py-8 lg:px-12 lg:py-12">
        <section class="rounded-3xl border border-amber-100 bg-white/94 p-5 shadow-sm lg:p-7">
            <div class="flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
                <div class="flex items-center gap-3">
                    <span class="flex size-10 items-center justify-center rounded-xl bg-emerald-50 text-emerald-800">
                        <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M3.75 19.5h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Z" />
                        </svg>
                    </span>
                    <h2 class="font-heading text-3xl font-bold text-emerald-950">{{ __('Hantar dengan poster') }} <span class="font-sans text-base font-semibold text-slate-600">{{ __('(4 langkah mudah)') }}</span></h2>
                </div>
                <div class="grid gap-2 sm:grid-cols-4 lg:min-w-[44rem]">
                    @foreach($posterSteps as $step)
                        <div class="flex items-center gap-2">
                            <span class="flex size-9 shrink-0 items-center justify-center rounded-full border {{ $loop->first ? 'border-emerald-800 bg-emerald-800 text-white' : 'border-amber-300 bg-white text-amber-800' }} text-sm font-bold">{{ $step['number'] }}</span>
                            <span class="text-sm font-semibold text-slate-700">{{ $step['label'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="mt-7 grid gap-4 lg:grid-cols-4">
                <article class="rounded-2xl border border-amber-100 bg-white p-5">
                    <h3 class="flex items-center gap-2 font-heading text-xl font-bold text-emerald-950"><span class="flex size-8 items-center justify-center rounded-full bg-emerald-800 text-sm text-white">1</span>{{ __('Muat naik poster') }}</h3>
                    <div class="mt-5 flex min-h-48 flex-col items-center justify-center rounded-2xl border border-dashed border-sky-300 bg-sky-50/30 p-6 text-center">
                        <svg class="size-12 text-sky-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0 3 3m-3-3-3 3M4.5 19.5h15a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5h-15A1.5 1.5 0 0 0 3 6v12a1.5 1.5 0 0 0 1.5 1.5Z" />
                        </svg>
                        <p class="mt-4 text-sm font-semibold text-slate-700">{{ __('Seret & lepaskan poster di sini') }}</p>
                        <p class="mt-2 text-xs leading-5 text-slate-500">{{ __('JPG, PNG atau PDF') }}<br>{{ __('Maks. 10MB') }}</p>
                    </div>
                    <div class="mt-5 overflow-hidden rounded-2xl border border-amber-100 bg-amber-50 p-4">
                        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-amber-800">{{ __('Contoh poster') }}</p>
                        <div class="mt-3 rounded-xl border border-amber-200 bg-[#fff8df] p-4 text-center">
                            <p class="text-xs font-bold uppercase tracking-[0.14em] text-amber-800">{{ __('Kuliah Maghrib') }}</p>
                            <p class="mt-2 font-heading text-xl font-bold text-emerald-950">{{ __('Kitab Riyadhus Salihin') }}</p>
                            <p class="mt-2 text-sm text-slate-700">{{ __('Bersama Ustaz Ahmad Nuruddin') }}</p>
                            <div class="mt-4 rounded-lg bg-emerald-900 p-3 text-left text-xs leading-5 text-white">
                                <p>{{ __('20 Mei 2024 (Isnin)') }}</p>
                                <p>{{ __('8:30 malam') }}</p>
                                <p>{{ __('Masjid Al-Hidayah') }}</p>
                            </div>
                        </div>
                    </div>
                    <p class="mt-4 flex items-center gap-2 text-xs text-slate-500"><span class="flex size-5 items-center justify-center rounded-full bg-emerald-50 text-emerald-700">&#10003;</span>{{ __('Poster jelas & maklumat boleh dibaca') }}</p>
                </article>

                <article class="rounded-2xl border border-amber-100 bg-white p-5">
                    <h3 class="flex items-center gap-2 font-heading text-xl font-bold text-emerald-950">
                        <svg class="size-6 text-emerald-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.25 7.5 17.5 9.75l-.75-2.25-2.25-.75 2.25-.75.75-2.25.75 2.25 2.25.75-2.25.75Z" /></svg>
                        {{ __('AI ekstrak maklumat') }}
                    </h3>
                    <p class="mt-4 text-sm leading-6 text-slate-600">{{ __('AI telah baca poster dan mencadangkan maklumat berikut:') }}</p>
                    <div class="mt-5 space-y-3">
                        @foreach([
                            __('Tajuk majlis') => __('Kuliah Maghrib: Kitab Riyadhus Salihin'),
                            __('Penceramah') => __('Ustaz Ahmad Nuruddin'),
                            __('Tarikh & masa') => __('20 Mei 2024 (Isnin), 8:30 malam'),
                            __('Tempat') => __('Masjid Al-Hidayah, Taman Ilmu'),
                        ] as $label => $value)
                            <div class="rounded-xl border border-slate-200 bg-[#fbfaf5] p-4">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <p class="text-xs font-semibold text-slate-500">{{ $label }}</p>
                                        <p class="mt-1 text-sm font-bold leading-5 text-slate-800">{{ $value }}</p>
                                    </div>
                                    <span class="text-xs font-bold text-emerald-800">{{ __('Ubah') }}</span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <p class="mt-5 flex items-start gap-2 text-xs leading-5 text-slate-500"><span class="mt-0.5 flex size-5 items-center justify-center rounded-full bg-emerald-50 text-emerald-700">&#10003;</span>{{ __('Maklumat ini cadangan AI. Anda boleh semak dan ubah di langkah seterusnya.') }}</p>
                </article>

                <article class="rounded-2xl border border-amber-100 bg-white p-5">
                    <h3 class="flex items-center gap-2 font-heading text-xl font-bold text-emerald-950"><span class="flex size-8 items-center justify-center rounded-full border border-amber-300 text-sm text-amber-800">3</span>{{ __('Semak & sunting') }}</h3>
                    <p class="mt-4 text-sm leading-6 text-slate-600">{{ __('Sahkan atau kemas kini maklumat.') }}</p>
                    <div class="mt-5 space-y-3">
                        @foreach([
                            __('Tajuk majlis') => __('Kuliah Maghrib: Kitab Riyadhus Salihin'),
                            __('Penceramah') => __('Ustaz Ahmad Nuruddin'),
                            __('Tarikh') => __('20/05/2024'),
                            __('Masa') => __('8:30 malam'),
                            __('Tempat') => __('Masjid Al-Hidayah, Taman Ilmu'),
                        ] as $label => $value)
                            <label class="block">
                                <span class="text-xs font-semibold text-slate-600">{{ $label }} <span class="text-amber-600">*</span></span>
                                <input value="{{ $value }}" readonly class="mt-1 h-10 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm text-slate-700">
                            </label>
                        @endforeach
                        <label class="block">
                            <span class="text-xs font-semibold text-slate-600">{{ __('Catatan') }}</span>
                            <textarea readonly rows="3" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700">{{ __('Semua dijemput hadir.') }}</textarea>
                        </label>
                    </div>
                    <p class="mt-4 flex items-start gap-2 text-xs leading-5 text-slate-500"><span class="mt-0.5 flex size-5 items-center justify-center rounded-full bg-emerald-50 text-emerald-700">&#10003;</span>{{ __('Terima kasih! Maklumat ini sangat membantu.') }}</p>
                </article>

                <article class="rounded-2xl border border-amber-100 bg-white p-5">
                    <h3 class="flex items-center gap-2 font-heading text-xl font-bold text-emerald-950"><span class="flex size-8 items-center justify-center rounded-full bg-emerald-800 text-sm text-white">4</span>{{ __('Semak & hantar') }}</h3>
                    <p class="mt-4 text-sm leading-6 text-slate-600">{{ __('Semak ringkasan sebelum dihantar.') }}</p>
                    <div class="mt-5 rounded-2xl border border-amber-200 bg-[#fffaf1] p-5">
                        <h4 class="font-heading text-xl font-bold text-emerald-950">{{ __('Kuliah Maghrib: Kitab Riyadhus Salihin') }}</h4>
                        <dl class="mt-5 space-y-4 text-sm text-slate-700">
                            <div><dt class="font-semibold text-slate-500">{{ __('Penceramah') }}</dt><dd>{{ __('Ustaz Ahmad Nuruddin') }}</dd></div>
                            <div><dt class="font-semibold text-slate-500">{{ __('Tarikh') }}</dt><dd>{{ __('20 Mei 2024 (Isnin), 8:30 malam') }}</dd></div>
                            <div><dt class="font-semibold text-slate-500">{{ __('Tempat') }}</dt><dd>{{ __('Masjid Al-Hidayah, Taman Ilmu, Kuala Lumpur') }}</dd></div>
                            <div><dt class="font-semibold text-slate-500">{{ __('Catatan') }}</dt><dd>{{ __('Semua dijemput hadir.') }}</dd></div>
                        </dl>
                    </div>
                    <div class="mt-4 rounded-2xl border border-amber-100 bg-amber-50 p-4 text-sm leading-6 text-slate-700">
                        <strong class="block text-amber-900">{{ __('Maklumat akan disemak sebelum diterbitkan.') }}</strong>
                        {{ __('Biasanya dalam 24-48 jam.') }}
                    </div>
                    <a href="{{ $posterSubmitUrl }}" wire:navigate
                        data-signal-event="submission.flow_started"
                        data-signal-category="submission"
                        data-signal-component="tambah_majlis"
                        data-signal-control="poster_flow_submit"
                        class="mt-5 inline-flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-emerald-800 px-5 text-sm font-bold text-white shadow-lg shadow-emerald-900/15 transition hover:bg-emerald-900">
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="m6 12 12-7-4 14-3-5-5-2Z" /></svg>
                        {{ __('Hantar untuk semakan') }}
                    </a>
                    <p class="mt-4 text-center text-xs text-slate-500">{{ __('Selamat & tidak akan diterbitkan segera') }}</p>
                </article>
            </div>
        </section>

        <div class="mt-6 grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
            <section class="rounded-3xl border border-amber-100 bg-white/94 p-5 shadow-sm lg:p-7">
                <h2 class="flex items-center gap-3 font-heading text-3xl font-bold text-[#082f57]">
                    <svg class="size-7 text-amber-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z" /></svg>
                    {{ __('Atau isi manual') }}
                    <span class="font-sans text-base font-semibold text-slate-600">{{ __('(senang & cepat)') }}</span>
                </h2>

                <div class="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1fr)_21rem]">
                    <div class="grid gap-4 sm:grid-cols-2">
                        @foreach($manualFields as $field)
                            <label class="{{ $field['wide'] ? 'sm:col-span-2' : '' }} block">
                                <span class="text-xs font-semibold text-slate-700">{{ $field['label'] }} <span class="text-amber-600">*</span></span>
                                <input readonly placeholder="{{ $field['placeholder'] }}" class="mt-1 h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm text-slate-500">
                            </label>
                        @endforeach
                    </div>

                    <div class="space-y-5">
                        <div>
                            <p class="text-xs font-semibold text-slate-700">{{ __('Poster') }} <span class="text-slate-400">({{ __('pilihan') }})</span></p>
                            <div class="mt-1 flex min-h-28 items-center justify-center rounded-2xl border border-dashed border-sky-300 bg-sky-50/30 p-4 text-center">
                                <p class="text-xs leading-5 text-slate-500">{{ __('Muat naik poster jika ada') }}<br>{{ __('JPG, PNG atau PDF - Maks. 10MB') }}</p>
                            </div>
                        </div>
                        <div class="grid gap-4">
                            <label class="block">
                                <span class="text-xs font-semibold text-slate-700">{{ __('Nama') }} <span class="text-amber-600">*</span></span>
                                <input readonly placeholder="{{ __('Contoh: Ahmad bin Ali') }}" class="mt-1 h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm text-slate-500">
                            </label>
                            <label class="block">
                                <span class="text-xs font-semibold text-slate-700">{{ __('Telefon / WhatsApp') }} <span class="text-amber-600">*</span></span>
                                <input readonly placeholder="{{ __('Contoh: 012-345 6789') }}" class="mt-1 h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm text-slate-500">
                            </label>
                        </div>
                    </div>
                </div>

                <a href="{{ $manualSubmitUrl }}" wire:navigate
                    data-signal-event="submission.flow_started"
                    data-signal-category="submission"
                    data-signal-component="tambah_majlis"
                    data-signal-control="manual_flow_submit"
                    class="mt-6 inline-flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-emerald-800 px-5 text-sm font-bold text-white shadow-lg shadow-emerald-900/15 transition hover:bg-emerald-900">
                    <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="m6 12 12-7-4 14-3-5-5-2Z" /></svg>
                    {{ __('Hantar untuk semakan') }}
                </a>
                <p class="mt-3 text-center text-xs text-slate-500">{{ __('Maklumat akan disemak sebelum diterbitkan.') }}</p>
            </section>

            <aside class="space-y-5">
                <section class="rounded-3xl border border-amber-100 bg-white p-6 shadow-sm">
                    <h2 class="flex items-center gap-3 font-heading text-2xl font-bold text-emerald-950">
                        <span class="flex size-10 items-center justify-center rounded-xl bg-amber-50 text-amber-700">
                            <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M12 3.75l7.5 3v5.25c0 4.477-2.873 8.27-7.5 9.75-4.627-1.48-7.5-5.273-7.5-9.75V6.75l7.5-3Z" /></svg>
                        </span>
                        {{ __('Amanah & telus') }}
                    </h2>
                    <ul class="mt-5 space-y-4 text-sm leading-6 text-slate-700">
                        <li class="flex gap-3"><span class="font-bold text-emerald-700">&#10003;</span>{{ __('Semua maklumat akan disemak sebelum diterbitkan.') }}</li>
                        <li class="flex gap-3"><span class="font-bold text-emerald-700">&#10003;</span>{{ __('Fokus pada majlis ilmu yang sahih, bermanfaat dan terbuka kepada umum.') }}</li>
                        <li class="flex gap-3"><span class="font-bold text-emerald-700">&#10003;</span>{{ __('Anda akan dimaklumkan jika ada maklumat perlu diperbetulkan.') }}</li>
                    </ul>
                </section>

                <section class="rounded-3xl border border-amber-100 bg-white p-6 shadow-sm">
                    <h2 class="font-heading text-2xl font-bold text-emerald-950">{{ __('Perlu bantuan?') }}</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('Kami sedia membantu anda.') }}</p>
                    <a href="mailto:hantar@ilmu360.com" class="mt-5 inline-flex h-11 w-full items-center justify-center rounded-xl border border-amber-300 text-sm font-bold text-amber-800 transition hover:bg-amber-50">
                        {{ __('Hubungi kami') }}
                    </a>
                    <p class="mt-5 text-sm leading-6 text-slate-600">{{ __('Biasanya kami balas dalam 1 hari bekerja.') }}</p>
                </section>
            </aside>
        </div>

        <section class="mt-6 rounded-3xl border border-amber-100 bg-gradient-to-r from-emerald-50 via-white to-amber-50 p-6 lg:p-8">
            <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_repeat(3,12rem)] lg:items-center">
                <div class="flex items-center gap-4">
                    <span class="flex size-16 shrink-0 items-center justify-center rounded-2xl bg-emerald-100 text-emerald-800">
                        <svg class="size-9" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 11c0 5.6-7 10-7 10Z" /></svg>
                    </span>
                    <div>
                        <h2 class="font-heading text-2xl font-bold text-emerald-950">{{ __('Terima kasih atas sumbangan anda.') }}</h2>
                        <p class="mt-1 text-sm leading-6 text-slate-600">{{ __('Setiap maklumat yang anda hantar, membuka jalan ilmu untuk ramai.') }}</p>
                    </div>
                </div>
                @foreach([
                    __('Mudah') => __('Hantar dalam beberapa minit.'),
                    __('Bermanfaat') => __('Ilmu sampai kepada lebih ramai.'),
                    __('Berkesan') => __('Pahala berpanjangan, meski kita tidak hadir.'),
                ] as $title => $copy)
                    <div class="rounded-2xl border border-amber-100 bg-white/80 p-4">
                        <p class="font-heading text-xl font-bold text-emerald-950">{{ $title }}</p>
                        <p class="mt-1 text-xs leading-5 text-slate-600">{{ $copy }}</p>
                    </div>
                @endforeach
            </div>
        </section>
    </main>
</div>
