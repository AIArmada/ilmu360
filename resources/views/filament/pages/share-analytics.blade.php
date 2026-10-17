<x-filament-panels::page>
    @php
        $summary = $report['summary'] ?? [];
        $providerBreakdown = $report['provider_breakdown'] ?? [];
        $topSharers = $report['top_sharers'] ?? [];
        $topLinks = $report['top_links'] ?? [];
        $recentVisits = $report['recent_visits'] ?? [];
        $recentOutcomes = $report['recent_outcomes'] ?? [];
    @endphp

    <div class="space-y-6">
        <div class="rounded-xl border border-amber-300 bg-amber-50 p-4">
            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-amber-700">{{ __('Measurement status') }}</p>
            @if ($measurementStatus === 'active' && $reportingStart !== null)
                <p class="mt-1 text-sm font-semibold text-amber-900">{{ __('Measurement active since :date', ['date' => $reportingStart]) }}</p>
            @else
                <p class="mt-1 text-sm font-semibold text-amber-900">{{ __('Not configured') }}</p>
                <p class="mt-1 text-sm text-amber-800">{{ __('Reporting is not configured yet. Measurements appear after the operator declares a reporting start.') }}</p>
            @endif
        </div>

        <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
            <div class="max-w-3xl space-y-1">
                <p class="text-sm text-gray-500">{{ __('Superadmin view of affiliate-backed Dawah Share performance across all users and shared links.') }}</p>
            </div>

            @if ($signalsReportUrl)
                <x-filament::button tag="a" :href="$signalsReportUrl" icon="heroicon-o-chart-bar-square">
                    {{ __('Open Signals Reports') }}
                </x-filament::button>
            @endif
        </div>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
            <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-500">{{ __('Affiliates') }}</p>
                <p class="mt-3 text-3xl font-semibold text-gray-950">{{ number_format($summary['affiliates'] ?? 0) }}</p>
                <p class="mt-2 text-sm text-gray-500">{{ __('Users with an active share-tracking affiliate profile.') }}</p>
            </div>
            <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-500">{{ __('Shared Links') }}</p>
                <p class="mt-3 text-3xl font-semibold text-gray-950">{{ number_format($summary['shared_links'] ?? 0) }}</p>
                <p class="mt-2 text-sm text-gray-500">{{ __('Distinct tracked URLs currently in the share library.') }}</p>
            </div>
            <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-500">{{ __('Visits') }}</p>
                <p class="mt-3 text-3xl font-semibold text-gray-950">{{ number_format($summary['visits'] ?? 0) }}</p>
                <p class="mt-2 text-sm text-gray-500">{{ __('Attributed visit touchpoints after share landings.') }}</p>
            </div>
            <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-500">{{ __('Distinct Browsers') }}</p>
                <p class="mt-3 text-3xl font-semibold text-gray-950">{{ number_format($summary['distinct_browsers'] ?? 0) }}</p>
                <p class="mt-2 text-sm text-gray-500">{{ __('Estimated browsers across marked share profiles. Not a count of people.') }}</p>
            </div>
            <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-500">{{ __('Valid Outcomes') }}</p>
                <p class="mt-3 text-3xl font-semibold text-gray-950">{{ number_format($summary['total_outcomes'] ?? 0) }}</p>
                <p class="mt-2 text-sm text-gray-500">{{ __('Currently valid attributed outcomes. Reversed, rejected, and pending outcomes are excluded.') }}</p>
            </div>
        </div>

        <div class="grid gap-6 xl:grid-cols-3">
            <section class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm xl:col-span-1">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-base font-semibold text-gray-950">{{ __('Provider Breakdown') }}</h2>
                        <p class="mt-1 text-sm text-gray-500">{{ __('Which sharing channels generated traffic and outcomes.') }}</p>
                    </div>
                </div>

                <div class="mt-4 space-y-3">
                    @forelse ($providerBreakdown as $provider)
                        <div class="rounded-xl border border-gray-100 bg-gray-50 p-4">
                            <div class="flex items-center justify-between gap-3">
                                <p class="font-medium text-gray-950">{{ $provider['label'] }}</p>
                                <span class="text-sm text-gray-500">{{ number_format((int) $provider['outbound_shares']) }} {{ __('outbound') }}</span>
                            </div>
                            <div class="mt-3 grid grid-cols-2 gap-3 text-sm text-gray-600">
                                <div>
                                    <p class="text-xs uppercase tracking-[0.16em] text-gray-400">{{ __('Visits') }}</p>
                                    <p class="mt-1 font-semibold text-gray-950">{{ number_format((int) $provider['visits']) }}</p>
                                </div>
                                <div>
                                    <p class="text-xs uppercase tracking-[0.16em] text-gray-400">{{ __('Valid outcomes') }}</p>
                                    <p class="mt-1 font-semibold text-gray-950">{{ number_format((int) $provider['outcomes']) }}</p>
                                </div>
                                <div>
                                    <p class="text-xs uppercase tracking-[0.16em] text-gray-400">{{ __('Browsers') }}</p>
                                    <p class="mt-1 font-semibold text-gray-950">{{ number_format((int) $provider['distinct_browsers']) }}</p>
                                </div>
                                <div>
                                    <p class="text-xs uppercase tracking-[0.16em] text-gray-400">{{ __('Signups') }}</p>
                                    <p class="mt-1 font-semibold text-gray-950">{{ number_format((int) $provider['signups']) }}</p>
                                </div>
                            </div>
                        </div>
                    @empty
                        <p class="rounded-xl border border-dashed border-gray-300 p-4 text-sm text-gray-500">{{ __('No provider activity has been recorded yet.') }}</p>
                    @endforelse
                </div>
            </section>

            <section class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm xl:col-span-2">
                <h2 class="text-base font-semibold text-gray-950">{{ __('Top Sharers') }}</h2>
                <p class="mt-1 text-sm text-gray-500">{{ __('Users whose tracked links are producing the strongest downstream activity.') }}</p>

                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-[0.16em] text-gray-500">
                                <th class="pb-3 pr-4">{{ __('Sharer') }}</th>
                                <th class="pb-3 pr-4">{{ __('Code') }}</th>
                                <th class="pb-3 pr-4">{{ __('Links') }}</th>
                                <th class="pb-3 pr-4">{{ __('Visits') }}</th>
                                <th class="pb-3 pr-4">{{ __('Browsers') }}</th>
                                <th class="pb-3 pr-4">{{ __('Valid outcomes') }}</th>
                                <th class="pb-3">{{ __('Last Activity') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($topSharers as $sharer)
                                <tr>
                                    <td class="py-3 pr-4 align-top">
                                        <p class="font-medium text-gray-950">{{ $sharer['user_name'] }}</p>
                                        <p class="mt-1 text-xs text-gray-500">{{ $sharer['user_email'] }}</p>
                                    </td>
                                    <td class="py-3 pr-4 text-gray-600">{{ $sharer['affiliate_code'] }}</td>
                                    <td class="py-3 pr-4 text-gray-950">{{ number_format((int) $sharer['links']) }}</td>
                                    <td class="py-3 pr-4 text-gray-950">{{ number_format((int) $sharer['visits']) }}</td>
                                    <td class="py-3 pr-4 text-gray-950">{{ number_format((int) $sharer['distinct_browsers']) }}</td>
                                    <td class="py-3 pr-4 text-gray-950">{{ number_format((int) $sharer['outcomes']) }}</td>
                                    <td class="py-3 text-gray-600">{{ $sharer['last_activity_at'] ?? '—' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="py-6 text-sm text-gray-500">{{ __('No tracked sharers are available yet.') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <section class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <h2 class="text-base font-semibold text-gray-950">{{ __('Top Links') }}</h2>
            <p class="mt-1 text-sm text-gray-500">{{ __('The most active tracked links across all affiliates.') }}</p>

            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-[0.16em] text-gray-500">
                            <th class="pb-3 pr-4">{{ __('Shared Item') }}</th>
                            <th class="pb-3 pr-4">{{ __('Sharer') }}</th>
                            <th class="pb-3 pr-4">{{ __('Outbounds') }}</th>
                            <th class="pb-3 pr-4">{{ __('Visits') }}</th>
                            <th class="pb-3 pr-4">{{ __('Browsers') }}</th>
                            <th class="pb-3 pr-4">{{ __('Valid outcomes') }}</th>
                            <th class="pb-3">{{ __('Last Shared') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($topLinks as $link)
                            <tr>
                                <td class="py-3 pr-4 align-top">
                                    <p class="font-medium text-gray-950">{{ $link['title_snapshot'] }}</p>
                                    <p class="mt-1 text-xs uppercase tracking-[0.12em] text-gray-500">{{ str($link['subject_type'])->headline()->toString() }}</p>
                                    <a href="{{ $link['destination_url'] }}" class="mt-1 block break-all text-xs text-primary-600 hover:text-primary-500" target="_blank" rel="noreferrer">
                                        {{ $link['destination_url'] }}
                                    </a>
                                </td>
                                <td class="py-3 pr-4 text-gray-600">{{ $link['sharer_name'] ?? '—' }}</td>
                                <td class="py-3 pr-4 text-gray-950">{{ number_format((int) $link['outbound_shares']) }}</td>
                                <td class="py-3 pr-4 text-gray-950">{{ number_format((int) $link['visits']) }}</td>
                                <td class="py-3 pr-4 text-gray-950">{{ number_format((int) $link['distinct_browsers']) }}</td>
                                <td class="py-3 pr-4 text-gray-950">{{ number_format((int) $link['outcomes']) }}</td>
                                <td class="py-3 text-gray-600">{{ $link['last_shared_at'] ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="py-6 text-sm text-gray-500">{{ __('No tracked links are available yet.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <div class="grid gap-6 xl:grid-cols-2">
            <section class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                <h2 class="text-base font-semibold text-gray-950">{{ __('Recent Visits') }}</h2>
                <p class="mt-1 text-sm text-gray-500">{{ __('Latest coarse browser activity across marked share links. Exact pages are never shown.') }}</p>

                <div class="mt-4 space-y-3">
                    @forelse ($recentVisits as $visit)
                        <div class="rounded-xl border border-gray-100 bg-gray-50 p-4">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <p class="font-medium text-gray-950">{{ $visit['sharer_name'] ?? '—' }}</p>
                                    <p class="mt-1 text-xs text-gray-500">{{ $visit['subject'] }} · {{ $visit['provider'] }}</p>
                                </div>
                                <span class="text-xs uppercase tracking-[0.16em] text-gray-400">{{ $visit['provider'] }}</span>
                            </div>
                            <div class="mt-3 flex flex-wrap gap-3 text-xs text-gray-500">
                                <span>{{ $visit['visit_kind'] }}</span>
                                <span>{{ $visit['occurred_at'] ?? '—' }}</span>
                            </div>
                        </div>
                    @empty
                        <p class="rounded-xl border border-dashed border-gray-300 p-4 text-sm text-gray-500">{{ __('No attributed visits have been recorded yet.') }}</p>
                    @endforelse
                </div>
            </section>

            <section class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                <h2 class="text-base font-semibold text-gray-950">{{ __('Recent Valid Outcomes') }}</h2>
                <p class="mt-1 text-sm text-gray-500">{{ __('Latest attributed outcomes recorded against tracked links.') }}</p>

                <div class="mt-4 space-y-3">
                    @forelse ($recentOutcomes as $outcome)
                        <div class="rounded-xl border border-gray-100 bg-gray-50 p-4">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <p class="font-medium text-gray-950">{{ $outcome['title_snapshot'] }}</p>
                                    <p class="mt-1 text-xs uppercase tracking-[0.16em] text-gray-500">{{ $outcome['conversion_type'] }}</p>
                                </div>
                                <span class="text-xs text-gray-500">{{ $outcome['subject_type'] }}</span>
                            </div>
                            <div class="mt-3 flex flex-wrap gap-3 text-xs text-gray-500">
                                <span>{{ $outcome['sharer_name'] ?? '—' }}</span>
                                <span>{{ $outcome['occurred_at'] ?? '—' }}</span>
                            </div>
                        </div>
                    @empty
                        <p class="rounded-xl border border-dashed border-gray-300 p-4 text-sm text-gray-500">{{ __('No attributed outcomes have been recorded yet.') }}</p>
                    @endforelse
                </div>
            </section>
        </div>
    </div>
</x-filament-panels::page>
