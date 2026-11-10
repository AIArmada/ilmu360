@php
    $link = $this->linkData;
    $trackedUrl = $this->trackedUrl;
    $measurementsAvailable = $this->measurementStatus !== 'not_configured';
    $summary = $measurementsAvailable ? $this->summary : [];
    $providerBreakdown = $measurementsAvailable ? $this->providerBreakdown : collect();
    $shareLinks = $this->shareLinks;
    $dailyPerformance = $measurementsAvailable ? $this->dailyPerformance : collect();
    $outcomeBreakdown = $measurementsAvailable ? $this->outcomeBreakdown : collect();
    $recentVisits = $measurementsAvailable ? $this->recentVisits : collect();
    $recentOutcomes = $measurementsAvailable ? $this->recentOutcomes : collect();
    $activityWindow = $measurementsAvailable ? $this->activityWindow : [];
    $subjectBadgeClass = match ((string) $link->subjectType) {
        'event' => 'bg-emerald-100 text-emerald-700',
        'institution' => 'bg-sky-100 text-sky-700',
        'person' => 'bg-violet-100 text-violet-700',
        'series' => 'bg-indigo-100 text-indigo-700',
        'reference' => 'bg-amber-100 text-amber-700',
        'search' => 'bg-rose-100 text-rose-700',
        default => 'bg-slate-100 text-slate-700',
    };
    $subjectLabel = match ((string) $link->subjectType) {
        'event' => __('Event'),
        'institution' => __('Institution'),
        'person' => __('Speaker'),
        'series' => __('Series'),
        'reference' => __('Reference'),
        'search' => __('Search Results'),
        default => __('Page'),
    };
    $visitKindLabel = static fn (string $kind): string => match ($kind) {
        'landing' => __('Landing'),
        'return' => __('Return'),
        'navigated' => __('Navigated'),
        default => str($kind)->headline()->toString(),
    };
@endphp

@section('title', ($link->titleSnapshot ?: __('Dakwah')) . ' - ' . config('app.name'))

<div class="min-h-screen bg-slate-50 py-12 pb-32 [&_a]:focus-visible:outline-2 [&_a]:focus-visible:outline-offset-4 [&_a]:focus-visible:outline-emerald-700 [&_summary]:focus-visible:outline-2 [&_summary]:focus-visible:outline-offset-4 [&_summary]:focus-visible:outline-emerald-700">
    <div class="container mx-auto px-4 sm:px-6 lg:px-12">
        @if($this->measurementStatus === 'not_configured')
            <div class="mx-auto mb-6 max-w-7xl rounded-2xl border border-amber-300/60 bg-amber-50 p-4">
                <p class="text-sm font-semibold text-amber-800">{{ __('Reporting is not configured yet. Measurements appear after the operator declares a reporting start.') }}</p>
            </div>
        @endif
        <div class="mx-auto max-w-7xl space-y-8">
            <section class="overflow-hidden rounded-[2rem] border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-100 bg-gradient-to-r from-emerald-50 via-white to-white px-6 py-8 md:px-8">
                    <div class="flex flex-col gap-6 lg:flex-row lg:items-start lg:justify-between">
                        <div class="max-w-3xl">
                            <a href="{{ route('dakwah') }}" wire:navigate class="text-xs font-bold uppercase tracking-[0.18em] text-emerald-700 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-emerald-600">
                                {{ __('Back to Dakwah') }}
                            </a>
                            <div class="mt-4 flex flex-wrap items-center gap-3">
                                <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $subjectBadgeClass }}">{{ $subjectLabel }}</span>
                                <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">
                                    @if($measurementsAvailable)
                                        {{ $link->lastSharedAt ? __('Shared') . ' ' . \App\Platform\Time\UserDateTimeFormatter::translatedFormat($link->lastSharedAt, 'j M Y, g:i A') : __('No recorded shares') }}
                                    @else
                                        {{ __('Measurements withheld') }}
                                    @endif
                                </span>
                            </div>
                            <h1 class="mt-4 font-heading text-3xl font-bold text-slate-900">{{ $link->titleSnapshot ?: __('Untitled page') }}</h1>
                            <p class="mt-3 text-sm leading-6 text-slate-600 break-all">{{ $link->destinationUrl }}</p>
                        </div>

                        <div class="w-full max-w-md space-y-4">
                            <div>
                                <p class="text-sm font-bold text-slate-700">{{ __('Your tracked share link') }}</p>
                                <p class="mt-2 text-sm text-slate-600">{{ __('Reuse the same tracked link so all future responses continue to accumulate in one place.') }}</p>
                            </div>
                            <x-share-panel
                                :share-data="[
                                    'title' => $link->titleSnapshot ?: config('app.name'),
                                    'text' => __('Share this page from :app', ['app' => config('app.name')]),
                                    'url' => $link->destinationUrl,
                                    'sourceUrl' => $link->destinationUrl,
                                    'shareText' => trim(($link->titleSnapshot ?: config('app.name')).' - '.config('app.name')),
                                    'fallbackTitle' => $link->titleSnapshot,
                                    'payloadEndpoint' => route('share.payload'),
                                ]"
                                :share-links="$shareLinks"
                            />
                            <div class="rounded-xl border border-emerald-200 bg-white px-3 py-2">
                                <p class="text-sm font-semibold text-slate-600">{{ __('Tracked link') }}</p>
                                <p class="mt-1 break-all text-sm text-slate-700">{{ $trackedUrl }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                @if($measurementsAvailable)
                    <div class="grid gap-4 px-6 py-6 sm:grid-cols-2 xl:grid-cols-4 md:px-8">
                        <div class="rounded-3xl border border-slate-200 bg-slate-50/70 p-4">
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-600">{{ __('Outbound Shares') }}</p>
                            <p class="mt-2 text-3xl font-black text-slate-900">{{ number_format($summary['outbound_shares']) }}</p>
                        </div>
                        <div class="rounded-3xl border border-slate-200 bg-slate-50/70 p-4">
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-600">{{ __('Visits') }}</p>
                            <p class="mt-2 text-3xl font-black text-slate-900">{{ number_format($summary['visits']) }}</p>
                        </div>
                        <div class="rounded-3xl border border-slate-200 bg-slate-50/70 p-4">
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-600">{{ __('Distinct Browsers') }}</p>
                            <p class="mt-2 text-3xl font-black text-slate-900">{{ number_format($summary['distinct_browsers']) }}</p>
                            <p class="mt-2 text-xs text-slate-500">{{ __('Estimated browsers that opened your links. Not a count of people.') }}</p>
                        </div>
                        <div class="rounded-3xl border border-slate-200 bg-slate-50/70 p-4">
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-600">{{ __('Responses') }}</p>
                            <p class="mt-2 text-3xl font-black text-slate-900">{{ number_format($summary['outcomes']) }}</p>
                        </div>
                        <div class="rounded-3xl border border-slate-200 bg-slate-50/70 p-4">
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-600">{{ __('Signups') }}</p>
                            <p class="mt-2 text-3xl font-black text-slate-900">{{ number_format($summary['signups']) }}</p>
                        </div>
                        <div class="rounded-3xl border border-slate-200 bg-slate-50/70 p-4">
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-600">{{ __('Registrations') }}</p>
                            <p class="mt-2 text-3xl font-black text-slate-900">{{ number_format($summary['event_registrations']) }}</p>
                        </div>
                        <div class="rounded-3xl border border-slate-200 bg-slate-50/70 p-4">
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-600">{{ __('Recorded Check-ins') }}</p>
                            <p class="mt-2 text-3xl font-black text-slate-900">{{ number_format($summary['event_checkins']) }}</p>
                            <p class="mt-2 text-xs text-slate-500">{{ __('Check-ins recorded after a shared visit. Not verified attendance.') }}</p>
                        </div>
                        <div class="rounded-3xl border border-slate-200 bg-slate-50/70 p-4">
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-600">{{ __('Event Submissions') }}</p>
                            <p class="mt-2 text-3xl font-black text-slate-900">{{ number_format($summary['event_submissions']) }}</p>
                        </div>
                    </div>
                @endif
            </section>

            @if($measurementsAvailable)
                <section class="grid gap-8 xl:grid-cols-[1.25fr_0.95fr]">
                    <div class="space-y-8">
                        <section class="rounded-[1.75rem] border border-slate-200 bg-white p-5 shadow-sm">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <p class="text-xs font-bold uppercase tracking-[0.18em] text-slate-600">{{ __('Daily breakdown') }}</p>
                                    <h2 class="mt-1 font-heading text-2xl font-bold text-slate-900">{{ __('Last 14 days') }}</h2>
                                    <p class="mt-2 text-sm text-slate-600">{{ __('Daily totals use your timezone. Other link measurements cover activity since the declared reporting start.') }}</p>
                                </div>
                            </div>

                            <div class="mt-5 space-y-3">
                                @foreach($dailyPerformance as $day)
                                    <div wire:key="dakwah-day-{{ $day['date'] }}" class="rounded-3xl border border-slate-200 bg-slate-50/70 p-4">
                                        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                                            <div>
                                                <p class="text-sm font-semibold text-slate-900">{{ \App\Platform\Time\UserDateTimeFormatter::translatedFormat(\Illuminate\Support\Carbon::parse($day['date'], \App\Platform\Time\UserDateTimeFormatter::resolveTimezone()), 'l, j M Y') }}</p>
                                            </div>
                                            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6">
                                                <div class="rounded-2xl bg-white px-3 py-2">
                                                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-600">{{ __('Visits') }}</p>
                                                    <p class="mt-1 text-lg font-black text-slate-900">{{ number_format($day['visits']) }}</p>
                                                </div>
                                                <div class="rounded-2xl bg-white px-3 py-2">
                                                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-600">{{ __('Responses') }}</p>
                                                    <p class="mt-1 text-lg font-black text-slate-900">{{ number_format($day['outcomes']) }}</p>
                                                </div>
                                                <div class="rounded-2xl bg-white px-3 py-2">
                                                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-600">{{ __('Signups') }}</p>
                                                    <p class="mt-1 text-lg font-black text-slate-900">{{ number_format($day['signups']) }}</p>
                                                </div>
                                                <div class="rounded-2xl bg-white px-3 py-2">
                                                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-600">{{ __('Registrations') }}</p>
                                                    <p class="mt-1 text-lg font-black text-slate-900">{{ number_format($day['event_registrations']) }}</p>
                                                </div>
                                                <div class="rounded-2xl bg-white px-3 py-2">
                                                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-600">{{ __('Check-ins') }}</p>
                                                    <p class="mt-1 text-lg font-black text-slate-900">{{ number_format($day['event_checkins']) }}</p>
                                                </div>
                                                <div class="rounded-2xl bg-white px-3 py-2">
                                                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-600">{{ __('Submissions') }}</p>
                                                    <p class="mt-1 text-lg font-black text-slate-900">{{ number_format($day['event_submissions']) }}</p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </section>

                        <section class="rounded-[1.75rem] border border-slate-200 bg-white p-5 shadow-sm">
                            <p class="text-xs font-bold uppercase tracking-[0.18em] text-slate-600">{{ __('Recent Visits') }}</p>
                            <h2 class="mt-1 font-heading text-2xl font-bold text-slate-900">{{ __('Latest browser activity') }}</h2>
                            <p class="mt-2 text-sm text-slate-500">{{ __('Browser activity via your link. Exact pages and visitor identities are never shown.') }}</p>

                            <div class="mt-5 space-y-4">
                                @forelse($recentVisits as $visit)
                                    <article wire:key="dakwah-visit-{{ $loop->index }}" class="rounded-3xl border border-slate-200 bg-slate-50/70 p-4">
                                        <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                                            <div>
                                                <p class="text-sm font-semibold text-slate-900">{{ $visitKindLabel((string) $visit->visitKind) }} · {{ str((string) ($visit->subjectType ?? 'page'))->headline()->toString() }}</p>
                                                <p class="mt-1 text-xs text-slate-500">
                                                    @if(filled($visit->channel))
                                                        {{ __('Channel') }}: {{ str((string) $visit->channel)->headline()->toString() }}
                                                    @else
                                                        {{ __('Direct visit') }}
                                                    @endif
                                                </p>
                                            </div>
                                            <div class="text-right">
                                                <p class="mt-1 text-sm font-semibold text-slate-600">{{ \App\Platform\Time\UserDateTimeFormatter::translatedFormat($visit->occurredAt, 'j M Y, g:i A') }}</p>
                                            </div>
                                        </div>
                                    </article>
                                @empty
                                    <div class="rounded-3xl border border-dashed border-slate-200 bg-slate-50 px-5 py-10 text-center">
                                        <p class="text-sm font-semibold text-slate-900">{{ __('No visits recorded yet') }}</p>
                                    </div>
                                @endforelse
                            </div>
                        </section>
                    </div>

                    <div class="space-y-8">
                        @if($providerBreakdown->isNotEmpty())
                            <section class="rounded-[1.75rem] border border-slate-200 bg-white p-5 shadow-sm">
                                <p class="text-xs font-bold uppercase tracking-[0.18em] text-slate-600">{{ __('Share Channels') }}</p>
                                <h2 class="mt-1 font-heading text-2xl font-bold text-slate-900">{{ __('Which channels moved this link') }}</h2>
                                <p class="mt-2 text-sm text-slate-600">{{ __('A browser can appear in more than one channel. Channel browser counts cannot be added together.') }}</p>

                                <div class="mt-5 space-y-3">
                                    @foreach($providerBreakdown as $provider)
                                        <div wire:key="dakwah-provider-{{ $provider['provider'] }}" class="rounded-3xl border border-slate-200 bg-slate-50/70 p-4">
                                            <div class="flex items-center justify-between gap-3">
                                                <p class="text-sm font-semibold text-slate-900">{{ $provider['label'] }}</p>
                                                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ number_format($provider['outbound_shares']) }} {{ __('outbound') }}</p>
                                            </div>
                                            <div class="mt-3 grid grid-cols-3 gap-3">
                                                <div class="rounded-2xl bg-white px-3 py-2">
                                                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-600">{{ __('Visits') }}</p>
                                                    <p class="mt-1 text-lg font-black text-slate-900">{{ number_format($provider['visits']) }}</p>
                                                </div>
                                                <div class="rounded-2xl bg-white px-3 py-2">
                                                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-600">{{ __('Browsers') }}</p>
                                                    <p class="mt-1 text-lg font-black text-slate-900">{{ number_format($provider['distinct_browsers']) }}</p>
                                                </div>
                                                <div class="rounded-2xl bg-white px-3 py-2">
                                                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-600">{{ __('Responses') }}</p>
                                                    <p class="mt-1 text-lg font-black text-slate-900">{{ number_format($provider['outcomes']) }}</p>
                                                </div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </section>
                        @endif

                        <section class="rounded-[1.75rem] border border-slate-200 bg-white p-5 shadow-sm">
                            <p class="text-xs font-bold uppercase tracking-[0.18em] text-slate-600">{{ __('Link Window') }}</p>
                            <h2 class="mt-1 font-heading text-2xl font-bold text-slate-900">{{ __('Activity timing') }}</h2>

                            <div class="mt-5 space-y-3">
                                <div class="rounded-3xl border border-slate-200 bg-slate-50/70 p-4">
                                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-600">{{ __('First attributed visit') }}</p>
                                    <p class="mt-2 text-sm font-semibold text-slate-900">
                                        {{ $activityWindow['first_seen_at'] ? \App\Platform\Time\UserDateTimeFormatter::translatedFormat($activityWindow['first_seen_at'], 'j M Y, g:i A') : __('Not yet available') }}
                                    </p>
                                </div>
                                <div class="rounded-3xl border border-slate-200 bg-slate-50/70 p-4">
                                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-600">{{ __('Latest visit') }}</p>
                                    <p class="mt-2 text-sm font-semibold text-slate-900">
                                        {{ $activityWindow['last_visit_at'] ? \App\Platform\Time\UserDateTimeFormatter::translatedFormat($activityWindow['last_visit_at'], 'j M Y, g:i A') : __('Not yet available') }}
                                    </p>
                                </div>
                                <div class="rounded-3xl border border-slate-200 bg-slate-50/70 p-4">
                                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-600">{{ __('Latest response') }}</p>
                                    <p class="mt-2 text-sm font-semibold text-slate-900">
                                        {{ $activityWindow['last_outcome_at'] ? \App\Platform\Time\UserDateTimeFormatter::translatedFormat($activityWindow['last_outcome_at'], 'j M Y, g:i A') : __('Not yet available') }}
                                    </p>
                                </div>
                            </div>
                        </section>

                        <section class="rounded-[1.75rem] border border-slate-200 bg-white p-5 shadow-sm">
                            <p class="text-xs font-bold uppercase tracking-[0.18em] text-slate-600">{{ __('Outcome Breakdown') }}</p>
                            <h2 class="mt-1 font-heading text-2xl font-bold text-slate-900">{{ __('Responses by action') }}</h2>
                            <p class="mt-2 text-sm text-slate-600">{{ __('Responses count recorded actions, not your current saved items or followed pages.') }}</p>

                            <div class="mt-5 space-y-3">
                                @forelse($outcomeBreakdown as $outcome)
                                    <div wire:key="dakwah-outcome-type-{{ $outcome['outcome_type'] }}" class="rounded-3xl border border-slate-200 bg-slate-50/70 px-4 py-3">
                                        <div class="flex items-center justify-between gap-3">
                                            <p class="text-sm font-semibold text-slate-900">{{ $outcome['label'] }}</p>
                                            <p class="text-lg font-black text-slate-900">{{ number_format($outcome['count']) }}</p>
                                        </div>
                                    </div>
                                @empty
                                    <div class="rounded-3xl border border-dashed border-slate-200 bg-slate-50 px-5 py-10 text-center">
                                        <p class="text-sm font-semibold text-slate-900">{{ __('No downstream responses yet') }}</p>
                                    </div>
                                @endforelse
                            </div>
                        </section>

                        <section class="rounded-[1.75rem] border border-slate-200 bg-white p-5 shadow-sm">
                            <p class="text-xs font-bold uppercase tracking-[0.18em] text-slate-600">{{ __('Recent Responses') }}</p>
                            <h2 class="mt-1 font-heading text-2xl font-bold text-slate-900">{{ __('Latest outcome events') }}</h2>

                            <div class="mt-5 space-y-4">
                                @forelse($recentOutcomes as $outcome)
                                    <article wire:key="dakwah-outcome-{{ $loop->index }}" class="rounded-3xl border border-slate-200 bg-slate-50/70 p-4">
                                        <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                                            <div>
                                                <p class="text-sm font-semibold text-slate-900">{{ collect($outcomeBreakdown)->firstWhere('outcome_type', $outcome->outcomeType)['label'] ?? str((string) $outcome->outcomeType)->replace('_', ' ')->headline()->toString() }}</p>
                                                <p class="mt-1 text-xs text-slate-500">{{ __('Recorded at') }} {{ \App\Platform\Time\UserDateTimeFormatter::translatedFormat($outcome->occurredAt, 'j M Y, g:i A') }}</p>
                                            </div>
                                        </div>
                                    </article>
                                @empty
                                    <div class="rounded-3xl border border-dashed border-slate-200 bg-slate-50 px-5 py-10 text-center">
                                        <p class="text-sm font-semibold text-slate-900">{{ __('No responses recorded yet') }}</p>
                                    </div>
                                @endforelse
                            </div>
                        </section>
                    </div>
                </section>
            @endif
        </div>
    </div>
</div>
