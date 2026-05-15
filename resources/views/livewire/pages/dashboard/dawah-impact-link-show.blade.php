@php
    $link = $this->linkData;
    $summary = $this->summary;
    $providerBreakdown = $this->providerBreakdown;
    $shareLinks = $this->shareLinks;
    $dailyPerformance = $this->dailyPerformance;
    $outcomeBreakdown = $this->outcomeBreakdown;
    $recentVisits = $this->recentVisits;
    $recentOutcomes = $this->recentOutcomes;
    $activityWindow = $this->activityWindow;
    $subjectBadgeClass = match ((string) $link->subject_type) {
        'event' => 'bg-emerald-100 text-emerald-700',
        'institution' => 'bg-sky-100 text-sky-700',
        'speaker' => 'bg-violet-100 text-violet-700',
        'series' => 'bg-indigo-100 text-indigo-700',
        'reference' => 'bg-amber-100 text-amber-700',
        'search' => 'bg-rose-100 text-rose-700',
        default => 'bg-slate-100 text-slate-700',
    };
    $subjectLabel = match ((string) $link->subject_type) {
        'event' => __('Event'),
        'institution' => __('Institution'),
        'speaker' => __('Speaker'),
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

@section('title', ($link->title_snapshot ?: __('Share Link')) . ' - ' . config('app.name'))

<div class="min-h-screen bg-slate-50 py-12 pb-32">
    <div class="container mx-auto px-6 lg:px-12">
        <div class="mx-auto max-w-7xl space-y-8">
            <section class="overflow-hidden rounded-[2rem] border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-100 bg-gradient-to-r from-emerald-50 via-white to-white px-6 py-8 md:px-8">
                    <div class="flex flex-col gap-6 lg:flex-row lg:items-start lg:justify-between">
                        <div class="max-w-3xl">
                            <a href="{{ route('dashboard.dawah-impact') }}" wire:navigate class="text-xs font-bold uppercase tracking-[0.18em] text-emerald-600">
                                {{ __('Back to impact dashboard') }}
                            </a>
                            <div class="mt-4 flex flex-wrap items-center gap-3">
                                <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $subjectBadgeClass }}">{{ $subjectLabel }}</span>
                                <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">
                                    {{ __('Shared') }} {{ \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($link->last_shared_at, 'j M Y, g:i A') }}
                                </span>
                                <span class="rounded-full bg-white px-3 py-1 text-xs font-semibold text-slate-600">
                                    {{ __('Subject') }}: {{ $link->subject_key }}
                                </span>
                            </div>
                            <h1 class="mt-4 font-heading text-3xl font-bold text-slate-900">{{ $link->title_snapshot ?: __('Untitled page') }}</h1>
                            <p class="mt-3 text-sm leading-6 text-slate-600 break-all">{{ $link->destination_url }}</p>
                        </div>

                        <div class="w-full max-w-md rounded-3xl border border-slate-200 bg-slate-50 p-4">
                            <p class="text-xs font-bold uppercase tracking-[0.18em] text-slate-400">{{ __('Share Again') }}</p>
                            <p class="mt-2 text-sm text-slate-600">{{ __('Reuse the same tracked link so all future responses continue to accumulate in one place.') }}</p>
                            <div class="mt-4 flex flex-wrap gap-3" x-data="{
                                copied: false,
                                shareData: @js([
                                    'title' => $link->title_snapshot ?: config('app.name'),
                                    'text' => __('Share this page from :app', ['app' => config('app.name')]),
                                    'url' => $link->destination_url,
                                    'sourceUrl' => $link->destination_url,
                                    'shareText' => trim(($link->title_snapshot ?: config('app.name')).' - '.config('app.name')),
                                    'fallbackTitle' => $link->title_snapshot,
                                    'payloadEndpoint' => route('dawah-share.payload'),
                                ]),
                                trackEndpoint: @js(route('dawah-share.track')),
                                providerQueryParameter: @js(config('dawah-share.provider_query_parameter', 'channel')),
                                attributedShareData: null,
                                async resolveShareData() {
                                    if (this.attributedShareData) {
                                        return this.attributedShareData;
                                    }

                                    const params = new URLSearchParams({
                                        url: this.shareData.sourceUrl,
                                        text: this.shareData.shareText,
                                        title: this.shareData.fallbackTitle,
                                    });
                                    const response = await fetch(`${this.shareData.payloadEndpoint}?${params.toString()}`, {
                                        headers: {
                                            Accept: 'application/json',
                                        },
                                    });

                                    if (!response.ok) {
                                        return this.shareData;
                                    }

                                    const payload = await response.json();
                                    this.attributedShareData = {
                                        ...this.shareData,
                                        url: payload.url,
                                        tracking_token: payload.tracking_token ?? null,
                                    };

                                    return this.attributedShareData;
                                },
                                async sharePayloadForChannel(provider = null) {
                                    const shareData = await this.resolveShareData();

                                    if (! shareData || ! provider || ! shareData.tracking_token) {
                                        return shareData;
                                    }

                                    try {
                                        const shareUrl = new URL(shareData.url, window.location.origin);
                                        shareUrl.searchParams.set(this.providerQueryParameter, provider);

                                        return {
                                            ...shareData,
                                            url: shareUrl.toString(),
                                        };
                                    } catch (error) {
                                        return shareData;
                                    }
                                },
                                async trackShare(provider) {
                                    const shareData = await this.resolveShareData();

                                    if (! shareData?.tracking_token) {
                                        return;
                                    }

                                    const csrfToken = document.querySelector('meta[name=csrf-token]')?.content;

                                    if (! csrfToken) {
                                        return;
                                    }

                                    await fetch(this.trackEndpoint, {
                                        method: 'POST',
                                        headers: {
                                            Accept: 'application/json',
                                            'Content-Type': 'application/json',
                                            'X-CSRF-TOKEN': csrfToken,
                                        },
                                        body: JSON.stringify({
                                            provider,
                                            tracking_token: shareData.tracking_token,
                                        }),
                                    });
                                },
                                async nativeShare() {
                                    const shareData = await this.sharePayloadForChannel('native_share');
                                    if (navigator.share) {
                                        try {
                                            await navigator.share(shareData);
                                            await this.trackShare('native_share');
                                        } catch (error) {
                                        }

                                        return;
                                    }

                                    await this.copyLink();
                                },
                                async copyLink(shouldTrack = true, provider = 'copy_link') {
                                    const shareData = await this.sharePayloadForChannel(provider);
                                    if (!navigator.clipboard) {
                                        window.prompt(@js(__('Copy this link:')), shareData.url);

                                        if (shouldTrack) {
                                            await this.trackShare(provider);
                                        }

                                        return;
                                    }

                                    navigator.clipboard.writeText(shareData.url).then(async () => {
                                        if (shouldTrack) {
                                            await this.trackShare(provider);
                                        }

                                        this.copied = true;
                                        setTimeout(() => this.copied = false, 2200);
                                    }, async () => {
                                        window.prompt(@js(__('Copy this link:')), shareData.url);

                                        if (shouldTrack) {
                                            await this.trackShare(provider);
                                        }
                                    });
                                },
                            }">
                                <button type="button" @click="nativeShare()" class="inline-flex items-center rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-emerald-700">
                                    {{ __('Share Link') }}
                                </button>
                                <button type="button" @click="copyLink()" class="inline-flex items-center rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:border-emerald-300 hover:text-emerald-700">
                                    {{ __('Copy Link') }}
                                </button>
                                <a href="{{ $shareLinks['whatsapp'] }}" target="_blank" rel="noopener" class="inline-flex items-center rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:border-[#25D366] hover:text-[#25D366]">
                                    {{ __('WhatsApp') }}
                                </a>
                                <a href="{{ $shareLinks['telegram'] }}" target="_blank" rel="noopener" class="inline-flex items-center rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:border-[#0088cc] hover:text-[#0088cc]">
                                    {{ __('Telegram') }}
                                </a>
                                <p x-show="copied" class="text-sm font-semibold text-emerald-600">{{ __('Link copied to clipboard!') }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="grid gap-4 px-6 py-6 md:grid-cols-2 xl:grid-cols-8 md:px-8">
                    <div class="rounded-3xl border border-slate-200 bg-slate-50/70 p-4">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">{{ __('Outbound Shares') }}</p>
                        <p class="mt-2 text-3xl font-black text-slate-900">{{ number_format($summary['outbound_shares']) }}</p>
                    </div>
                    <div class="rounded-3xl border border-slate-200 bg-slate-50/70 p-4">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">{{ __('Visits') }}</p>
                        <p class="mt-2 text-3xl font-black text-slate-900">{{ number_format($summary['visits']) }}</p>
                    </div>
                    <div class="rounded-3xl border border-slate-200 bg-slate-50/70 p-4">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">{{ __('Unique Visitors') }}</p>
                        <p class="mt-2 text-3xl font-black text-slate-900">{{ number_format($summary['unique_visitors']) }}</p>
                    </div>
                    <div class="rounded-3xl border border-slate-200 bg-slate-50/70 p-4">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">{{ __('Responses') }}</p>
                        <p class="mt-2 text-3xl font-black text-slate-900">{{ number_format($summary['outcomes']) }}</p>
                    </div>
                    <div class="rounded-3xl border border-slate-200 bg-slate-50/70 p-4">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">{{ __('Signups') }}</p>
                        <p class="mt-2 text-3xl font-black text-slate-900">{{ number_format($summary['signups']) }}</p>
                    </div>
                    <div class="rounded-3xl border border-slate-200 bg-slate-50/70 p-4">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">{{ __('Registrations') }}</p>
                        <p class="mt-2 text-3xl font-black text-slate-900">{{ number_format($summary['event_registrations']) }}</p>
                    </div>
                    <div class="rounded-3xl border border-slate-200 bg-slate-50/70 p-4">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">{{ __('Event Check-ins') }}</p>
                        <p class="mt-2 text-3xl font-black text-slate-900">{{ number_format($summary['event_checkins']) }}</p>
                    </div>
                    <div class="rounded-3xl border border-slate-200 bg-slate-50/70 p-4">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">{{ __('Event Submissions') }}</p>
                        <p class="mt-2 text-3xl font-black text-slate-900">{{ number_format($summary['event_submissions']) }}</p>
                    </div>
                </div>
            </section>

            <section class="grid gap-8 xl:grid-cols-[1.25fr,0.95fr]">
                <div class="space-y-8">
                    <section class="rounded-[1.75rem] border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p class="text-xs font-bold uppercase tracking-[0.18em] text-slate-400">{{ __('Daily Trend') }}</p>
                                <h2 class="mt-1 font-heading text-2xl font-bold text-slate-900">{{ __('Last 14 days') }}</h2>
                            </div>
                        </div>

                        <div class="mt-5 space-y-3">
                            @foreach($dailyPerformance as $day)
                                <div class="rounded-3xl border border-slate-200 bg-slate-50/70 p-4">
                                    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                                        <div>
                                            <p class="text-sm font-semibold text-slate-900">{{ \App\Support\Timezone\UserDateTimeFormatter::translatedFormat(\Illuminate\Support\Carbon::parse($day['date']), 'l, j M Y') }}</p>
                                        </div>
                                        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6">
                                            <div class="rounded-2xl bg-white px-3 py-2">
                                                <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-400">{{ __('Visits') }}</p>
                                                <p class="mt-1 text-lg font-black text-slate-900">{{ number_format($day['visits']) }}</p>
                                            </div>
                                            <div class="rounded-2xl bg-white px-3 py-2">
                                                <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-400">{{ __('Responses') }}</p>
                                                <p class="mt-1 text-lg font-black text-slate-900">{{ number_format($day['outcomes']) }}</p>
                                            </div>
                                            <div class="rounded-2xl bg-white px-3 py-2">
                                                <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-400">{{ __('Signups') }}</p>
                                                <p class="mt-1 text-lg font-black text-slate-900">{{ number_format($day['signups']) }}</p>
                                            </div>
                                            <div class="rounded-2xl bg-white px-3 py-2">
                                                <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-400">{{ __('Registrations') }}</p>
                                                <p class="mt-1 text-lg font-black text-slate-900">{{ number_format($day['event_registrations']) }}</p>
                                            </div>
                                            <div class="rounded-2xl bg-white px-3 py-2">
                                                <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-400">{{ __('Check-ins') }}</p>
                                                <p class="mt-1 text-lg font-black text-slate-900">{{ number_format($day['event_checkins']) }}</p>
                                            </div>
                                            <div class="rounded-2xl bg-white px-3 py-2">
                                                <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-400">{{ __('Submissions') }}</p>
                                                <p class="mt-1 text-lg font-black text-slate-900">{{ number_format($day['event_submissions']) }}</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </section>

                    <section class="rounded-[1.75rem] border border-slate-200 bg-white p-5 shadow-sm">
                        <p class="text-xs font-bold uppercase tracking-[0.18em] text-slate-400">{{ __('Recent Visits') }}</p>
                        <h2 class="mt-1 font-heading text-2xl font-bold text-slate-900">{{ __('Latest attributed traffic') }}</h2>

                        <div class="mt-5 space-y-4">
                            @forelse($recentVisits as $visit)
                                <article class="rounded-3xl border border-slate-200 bg-slate-50/70 p-4">
                                    <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                                        <div>
                                            <p class="text-sm font-semibold text-slate-900 break-all">{{ $visit->visited_url }}</p>
                                            <p class="mt-1 text-xs text-slate-500">
                                                {{ __('Visitor key') }}: {{ $visit->visitor_key }}
                                                @if(filled(data_get($visit->metadata, 'share_provider')))
                                                    <span class="ml-2">{{ __('Channel') }}: {{ str((string) data_get($visit->metadata, 'share_provider'))->headline()->toString() }}</span>
                                                @endif
                                            </p>
                                        </div>
                                        <div class="text-right">
                                            <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-400">{{ $visitKindLabel((string) $visit->visit_kind) }}</p>
                                            <p class="mt-1 text-sm font-semibold text-slate-600">{{ \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($visit->occurred_at, 'j M Y, g:i A') }}</p>
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
                            <p class="text-xs font-bold uppercase tracking-[0.18em] text-slate-400">{{ __('Share Channels') }}</p>
                            <h2 class="mt-1 font-heading text-2xl font-bold text-slate-900">{{ __('Which channels moved this link') }}</h2>

                            <div class="mt-5 space-y-3">
                                @foreach($providerBreakdown as $provider)
                                    <div class="rounded-3xl border border-slate-200 bg-slate-50/70 p-4">
                                        <div class="flex items-center justify-between gap-3">
                                            <p class="text-sm font-semibold text-slate-900">{{ $provider['label'] }}</p>
                                            <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ number_format($provider['outbound_shares']) }} {{ __('outbound') }}</p>
                                        </div>
                                        <div class="mt-3 grid grid-cols-3 gap-3">
                                            <div class="rounded-2xl bg-white px-3 py-2">
                                                <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-400">{{ __('Visits') }}</p>
                                                <p class="mt-1 text-lg font-black text-slate-900">{{ number_format($provider['visits']) }}</p>
                                            </div>
                                            <div class="rounded-2xl bg-white px-3 py-2">
                                                <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-400">{{ __('Visitors') }}</p>
                                                <p class="mt-1 text-lg font-black text-slate-900">{{ number_format($provider['unique_visitors']) }}</p>
                                            </div>
                                            <div class="rounded-2xl bg-white px-3 py-2">
                                                <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-400">{{ __('Responses') }}</p>
                                                <p class="mt-1 text-lg font-black text-slate-900">{{ number_format($provider['outcomes']) }}</p>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </section>
                    @endif

                    <section class="rounded-[1.75rem] border border-slate-200 bg-white p-5 shadow-sm">
                        <p class="text-xs font-bold uppercase tracking-[0.18em] text-slate-400">{{ __('Link Window') }}</p>
                        <h2 class="mt-1 font-heading text-2xl font-bold text-slate-900">{{ __('Activity timing') }}</h2>

                        <div class="mt-5 space-y-3">
                            <div class="rounded-3xl border border-slate-200 bg-slate-50/70 p-4">
                                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-400">{{ __('First attributed visit') }}</p>
                                <p class="mt-2 text-sm font-semibold text-slate-900">
                                    {{ $activityWindow['first_seen_at'] ? \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($activityWindow['first_seen_at'], 'j M Y, g:i A') : __('Not yet available') }}
                                </p>
                            </div>
                            <div class="rounded-3xl border border-slate-200 bg-slate-50/70 p-4">
                                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-400">{{ __('Latest visit') }}</p>
                                <p class="mt-2 text-sm font-semibold text-slate-900">
                                    {{ $activityWindow['last_visit_at'] ? \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($activityWindow['last_visit_at'], 'j M Y, g:i A') : __('Not yet available') }}
                                </p>
                            </div>
                            <div class="rounded-3xl border border-slate-200 bg-slate-50/70 p-4">
                                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-400">{{ __('Latest response') }}</p>
                                <p class="mt-2 text-sm font-semibold text-slate-900">
                                    {{ $activityWindow['last_outcome_at'] ? \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($activityWindow['last_outcome_at'], 'j M Y, g:i A') : __('Not yet available') }}
                                </p>
                            </div>
                        </div>
                    </section>

                    <section class="rounded-[1.75rem] border border-slate-200 bg-white p-5 shadow-sm">
                        <p class="text-xs font-bold uppercase tracking-[0.18em] text-slate-400">{{ __('Outcome Breakdown') }}</p>
                        <h2 class="mt-1 font-heading text-2xl font-bold text-slate-900">{{ __('Responses by action') }}</h2>

                        <div class="mt-5 space-y-3">
                            @forelse($outcomeBreakdown as $outcome)
                                <div class="rounded-3xl border border-slate-200 bg-slate-50/70 px-4 py-3">
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
                        <p class="text-xs font-bold uppercase tracking-[0.18em] text-slate-400">{{ __('Recent Responses') }}</p>
                        <h2 class="mt-1 font-heading text-2xl font-bold text-slate-900">{{ __('Latest outcome events') }}</h2>

                        <div class="mt-5 space-y-4">
                            @forelse($recentOutcomes as $outcome)
                                <article class="rounded-3xl border border-slate-200 bg-slate-50/70 p-4">
                                    <div class="flex items-start justify-between gap-4">
                                        <div>
                                            <p class="text-sm font-semibold text-slate-900">{{ collect($outcomeBreakdown)->firstWhere('outcome_type', $outcome->outcome_type)['label'] ?? str((string) $outcome->outcome_type)->replace('_', ' ')->headline()->toString() }}</p>
                                            <p class="mt-1 text-xs text-slate-500">{{ __('Recorded at') }} {{ \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($outcome->occurred_at, 'j M Y, g:i A') }}</p>
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
        </div>
    </div>
</div>
