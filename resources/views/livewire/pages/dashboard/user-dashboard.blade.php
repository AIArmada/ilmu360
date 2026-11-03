@php
    $dashboardPageLabel = in_array(app()->getLocale(), ['ms', 'ms_MY'], true) ? 'Dashboard' : __('Dashboard');
@endphp
@section('title', $dashboardPageLabel . ' - ' . config('app.name'))

@php
    $summary = $this->summaryStats;
    $followingPersons = $this->followingPersons;
    $followingReferences = $this->followingReferences;
    $followingInstitutions = $this->followingInstitutions;
    $recentSavedSearches = $this->recentSavedSearches;
    $dawahImpactSummary = $this->dawahImpactSummary;
    $shareRewardEnrollmentPrompt = $this->shareRewardEnrollmentPrompt;

    $dashboardStats = [
        [
            'label' => __('Majlis Disimpan'),
            'value' => $summary['saved_count'],
            'icon' => 'M5 5a2 2 0 012-2h10a2 2 0 012 2v16l-7-3.5L5 21V5z',
            'class' => 'bg-emerald-50 text-emerald-700',
        ],
        [
            'label' => __('Going'),
            'value' => $summary['going_count'],
            'icon' => 'M8 7V3m8 4V3M4 11h16M5 5h14a1 1 0 011 1v14a1 1 0 01-1 1H5a1 1 0 01-1-1V6a1 1 0 011-1zm4 10l2 2 4-4',
            'class' => 'bg-emerald-50 text-emerald-700',
        ],
        [
            'label' => __('Institusi Diikuti'),
            'value' => $followingInstitutions->count(),
            'icon' => 'M3 21h18M5 21V8l7-4 7 4v13M9 21v-7h6v7M9 11h.01M15 11h.01',
            'class' => 'bg-emerald-50 text-emerald-700',
        ],
        [
            'label' => __('Penceramah Diikuti'),
            'value' => $followingPersons->count(),
            'icon' => 'M15 7a3 3 0 11-6 0 3 3 0 016 0zM4 21a8 8 0 0116 0',
            'class' => 'bg-teal-50 text-teal-700',
        ],
        [
            'label' => __('Rujukan Diikuti'),
            'value' => $followingReferences->count(),
            'icon' => 'M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253',
            'class' => 'bg-sky-50 text-sky-700',
        ],
        [
            'label' => __('Sumbangan Saya'),
            'value' => $dawahImpactSummary['total_outcomes'],
            'icon' => 'M12 21s-7-4.4-7-10a4 4 0 017-2.6A4 4 0 0119 11c0 5.6-7 10-7 10z',
            'class' => 'bg-amber-50 text-amber-700',
        ],
    ];

    $impactMetrics = [
        ['label' => __('Sesi Ilmu Disokong'), 'value' => $dawahImpactSummary['total_outcomes']],
        ['label' => __('Jumlah Jangkauan'), 'value' => $dawahImpactSummary['visits']],
        ['label' => __('Pendaftaran Dibantu'), 'value' => $dawahImpactSummary['signups']],
        ['label' => __('Pelayar Berbeza'), 'value' => $dawahImpactSummary['distinct_browsers']],
    ];

@endphp

<div class="min-h-screen w-full max-w-[100vw] overflow-x-hidden bg-[#f7f3ea] pb-0">
    <section class="relative max-w-[100vw] overflow-hidden border-b border-[#eadfca] bg-[#fbf8f1]">
        <div class="pointer-events-none absolute inset-0 opacity-[0.16]"
            style="background-image: url('{{ asset('images/pattern-bg.jpg') }}'); background-position: center; background-size: 420px;"></div>
        <div class="pointer-events-none absolute inset-0 bg-gradient-to-r from-[#fbf8f1] via-[#fbf8f1]/96 to-[#f6efe0]/90"></div>
        <div class="container relative mx-auto px-6 py-6 lg:px-12 lg:py-8">
            <div class="relative z-10 rounded-lg border border-[#eadfca] bg-white/95 p-4 shadow-xl shadow-amber-950/10 backdrop-blur">
                <div class="grid grid-cols-3 gap-2 md:grid-cols-3 xl:grid-cols-6">
                    @foreach($dashboardStats as $stat)
                        @php
                            $statHref = match ($loop->index) {
                                0 => route('events.index', ['saved_only' => 1]),
                                1 => route('events.index', ['going_only' => 1]),
                                2 => route('institutions.index', ['followed_only' => 1]),
                                3 => route('persons.index', ['followed_only' => 1]),
                                4 => route('references.index', ['followed_only' => 1]),
                                default => route('dashboard.dawah-impact'),
                            };
                        @endphp
                        <a href="{{ $statHref }}"
                            wire:navigate
                            class="group flex flex-col items-center justify-center gap-1.5 rounded-lg border border-[#eee4d3] bg-white px-2 py-3 text-center transition hover:-translate-y-0.5 hover:border-emerald-200 hover:shadow-lg hover:shadow-emerald-950/5">
                            <span class="flex size-8 items-center justify-center rounded-full {{ $stat['class'] }}">
                                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="{{ $stat['icon'] }}" />
                                </svg>
                            </span>
                            <span class="font-heading text-2xl font-bold leading-none text-[#0b2a42]">{{ number_format($stat['value']) }}</span>
                            <span class="text-xs font-medium leading-tight text-slate-500">{{ $stat['label'] }}</span>
                        </a>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    <main class="container mx-auto w-full px-6 py-8 lg:px-12">
        @if($shareRewardEnrollmentPrompt)
            <section data-testid="share-reward-enrollment-prompt" class="mb-7 rounded-lg border border-emerald-200 bg-emerald-50 p-5 shadow-sm">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="font-heading text-xl font-bold text-emerald-950">{{ __('Activity is waiting.') }}</h2>
                        <p class="mt-1 font-semibold text-emerald-950">{{ $shareRewardEnrollmentPrompt->title }}</p>
                        <p class="mt-1 text-sm text-emerald-900">{{ __('Enrol now to start earning on eligible activity.') }}</p>
                        <p class="mt-2 text-xs text-emerald-800">{{ __('Enrolling does not award points for past activity.') }}</p>
                    </div>
                    <button type="button" wire:click="enrollRewardProgram" wire:loading.attr="disabled" class="inline-flex shrink-0 items-center justify-center rounded-lg bg-emerald-800 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-emerald-900 disabled:cursor-wait disabled:opacity-60">
                        {{ __('Enrol now') }}
                    </button>
                </div>
            </section>
        @endif
        @include('share.claim-prompt')
        <div class="grid gap-7 xl:grid-cols-[minmax(0,1fr)_380px]">
            <div class="min-w-0 space-y-7">
                <section id="carian-saya" class="rounded-lg border border-[#eadfca] bg-white p-5 shadow-sm">
                    <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                        <div class="flex items-center gap-3">
                            <span class="flex size-9 items-center justify-center rounded-lg bg-emerald-50 text-emerald-700">
                                <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.2-5.2M10.8 18a7.2 7.2 0 100-14.4 7.2 7.2 0 000 14.4z" />
                                </svg>
                            </span>
                            <h2 class="font-heading text-2xl font-bold text-[#0b2a42]">{{ __('Carian Saya') }}</h2>
                        </div>
                        <a href="{{ route('saved-searches.index') }}" wire:navigate class="text-sm font-semibold text-emerald-800 transition hover:text-emerald-950">{{ __('Lihat semua carian') }}</a>
                    </div>

                    <div class="mt-5 space-y-2">
                        @forelse($recentSavedSearches as $savedSearch)
                            @php
                                $savedSearchUrl = route('events.index', array_filter(
                                    array_merge(
                                        ['search' => $savedSearch->query ?: null],
                                        is_array($savedSearch->filters) ? $savedSearch->filters : [],
                                        ['filters_panel' => 'closed']
                                    ),
                                    fn($v) => $v !== null && $v !== '' && $v !== []
                                ));
                            @endphp
                            <a href="{{ $savedSearchUrl }}" wire:navigate class="flex items-center gap-3 rounded-lg border border-[#eadfca] bg-white px-4 py-3 transition hover:border-emerald-200 hover:bg-emerald-50/40">
                                <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-emerald-50 text-emerald-700">
                                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.2-5.2M10.8 18a7.2 7.2 0 100-14.4 7.2 7.2 0 000 14.4z" />
                                    </svg>
                                </span>
                                <span class="min-w-0 flex-1 truncate text-sm font-medium text-slate-700">{{ $savedSearch->name }}</span>
                                <span class="hidden text-xs text-slate-500 md:inline">{{ __('Disimpan pada :date', ['date' => \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($savedSearch->created_at, 'j M Y')]) }}</span>
                                <svg class="size-5 shrink-0 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6h.01M12 12h.01M12 18h.01" />
                                </svg>
                            </a>
                        @empty
                            <div class="rounded-lg border border-dashed border-[#eadfca] bg-[#fbf8f1] p-6 text-center text-sm text-slate-600">
                                {{ __('Belum ada carian tersimpan. Simpan carian dari halaman majlis untuk pantau topik yang penting.') }}
                            </div>
                        @endforelse
                    </div>
                </section>

                <section class="rounded-lg border border-[#eadfca] bg-white p-5 shadow-sm">
                    <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                        <div class="flex items-start gap-3">
                            <span class="flex size-10 items-center justify-center rounded-lg bg-emerald-50 text-emerald-700">
                                <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 21s-7-4.4-7-10a4 4 0 017-2.6A4 4 0 0119 11c0 5.6-7 10-7 10z" />
                                </svg>
                            </span>
                            <div>
                                <h2 class="font-heading text-2xl font-bold text-[#0b2a42]">{{ __('Sumbangan Saya') }}</h2>
                                <p class="mt-2 max-w-xl text-xl leading-8 text-[#0b2a42]">
                                    {{ __('Anda telah membantu membuka jalan kepada ilmu sebanyak :count kali.', ['count' => number_format($dawahImpactSummary['total_outcomes'])]) }}
                                </p>
                            </div>
                        </div>
                        <a href="{{ route('dashboard.dawah-impact') }}" wire:navigate class="text-sm font-semibold text-emerald-800 transition hover:text-emerald-950">{{ __('Lihat laporan penuh') }}</a>
                    </div>

                    <div class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        @foreach($impactMetrics as $metric)
                            <div class="rounded-lg border border-[#eadfca] bg-[#fbf8f1] px-4 py-3 text-center">
                                <p class="font-heading text-2xl font-bold text-[#0b2a42]">{{ number_format($metric['value']) }}</p>
                                <p class="mt-1 text-xs font-medium text-slate-600">{{ $metric['label'] }}</p>
                            </div>
                        @endforeach
                    </div>

                    <div class="mt-5 rounded-lg border border-[#eadfca]">
                        <div class="flex border-b border-[#eadfca] text-sm font-semibold text-emerald-800">
                            <span class="border-b-2 border-emerald-700 px-4 py-3">{{ __('Aktiviti Terkini') }}</span>
                            <span class="px-4 py-3 text-slate-500">{{ __('Institusi Disokong') }}</span>
                        </div>
                        <div class="divide-y divide-[#eadfca]">
                            @foreach([
                                __('Lawatan daripada pautan yang anda kongsi'),
                                __('Pendaftaran yang dibantu oleh perkongsian'),
                                __('Tindakan bermanfaat daripada komuniti'),
                            ] as $activity)
                                <div class="flex items-center justify-between gap-4 px-4 py-3 text-sm">
                                    <span class="flex items-center gap-3 text-slate-700">
                                        <span class="size-2 rounded-full bg-emerald-700"></span>
                                        {{ $activity }}
                                    </span>
                                    <span class="rounded-lg bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-800">{{ __('Aktif') }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </section>
            </div>

            <aside class="min-w-0 space-y-7">
                <section class="relative overflow-hidden rounded-lg border border-emerald-900 bg-emerald-900 p-6 text-white shadow-sm">
                    <div class="pointer-events-none absolute inset-0 bg-repeat opacity-15"
                        style="background-image: url('{{ asset('images/pattern-bg.jpg') }}'); background-size: 260px;"></div>
                    <div class="pointer-events-none absolute inset-0 bg-gradient-to-br from-white/0 via-white/0 to-black/10"></div>
                    <div class="relative">
                    <h2 class="font-heading text-2xl font-bold">{{ __('Sebarkan ilmu, luaskan manfaat.') }}</h2>
                    <p class="mt-3 text-sm leading-6 text-emerald-50">{{ __('Jemput rakan dan keluarga untuk sertai Ilmu360 dan temui majlis ilmu yang bermanfaat.') }}</p>
                    <a href="{{ route('dashboard.dawah-impact') }}" wire:navigate class="mt-5 inline-flex w-full items-center justify-center gap-2 rounded-lg border border-emerald-200/50 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-white/10">
                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 7a3 3 0 11-6 0 3 3 0 016 0zM4 21a8 8 0 0116 0" />
                        </svg>
                        {{ __('Jemput Sekarang') }}
                    </a>
                    </div>
                </section>

                </section>
            </aside>
        </div>
    </main>

</div>
