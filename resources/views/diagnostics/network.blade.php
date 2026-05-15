<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Network Diagnostics</title>
    <meta name="description" content="Database-to-database latency diagnostics for the current Laravel connection and an env-defined target database.">
    <meta name="robots" content="noindex, nofollow">
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-slate-950 text-slate-100 antialiased selection:bg-emerald-500/30 selection:text-emerald-50">
    @php
        $formatMilliseconds = static fn (?float $value): string => $value === null ? '—' : number_format($value, 2).' ms';
        $formatList = static fn (array $values): string => $values === [] ? '—' : implode(', ', $values);
        $currentDatabase = $report['current_database'];
        $targetDatabase = $report['target_database'];
        $comparison = $report['comparison'];
        $requestToken = request()->query('token');
    @endphp

    <div class="pointer-events-none absolute inset-0 overflow-hidden">
        <div class="absolute inset-0 bg-[radial-gradient(circle_at_top_right,rgba(16,185,129,0.12),transparent_35%)]"></div>
        <div class="absolute -left-40 -top-40 h-80 w-80 rounded-full bg-emerald-500/10 blur-3xl"></div>
        <div class="absolute -bottom-48 -right-32 h-96 w-96 rounded-full bg-sky-500/10 blur-3xl"></div>
    </div>

    <main class="relative container mx-auto space-y-8 px-6 py-10 lg:px-12">
        <section class="overflow-hidden rounded-3xl border border-white/10 bg-white/5 p-8 shadow-2xl shadow-black/20 backdrop-blur-xl">
            <div class="max-w-4xl space-y-4">
                <span class="inline-flex items-center rounded-full border border-emerald-400/30 bg-emerald-400/10 px-3 py-1 text-xs font-semibold uppercase tracking-[0.24em] text-emerald-300">
                    Ops
                </span>

                <div class="space-y-3">
                    <h1 class="text-3xl font-bold tracking-tight text-white sm:text-4xl">Network Diagnostics</h1>
                    <p class="max-w-3xl text-sm leading-7 text-slate-300 sm:text-base">
                        This page compares the database Laravel is using right now against a separate target database defined in
                        <code class="rounded bg-white/10 px-2 py-1 text-xs text-emerald-200">.env</code>, so the numbers stay focused on real database connection behavior instead of generic URL timing.
                    </p>
                </div>

                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <div class="rounded-2xl border border-white/10 bg-white/5 p-5 shadow-sm">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">Current DB query RTT</p>
                        <p class="mt-3 text-lg font-semibold text-white">{{ $formatMilliseconds($currentDatabase['query_metric']['average_ms']) }}</p>
                        <p class="mt-2 text-sm text-slate-300">{{ $currentDatabase['database'] ?? 'Unknown database' }} @ {{ $currentDatabase['network_target'] ?? 'local transport' }}</p>
                    </div>

                    <div class="rounded-2xl border border-white/10 bg-white/5 p-5 shadow-sm">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">Target DB query RTT</p>
                        <p class="mt-3 text-lg font-semibold text-white">{{ $formatMilliseconds($targetDatabase['query_metric']['average_ms'] ?? null) }}</p>
                        <p class="mt-2 text-sm text-slate-300">
                            {{ $targetDatabase !== null ? (($targetDatabase['database'] ?? 'Unknown database').' @ '.($targetDatabase['network_target'] ?? 'local transport')) : 'Target database not configured yet' }}
                        </p>
                    </div>

                    <div class="rounded-2xl border border-white/10 bg-white/5 p-5 shadow-sm">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">Connect delta</p>
                        <p class="mt-3 text-lg font-semibold text-white">{{ $formatMilliseconds($comparison['db_connect']['difference_ms'] ?? null) }}</p>
                        <p class="mt-2 text-sm text-slate-300">
                            {{ $comparison['db_connect']['summary'] ?? 'Visible after both databases return connection timings' }}
                        </p>
                    </div>

                    <div class="rounded-2xl border border-white/10 bg-white/5 p-5 shadow-sm">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">TCP delta</p>
                        <p class="mt-3 text-lg font-semibold text-white">{{ $formatMilliseconds($comparison['tcp_connect']['difference_ms'] ?? null) }}</p>
                        <p class="mt-2 text-sm text-slate-300">
                            {{ $comparison['tcp_connect']['summary'] ?? 'Visible when both databases expose host:port TCP paths' }}
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <section class="space-y-8">
            <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                <div class="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
                    <div class="space-y-2">
                        <h2 class="text-xl font-semibold text-slate-900">Run the probe</h2>
                        <p class="text-sm leading-6 text-slate-600">
                            This route always compares the current Laravel database connection against the target database credentials stored in <code class="rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-700">.env</code>.
                        </p>
                    </div>

                    <div class="rounded-2xl border border-emerald-100 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                        <span class="font-semibold">Environment:</span> {{ $report['environment'] }}
                        <span class="mx-2 text-emerald-300">•</span>
                        <span class="font-semibold">Samples:</span> {{ $report['settings']['sample_count'] }}
                        <span class="mx-2 text-emerald-300">•</span>
                        <span class="font-semibold">Timeout:</span> {{ $report['settings']['timeout_ms'] }} ms
                    </div>
                </div>

                <form method="GET" action="{{ route('network-diagnostics') }}" class="mt-6 flex flex-wrap items-center gap-4">
                    @if (is_string($requestToken) && $requestToken !== '')
                        <input type="hidden" name="token" value="{{ $requestToken }}">
                    @endif

                    <button
                        type="submit"
                        class="inline-flex items-center justify-center rounded-2xl bg-emerald-600 px-5 py-3 text-sm font-semibold text-white shadow-lg shadow-emerald-500/20 transition hover:bg-emerald-700"
                    >
                        Refresh diagnostics
                    </button>

                    <p class="text-sm text-slate-500">Generated at {{ $report['generated_at'] }}</p>
                </form>
            </div>

            @if ($comparison !== null && $comparison['headline'] !== null)
                <div class="rounded-3xl border border-emerald-200 bg-emerald-50 p-6 text-sm leading-6 text-emerald-900 shadow-sm">
                    <p class="font-semibold">Comparison headline</p>
                    <p class="mt-2">{{ $comparison['headline'] }}</p>
                </div>
            @endif

            <div class="grid gap-8 xl:grid-cols-2">
                @foreach ([$currentDatabase, $targetDatabase] as $databaseProbe)
                    @if ($databaseProbe !== null)
                        <article class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <h2 class="text-xl font-semibold text-slate-900">{{ $databaseProbe['label'] }}</h2>
                                    <p class="mt-2 text-sm leading-6 text-slate-600">{{ $databaseProbe['summary'] }}</p>
                                </div>

                                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $databaseProbe['query_metric']['average_ms'] !== null ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-700' }}">
                                    {{ $databaseProbe['query_metric']['average_ms'] !== null ? 'Query OK' : 'Needs attention' }}
                                </span>
                            </div>

                            <dl class="mt-6 grid gap-4 sm:grid-cols-2 text-sm text-slate-700">
                                <div>
                                    <dt class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Source</dt>
                                    <dd class="mt-1 font-medium text-slate-900">{{ $databaseProbe['source'] }}</dd>
                                </div>
                                <div>
                                    <dt class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Connection</dt>
                                    <dd class="mt-1 font-medium text-slate-900">{{ $databaseProbe['connection_name'] ?? 'n/a' }}</dd>
                                </div>
                                <div>
                                    <dt class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Driver</dt>
                                    <dd class="mt-1 font-medium text-slate-900">{{ $databaseProbe['driver'] ?? 'n/a' }}</dd>
                                </div>
                                <div>
                                    <dt class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Database</dt>
                                    <dd class="mt-1 font-medium text-slate-900">{{ $databaseProbe['database'] ?? 'n/a' }}</dd>
                                </div>
                                <div>
                                    <dt class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">User</dt>
                                    <dd class="mt-1 font-medium text-slate-900">{{ $databaseProbe['username'] ?? 'n/a' }}</dd>
                                </div>
                                <div>
                                    <dt class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Network target</dt>
                                    <dd class="mt-1 font-medium text-slate-900">{{ $databaseProbe['network_target'] ?? 'n/a' }}</dd>
                                </div>
                                <div>
                                    <dt class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Resolved IPs</dt>
                                    <dd class="mt-1 font-medium text-slate-900">{{ $formatList($databaseProbe['resolved_ips']) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Server version</dt>
                                    <dd class="mt-1 font-medium text-slate-900">{{ $databaseProbe['server_version'] ?? 'n/a' }}</dd>
                                </div>
                            </dl>

                            <div class="mt-6 overflow-x-auto">
                                <table class="min-w-full divide-y divide-slate-200 text-sm">
                                    <thead>
                                        <tr class="text-left text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                                            <th class="pb-3 pr-4">Metric</th>
                                            <th class="pb-3 pr-4">Average</th>
                                            <th class="pb-3 pr-4">Min</th>
                                            <th class="pb-3 pr-4">Max</th>
                                            <th class="pb-3 pr-4">Jitter</th>
                                            <th class="pb-3">Errors</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100 text-slate-700">
                                        @foreach (array_filter([$databaseProbe['tcp_metric'], $databaseProbe['connect_metric'], $databaseProbe['query_metric']]) as $metric)
                                            <tr>
                                                <td class="py-4 pr-4 align-top font-medium text-slate-900">{{ $metric['label'] }}</td>
                                                <td class="py-4 pr-4 align-top">{{ $formatMilliseconds($metric['average_ms']) }}</td>
                                                <td class="py-4 pr-4 align-top">{{ $formatMilliseconds($metric['min_ms']) }}</td>
                                                <td class="py-4 pr-4 align-top">{{ $formatMilliseconds($metric['max_ms']) }}</td>
                                                <td class="py-4 pr-4 align-top">{{ $formatMilliseconds($metric['jitter_ms']) }}</td>
                                                <td class="py-4 align-top text-xs leading-5 text-slate-500">
                                                    {{ $metric['last_error'] ?? 'No recorded error' }}
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>

                            @if ($databaseProbe['errors'] !== [])
                                <div class="mt-6 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                                    <p class="font-semibold">Captured errors</p>
                                    <ul class="mt-2 space-y-2">
                                        @foreach ($databaseProbe['errors'] as $error)
                                            <li>{{ $error }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                        </article>
                    @endif
                @endforeach

                @if (! $report['target_database_configured'])
                    <article class="rounded-3xl border border-dashed border-slate-300 bg-white p-6 shadow-sm">
                        <h2 class="text-xl font-semibold text-slate-900">Target database credentials are not configured yet.</h2>
                        <p class="mt-2 text-sm leading-6 text-slate-600">
                            Add the target database values below to <code class="rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-700">.env</code>, then refresh this page to get a proper side-by-side comparison.
                        </p>

                        <ul class="mt-4 space-y-2 text-sm text-slate-700">
                            @foreach ($report['target_environment_variables'] as $environmentVariable)
                                <li>
                                    <code class="rounded bg-slate-100 px-2 py-1 text-xs text-slate-800">{{ $environmentVariable }}</code>
                                </li>
                            @endforeach
                        </ul>
                    </article>
                @endif
            </div>

            @if ($comparison !== null)
                <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 class="text-xl font-semibold text-slate-900">Side-by-side comparison</h2>
                    <div class="mt-6 overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead>
                                <tr class="text-left text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                                    <th class="pb-3 pr-4">Metric</th>
                                    <th class="pb-3 pr-4">Current</th>
                                    <th class="pb-3 pr-4">Target</th>
                                    <th class="pb-3 pr-4">Delta</th>
                                    <th class="pb-3">Summary</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 text-slate-700">
                                @foreach (array_filter([$comparison['tcp_connect'], $comparison['db_connect'], $comparison['query_round_trip']]) as $metricComparison)
                                    <tr>
                                        <td class="py-4 pr-4 align-top font-medium text-slate-900">{{ $metricComparison['metric_label'] }}</td>
                                        <td class="py-4 pr-4 align-top">{{ $formatMilliseconds($metricComparison['current_ms']) }}</td>
                                        <td class="py-4 pr-4 align-top">{{ $formatMilliseconds($metricComparison['target_ms']) }}</td>
                                        <td class="py-4 pr-4 align-top {{ $metricComparison['target_faster'] ? 'text-emerald-700' : 'text-amber-700' }}">{{ $formatMilliseconds($metricComparison['difference_ms']) }}</td>
                                        <td class="py-4 align-top text-xs leading-5 text-slate-500">{{ $metricComparison['summary'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                <h2 class="text-xl font-semibold text-slate-900">Reading the numbers</h2>
                <ul class="mt-4 space-y-3 text-sm leading-6 text-slate-600">
                    @foreach ($report['notes'] as $note)
                        <li class="flex gap-3">
                            <span class="mt-1 inline-block h-2 w-2 shrink-0 rounded-full bg-emerald-500"></span>
                            <span>{{ $note }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </section>
    </main>
</body>
</html>