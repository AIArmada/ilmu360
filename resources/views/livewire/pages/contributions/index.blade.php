@php
    $activeTab = $this->activeTab;
    $metrics = $this->summaryMetrics;
    $viewer = $this->viewerContext;
    $blocked = $viewer['is_feedback_blocked'];

    $pageShell = 'mx-auto max-w-6xl px-4 sm:px-6 lg:px-8';
    $cardClass = 'rounded-3xl border border-[#e5ded8] bg-white shadow-[0_1px_2px_rgba(45,44,43,0.05),0_6px_20px_rgba(45,44,43,0.06)]';
    $pillPrimary = 'inline-flex min-h-11 items-center justify-center gap-2 rounded-full bg-[#047857] px-5 text-sm font-semibold text-white transition-colors hover:bg-[#065f46] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#047857] active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-50';
    $pillSecondary = 'inline-flex min-h-11 items-center justify-center gap-2 rounded-full border border-[#8c867e]/60 bg-white px-4 text-sm font-semibold text-[#17191c] transition-colors hover:border-[#047857] hover:text-[#047857] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#047857] active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-50';
    $pillEmeraldOutline = 'inline-flex min-h-11 items-center justify-center gap-2 rounded-full border border-[#047857] bg-white px-4 text-sm font-semibold text-[#065f46] transition-colors hover:bg-emerald-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#047857] active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-50';
    $inputClass = 'min-h-11 w-full rounded-full border border-[#8c867e]/60 bg-white px-4 text-sm text-[#17191c] placeholder:text-[#8c867e] transition-colors hover:border-[#8c867e] focus:border-[#047857] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#047857]';
    $toneClasses = [
        'emerald' => 'bg-emerald-50 text-[#065f46] ring-1 ring-inset ring-emerald-200',
        'amber' => 'bg-amber-50 text-amber-800 ring-1 ring-inset ring-amber-200',
        'sky' => 'bg-sky-50 text-sky-800 ring-1 ring-inset ring-sky-200',
        'rose' => 'bg-rose-50 text-rose-700 ring-1 ring-inset ring-rose-200',
        'neutral' => 'bg-[#f4f1ec] text-[#6b645e] ring-1 ring-inset ring-[#e5ded8]',
    ];
    $toneBadge = static fn (string $tone): string => 'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold '
        .($toneClasses[$tone] ?? $toneClasses['neutral']);
    $tabs = [
        'overview' => ['label' => __('Overview'), 'count' => null],
        'events' => ['label' => __('Events'), 'count' => $metrics['tabs']['events']],
        'directory' => ['label' => __('New Directory'), 'count' => $metrics['tabs']['directory']],
        'updates' => ['label' => __('Updates'), 'count' => $metrics['tabs']['updates']],
        'reports' => ['label' => __('Reports'), 'count' => $metrics['tabs']['reports']],
        'membership' => ['label' => __('Membership'), 'count' => $metrics['tabs']['membership']],
        'feedback' => ['label' => __('App Feedback'), 'count' => $metrics['tabs']['feedback']],
    ];
    $filtersActive = $this->statusFilter !== 'all' || $this->subjectFilter !== 'all' || $this->search !== '';
@endphp

@section('title', __('My Contributions') . ' - ' . config('app.name'))

<div data-art-direction="living-majlis" data-active-tab="{{ $activeTab }}"
    class="living-majlis-field min-h-screen pb-24 text-[#2d2c2b]">

    {{-- Hero header --}}
    <header class="relative overflow-hidden border-b border-[#e5ded8] bg-gradient-to-b from-[#fdfbf7] via-[#faf7f1] to-[#f9f4f2]">
        <div class="pointer-events-none absolute inset-0 opacity-[0.03]" aria-hidden="true"
            style="background-image: url('{{ asset('images/pattern-bg.jpg') }}'); background-size: 320px;"></div>
        <div class="{{ $pageShell }} relative pb-8 pt-8 sm:pb-10 sm:pt-10">
            <div class="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
                <div class="max-w-2xl">
                    <p class="text-sm font-semibold text-[#047857]">{{ __('Contributions Hub') }}</p>
                    <h1 class="mt-2 font-heading text-3xl font-semibold text-[#17191c] text-balance sm:text-4xl">
                        {{ __('My Contributions') }}
                    </h1>
                    <p class="mt-3 text-[17px] leading-7 text-[#3f3b37]">
                        {{ __('Track every majlis, directory record, update suggestion, report, membership claim and app feedback in one calm place.') }}
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <a href="{{ route('submit-event.create') }}" wire:navigate
                        data-signal-event="submission.event_start_clicked"
                        data-signal-category="submission"
                        data-signal-component="contributions_hub"
                        data-signal-control="add_event"
                        class="{{ $pillPrimary }}">
                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                            <path stroke-linecap="round" d="M12 6v12M6 12h12" />
                        </svg>
                        {{ __('Add Event') }}
                    </a>
                    <a href="{{ route('contributions.submit-institution') }}" wire:navigate class="{{ $pillSecondary }}">
                        {{ __('Institution') }}
                    </a>
                    <a href="{{ route('contributions.submit-person') }}" wire:navigate class="{{ $pillSecondary }}">
                        {{ __('Speaker') }}
                    </a>
                    <a href="{{ route('contributions.submit-reference') }}" wire:navigate class="{{ $pillSecondary }}">
                        {{ __('Reference') }}
                    </a>
                </div>
            </div>
        </div>
    </header>

    <div class="{{ $pageShell }} relative">
        {{-- Conditional context banners --}}
        @if($blocked)
            <div class="mt-6 rounded-3xl border border-amber-200 bg-amber-50/90 p-5 sm:p-6" role="status">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div class="flex gap-3">
                        <span class="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-800">
                            <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m0 3.75h.008M10.34 3.94l-8.02 13.9A1.7 1.7 0 0 0 3.79 20.4h16.42a1.7 1.7 0 0 0 1.47-2.56L13.66 3.94a1.87 1.87 0 0 0-3.32 0Z" />
                            </svg>
                        </span>
                        <div>
                            <p class="text-sm font-semibold text-amber-900">{{ __('Directory feedback is paused for your account') }}</p>
                            <p class="mt-1 text-sm leading-6 text-amber-900/90">{{ $viewer['feedback_ban_message'] }}</p>
                        </div>
                    </div>
                    <a href="{{ route('contact') }}" wire:navigate class="{{ $pillSecondary }} shrink-0">
                        {{ __('Contact Us') }}
                    </a>
                </div>
            </div>
        @endif

        @if($viewer['has_any_workspace_access'])
            <div class="mt-6 flex flex-col gap-3 rounded-3xl border border-emerald-100 bg-emerald-50/70 p-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                <div>
                    <p class="text-sm font-semibold text-[#065f46]">{{ __('Managed workspace') }}</p>
                    <p class="mt-0.5 text-sm text-[#3f3b37]">{{ __('You are an organizer here — jump straight to your workspace tools.') }}</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <a href="{{ route('dashboard.events.create-advanced') }}" wire:navigate class="{{ $pillEmeraldOutline }}">
                        {{ __('Advanced Event Creator') }}
                    </a>
                    @if($viewer['manages_institution'])
                        <a href="{{ route('dashboard.institutions') }}" wire:navigate class="{{ $pillEmeraldOutline }}">
                            {{ __('Manage Institution') }}
                        </a>
                    @endif
                </div>
            </div>
        @endif

        {{-- Live contribution pulse --}}
        <dl class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="rounded-3xl border border-[#e5ded8] bg-white/85 px-4 py-4">
                <dt class="text-xs font-semibold text-[#6b645e]">{{ __('Total Contributions') }}</dt>
                <dd class="mt-1 font-heading text-2xl font-semibold tabular-nums text-[#17191c]">{{ $metrics['totals']['total'] }}</dd>
            </div>
            <div class="rounded-3xl border border-[#e5ded8] bg-white/85 px-4 py-4">
                <dt class="text-xs font-semibold text-[#6b645e]">{{ __('In Review') }}</dt>
                <dd class="mt-1 font-heading text-2xl font-semibold tabular-nums text-[#17191c]">{{ $metrics['totals']['review'] }}</dd>
            </div>
            <div class="rounded-3xl border border-[#e5ded8] bg-white/85 px-4 py-4">
                <dt class="text-xs font-semibold text-[#6b645e]">{{ __('Published / Approved') }}</dt>
                <dd class="mt-1 font-heading text-2xl font-semibold tabular-nums text-[#17191c]">{{ $metrics['totals']['approved'] }}</dd>
            </div>
            <div class="rounded-3xl border border-[#e5ded8] bg-white/85 px-4 py-4">
                <dt class="text-xs font-semibold text-[#6b645e]">{{ __('Needs Attention / Closed') }}</dt>
                <dd class="mt-1 font-heading text-2xl font-semibold tabular-nums text-[#17191c]">{{ $metrics['totals']['attention'] }}</dd>
            </div>
        </dl>

        {{-- Tab rail --}}
        <nav class="mt-6" aria-label="{{ __('Contribution sections') }}">
            <div class="-mx-4 overflow-x-auto px-4 pb-1 sm:-mx-6 sm:px-6 lg:mx-0 lg:px-0">
                <div class="flex w-max min-w-full snap-x snap-mandatory gap-2">
                    @foreach($tabs as $tabKey => $tab)
                        <button type="button"
                            wire:key="hub-tab-{{ $tabKey }}"
                            wire:click="selectTab('{{ $tabKey }}')"
                            wire:loading.attr="disabled"
                            wire:target="selectTab"
                            aria-current="{{ $activeTab === $tabKey ? 'true' : 'false' }}"
                            data-tab="{{ $tabKey }}"
                            @class([
                                'inline-flex min-h-11 shrink-0 snap-start items-center gap-2 rounded-full border px-4 text-sm transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#047857] active:scale-[0.98]',
                                'border-[#047857] bg-white font-bold text-[#065f46]' => $activeTab === $tabKey,
                                'border-[#8c867e]/50 bg-white/70 font-semibold text-[#3f3b37] hover:border-[#047857] hover:text-[#047857]' => $activeTab !== $tabKey,
                            ])>
                            @if($activeTab === $tabKey)
                                <svg class="size-4 text-[#047857]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                                </svg>
                            @endif
                            <span>{{ $tab['label'] }}</span>
                            @if($tab['count'] !== null)
                                <span @class([
                                    'rounded-full px-2 py-0.5 text-xs font-bold tabular-nums',
                                    'bg-emerald-50 text-[#065f46]' => $activeTab === $tabKey,
                                    'bg-[#f4f1ec] text-[#6b645e]' => $activeTab !== $tabKey,
                                ])>{{ $tab['count'] }}</span>
                            @endif
                        </button>
                    @endforeach
                </div>
            </div>
        </nav>

        {{-- Shared filter bar for the detail tabs --}}
        @if($activeTab !== 'overview')
            <section aria-label="{{ __('Filters') }}"
                class="mt-5 rounded-3xl border border-[#e5ded8] bg-white/85 p-4 sm:p-5">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                    <label class="relative block w-full lg:max-w-sm">
                        <span class="sr-only">{{ __('Search this list') }}</span>
                        <svg class="pointer-events-none absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-[#8c867e]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.35-4.35M17 10.5a6.5 6.5 0 1 1-13 0 6.5 6.5 0 0 1 13 0Z" />
                        </svg>
                        <input type="search" wire:model.live.debounce.300ms="search"
                            placeholder="{{ __('Search this list') }}"
                            class="{{ $inputClass }} pl-10" />
                    </label>

                    <div class="flex flex-wrap items-center gap-2">
                        @foreach($this->statusFilterOptions as $statusValue => $statusLabel)
                            <button type="button" wire:key="status-chip-{{ $statusValue }}"
                                wire:click="$set('statusFilter', '{{ $statusValue }}')"
                                wire:loading.attr="disabled" wire:target="statusFilter"
                                aria-pressed="{{ $this->statusFilter === $statusValue ? 'true' : 'false' }}"
                                @class([
                                    'inline-flex min-h-11 items-center gap-1.5 rounded-full border px-3.5 text-sm transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#047857] active:scale-[0.98]',
                                    'border-[#047857] bg-white font-bold text-[#065f46]' => $this->statusFilter === $statusValue,
                                    'border-[#8c867e]/50 bg-white/70 font-semibold text-[#3f3b37] hover:border-[#047857] hover:text-[#047857]' => $this->statusFilter !== $statusValue,
                                ])>
                                @if($this->statusFilter === $statusValue)
                                    <svg class="size-3.5 text-[#047857]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                                    </svg>
                                @endif
                                {{ $statusLabel }}
                            </button>
                        @endforeach
                    </div>
                </div>

                @if(in_array($activeTab, ['directory', 'updates'], true))
                    <div class="mt-3 flex flex-wrap items-center gap-2 border-t border-[#f0e9e2] pt-3">
                        <span class="text-xs font-semibold text-[#6b645e]">{{ __('Record type') }}</span>
                        @foreach($this->subjectFilterOptions as $subjectValue => $subjectLabel)
                            <button type="button" wire:key="subject-chip-{{ $subjectValue }}"
                                wire:click="$set('subjectFilter', '{{ $subjectValue }}')"
                                wire:loading.attr="disabled" wire:target="subjectFilter"
                                aria-pressed="{{ $this->subjectFilter === $subjectValue ? 'true' : 'false' }}"
                                @class([
                                    'inline-flex min-h-11 items-center gap-1.5 rounded-full border px-3.5 text-sm transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#047857] active:scale-[0.98]',
                                    'border-[#047857] bg-white font-bold text-[#065f46]' => $this->subjectFilter === $subjectValue,
                                    'border-[#8c867e]/50 bg-white/70 font-semibold text-[#3f3b37] hover:border-[#047857] hover:text-[#047857]' => $this->subjectFilter !== $subjectValue,
                                ])>
                                {{ $subjectLabel }}
                            </button>
                        @endforeach
                    </div>
                @endif

                @if($filtersActive)
                    <div class="mt-3 flex items-center justify-between gap-3 border-t border-[#f0e9e2] pt-3">
                        <p class="text-xs text-[#6b645e]">{{ __('Filters are active.') }}</p>
                        <button type="button" wire:click="resetFilters" wire:loading.attr="disabled" wire:target="resetFilters"
                            class="inline-flex min-h-11 items-center gap-1.5 rounded-full px-3 text-sm font-semibold text-[#047857] underline-offset-4 transition-colors hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#047857] active:scale-[0.98]">
                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                            </svg>
                            {{ __('Reset filters') }}
                        </button>
                    </div>
                @endif
            </section>
        @endif

        {{-- Tab content --}}
        <div class="mt-6" wire:loading.class="opacity-60" wire:loading.attr="aria-busy"
            wire:target="selectTab,statusFilter,subjectFilter,search,resetFilters">
            @switch($activeTab)
                @case('events')
                    @include('livewire.pages.contributions.partials.events')
                @break

                @case('directory')
                    @include('livewire.pages.contributions.partials.directory')
                @break

                @case('updates')
                    @include('livewire.pages.contributions.partials.updates')
                @break

                @case('reports')
                    @include('livewire.pages.contributions.partials.reports')
                @break

                @case('membership')
                    @include('livewire.pages.contributions.partials.membership')
                @break

                @case('feedback')
                    @include('livewire.pages.contributions.partials.feedback')
                @break

                @default
                    @include('livewire.pages.contributions.partials.overview')
            @endswitch
        </div>
    </div>

    @include('livewire.pages.contributions.partials.detail-modal')
    @include('livewire.pages.contributions.partials.record-finder-modal')
</div>
