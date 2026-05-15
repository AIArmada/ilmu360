@php
    $myRequests = $this->myRequests;
    $myUpdateRequests = $this->myUpdateRequests;
    $submittedEvents = $this->submittedEvents;
    $myReports = $this->myReports;
    $activeTab = $this->activeTab;
    $statusClass = static fn (string $status): string => match ($status) {
        'approved' => 'bg-emerald-100 text-emerald-700',
        'rejected' => 'bg-rose-100 text-rose-700',
        'cancelled' => 'bg-slate-200 text-slate-700',
        'open' => 'bg-amber-100 text-amber-700',
        'triaged' => 'bg-sky-100 text-sky-700',
        'resolved' => 'bg-emerald-100 text-emerald-700',
        'dismissed' => 'bg-slate-200 text-slate-700',
        'needs_changes' => 'bg-amber-100 text-amber-700',
        'draft' => 'bg-slate-100 text-slate-700',
        default => 'bg-amber-100 text-amber-700',
    };
    $statusLabel = static fn (string $status): string => str($status)->headline()->toString();
    $requestTypeLabel = static fn ($request): string => str($request->type->value)->headline().' · '.str($request->subject_type->value)->headline();
    $eventStatusLabel = static fn (object|string $status): string => method_exists($status, 'getLabel')
        ? $status->getLabel()
        : str(class_basename($status))->headline()->toString();
    $reportEntityMetadata = app(\App\Actions\Reports\ResolveReportEntityMetadataAction::class);
    $reportCategoryOptions = app(\App\Actions\Reports\ResolveReportCategoryOptionsAction::class);
@endphp

@section('title', __('My Contributions') . ' - ' . config('app.name'))

@include('partials.filament-assets', [
    'scripts' => ['filament/support', 'filament/schemas', 'filament/forms'],
])

<div
    class="min-h-screen bg-slate-50 pb-28"
    data-active-tab="{{ $activeTab }}"
    x-data="{ activeTab: @js($activeTab) }"
>
    {{-- Page header --}}
    <div class="relative overflow-hidden border-b border-slate-200 bg-gradient-to-br from-slate-50 via-white to-emerald-50/30">
        <div class="pointer-events-none absolute inset-0 bg-[size:300px] opacity-[0.025]" style="background-image: url('{{ asset('images/pattern-bg.png') }}');"></div>
        <div class="relative mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.22em] text-emerald-600">{{ __('Sumbangan') }}</p>
                    <h1 class="mt-2 font-heading text-3xl font-bold text-slate-900">{{ __('My Contributions') }}</h1>
                    <p class="mt-2 max-w-xl text-sm leading-6 text-slate-500">{{ __('Track all your submissions, update requests, reports, and membership claims in one place.') }}</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('submit-event.create') }}" wire:navigate
                        class="inline-flex items-center gap-1.5 rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-emerald-700">
                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        {{ __('Submit Event') }}
                    </a>
                    <a href="{{ route('contributions.submit-institution') }}" wire:navigate
                        class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:bg-slate-50">
                        {{ __('Submit Institution') }}
                    </a>
                    <a href="{{ route('contributions.submit-speaker') }}" wire:navigate
                        class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:bg-slate-50">
                        {{ __('Submit Speaker') }}
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <div class="flex flex-col gap-8 lg:flex-row lg:items-start">

            {{-- ═══════════════════════════════════ --}}
            {{-- SIDEBAR                             --}}
            {{-- ═══════════════════════════════════ --}}
            <aside class="shrink-0">
                {{-- Mobile: horizontal scrollable tabs --}}
                <div class="flex w-full gap-1 overflow-x-auto rounded-2xl border border-slate-200 bg-white p-2 shadow-sm lg:hidden">
                    <button type="button" @click="activeTab = 'events'; $wire.$set('activeTab', 'events')"
                        :class="activeTab === 'events' ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100'"
                        class="inline-flex shrink-0 items-center gap-2 rounded-xl px-3 py-2 text-sm font-semibold transition">
                        {{ __('Events') }}
                        <span :class="activeTab === 'events' ? 'bg-white/20 text-white' : 'bg-slate-100 text-slate-600'"
                            class="rounded-full px-2 py-0.5 text-xs font-bold">{{ $submittedEvents->total() }}</span>
                    </button>
                    <button type="button" @click="activeTab = 'contributions'; $wire.$set('activeTab', 'contributions')"
                        :class="activeTab === 'contributions' ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100'"
                        class="inline-flex shrink-0 items-center gap-2 rounded-xl px-3 py-2 text-sm font-semibold transition">
                        {{ __('New Submissions') }}
                        <span :class="activeTab === 'contributions' ? 'bg-white/20 text-white' : 'bg-slate-100 text-slate-600'"
                            class="rounded-full px-2 py-0.5 text-xs font-bold">{{ $myRequests->total() }}</span>
                    </button>
                    <button type="button" @click="activeTab = 'updates'; $wire.$set('activeTab', 'updates')"
                        :class="activeTab === 'updates' ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100'"
                        class="inline-flex shrink-0 items-center gap-2 rounded-xl px-3 py-2 text-sm font-semibold transition">
                        {{ __('Updates') }}
                        <span :class="activeTab === 'updates' ? 'bg-white/20 text-white' : 'bg-slate-100 text-slate-600'"
                            class="rounded-full px-2 py-0.5 text-xs font-bold">{{ $myUpdateRequests->total() }}</span>
                    </button>
                    <button type="button" @click="activeTab = 'reports'; $wire.$set('activeTab', 'reports')"
                        :class="activeTab === 'reports' ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100'"
                        class="inline-flex shrink-0 items-center gap-2 rounded-xl px-3 py-2 text-sm font-semibold transition">
                        {{ __('Reports') }}
                        <span :class="activeTab === 'reports' ? 'bg-white/20 text-white' : 'bg-slate-100 text-slate-600'"
                            class="rounded-full px-2 py-0.5 text-xs font-bold">{{ $myReports->total() }}</span>
                    </button>
                    <button type="button" @click="activeTab = 'membership'; $wire.$set('activeTab', 'membership')"
                        :class="activeTab === 'membership' ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100'"
                        class="inline-flex shrink-0 items-center gap-2 rounded-xl px-3 py-2 text-sm font-semibold transition">
                        {{ __('Membership') }}
                    </button>
                </div>

                {{-- Desktop: vertical nav card --}}
                <div class="sticky top-6 hidden w-64 rounded-2xl border border-slate-200 bg-white shadow-sm lg:block">
                    <div class="border-b border-slate-100 px-4 py-4">
                        <p class="text-xs font-bold uppercase tracking-[0.18em] text-slate-400">{{ __('Sections') }}</p>
                    </div>
                    <nav class="space-y-0.5 p-2">
                        {{-- Events --}}
                        <button type="button" @click="activeTab = 'events'; $wire.$set('activeTab', 'events')" class="group w-full rounded-xl px-3 py-3 text-left transition"
                            :class="activeTab === 'events' ? 'bg-slate-900 text-white' : 'text-slate-700 hover:bg-slate-50'">
                            <div class="flex items-center justify-between gap-2">
                                <div class="flex items-center gap-3">
                                    <div class="flex size-7 shrink-0 items-center justify-center rounded-lg transition"
                                        :class="activeTab === 'events' ? 'bg-white/15' : 'bg-emerald-100 group-hover:bg-emerald-200'">
                                        <svg class="size-3.5" :class="activeTab === 'events' ? 'text-white' : 'text-emerald-600'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 9v7.5" />
                                        </svg>
                                    </div>
                                    <span class="text-sm font-semibold">{{ __('Event Submissions') }}</span>
                                </div>
                                <span class="rounded-full px-2 py-0.5 text-xs font-bold transition"
                                    :class="activeTab === 'events' ? 'bg-white/20 text-white' : 'bg-slate-100 text-slate-600'">
                                    {{ $submittedEvents->total() }}
                                </span>
                            </div>
                        </button>

                        {{-- Contribution Requests --}}
                        <button type="button" @click="activeTab = 'contributions'; $wire.$set('activeTab', 'contributions')" class="group w-full rounded-xl px-3 py-3 text-left transition"
                            :class="activeTab === 'contributions' ? 'bg-slate-900 text-white' : 'text-slate-700 hover:bg-slate-50'">
                            <div class="flex items-center justify-between gap-2">
                                <div class="flex items-center gap-3">
                                    <div class="flex size-7 shrink-0 items-center justify-center rounded-lg transition"
                                        :class="activeTab === 'contributions' ? 'bg-white/15' : 'bg-sky-100 group-hover:bg-sky-200'">
                                        <svg class="size-3.5" :class="activeTab === 'contributions' ? 'text-white' : 'text-sky-600'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v6m3-3H9m12 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                                        </svg>
                                    </div>
                                    <span class="text-sm font-semibold">{{ __('New Submissions') }}</span>
                                </div>
                                <span class="rounded-full px-2 py-0.5 text-xs font-bold transition"
                                    :class="activeTab === 'contributions' ? 'bg-white/20 text-white' : 'bg-slate-100 text-slate-600'">
                                    {{ $myRequests->total() }}
                                </span>
                            </div>
                        </button>

                        {{-- Update Submissions --}}
                        <button type="button" @click="activeTab = 'updates'; $wire.$set('activeTab', 'updates')" class="group w-full rounded-xl px-3 py-3 text-left transition"
                            :class="activeTab === 'updates' ? 'bg-slate-900 text-white' : 'text-slate-700 hover:bg-slate-50'">
                            <div class="flex items-center justify-between gap-2">
                                <div class="flex items-center gap-3">
                                    <div class="flex size-7 shrink-0 items-center justify-center rounded-lg transition"
                                        :class="activeTab === 'updates' ? 'bg-white/15' : 'bg-amber-100 group-hover:bg-amber-200'">
                                        <svg class="size-3.5" :class="activeTab === 'updates' ? 'text-white' : 'text-amber-600'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125" />
                                        </svg>
                                    </div>
                                    <span class="text-sm font-semibold">{{ __('Update Submissions') }}</span>
                                </div>
                                <span class="rounded-full px-2 py-0.5 text-xs font-bold transition"
                                    :class="activeTab === 'updates' ? 'bg-white/20 text-white' : 'bg-slate-100 text-slate-600'">
                                    {{ $myUpdateRequests->total() }}
                                </span>
                            </div>
                        </button>

                        {{-- Reports --}}
                        <button type="button" @click="activeTab = 'reports'; $wire.$set('activeTab', 'reports')" class="group w-full rounded-xl px-3 py-3 text-left transition"
                            :class="activeTab === 'reports' ? 'bg-slate-900 text-white' : 'text-slate-700 hover:bg-slate-50'">
                            <div class="flex items-center justify-between gap-2">
                                <div class="flex items-center gap-3">
                                    <div class="flex size-7 shrink-0 items-center justify-center rounded-lg transition"
                                        :class="activeTab === 'reports' ? 'bg-white/15' : 'bg-rose-100 group-hover:bg-rose-200'">
                                        <svg class="size-3.5" :class="activeTab === 'reports' ? 'text-white' : 'text-rose-600'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 3v1.5M3 21v-6m0 0 2.77-.693a9 9 0 0 1 6.208.682l.108.054a9 9 0 0 0 6.086.71l3.114-.732a48.524 48.524 0 0 1-.005-10.499l-3.11.732a9 9 0 0 1-6.085-.711l-.108-.054a9 9 0 0 0-6.208-.682L3 4.5M3 15V4.5" />
                                        </svg>
                                    </div>
                                    <span class="text-sm font-semibold">{{ __('Report Submissions') }}</span>
                                </div>
                                <span class="rounded-full px-2 py-0.5 text-xs font-bold transition"
                                    :class="activeTab === 'reports' ? 'bg-white/20 text-white' : 'bg-slate-100 text-slate-600'">
                                    {{ $myReports->total() }}
                                </span>
                            </div>
                        </button>

                        {{-- Membership --}}
                        <button type="button" @click="activeTab = 'membership'; $wire.$set('activeTab', 'membership')" class="group w-full rounded-xl px-3 py-3 text-left transition"
                            :class="activeTab === 'membership' ? 'bg-slate-900 text-white' : 'text-slate-700 hover:bg-slate-50'">
                            <div class="flex items-center gap-3">
                                <div class="flex size-7 shrink-0 items-center justify-center rounded-lg transition"
                                    :class="activeTab === 'membership' ? 'bg-white/15' : 'bg-violet-100 group-hover:bg-violet-200'">
                                    <svg class="size-3.5" :class="activeTab === 'membership' ? 'text-white' : 'text-violet-600'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
                                    </svg>
                                </div>
                                <span class="text-sm font-semibold">{{ __('Membership Claims') }}</span>
                            </div>
                        </button>
                    </nav>

                    {{-- Quick links --}}
                    <div class="border-t border-slate-100 p-3">
                        <p class="mb-2 px-1 text-xs font-bold uppercase tracking-[0.18em] text-slate-400">{{ __('Quick Links') }}</p>
                        <a href="{{ route('membership-claims.index') }}" wire:navigate
                            class="flex w-full items-center gap-2 rounded-xl px-3 py-2.5 text-sm font-medium text-slate-600 transition hover:bg-slate-50 hover:text-slate-900">
                            <svg class="size-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25Z" />
                            </svg>
                            {{ __('View All Claims') }}
                        </a>
                    </div>
                </div>
            </aside>

            {{-- ═══════════════════════════════════ --}}
            {{-- MAIN CONTENT                        --}}
            {{-- ═══════════════════════════════════ --}}
            <div class="min-w-0 flex-1">

                {{-- ── Event Submissions ── --}}
                <section x-show="activeTab === 'events'" x-cloak>
                    <div class="mb-5 flex items-start justify-between gap-3">
                        <div>
                            <h2 class="font-heading text-xl font-bold text-slate-900">{{ __('Event Submissions') }}</h2>
                            <p class="mt-1 text-sm text-slate-500">{{ __('When you submit events, they will appear here with status and related details.') }}</p>
                        </div>
                        <span class="mt-1 shrink-0 rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">{{ $submittedEvents->total() }}</span>
                    </div>

                    <div class="space-y-3">
                        @forelse($submittedEvents as $submission)
                            @php
                                $event = $submission->event;
                                $eventStatus = str(class_basename($event->status))->snake()->toString();
                                $eventDetails = $this->eventSubmissionDetails($submission);
                                $eventUrl = route('events.show', $event);
                            @endphp

                            <a href="{{ $eventUrl }}" wire:navigate
                                class="block rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:border-emerald-300 hover:bg-emerald-50/40 hover:shadow-md">
                                <article>
                                    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                        <div class="space-y-2">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <span class="rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-semibold text-emerald-700">
                                                    {{ __('Event') }}
                                                </span>
                                                <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $statusClass($eventStatus) }}">
                                                    {{ $eventStatusLabel($event->status) }}
                                                </span>
                                            </div>

                                            <p class="text-base font-semibold text-slate-900">{{ $event->title }}</p>

                                            <p class="text-xs text-slate-500">{{ __('Submitted :date', ['date' => $submission->created_at?->diffForHumans()]) }}</p>

                                            @if(filled($submission->notes))
                                                <p class="text-sm leading-6 text-slate-700">{{ $submission->notes }}</p>
                                            @endif

                                            @if($eventDetails !== [])
                                                <div class="flex flex-wrap gap-2 pt-1">
                                                    @foreach($eventDetails as $detail)
                                                        <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-600">{{ $detail }}</span>
                                                    @endforeach
                                                </div>
                                            @endif
                                        </div>
                                        <svg class="size-4 shrink-0 text-slate-400 sm:mt-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                                        </svg>
                                    </div>
                                </article>
                            </a>
                        @empty
                            <div class="rounded-2xl border border-dashed border-slate-200 bg-white px-5 py-16 text-center shadow-sm">
                                <div class="mx-auto flex size-12 items-center justify-center rounded-full bg-emerald-100">
                                    <svg class="size-6 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25" />
                                    </svg>
                                </div>
                                <p class="mt-4 text-base font-semibold text-slate-700">{{ __('No event submissions yet.') }}</p>
                                <p class="mt-1 text-sm text-slate-500">{{ __('Submit your first event to get started.') }}</p>
                                <a href="{{ route('submit-event.create') }}" wire:navigate
                                    class="mt-5 inline-flex items-center gap-1.5 rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-emerald-700">
                                    {{ __('Submit Event') }}
                                </a>
                            </div>
                        @endforelse
                    </div>

                    @if($submittedEvents->hasPages())
                        <div class="mt-6">{{ $submittedEvents->links() }}</div>
                    @endif
                </section>

                {{-- ── New Submissions (Contribution Requests) ── --}}
                <section x-show="activeTab === 'contributions'" x-cloak>
                    <div class="mb-5 flex items-start justify-between gap-3">
                        <div>
                            <h2 class="font-heading text-xl font-bold text-slate-900">{{ __('New Submissions') }}</h2>
                            <p class="mt-1 text-sm text-slate-500">{{ __('Requests for new institutions and speakers.') }}</p>
                        </div>
                        <span class="mt-1 shrink-0 rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">{{ $myRequests->total() }}</span>
                    </div>

                    <div class="mb-5 flex flex-wrap gap-2">
                        <a href="{{ route('contributions.submit-institution') }}" wire:navigate
                            class="inline-flex items-center gap-1.5 rounded-xl border border-sky-200 bg-sky-50 px-3 py-2 text-sm font-semibold text-sky-700 transition hover:bg-sky-100">
                            <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                            {{ __('Submit Institution') }}
                        </a>
                        <a href="{{ route('contributions.submit-speaker') }}" wire:navigate
                            class="inline-flex items-center gap-1.5 rounded-xl border border-sky-200 bg-sky-50 px-3 py-2 text-sm font-semibold text-sky-700 transition hover:bg-sky-100">
                            <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                            {{ __('Submit Speaker') }}
                        </a>
                    </div>

                    <div class="space-y-3">
                        @forelse($myRequests as $request)
                            <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                    <div class="space-y-2">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $statusClass((string) $request->status->value) }}">
                                                {{ $statusLabel($request->status->value) }}
                                            </span>
                                            <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600">
                                                {{ $requestTypeLabel($request) }}
                                            </span>
                                        </div>

                                        <p class="text-base font-semibold text-slate-900">
                                            {{ \App\Filament\Resources\ContributionRequests\Support\ContributionRequestPresenter::entityTitle($request) }}
                                        </p>

                                        <p class="text-xs text-slate-500">{{ __('Submitted :date', ['date' => $request->created_at?->diffForHumans()]) }}</p>

                                        @if(filled($request->proposer_note))
                                            <p class="text-sm leading-6 text-slate-700">{{ $request->proposer_note }}</p>
                                        @endif

                                        @if(filled($request->reviewer_note))
                                            <p class="rounded-xl bg-slate-50 px-4 py-3 text-sm leading-6 text-slate-700">{{ $request->reviewer_note }}</p>
                                        @endif
                                    </div>

                                    @if($request->status === \App\Enums\ContributionRequestStatus::Pending)
                                        <button type="button" wire:click="cancel('{{ $request->id }}')"
                                            class="inline-flex shrink-0 items-center justify-center rounded-xl border border-rose-200 px-4 py-2 text-sm font-semibold text-rose-700 transition hover:bg-rose-50">
                                            {{ __('Cancel') }}
                                        </button>
                                    @endif
                                </div>
                            </article>
                        @empty
                            <div class="rounded-2xl border border-dashed border-slate-200 bg-white px-5 py-16 text-center shadow-sm">
                                <div class="mx-auto flex size-12 items-center justify-center rounded-full bg-sky-100">
                                    <svg class="size-6 text-sky-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v6m3-3H9m12 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                                    </svg>
                                </div>
                                <p class="mt-4 text-base font-semibold text-slate-700">{{ __('No contribution requests yet.') }}</p>
                                <p class="mt-1 text-sm text-slate-500">{{ __('Submit a new institution or speaker to contribute.') }}</p>
                            </div>
                        @endforelse
                    </div>

                    @if($myRequests->hasPages())
                        <div class="mt-6">{{ $myRequests->links() }}</div>
                    @endif
                </section>

                {{-- ── Update Submissions ── --}}
                <section x-show="activeTab === 'updates'" x-cloak>
                    <div class="mb-5 flex items-start justify-between gap-3">
                        <div>
                            <h2 class="font-heading text-xl font-bold text-slate-900">{{ __('Update Submissions') }}</h2>
                            <p class="mt-1 text-sm text-slate-500">{{ __('Updates you submit here will appear with their status and review notes.') }}</p>
                        </div>
                        <span class="mt-1 shrink-0 rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">{{ $myUpdateRequests->total() }}</span>
                    </div>

                    <div class="space-y-3">
                        @forelse($myUpdateRequests as $request)
                            <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                    <div class="space-y-2">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $statusClass((string) $request->status->value) }}">
                                                {{ $statusLabel($request->status->value) }}
                                            </span>
                                            <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600">
                                                {{ $requestTypeLabel($request) }}
                                            </span>
                                        </div>

                                        <p class="text-base font-semibold text-slate-900">
                                            {{ \App\Filament\Resources\ContributionRequests\Support\ContributionRequestPresenter::entityTitle($request) }}
                                        </p>

                                        <p class="text-xs text-slate-500">{{ __('Submitted :date', ['date' => $request->created_at?->diffForHumans()]) }}</p>

                                        @if(filled($request->proposer_note))
                                            <p class="text-sm leading-6 text-slate-700">{{ $request->proposer_note }}</p>
                                        @endif

                                        @if(filled($request->reviewer_note))
                                            <p class="rounded-xl bg-slate-50 px-4 py-3 text-sm leading-6 text-slate-700">{{ $request->reviewer_note }}</p>
                                        @endif
                                    </div>

                                    @if($request->status === \App\Enums\ContributionRequestStatus::Pending)
                                        <button type="button" wire:click="cancel('{{ $request->id }}')"
                                            class="inline-flex shrink-0 items-center justify-center rounded-xl border border-rose-200 px-4 py-2 text-sm font-semibold text-rose-700 transition hover:bg-rose-50">
                                            {{ __('Cancel') }}
                                        </button>
                                    @endif
                                </div>
                            </article>
                        @empty
                            <div class="rounded-2xl border border-dashed border-slate-200 bg-white px-5 py-16 text-center shadow-sm">
                                <div class="mx-auto flex size-12 items-center justify-center rounded-full bg-amber-100">
                                    <svg class="size-6 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z" />
                                    </svg>
                                </div>
                                <p class="mt-4 text-base font-semibold text-slate-700">{{ __('No update submissions yet.') }}</p>
                                <p class="mt-1 text-sm text-slate-500">{{ __('Find a record with incorrect info and suggest a fix.') }}</p>
                            </div>
                        @endforelse
                    </div>

                    @if($myUpdateRequests->hasPages())
                        <div class="mt-6">{{ $myUpdateRequests->links() }}</div>
                    @endif
                </section>

                {{-- ── Report Submissions ── --}}
                <section x-show="activeTab === 'reports'" x-cloak>
                    <div class="mb-5 flex items-start justify-between gap-3">
                        <div>
                            <h2 class="font-heading text-xl font-bold text-slate-900">{{ __('Report Submissions') }}</h2>
                            <p class="mt-1 text-sm text-slate-500">{{ __('Reports you submit here will appear with their status and review notes.') }}</p>
                        </div>
                        <span class="mt-1 shrink-0 rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">{{ $myReports->total() }}</span>
                    </div>

                    <div class="space-y-3">
                        @forelse($myReports as $report)
                            @php
                                $reportEntityLabel = $reportEntityMetadata->handle($report->entity_type)['label'];
                                $reportCategoryLabel = $reportCategoryOptions->handle($report->entity_type)[$report->category] ?? str($report->category)->headline()->toString();
                                $reportTitle = \App\Filament\Resources\Reports\Support\ReportPresenter::entityTitle($report);
                            @endphp

                            <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                                <div class="space-y-2">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="rounded-full bg-rose-100 px-2.5 py-1 text-xs font-semibold text-rose-700">{{ __('Report') }}</span>
                                        <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $statusClass((string) $report->status) }}">
                                            {{ $statusLabel($report->status) }}
                                        </span>
                                        <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600">{{ $reportEntityLabel }}</span>
                                    </div>

                                    <p class="text-base font-semibold text-slate-900">{{ $reportTitle }}</p>

                                    <p class="text-xs text-slate-500">{{ __('Submitted :date', ['date' => $report->created_at?->diffForHumans()]) }}</p>

                                    <p class="text-sm leading-6 text-slate-700">{{ $reportCategoryLabel }}</p>

                                    @if(filled($report->description))
                                        <p class="text-sm leading-6 text-slate-600">{{ $report->description }}</p>
                                    @endif

                                    @if(filled($report->resolution_note))
                                        <p class="rounded-xl bg-slate-50 px-4 py-3 text-sm leading-6 text-slate-700">{{ $report->resolution_note }}</p>
                                    @endif
                                </div>
                            </article>
                        @empty
                            <div class="rounded-2xl border border-dashed border-slate-200 bg-white px-5 py-16 text-center shadow-sm">
                                <div class="mx-auto flex size-12 items-center justify-center rounded-full bg-rose-100">
                                    <svg class="size-6 text-rose-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 3v1.5M3 21v-6m0 0 2.77-.693a9 9 0 0 1 6.208.682l.108.054a9 9 0 0 0 6.086.71l3.114-.732a48.524 48.524 0 0 1-.005-10.499l-3.11.732a9 9 0 0 1-6.085-.711l-.108-.054a9 9 0 0 0-6.208-.682L3 4.5M3 15V4.5" />
                                    </svg>
                                </div>
                                <p class="mt-4 text-base font-semibold text-slate-700">{{ __('No report submissions yet.') }}</p>
                                <p class="mt-1 text-sm text-slate-500">{{ __('Spot something wrong? Help us keep the platform accurate.') }}</p>
                            </div>
                        @endforelse
                    </div>

                    @if($myReports->hasPages())
                        <div class="mt-6">{{ $myReports->links() }}</div>
                    @endif
                </section>

                {{-- ── Membership Claims ── --}}
                <section x-show="activeTab === 'membership'" x-cloak>
                    <div class="mb-5 flex items-start justify-between gap-3">
                        <div>
                            <h2 class="font-heading text-xl font-bold text-slate-900">{{ __('Membership Claims') }}</h2>
                            <p class="mt-1 text-sm text-slate-500">{{ __('Claim management access to institutions or speaker profiles you represent.') }}</p>
                        </div>
                        <a href="{{ route('membership-claims.index') }}" wire:navigate
                            class="inline-flex shrink-0 items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:bg-slate-50">
                            {{ __('View All Claims') }}
                        </a>
                    </div>

                    <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                        <p class="mb-1 text-xs font-bold uppercase tracking-[0.22em] text-violet-600">{{ __('Institution & Speaker Management') }}</p>
                        <p class="mb-6 text-sm text-slate-500">{{ __('Select a record type below and find the institution or speaker you represent to begin your claim.') }}</p>

                        <form wire:submit="startMembershipClaim" class="space-y-6">
                            {{ $this->form }}

                            <div class="flex flex-wrap items-center gap-3">
                                <button type="submit"
                                    class="inline-flex items-center justify-center rounded-xl bg-emerald-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-emerald-700">
                                    {{ __('Continue to Claim Form') }}
                                </button>
                            </div>
                        </form>
                    </div>
                </section>

            </div>{{-- /main --}}
        </div>{{-- /flex --}}
    </div>{{-- /container --}}
</div>
