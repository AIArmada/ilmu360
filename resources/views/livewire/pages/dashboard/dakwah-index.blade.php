@section('title', __('Your Dakwah') . ' - ' . config('app.name'))

@php
    $measurementsAvailable = $this->measurementStatus !== 'not_configured';
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
        'person' => 'bg-violet-100 text-violet-700',
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
        'person_follow' => __('Followed a speaker'),
        'series_follow' => __('Followed a series'),
        'reference_follow' => __('Followed a reference'),
        'saved_search_created' => __('Saved a search'),
        default => str($type)->replace('_', ' ')->headline()->toString(),
    };
@endphp

<div class="min-h-screen bg-slate-50 py-12 pb-32 [&_a]:focus-visible:outline-2 [&_a]:focus-visible:outline-offset-4 [&_a]:focus-visible:outline-emerald-700 [&_summary]:focus-visible:outline-2 [&_summary]:focus-visible:outline-offset-4 [&_summary]:focus-visible:outline-emerald-700">
    <div class="container mx-auto px-4 sm:px-6 lg:px-12">
        <div class="mx-auto max-w-7xl space-y-8">
            <section class="rounded-3xl border border-slate-200 bg-white p-6 md:p-8">
                <p class="text-sm font-semibold text-emerald-700">{{ __('Your Dakwah') }}</p>
                <h1 class="mt-2 font-heading text-3xl font-bold text-slate-900">{{ __('See what happened after your sharing') }}</h1>
                <p class="mt-3 max-w-3xl text-sm leading-6 text-slate-600">{{ __('This is your private sharing report. Share a useful page, invite a friend personally, or check your rewards.') }}</p>
                <nav aria-label="{{ __('Dakwah actions') }}" class="mt-5 flex flex-wrap gap-3">
                    <a href="{{ route('events.index') }}" wire:navigate class="rounded-xl bg-emerald-700 px-4 py-3 text-sm font-semibold text-white hover:bg-emerald-800">{{ __('Find a page to share') }}</a>
                    <a href="{{ route('dashboard.referrals') }}" wire:navigate class="rounded-xl border border-slate-300 px-4 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50">{{ __('Invite a friend') }}</a>
                    <a href="{{ route('dashboard.rewards') }}" wire:navigate class="rounded-xl border border-slate-300 px-4 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50">{{ __('Rewards') }}</a>
                </nav>
                <p class="mt-4 text-sm leading-6 text-slate-600">{{ __('Sharing tracks visits and follow-up actions. Personal invitations have their own acceptance and qualification rules; rewards depend on programme terms.') }}</p>
                @if(!$measurementsAvailable)
                    <p role="status" class="mt-5 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm font-semibold text-amber-900">{{ __('Reporting is not configured yet. Measurements appear after the operator declares a reporting start.') }}</p>
                @else
                    <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        @foreach([
                            ['visits', __('Visits'), __('Visits attributed to your sharing.')],
                            ['signups', __('Signups'), __('New accounts created after a share touch.')],
                            ['event_registrations', __('Event Registrations'), __('Registrations credited back to your sharing.')],
                            ['event_checkins', __('Recorded Check-ins'), __('Check-ins recorded after a shared visit. Not verified attendance.')],
                        ] as [$metric, $label, $description])
                            <div wire:key="metric-{{ $metric }}" class="rounded-2xl bg-slate-50 p-4">
                                <p class="text-sm font-semibold text-slate-600">{{ $label }}</p>
                                <p class="mt-2 text-3xl font-bold text-slate-900">{{ number_format($summary[$metric]) }}</p>
                                <p class="mt-2 text-sm text-slate-600">{{ $description }}</p>
                            </div>
                        @endforeach
                    </div>
                    <p class="mt-4 text-sm text-slate-600">{{ __('All measurements cover activity since the declared reporting start. Library filters do not change the summary, channels, top links, or recent responses.') }}</p>
                    <details class="mt-5 text-sm text-slate-600">
                        <summary class="cursor-pointer font-semibold text-slate-900">{{ __('How we count') }}</summary>
                        <p class="mt-3">{{ __('A browser can appear in more than one channel. Channel browser counts cannot be added together.') }}</p>
                        <p class="mt-3">{{ __('Responses count recorded actions, not your current saved items or followed pages.') }}</p>
                        <dl class="mt-3 grid gap-3 sm:grid-cols-2">
                            @foreach([
                                ['distinct_browsers', __('Distinct Browsers'), __('Estimated browsers that opened your links. Not a count of people.')],
                                ['outbound_shares', __('Outbound Shares'), __('Share actions you started. Delivery to recipients is unknown.')],
                                ['event_submissions', __('Event Submissions'), __('Event proposals submitted after a shared visit.')],
                                ['total_outcomes', __('Recorded Responses'), __('Currently valid follow-up actions. Counts actions, not people.')],
                            ] as [$metric, $label, $description])
                                <div wire:key="secondary-{{ $metric }}"><dt class="font-semibold">{{ $label }}: {{ number_format($summary[$metric]) }}</dt><dd>{{ $description }}</dd></div>
                            @endforeach
                        </dl>
                        <p class="mt-3 leading-6">{{ __('Totals retain historical activity whose original page link is no longer available. Page breakdowns cover links in your library.') }}</p>
                    </details>
                @endif
            </section>

            @if($measurementsAvailable && $subjectSummaries->isNotEmpty())
                <section class="rounded-[1.75rem] border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[0.18em] text-slate-600">{{ __('By Shared Type') }}</p>
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
                                        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-600">{{ __('Visits') }}</p>
                                        <p class="mt-2 text-xl font-black text-slate-900">{{ number_format($subject['visits']) }}</p>
                                    </div>
                                    <div class="rounded-2xl bg-white p-3">
                                        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-600">{{ __('Responses') }}</p>
                                        <p class="mt-2 text-xl font-black text-slate-900">{{ number_format($subject['total_outcomes']) }}</p>
                                    </div>
                                    <div class="rounded-2xl bg-white p-3">
                                        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-600">{{ __('Signups') }}</p>
                                        <p class="mt-2 text-xl font-black text-slate-900">{{ number_format($subject['signups']) }}</p>
                                    </div>
                                    <div class="rounded-2xl bg-white p-3">
                                        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-600">{{ __('Registrations') }}</p>
                                        <p class="mt-2 text-xl font-black text-slate-900">{{ number_format($subject['event_registrations']) }}</p>
                                    </div>
                                    <div class="rounded-2xl bg-white p-3">
                                        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-600">{{ __('Check-ins') }}</p>
                                        <p class="mt-2 text-xl font-black text-slate-900">{{ number_format($subject['event_checkins']) }}</p>
                                    </div>
                                    <div class="rounded-2xl bg-white p-3">
                                        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-600">{{ __('Submissions') }}</p>
                                        <p class="mt-2 text-xl font-black text-slate-900">{{ number_format($subject['event_submissions']) }}</p>
                                    </div>
                                </div>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endif

            @if($measurementsAvailable && $providerBreakdown->isNotEmpty())
                <section class="rounded-[1.75rem] border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[0.18em] text-slate-600">{{ __('Sharing channels') }}</p>
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
                                        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-600">{{ __('Visits') }}</p>
                                        <p class="mt-2 text-xl font-black text-slate-900">{{ number_format($provider['visits']) }}</p>
                                    </div>
                                    <div class="rounded-2xl bg-white p-3">
                                        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-600">{{ __('Responses') }}</p>
                                        <p class="mt-2 text-xl font-black text-slate-900">{{ number_format($provider['outcomes']) }}</p>
                                    </div>
                                    <div class="rounded-2xl bg-white p-3">
                                        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-600">{{ __('Browsers') }}</p>
                                        <p class="mt-2 text-xl font-black text-slate-900">{{ number_format($provider['distinct_browsers']) }}</p>
                                    </div>
                                    <div class="rounded-2xl bg-white p-3">
                                        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-600">{{ __('Signups') }}</p>
                                        <p class="mt-2 text-xl font-black text-slate-900">{{ number_format($provider['signups']) }}</p>
                                    </div>
                                </div>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endif

            <section class="grid gap-8 xl:grid-cols-[1.45fr_0.95fr]">
                <div class="rounded-[1.75rem] border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[0.18em] text-slate-600">{{ __('Share Library') }}</p>
                            <h2 class="mt-1 font-heading text-2xl font-bold text-slate-900">{{ __('My shared links') }}</h2>
                            <p class="mt-2 text-sm text-slate-600">{{ __('Link counts include prepared links, even if no share action has been recorded.') }}</p>
                            <p class="mt-2 text-sm text-slate-500">{{ __('Filter the links you have shared and open a detailed page for visits, signups, registrations, and response history.') }}</p>
                        </div>

                        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                            <label class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                                <span class="mb-2 block">{{ __('Type') }}</span>
                                <select wire:model.live="subjectType" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-900 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-600">
                                    @foreach($subjectTypeOptions as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                                <span class="mb-2 block">{{ __('Activity') }}</span>
                                <select @disabled(!$measurementsAvailable) wire:model.live="status" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-900 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-600">
                                    @foreach($statusOptions as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                                <span class="mb-2 block">{{ __('Sort') }}</span>
                                <select @disabled(!$measurementsAvailable) wire:model.live="sort" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-900 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-600">
                                    @foreach($sortOptions as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                                <span class="mb-2 block">{{ __('Response') }}</span>
                                <select @disabled(!$measurementsAvailable) wire:model.live="outcomeType" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-900 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-600">
                                    @foreach($outcomeTypeOptions as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                        </div>
                    </div>

                    <div class="mt-6 space-y-4">
                        @forelse($links as $link)
                            <article wire:key="share-link-{{ $link->id }}" class="rounded-3xl border border-slate-200 bg-slate-50/70 p-5 transition hover:border-emerald-300 hover:bg-white">
                                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                    <div class="space-y-3">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $subjectBadgeClass((string) $link->subjectType) }}">
                                                {{ $subjectTypeOptions[$link->subjectType] ?? str($link->subjectType)->headline()->toString() }}
                                            </span>
                                            <span class="rounded-full bg-white px-3 py-1 text-xs font-semibold text-slate-600">
                                                {{ !$measurementsAvailable ? __('Measurements withheld') : ($link->lastSharedAt ? __('Last shared') . ' ' . \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($link->lastSharedAt, 'j M Y, g:i A') : __('No recorded shares')) }}
                                            </span>
                                        </div>

                                        <div>
                                            <h3 class="text-lg font-semibold text-slate-900">{{ $link->titleSnapshot ?: __('Untitled page') }}</h3>
                                            <p class="mt-2 text-sm text-slate-500 break-all">{{ $link->destinationUrl }}</p>
                                        </div>

                                        @if($measurementsAvailable)
                                        <div class="grid gap-3 sm:grid-cols-3 xl:grid-cols-6">
                                            <div class="rounded-2xl bg-white p-3">
                                                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-600">{{ __('Visits') }}</p>
                                                <p class="mt-2 text-xl font-black text-slate-900">{{ number_format((int) ($link->visitsCount ?? 0)) }}</p>
                                            </div>
                                            <div class="rounded-2xl bg-white p-3">
                                                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-600">{{ __('Responses') }}</p>
                                                <p class="mt-2 text-xl font-black text-slate-900">{{ number_format((int) ($link->outcomesCount ?? 0)) }}</p>
                                            </div>
                                            <div class="rounded-2xl bg-white p-3">
                                                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-600">{{ __('Signups') }}</p>
                                                <p class="mt-2 text-xl font-black text-slate-900">{{ number_format((int) ($link->signupsCount ?? 0)) }}</p>
                                            </div>
                                            <div class="rounded-2xl bg-white p-3">
                                                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-600">{{ __('Registrations') }}</p>
                                                <p class="mt-2 text-xl font-black text-slate-900">{{ number_format((int) ($link->eventRegistrationsCount ?? 0)) }}</p>
                                            </div>
                                            <div class="rounded-2xl bg-white p-3">
                                                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-600">{{ __('Check-ins') }}</p>
                                                <p class="mt-2 text-xl font-black text-slate-900">{{ number_format((int) ($link->eventCheckinsCount ?? 0)) }}</p>
                                            </div>
                                            <div class="rounded-2xl bg-white p-3">
                                                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-600">{{ __('Submissions') }}</p>
                                                <p class="mt-2 text-xl font-black text-slate-900">{{ number_format((int) ($link->eventSubmissionsCount ?? 0)) }}</p>
                                            </div>
                                        </div>
                                        @endif
                                    </div>

                                    <div class="flex flex-wrap gap-3 lg:justify-end">
                                        <a
                                            href="{{ route('dakwah.links.show', ['link' => $link->id]) }}"
                                            wire:navigate
                                            class="inline-flex items-center rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800"
                                        >
                                            {{ __('View details') }}
                                        </a>
                                        <a
                                            href="{{ $link->destinationUrl }}"
                                            target="_blank"
                                            rel="noopener"
                                            class="inline-flex items-center rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:border-emerald-300 hover:text-emerald-700"
                                        >
                                            {{ __('Open Shared Page') }}
                                        </a>
                                    </div>
                                </div>
                                <details class="mt-4">
                                    <summary class="cursor-pointer text-sm font-semibold text-emerald-700">{{ __('Share again') }}</summary>
                                    <div class="mt-3">
                                        <x-share-panel
                                            :share-data="[
                                                'title' => $link->titleSnapshot ?: config('app.name'),
                                                'text' => $link->titleSnapshot,
                                                'url' => $link->destinationUrl,
                                                'sourceUrl' => $link->destinationUrl,
                                                'shareText' => $link->titleSnapshot,
                                                'fallbackTitle' => $link->titleSnapshot,
                                                'payloadEndpoint' => route('share.payload'),
                                            ]"
                                            :share-links="app(\App\Services\ShareTrackingService::class)->redirectLinks($link->destinationUrl, $link->titleSnapshot, $link->titleSnapshot)"
                                        />
                                    </div>
                                </details>
                            </article>
                        @empty
                            <div class="rounded-3xl border border-dashed border-slate-200 bg-slate-50 px-6 py-12 text-center">
                                <h3 class="text-lg font-semibold text-slate-900">{{ $this->linkLibrary->isEmpty() ? __('No share links yet') : __('No links match these filters') }}</h3>
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
                    @if($measurementsAvailable && $topSubjects->isNotEmpty())
                        <section class="rounded-[1.75rem] border border-slate-200 bg-white p-5 shadow-sm">
                            <p class="text-xs font-bold uppercase tracking-[0.18em] text-slate-600">{{ __('Top Shared Subjects') }}</p>
                            <h2 class="mt-1 font-heading text-2xl font-bold text-slate-900">{{ __('Which topics and pages are driving the strongest response') }}</h2>

                            <div class="mt-5 space-y-4">
                                @foreach($topSubjects as $subject)
                                    <article class="rounded-3xl border border-slate-200 bg-slate-50/70 p-4">
                                        <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
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
                                            </div>
                                            <div class="grid min-w-44 grid-cols-2 gap-2 text-right">
                                                <div class="rounded-2xl bg-white px-3 py-2">
                                                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-600">{{ __('Visits') }}</p>
                                                    <p class="mt-1 text-lg font-black text-slate-900">{{ number_format($subject['visits']) }}</p>
                                                </div>
                                                <div class="rounded-2xl bg-white px-3 py-2">
                                                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-600">{{ __('Responses') }}</p>
                                                    <p class="mt-1 text-lg font-black text-slate-900">{{ number_format($subject['total_outcomes']) }}</p>
                                                </div>
                                            </div>
                                        </div>
                                    </article>
                                @endforeach
                            </div>
                        </section>
                    @endif

                    @if($measurementsAvailable && $topLinks->isNotEmpty())
                        <section class="rounded-[1.75rem] border border-slate-200 bg-white p-5 shadow-sm">
                            <p class="text-xs font-bold uppercase tracking-[0.18em] text-slate-600">{{ __('Top Shared Items') }}</p>
                            <h2 class="mt-1 font-heading text-2xl font-bold text-slate-900">{{ __('Most reached links') }}</h2>

                            <div class="mt-5 space-y-4">
                                @foreach($topLinks as $link)
                                    <a href="{{ route('dakwah.links.show', ['link' => $link->id]) }}" wire:navigate class="block rounded-3xl border border-slate-200 bg-slate-50/70 p-4 transition hover:border-emerald-300 hover:bg-white">
                                        <div class="flex items-center justify-between gap-4">
                                            <div>
                                                <p class="text-sm font-semibold text-slate-900">{{ $link->titleSnapshot ?: __('Untitled page') }}</p>
                                                <p class="mt-1 text-xs text-slate-500">{{ $subjectTypeOptions[$link->subjectType] ?? str($link->subjectType)->headline()->toString() }}</p>
                                            </div>
                                            <div class="text-right">
                                                <p class="text-lg font-black text-slate-900">{{ number_format((int) ($link->visitsCount ?? 0)) }}</p>
                                                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-600">{{ __('Visits') }}</p>
                                            </div>
                                        </div>
                                    </a>
                                @endforeach
                            </div>
                        </section>
                    @endif

                    @if($measurementsAvailable)
                    <section class="rounded-[1.75rem] border border-slate-200 bg-white p-5 shadow-sm">
                        <p class="text-xs font-bold uppercase tracking-[0.18em] text-slate-600">{{ __('Recent Responses') }}</p>
                        <h2 class="mt-1 font-heading text-2xl font-bold text-slate-900">{{ __('Follow-up actions after visiting') }}</h2>

                        <div class="mt-5 space-y-4">
                            @forelse($recentResponses as $response)
                                <article class="rounded-3xl border border-slate-200 bg-slate-50/70 p-4">
                                    <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                                        <div>
                                            <p class="text-sm font-semibold text-slate-900">{{ $outcomeLabel((string) $response->outcomeType) }}</p>
                                            <p class="mt-1 text-sm text-slate-600">{{ $response->linkTitleSnapshot ?: __('Shared page') }}</p>
                                        </div>
                                        <p class="text-xs font-semibold text-slate-500">
                                            {{ \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($response->occurredAt, 'j M Y, g:i A') }}
                                        </p>
                                    </div>
                                </article>
                            @empty
                                <div class="rounded-3xl border border-dashed border-slate-200 bg-slate-50 px-5 py-10 text-center">
                                    <p class="text-sm font-semibold text-slate-900">{{ __('No responses yet') }}</p>
                                    <p class="mt-2 text-sm text-slate-500">{{ __('Visits and responses will appear here after recipients interact with your shared pages.') }}</p>
                                </div>
                            @endforelse
                        </div>
                    </section>
                    @endif
                </div>
            </section>
        </div>
    </div>
</div>
