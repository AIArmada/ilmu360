<x-filament-panels::page>
    @php
        $summary = $report['summary'] ?? [];
        $originBreakdown = $report['origin_breakdown'] ?? [];
        $platformBreakdown = $report['platform_breakdown'] ?? [];
        $transportBreakdown = $report['transport_breakdown'] ?? [];
        $recentEvents = $report['recent_events'] ?? [];
    @endphp

    <div class="space-y-6">
        <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
            <div class="max-w-3xl space-y-1">
                <p class="text-sm text-gray-500">{{ __('Product event telemetry grouped by client origin, platform, and transport. Use this to compare web behavior against iOS, Android, and API/mobile traffic.') }}</p>
            </div>

            @if ($liveActivityUrl)
                <x-filament::button tag="a" :href="$liveActivityUrl" icon="heroicon-o-bolt">
                    {{ __('Open Live Activity') }}
                </x-filament::button>
            @endif
        </div>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
            <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-500">{{ __('Client Events') }}</p>
                <p class="mt-3 text-3xl font-semibold text-gray-950">{{ number_format((int) ($summary['events'] ?? 0)) }}</p>
                <p class="mt-2 text-sm text-gray-500">{{ __('Recent Signals events with client origin metadata.') }}</p>
            </div>
            <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-500">{{ __('Web') }}</p>
                <p class="mt-3 text-3xl font-semibold text-gray-950">{{ number_format((int) ($summary['web_events'] ?? 0)) }}</p>
                <p class="mt-2 text-sm text-gray-500">{{ __('Browser and web-app originated activity.') }}</p>
            </div>
            <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-500">{{ __('API') }}</p>
                <p class="mt-3 text-3xl font-semibold text-gray-950">{{ number_format((int) ($summary['api_events'] ?? 0)) }}</p>
                <p class="mt-2 text-sm text-gray-500">{{ __('Server-recorded events from API requests.') }}</p>
            </div>
            <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-500">{{ __('Mobile') }}</p>
                <p class="mt-3 text-3xl font-semibold text-gray-950">{{ number_format((int) ($summary['mobile_events'] ?? 0)) }}</p>
                <p class="mt-2 text-sm text-gray-500">{{ __('iOS, iPadOS, and Android-attributed events.') }}</p>
            </div>
            <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-500">{{ __('Unattributed') }}</p>
                <p class="mt-3 text-3xl font-semibold text-gray-950">{{ number_format((int) ($summary['unattributed_events'] ?? 0)) }}</p>
                <p class="mt-2 text-sm text-gray-500">{{ __('Recent events missing client origin metadata.') }}</p>
            </div>
        </div>

        <div class="grid gap-6 xl:grid-cols-3">
            <section class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                <h2 class="text-base font-semibold text-gray-950">{{ __('Client Origins') }}</h2>
                <p class="mt-1 text-sm text-gray-500">{{ __('Web, API, iOS, Android, and other origin labels.') }}</p>

                <div class="mt-4 space-y-3">
                    @forelse ($originBreakdown as $row)
                        <div class="rounded-xl border border-gray-100 bg-gray-50 p-4">
                            <div class="flex items-center justify-between gap-3">
                                <p class="font-medium text-gray-950">{{ $row['label'] }}</p>
                                <span class="text-sm text-gray-500">{{ number_format((int) $row['count']) }}</span>
                            </div>
                            <div class="mt-3 h-2 rounded-full bg-gray-200">
                                <div class="h-2 rounded-full bg-primary-500" style="width: {{ min(100, max(0, (float) $row['share'])) }}%"></div>
                            </div>
                            <p class="mt-2 text-xs uppercase tracking-[0.16em] text-gray-400">{{ number_format((float) $row['share'], 1) }}%</p>
                        </div>
                    @empty
                        <p class="rounded-xl border border-dashed border-gray-300 p-4 text-sm text-gray-500">{{ __('No client origin activity has been recorded yet.') }}</p>
                    @endforelse
                </div>
            </section>

            <section class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                <h2 class="text-base font-semibold text-gray-950">{{ __('Platforms') }}</h2>
                <p class="mt-1 text-sm text-gray-500">{{ __('Operating system or app platform inferred from headers and user agent.') }}</p>

                <div class="mt-4 space-y-3">
                    @forelse ($platformBreakdown as $row)
                        <div class="flex items-center justify-between gap-3 rounded-xl border border-gray-100 bg-gray-50 p-4">
                            <div>
                                <p class="font-medium text-gray-950">{{ $row['label'] }}</p>
                                <p class="mt-1 text-xs uppercase tracking-[0.16em] text-gray-400">{{ number_format((float) $row['share'], 1) }}%</p>
                            </div>
                            <span class="text-sm font-semibold text-gray-950">{{ number_format((int) $row['count']) }}</span>
                        </div>
                    @empty
                        <p class="rounded-xl border border-dashed border-gray-300 p-4 text-sm text-gray-500">{{ __('No platform activity has been recorded yet.') }}</p>
                    @endforelse
                </div>
            </section>

            <section class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                <h2 class="text-base font-semibold text-gray-950">{{ __('Transport') }}</h2>
                <p class="mt-1 text-sm text-gray-500">{{ __('Whether the event came through web UI, API, or server-side workflows.') }}</p>

                <div class="mt-4 space-y-3">
                    @forelse ($transportBreakdown as $row)
                        <div class="flex items-center justify-between gap-3 rounded-xl border border-gray-100 bg-gray-50 p-4">
                            <div>
                                <p class="font-medium text-gray-950">{{ $row['label'] }}</p>
                                <p class="mt-1 text-xs uppercase tracking-[0.16em] text-gray-400">{{ number_format((float) $row['share'], 1) }}%</p>
                            </div>
                            <span class="text-sm font-semibold text-gray-950">{{ number_format((int) $row['count']) }}</span>
                        </div>
                    @empty
                        <p class="rounded-xl border border-dashed border-gray-300 p-4 text-sm text-gray-500">{{ __('No transport activity has been recorded yet.') }}</p>
                    @endforelse
                </div>
            </section>
        </div>

        <section class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <h2 class="text-base font-semibold text-gray-950">{{ __('Recent Product Events') }}</h2>
            <p class="mt-1 text-sm text-gray-500">{{ __('Latest client-attributed events with origin metadata visible without opening raw JSON.') }}</p>

            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-[0.16em] text-gray-500">
                            <th class="pb-3 pr-4">{{ __('When') }}</th>
                            <th class="pb-3 pr-4">{{ __('Event') }}</th>
                            <th class="pb-3 pr-4">{{ __('Property') }}</th>
                            <th class="pb-3 pr-4">{{ __('Origin') }}</th>
                            <th class="pb-3 pr-4">{{ __('Platform') }}</th>
                            <th class="pb-3 pr-4">{{ __('Transport') }}</th>
                            <th class="pb-3 pr-4">{{ __('Version') }}</th>
                            <th class="pb-3">{{ __('Path / Query') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($recentEvents as $event)
                            <tr>
                                <td class="py-3 pr-4 text-gray-600">{{ $event['occurred_at'] }}</td>
                                <td class="py-3 pr-4 align-top">
                                    <p class="font-medium text-gray-950">{{ $event['event_name'] }}</p>
                                    <p class="mt-1 text-xs uppercase tracking-[0.16em] text-gray-500">{{ $event['event_category'] }}</p>
                                </td>
                                <td class="py-3 pr-4 text-gray-600">{{ $event['property'] }}</td>
                                <td class="py-3 pr-4 text-gray-950">{{ $event['client_origin_label'] }}</td>
                                <td class="py-3 pr-4 text-gray-950">{{ $event['client_platform_label'] }}</td>
                                <td class="py-3 pr-4 text-gray-950">{{ $event['client_transport_label'] }}</td>
                                <td class="py-3 pr-4 text-gray-600">{{ $event['client_version'] ?? '—' }}</td>
                                <td class="py-3 align-top">
                                    <p class="break-all text-gray-600">{{ $event['path'] ?? '—' }}</p>
                                    @if ($event['query'])
                                        <p class="mt-1 break-all text-xs text-gray-500">{{ $event['query'] }}</p>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="py-6 text-sm text-gray-500">{{ __('No product signals are available yet.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</x-filament-panels::page>
