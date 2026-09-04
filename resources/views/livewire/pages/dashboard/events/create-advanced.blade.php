@section('title', __('Cipta Majlis') . ' - ' . config('app.name'))

@include('partials.filament-assets', [
    'scripts' => ['filament/support', 'filament/schemas', 'filament/forms', 'filament/actions', 'filament/notifications'],
])

@push('head')
<style>
    /* Keep the same calm, scrollable wizard treatment as Hantar Majlis. */
    .fi-sc-wizard-header {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        scrollbar-width: thin;
        gap: 0.5rem;
        padding: 0.5rem;
        border: 1px solid #e2e8f0;
        border-radius: 1rem;
        background: #ffffff;
    }

    .fi-sc-wizard-header-step-btn {
        min-width: 10rem;
        border-radius: 0.75rem;
    }

    .fi-sc-wizard-header::-webkit-scrollbar {
        height: 3px;
    }

    .fi-sc-wizard-header::-webkit-scrollbar-thumb {
        background-color: #cbd5e1;
        border-radius: 9999px;
    }

    .fi-loading-indicator {
        display: none;
    }
</style>
@endpush

<div class="min-h-screen bg-gradient-to-b from-emerald-50/80 via-[#f6f8f6] to-[#f6f8f6] py-10 pb-32 sm:py-14">
    <div class="container mx-auto px-4 sm:px-6 lg:px-12">
        <div class="mx-auto max-w-6xl xl:max-w-7xl">
            <header class="mx-auto mb-8 max-w-4xl text-center sm:mb-10">
                <div class="mb-4 flex flex-wrap items-center justify-center gap-3 text-sm">
                    <a href="{{ route('dashboard') }}" wire:navigate class="font-semibold text-emerald-800 transition hover:text-emerald-950">
                        ← {{ __('Dashboard') }}
                    </a>
                    <span class="text-slate-300">/</span>
                    <span class="text-slate-500">{{ __('Cipta Majlis') }}</span>
                </div>

                <div class="inline-flex items-center gap-2 rounded-full bg-emerald-100 px-3 py-1.5 text-[11px] font-bold uppercase tracking-[0.2em] text-emerald-800">
                    <span class="size-2 rounded-full bg-emerald-500"></span>
                    {{ __('Majlis terurus') }}
                </div>
                <h1 class="mt-4 font-heading text-4xl font-bold leading-tight tracking-[-0.04em] text-slate-950 sm:text-5xl">
                    {{ __('Cipta majlis dengan maklumat lengkap.') }}
                </h1>
                <p class="mx-auto mt-4 max-w-3xl text-base leading-7 text-slate-600 sm:text-lg">
                    {{ __('Lengkapkan profil majlis dan sesi pertama dalam beberapa langkah. Maklumat yang sudah diketahui daripada halaman sebelumnya telah diisi untuk anda.') }}
                </p>
            </header>

            @if($contextInstitutionId !== '' || $contextPersonId !== '')
                <div class="mb-6 rounded-2xl border border-emerald-200 bg-emerald-50/70 p-5 shadow-sm">
                    <div class="flex items-start gap-3">
                        <span class="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-full bg-emerald-600 text-sm font-bold text-white" aria-hidden="true">✓</span>
                        <div>
                            <p class="text-sm font-semibold text-emerald-950">{{ __('Maklumat penganjur telah ditetapkan') }}</p>
                            <p class="mt-1 text-sm leading-6 text-emerald-900/75">
                                @if($contextInstitutionId !== '')
                                    {{ __('Majlis ini akan diuruskan untuk institusi :name.', ['name' => $institutionOptions[$contextInstitutionId] ?? __('institusi yang dipilih')]) }}
                                @else
                                    {{ __('Penceramah :name telah ditambah sebagai penganjur dan penceramah sesi pertama.', ['name' => $prefillPersonLabel ?? __('penceramah yang dipilih')]) }}
                                @endif
                            </p>
                        </div>
                    </div>
                </div>
            @endif

            <section class="mb-8 overflow-hidden rounded-3xl border border-emerald-100 bg-white shadow-[0_24px_70px_-50px_rgba(6,95,70,0.65)]" aria-labelledby="managed-event-intro-title">
                <div class="grid gap-0 lg:grid-cols-[minmax(0,1fr)_19rem]">
                    <div class="p-6 sm:p-8">
                        <div class="flex items-start gap-4">
                            <span class="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-700" aria-hidden="true">
                                <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 6.75A2.25 2.25 0 0 1 6.75 4.5h10.5a2.25 2.25 0 0 1 2.25 2.25v10.5a2.25 2.25 0 0 1-2.25 2.25H6.75a2.25 2.25 0 0 1-2.25-2.25V6.75Z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 9h8M8 12h8M8 15h5" />
                                </svg>
                            </span>
                            <div>
                                <p class="text-xs font-bold uppercase tracking-[0.18em] text-emerald-700">{{ __('Satu borang lengkap') }}</p>
                                <h2 id="managed-event-intro-title" class="mt-2 font-heading text-2xl font-bold text-slate-950">{{ __('Profil majlis dahulu, sesi kemudian') }}</h2>
                                <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                                    {{ __('Tetapkan maklumat yang kekal untuk majlis ini sekarang. Selepas dicipta, anda boleh tambah sesi, kemas kini pendaftaran, dan urus majlis daripada ruang kerja.') }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="border-t border-emerald-100 bg-emerald-50/50 p-6 sm:p-8 lg:border-l lg:border-t-0">
                        <p class="text-sm font-semibold text-emerald-950">{{ __('Aliran yang mudah') }}</p>
                        <ol class="mt-5 space-y-4">
                            @foreach([
                                __('Isi profil majlis'),
                                __('Tetapkan sesi pertama'),
                                __('Tambah sesi lain kemudian'),
                            ] as $step => $label)
                                <li class="flex items-center gap-3 text-sm text-slate-700">
                                    <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-white text-xs font-bold text-emerald-800 shadow-sm ring-1 ring-emerald-100">{{ $step + 1 }}</span>
                                    <span>{{ $label }}</span>
                                </li>
                            @endforeach
                        </ol>
                    </div>
                </div>
            </section>

            <section class="mb-8 rounded-2xl border border-amber-200 bg-amber-50/70 p-5 shadow-sm" aria-labelledby="advanced-features-title">
                <p id="advanced-features-title" class="text-sm font-semibold text-amber-950">{{ __('Ciri lanjutan yang tersedia') }}</p>
                <div class="mt-4 grid gap-3 text-sm text-amber-950 sm:grid-cols-3">
                    <div class="rounded-xl border border-amber-200/80 bg-white/70 p-3">
                        <p class="font-semibold">{{ __('Pendaftaran') }}</p>
                        <p class="mt-1 text-xs leading-5 text-amber-900/70">{{ __('Wajib daftar atau hadir secara terus.') }}</p>
                    </div>
                    <div class="rounded-xl border border-amber-200/80 bg-white/70 p-3">
                        <p class="font-semibold">{{ __('Tiket & pakej') }}</p>
                        <p class="mt-1 text-xs leading-5 text-amber-900/70">{{ __('Harga, kuota, kod, dan had seorang.') }}</p>
                    </div>
                    <div class="rounded-xl border border-amber-200/80 bg-white/70 p-3">
                        <p class="font-semibold">{{ __('Kehadiran') }}</p>
                        <p class="mt-1 text-xs leading-5 text-amber-900/70">{{ __('Pendaftaran umum tanpa pemilihan atau penetapan tempat duduk.') }}</p>
                    </div>
                </div>
                <p class="mt-4 text-xs leading-5 text-amber-900/70">{{ __('Semua ciri ini berada pada langkah Pendaftaran & tiket di dalam borang di bawah.') }}</p>
            </section>

            @if($templateOptions !== [])
                <details class="mb-8 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <summary class="cursor-pointer px-5 py-4 text-sm font-semibold text-slate-900 transition hover:bg-slate-50">
                        {{ __('Mula dengan templat') }}
                        <span class="ml-1 font-normal text-slate-500">({{ __('pilihan') }})</span>
                    </summary>
                    <div class="border-t border-slate-100 bg-slate-50/70 p-5">
                        <p class="mb-4 text-sm leading-6 text-slate-600">{{ __('Pilih templat untuk mengisi tajuk, penerangan, dan tempoh program. Anda masih boleh mengubah semua maklumat sebelum mencipta majlis.') }}</p>
                        <div class="grid gap-3 md:grid-cols-3">
                            @foreach($templateOptions as $template)
                                <button type="button" wire:click="applyTemplate('{{ $template['key'] }}')" class="group rounded-2xl border border-slate-200 bg-white p-4 text-left transition hover:-translate-y-0.5 hover:border-emerald-300 hover:shadow-md">
                                    <span class="text-[11px] font-bold uppercase tracking-[0.16em] text-emerald-700">{{ $template['eyebrow'] }}</span>
                                    <span class="mt-2 block text-sm font-semibold text-slate-900">{{ $template['title'] }}</span>
                                    <span class="mt-1 block text-xs leading-5 text-slate-500">{{ $template['description'] }}</span>
                                </button>
                            @endforeach
                        </div>
                    </div>
                </details>
            @endif

            <form wire:submit="submit" novalidate
                data-signal-submit-event="submission.managed_event_submitted"
                data-signal-category="submission"
                data-signal-component="managed_event_form"
                data-signal-control="submit"
                class="overflow-hidden rounded-3xl border border-emerald-100 bg-white p-4 shadow-[0_24px_70px_-50px_rgba(6,95,70,0.65)] sm:p-6">
                {{ $this->getForm('form') }}
            </form>

            <x-filament-actions::modals />
        </div>
    </div>
</div>
