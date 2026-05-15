@section('title', __('Your Dawah Impact') . ' - ' . config('app.name'))

@php
    $summary = $this->impactSummary;
    $subjectSummaries = $this->subjectSummaries;
    $providerBreakdown = $this->providerBreakdown;
    $topSubjects = $this->topSubjects;
    $topLinks = $this->topLinks;
    $recentResponses = $this->recentResponses;
    $links = $this->links;
    $subjectTypeOptions = $this->subjectTypeOptions;
    $sortOptions = $this->sortOptions;
    $statusOptions = $this->statusOptions;
    $outcomeTypeOptions = $this->outcomeTypeOptions;
    $subjectBadgeClass = static fn (string $subjectType): string => match ($subjectType) {
        'event' => 'bg-emerald-100 text-emerald-700',
        'institution' => 'bg-sky-100 text-sky-700',
        'speaker' => 'bg-violet-100 text-violet-700',
        'series' => 'bg-indigo-100 text-indigo-700',
        'reference' => 'bg-amber-100 text-amber-700',
        'search' => 'bg-rose-100 text-rose-700',
        default => 'bg-slate-100 text-slate-700',
    };
    $outcomeLabel = static fn (string $type): string => match ($type) {
        'signup' => __('Signed up'),
        'event_registration' => __('Registered for an event'),
        'event_checkin' => __('Checked in to an event'),
        'event_submission' => __('Submitted an event'),
        'event_save' => __('Saved an event'),
        'event_going' => __('Planned to attend'),
        'institution_follow' => __('Followed an institution'),
        'speaker_follow' => __('Followed a speaker'),
        'series_follow' => __('Followed a series'),
        'reference_follow' => __('Followed a reference'),
        'saved_search_created' => __('Saved a search'),
        default => str($type)->replace('_', ' ')->headline()->toString(),
    };
@endphp

<div class="min-h-screen bg-slate-50 py-12 pb-32">
    <div class="container mx-auto px-6 lg:px-12">
        <div class="mx-auto max-w-7xl space-y-8">
            <section class="overflow-hidden rounded-[2rem] border border-slate-200/70 bg-gradient-to-br from-slate-950 via-emerald-950 to-slate-950 p-6 shadow-2xl shadow-emerald-950/10 md:p-8">
                <div class="grid gap-8 xl:grid-cols-[1.25fr,0.95fr] xl:items-end">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.22em] text-emerald-300">{{ __('Private Impact Dashboard') }}</p>
                        <h1 class="mt-3 font-heading text-3xl font-bold tracking-tight text-white md:text-4xl">
                            {{ __('See what happened after your sharing') }}
                        </h1>
                        <p class="mt-3 max-w-3xl text-sm leading-6 text-slate-300">
                            {{ __('Every shared page here stays private to you. Use it to understand how many people visited, responded, signed up, registered, and which channels produced the strongest follow-through after seeing what you passed along.') }}
                        </p>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        <div class="rounded-2xl border border-white/10 bg-white/10 p-4 backdrop-blur">
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-300">{{ __('Visits') }}</p>
                            <p class="mt-2 text-3xl font-black text-white">{{ number_format($summary['visits']) }}</p>
                            <p class="mt-2 text-xs text-slate-300">{{ __('Attributed visits to your shared pages.') }}</p>
                        </div>
                        <div class="rounded-2xl border border-white/10 bg-white/10 p-4 backdrop-blur">
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-300">{{ __('Unique Visitors') }}</p>
                            <p class="mt-2 text-3xl font-black text-white">{{ number_format($summary['unique_visitors']) }}</p>
                            <p class="mt-2 text-xs text-slate-300">{{ __('People who reached your links at least once.') }}</p>
                        </div>
                        <div class="rounded-2xl border border-white/10 bg-white/10 p-4 backdrop-blur">
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-300">{{ __('Signups') }}</p>
                            <p class="mt-2 text-3xl font-black text-white">{{ number_format($summary['signups']) }}</p>
                            <p class="mt-2 text-xs text-slate-300">{{ __('New accounts created after a share touch.') }}</p>
                        </div>
                        <div class="rounded-2xl border border-white/10 bg-white/10 p-4 backdrop-blur">
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-300">{{ __('Event Registrations') }}</p>
                            <p class="mt-2 text-3xl font-black text-white">{{ number_format($summary['event_registrations']) }}</p>
                            <p class="mt-2 text-xs text-slate-300">{{ __('Registrations credited back to your sharing.') }}</p>
                        </div>
                        <div class="rounded-2xl border border-white/10 bg-white/10 p-4 backdrop-blur">
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-300">{{ __('Event Check-ins') }}</p>
                            <p class="mt-2 text-3xl font-black text-white">{{ number_format($summary['event_checkins']) }}</p>
                            <p class="mt-2 text-xs text-slate-300">{{ __('Attendance confirmed after a shared visit.') }}</p>
                        </div>
                        <div class="rounded-2xl border border-white/10 bg-white/10 p-4 backdrop-blur">
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-300">{{ __('Event Submissions') }}</p>
                            <p class="mt-2 text-3xl font-black text-white">{{ number_format($summary['event_submissions']) }}</p>
                            <p class="mt-2 text-xs text-slate-300">{{ __('New event proposals started from your shared link.') }}</p>
                        </div>
                        <div class="rounded-2xl border border-white/10 bg-white/10 p-4 backdrop-blur">
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-300">{{ __('Outbound Shares') }}</p>
                            <p class="mt-2 text-3xl font-black text-white">{{ number_format($summary['outbound_shares']) }}</p>
                            <p class="mt-2 text-xs text-slate-300">{{ __('Provider button clicks from your tracked share surfaces.') }}</p>
                        </div>
                        <div class="rounded-2xl border border-white/10 bg-white/10 p-4 backdrop-blur">
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-300">{{ __('Total Responses') }}</p>
                            <p class="mt-2 text-3xl font-black text-white">{{ number_format($summary['total_outcomes']) }}</p>
                            <p class="mt-2 text-xs text-slate-300">{{ __('All attributed follow-up actions combined.') }}</p>
                        </div>
                    </div>
                </div>
            </section>

            @if($subjectSummaries->isNotEmpty())
                <section class="rounded-[1.75rem] border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[0.18em] text-slate-400">{{ __('By Shared Type') }}</p>
                            <h2 class="mt-1 font-heading text-2xl font-bold text-slate-900">{{ __('Where your sharing is reaching people') }}</h2>
                        </div>
                    </div>

                    <div class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        @foreach($subjectSummaries as $subject)
                            <article class="rounded-3xl border border-slate-200 bg-slate-50/80 p-5">
                                <div class="flex items-center justify-between gap-3">
                                    <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $subjectBadgeClass($subject['subject_type']) }}">
                                        {{ $subject['label'] }}
                                    </span>
                                    <span class="text-xs font-semibold text-slate-500">
                                        {{ trans_choice(':count link|:count links', $subject['links'], ['count' => $subject['links']]) }}
                                    </span>
                                </div>
                                <div class="mt-4 grid grid-cols-2 gap-3 xl:grid-cols-3">
                                    <div class="rounded-2xl bg-white p-3">
                                        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-400">{{ __('Visits') }}</p>
                                        <p class="mt-2 text-xl font-black text-slate-900">{{ number_format($subject['visits']) }}</p>
                                    </div>
                                    <div class="rounded-2xl bg-white p-3">
                                        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-400">{{ __('Responses') }}</p>
                                        <p class="mt-2 text-xl font-black text-slate-900">{{ number_format($subject['total_outcomes']) }}</p>
                                    </div>
                                    <div class="rounded-2xl bg-white p-3">
                                        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-400">{{ __('Signups') }}</p>
                                        <p class="mt-2 text-xl font-black text-slate-900">{{ number_format($subject['signups']) }}</p>
                                    </div>
                                    <div class="rounded-2xl bg-white p-3">
                                        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-400">{{ __('Registrations') }}</p>
                                        <p class="mt-2 text-xl font-black text-slate-900">{{ number_format($subject['event_registrations']) }}</p>
                                    </div>
                                    <div class="rounded-2xl bg-white p-3">
                                        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-400">{{ __('Check-ins') }}</p>
                                        <p class="mt-2 text-xl font-black text-slate-900">{{ number_format($subject['event_checkins']) }}</p>
                                    </div>
                                    <div class="rounded-2xl bg-white p-3">
                                        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-400">{{ __('Submissions') }}</p>
                                        <p class="mt-2 text-xl font-black text-slate-900">{{ number_format($subject['event_submissions']) }}</p>
                                    </div>
                                </div>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endif

            @if($providerBreakdown->isNotEmpty())
                <section class="rounded-[1.75rem] border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[0.18em] text-slate-400">{{ __('Channel Impact') }}</p>
                            <h2 class="mt-1 font-heading text-2xl font-bold text-slate-900">{{ __('Which sharing channels delivered results') }}</h2>
                            <p class="mt-2 text-sm text-slate-500">{{ __('Outbound shares show which provider buttons were used. Visits and responses show what actually came back from each channel.') }}</p>
                        </div>
                    </div>

                    <div class="mt-5 grid gap-4 lg:grid-cols-2 xl:grid-cols-4">
                        @foreach($providerBreakdown as $provider)
                            <article class="rounded-3xl border border-slate-200 bg-slate-50/80 p-5">
                                <div class="flex items-center justify-between gap-3">
                                    <h3 class="text-base font-bold text-slate-900">{{ $provider['label'] }}</h3>
                                    <span class="rounded-full bg-white px-3 py-1 text-xs font-semibold text-slate-600">
                                        {{ number_format($provider['outbound_shares']) }} {{ __('outbound') }}
                                    </span>
                                </div>

                                <div class="mt-4 grid grid-cols-2 gap-3">
                                    <div class="rounded-2xl bg-white p-3">
                                        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-400">{{ __('Visits') }}</p>
                                        <p class="mt-2 text-xl font-black text-slate-900">{{ number_format($provider['visits']) }}</p>
                                    </div>
                                    <div class="rounded-2xl bg-white p-3">
                                        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-400">{{ __('Responses') }}</p>
                                        <p class="mt-2 text-xl font-black text-slate-900">{{ number_format($provider['outcomes']) }}</p>
                                    </div>
                                    <div class="rounded-2xl bg-white p-3">
                                        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-400">{{ __('Visitors') }}</p>
                                        <p class="mt-2 text-xl font-black text-slate-900">{{ number_format($provider['unique_visitors']) }}</p>
                                    </div>
                                    <div class="rounded-2xl bg-white p-3">
                                        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-400">{{ __('Signups') }}</p>
                                        <p class="mt-2 text-xl font-black text-slate-900">{{ number_format($provider['signups']) }}</p>
                                    </div>
                                </div>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endif

            <section class="grid gap-8 xl:grid-cols-[1.45fr,0.95fr]">
                <div class="rounded-[1.75rem] border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[0.18em] text-slate-400">{{ __('Share Library') }}</p>
                            <h2 class="mt-1 font-heading text-2xl font-bold text-slate-900">{{ __('My shared links') }}</h2>
                            <p class="mt-2 text-sm text-slate-500">{{ __('Filter the links you have shared and open a detailed page for visits, signups, registrations, and response history.') }}</p>
                        </div>

                        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                            <label class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                                <span class="mb-2 block">{{ __('Type') }}</span>
                                <select wire:model.live="subjectType" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-900 focus:border-emerald-500 focus:outline-none">
                                    @foreach($subjectTypeOptions as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                                <span class="mb-2 block">{{ __('Status') }}</span>
                                <select wire:model.live="status" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-900 focus:border-emerald-500 focus:outline-none">
                                    @foreach($statusOptions as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                                <span class="mb-2 block">{{ __('Sort') }}</span>
                                <select wire:model.live="sort" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-900 focus:border-emerald-500 focus:outline-none">
                                    @foreach($sortOptions as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                                <span class="mb-2 block">{{ __('Response') }}</span>
                                <select wire:model.live="outcomeType" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-900 focus:border-emerald-500 focus:outline-none">
                                    @foreach($outcomeTypeOptions as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                        </div>
                    </div>

                    <div class="mt-6 space-y-4">
                        @forelse($links as $link)
                            <article class="rounded-3xl border border-slate-200 bg-slate-50/70 p-5 transition hover:border-emerald-300 hover:bg-white">
                                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                    <div class="space-y-3">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $subjectBadgeClass((string) $link->subject_type) }}">
                                                {{ $subjectTypeOptions[$link->subject_type] ?? str($link->subject_type)->headline()->toString() }}
                                            </span>
                                            <span class="rounded-full bg-white px-3 py-1 text-xs font-semibold text-slate-600">
                                                {{ __('Shared') }} {{ \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($link->last_shared_at, 'j M Y, g:i A') }}
                                            </span>
                                        </div>

                                        <div>
                                            <h3 class="text-lg font-semibold text-slate-900">{{ $link->title_snapshot ?: __('Untitled page') }}</h3>
                                            <p class="mt-2 text-sm text-slate-500 break-all">{{ $link->destination_url }}</p>
                                        </div>

                                        <div class="grid gap-3 sm:grid-cols-3 xl:grid-cols-6">
                                            <div class="rounded-2xl bg-white p-3">
                                                <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-400">{{ __('Visits') }}</p>
                                                <p class="mt-2 text-xl font-black text-slate-900">{{ number_format((int) ($link->visits_count ?? 0)) }}</p>
                                            </div>
                                            <div class="rounded-2xl bg-white p-3">
                                                <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-400">{{ __('Responses') }}</p>
                                                <p class="mt-2 text-xl font-black text-slate-900">{{ number_format((int) ($link->outcomes_count ?? 0)) }}</p>
                                            </div>
                                            <div class="rounded-2xl bg-white p-3">
                                                <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-400">{{ __('Signups') }}</p>
                                                <p class="mt-2 text-xl font-black text-slate-900">{{ number_format((int) ($link->signups_count ?? 0)) }}</p>
                                            </div>
                                            <div class="rounded-2xl bg-white p-3">
                                                <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-400">{{ __('Registrations') }}</p>
                                                <p class="mt-2 text-xl font-black text-slate-900">{{ number_format((int) ($link->event_registrations_count ?? 0)) }}</p>
                                            </div>
                                            <div class="rounded-2xl bg-white p-3">
                                                <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-400">{{ __('Check-ins') }}</p>
                                                <p class="mt-2 text-xl font-black text-slate-900">{{ number_format((int) ($link->event_checkins_count ?? 0)) }}</p>
                                            </div>
                                            <div class="rounded-2xl bg-white p-3">
                                                <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-400">{{ __('Submissions') }}</p>
                                                <p class="mt-2 text-xl font-black text-slate-900">{{ number_format((int) ($link->event_submissions_count ?? 0)) }}</p>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="flex flex-wrap gap-3 lg:justify-end">
                                        <a
                                            href="{{ route('dashboard.dawah-impact.links.show', ['link' => $link->id]) }}"
                                            wire:navigate
                                            class="inline-flex items-center rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800"
                                        >
                                            {{ __('View Impact') }}
                                        </a>
                                        <a
                                            href="{{ $link->destination_url }}"
                                            target="_blank"
                                            rel="noopener"
                                            class="inline-flex items-center rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:border-emerald-300 hover:text-emerald-700"
                                        >
                                            {{ __('Open Shared Page') }}
                                        </a>
                                    </div>
                                </div>
                            </article>
                        @empty
                            <div class="rounded-3xl border border-dashed border-slate-200 bg-slate-50 px-6 py-12 text-center">
                                <h3 class="text-lg font-semibold text-slate-900">{{ __('No share links yet') }}</h3>
                                <p class="mt-3 text-sm leading-6 text-slate-600">{{ __('Once you start sharing events, institutions, speakers, series, references, or filtered results, your impact will appear here.') }}</p>
                                <a href="{{ route('events.index') }}" wire:navigate class="mt-5 inline-flex items-center rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-emerald-700">
                                    {{ __('Browse shareable pages') }}
                                </a>
                            </div>
                        @endforelse
                    </div>

                    @if($links->hasPages())
                        <div class="mt-8">
                            {{ $links->links(data: ['scrollTo' => false]) }}
                        </div>
                    @endif
                </div>

                <div class="space-y-8">
                    @if($topSubjects->isNotEmpty())
                        <section class="rounded-[1.75rem] border border-slate-200 bg-white p-5 shadow-sm">
                            <p class="text-xs font-bold uppercase tracking-[0.18em] text-slate-400">{{ __('Top Shared Subjects') }}</p>
                            <h2 class="mt-1 font-heading text-2xl font-bold text-slate-900">{{ __('Which topics and pages are driving the strongest response') }}</h2>

                            <div class="mt-5 space-y-4">
                                @foreach($topSubjects as $subject)
                                    <article class="rounded-3xl border border-slate-200 bg-slate-50/70 p-4">
                                        <div class="flex items-start justify-between gap-4">
                                            <div>
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $subjectBadgeClass($subject['subject_type']) }}">
                                                        {{ $subject['type_label'] }}
                                                    </span>
                                                    <span class="rounded-full bg-white px-3 py-1 text-xs font-semibold text-slate-600">
                                                        {{ trans_choice(':count link|:count links', $subject['links'], ['count' => $subject['links']]) }}
                                                    </span>
                                                </div>
                                                <p class="mt-3 text-sm font-semibold text-slate-900">{{ $subject['title_snapshot'] }}</p>
                                                <p class="mt-1 text-xs text-slate-500 break-all">{{ $subject['subject_key'] }}</p>
                                            </div>
                                            <div class="grid min-w-44 grid-cols-2 gap-2 text-right">
                                                <div class="rounded-2xl bg-white px-3 py-2">
                                                    <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-400">{{ __('Visits') }}</p>
                                                    <p class="mt-1 text-lg font-black text-slate-900">{{ number_format($subject['visits']) }}</p>
                                                </div>
                                                <div class="rounded-2xl bg-white px-3 py-2">
                                                    <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-400">{{ __('Responses') }}</p>
                                                    <p class="mt-1 text-lg font-black text-slate-900">{{ number_format($subject['total_outcomes']) }}</p>
                                                </div>
                                            </div>
                                        </div>
                                    </article>
                                @endforeach
                            </div>
                        </section>
                    @endif

                    @if($topLinks->isNotEmpty())
                        <section class="rounded-[1.75rem] border border-slate-200 bg-white p-5 shadow-sm">
                            <p class="text-xs font-bold uppercase tracking-[0.18em] text-slate-400">{{ __('Top Shared Items') }}</p>
                            <h2 class="mt-1 font-heading text-2xl font-bold text-slate-900">{{ __('Most reached links') }}</h2>

                            <div class="mt-5 space-y-4">
                                @foreach($topLinks as $link)
                                    <a href="{{ route('dashboard.dawah-impact.links.show', ['link' => $link->id]) }}" wire:navigate class="block rounded-3xl border border-slate-200 bg-slate-50/70 p-4 transition hover:border-emerald-300 hover:bg-white">
                                        <div class="flex items-center justify-between gap-4">
                                            <div>
                                                <p class="text-sm font-semibold text-slate-900">{{ $link->title_snapshot ?: __('Untitled page') }}</p>
                                                <p class="mt-1 text-xs text-slate-500">{{ $subjectTypeOptions[$link->subject_type] ?? str($link->subject_type)->headline()->toString() }}</p>
                                            </div>
                                            <div class="text-right">
                                                <p class="text-lg font-black text-slate-900">{{ number_format((int) ($link->visits_count ?? 0)) }}</p>
                                                <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-400">{{ __('Visits') }}</p>
                                            </div>
                                        </div>
                                    </a>
                                @endforeach
                            </div>
                        </section>
                    @endif

                    <section class="rounded-[1.75rem] border border-slate-200 bg-white p-5 shadow-sm">
                        <p class="text-xs font-bold uppercase tracking-[0.18em] text-slate-400">{{ __('Recent Responses') }}</p>
                        <h2 class="mt-1 font-heading text-2xl font-bold text-slate-900">{{ __('What people did after visiting') }}</h2>

                        <div class="mt-5 space-y-4">
                            @forelse($recentResponses as $response)
                                <article class="rounded-3xl border border-slate-200 bg-slate-50/70 p-4">
                                    <div class="flex items-start justify-between gap-4">
                                        <div>
                                            <p class="text-sm font-semibold text-slate-900">{{ $outcomeLabel((string) $response->outcome_type) }}</p>
                                            <p class="mt-1 text-sm text-slate-600">{{ $response->link_title_snapshot ?: __('Shared page') }}</p>
                                        </div>
                                        <p class="text-xs font-semibold text-slate-500">
                                            {{ \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($response->occurred_at, 'j M Y, g:i A') }}
                                        </p>
                                    </div>
                                </article>
                            @empty
                                <div class="rounded-3xl border border-dashed border-slate-200 bg-slate-50 px-5 py-10 text-center">
                                    <p class="text-sm font-semibold text-slate-900">{{ __('No responses yet') }}</p>
                                    <p class="mt-2 text-sm text-slate-500">{{ __('Visits and responses will appear here after people interact with your shared pages.') }}</p>
                                </div>
                            @endforelse
                        </div>
                    </section>
                </div>
            </section>
        </div>
    </div>
</div>
