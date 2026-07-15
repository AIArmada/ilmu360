<?php

use App\Services\Diagnostics\NetworkDiagnosticsService;
use App\Support\Diagnostics\NetworkDiagnosticsEnvironment;
use Mockery\MockInterface;

const NETWORK_DIAGNOSTICS_ENV_KEYS = [
    'NETWORK_DIAGNOSTICS_ENABLED',
    'NETWORK_DIAGNOSTICS_TOKEN',
    'NETWORK_DIAGNOSTICS_SAMPLE_COUNT',
    'NETWORK_DIAGNOSTICS_TIMEOUT_MS',
    'NETWORK_DIAGNOSTICS_TARGET_DB_LABEL',
    'NETWORK_DIAGNOSTICS_TARGET_DB_DRIVER',
    'NETWORK_DIAGNOSTICS_TARGET_DB_URL',
    'NETWORK_DIAGNOSTICS_TARGET_DB_HOST',
    'NETWORK_DIAGNOSTICS_TARGET_DB_PORT',
    'NETWORK_DIAGNOSTICS_TARGET_DB_DATABASE',
    'NETWORK_DIAGNOSTICS_TARGET_DB_USERNAME',
    'NETWORK_DIAGNOSTICS_TARGET_DB_PASSWORD',
    'NETWORK_DIAGNOSTICS_TARGET_DB_SOCKET',
    'NETWORK_DIAGNOSTICS_TARGET_DB_CHARSET',
    'NETWORK_DIAGNOSTICS_TARGET_DB_SSLMODE',
];

afterEach(function (): void {
    foreach (NETWORK_DIAGNOSTICS_ENV_KEYS as $key) {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }

    app()->forgetInstance(NetworkDiagnosticsEnvironment::class);
});

it('renders a database-to-database diagnostics report directly on the page', function (): void {
    setNetworkDiagnosticsEnv([
        'NETWORK_DIAGNOSTICS_ENABLED' => 'true',
    ]);

    $this->mock(NetworkDiagnosticsService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('run')
            ->once()
            ->withNoArgs()
            ->andReturn(fakeDatabaseDiagnosticsReport());
    });

    $this->get(route('network-diagnostics'))
        ->assertSuccessful()
        ->assertSee('Current environment database')
        ->assertSee('Target database')
        ->assertSee('ilmu360')
        ->assertSee('candidate-db')
        ->assertSee('Target database is 5.90 ms faster on query round-trip than Current environment database.')
        ->assertSee('Query round-trip');
});

it('is accessible when diagnostics token is not configured', function (): void {
    $this->mock(NetworkDiagnosticsService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('run')
            ->once()
            ->withNoArgs()
            ->andReturn(fakeDatabaseDiagnosticsReport());
    });

    $this->get(route('network-diagnostics'))
        ->assertSuccessful()
        ->assertSee('Current environment database')
        ->assertSee('Target database');
});

it('reads target database credentials from the raw env file when runtime env values are unavailable', function (): void {
    $originalDatabaseDefault = config('database.default');
    $originalSqliteConnection = config('database.connections.sqlite');

    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => false,
    ]);

    $environmentFilePath = bindNetworkDiagnosticsEnvironmentFile(<<<'ENV'
NETWORK_DIAGNOSTICS_ENABLED=true
NETWORK_DIAGNOSTICS_TARGET_DB_LABEL="Production candidate database"
NETWORK_DIAGNOSTICS_TARGET_DB_DRIVER=sqlite
NETWORK_DIAGNOSTICS_TARGET_DB_DATABASE=:memory:
ENV
    );

    try {
        $this->get(route('network-diagnostics'))
            ->assertSuccessful()
            ->assertSee('Production candidate database')
            ->assertDontSee('Target database credentials are not configured yet.');
    } finally {
        @unlink($environmentFilePath);
        config()->set('database.default', $originalDatabaseDefault);
        config()->set('database.connections.sqlite', $originalSqliteConnection);
    }
});

it('shows env-based setup guidance when the target database is not configured yet', function (): void {
    setNetworkDiagnosticsEnv([
        'NETWORK_DIAGNOSTICS_ENABLED' => 'true',
    ]);

    $this->mock(NetworkDiagnosticsService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('run')
            ->once()
            ->withNoArgs()
            ->andReturn(fakeDatabaseDiagnosticsReport(targetConfigured: false));
    });

    $this->get(route('network-diagnostics'))
        ->assertSuccessful()
        ->assertSee('Target database credentials are not configured yet.')
        ->assertSee('NETWORK_DIAGNOSTICS_TARGET_DB_DRIVER')
        ->assertSee('NETWORK_DIAGNOSTICS_TARGET_DB_HOST')
        ->assertSee('NETWORK_DIAGNOSTICS_TARGET_DB_PASSWORD');
});

it('requires the configured token when diagnostics are protected', function (): void {
    setNetworkDiagnosticsEnv([
        'NETWORK_DIAGNOSTICS_ENABLED' => 'true',
        'NETWORK_DIAGNOSTICS_TOKEN' => 'secret-network-token',
    ]);

    $this->get(route('network-diagnostics'))->assertForbidden();
});

it('reads token protection from the raw env file when runtime env values are unavailable', function (): void {
    $environmentFilePath = bindNetworkDiagnosticsEnvironmentFile(<<<'ENV'
NETWORK_DIAGNOSTICS_ENABLED=true
NETWORK_DIAGNOSTICS_TOKEN=secret-network-token
ENV
    );

    try {
        $this->mock(NetworkDiagnosticsService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->once()
                ->withNoArgs()
                ->andReturn(fakeDatabaseDiagnosticsReport());
        });

        $this->get(route('network-diagnostics'))->assertForbidden();
        $this->get(route('network-diagnostics', ['token' => 'secret-network-token']))
            ->assertSuccessful()
            ->assertSee('Current environment database');
    } finally {
        @unlink($environmentFilePath);
    }
});

function setNetworkDiagnosticsEnv(array $values): void
{
    foreach ($values as $key => $value) {
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);

            continue;
        }

        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

function bindNetworkDiagnosticsEnvironmentFile(string $contents): string
{
    $environmentFilePath = tempnam(sys_get_temp_dir(), 'network-diagnostics-env-');

    if (! is_string($environmentFilePath)) {
        throw new RuntimeException('Unable to create a temporary diagnostics env file.');
    }

    file_put_contents($environmentFilePath, $contents.PHP_EOL);

    app()->instance(NetworkDiagnosticsEnvironment::class, new NetworkDiagnosticsEnvironment($environmentFilePath));

    return $environmentFilePath;
}

function fakeDatabaseDiagnosticsReport(bool $targetConfigured = true): array
{
    return [
        'generated_at' => '2026-04-21T10:00:00+00:00',
        'environment' => 'testing',
        'settings' => [
            'sample_count' => 3,
            'timeout_ms' => 1000,
        ],
        'current_database' => [
            'label' => 'Current environment database',
            'source' => 'Laravel default connection',
            'connection_name' => 'pgsql',
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => 5432,
            'socket' => null,
            'database' => 'ilmu360',
            'username' => 'postgres',
            'using_url' => false,
            'network_target' => '127.0.0.1:5432',
            'resolved_ips' => ['127.0.0.1'],
            'server_version' => 'PostgreSQL 16',
            'summary' => 'Healthy query round-trip latency for most application workloads.',
            'errors' => [],
            'tcp_metric' => fakeMetric('TCP connect', 1.9),
            'connect_metric' => fakeMetric('DB connect', 4.7),
            'query_metric' => fakeMetric('Query round-trip', 8.2),
        ],
        'target_database' => $targetConfigured
            ? [
                'label' => 'Target database',
                'source' => 'NETWORK_DIAGNOSTICS_TARGET_DB_*',
                'connection_name' => null,
                'driver' => 'pgsql',
                'host' => '128.199.152.47',
                'port' => 5432,
                'socket' => null,
                'database' => 'candidate-db',
                'username' => 'candidate-user',
                'using_url' => false,
                'network_target' => '128.199.152.47:5432',
                'resolved_ips' => ['128.199.152.47'],
                'server_version' => 'PostgreSQL 16',
                'summary' => 'Excellent query round-trip latency for database work.',
                'errors' => [],
                'tcp_metric' => fakeMetric('TCP connect', 1.4),
                'connect_metric' => fakeMetric('DB connect', 3.1),
                'query_metric' => fakeMetric('Query round-trip', 2.3),
            ]
            : null,
        'target_database_configured' => $targetConfigured,
        'comparison' => $targetConfigured
            ? [
                'headline' => 'Target database is 5.90 ms faster on query round-trip than Current environment database.',
                'tcp_connect' => fakeComparison('TCP connect', 1.9, 1.4),
                'db_connect' => fakeComparison('DB connect', 4.7, 3.1),
                'query_round_trip' => fakeComparison('Query round-trip', 8.2, 2.3),
            ]
            : null,
        'target_environment_variables' => [
            'NETWORK_DIAGNOSTICS_ENABLED',
            'NETWORK_DIAGNOSTICS_TOKEN',
            'NETWORK_DIAGNOSTICS_TARGET_DB_DRIVER',
            'NETWORK_DIAGNOSTICS_TARGET_DB_HOST',
            'NETWORK_DIAGNOSTICS_TARGET_DB_PORT',
            'NETWORK_DIAGNOSTICS_TARGET_DB_DATABASE',
            'NETWORK_DIAGNOSTICS_TARGET_DB_USERNAME',
            'NETWORK_DIAGNOSTICS_TARGET_DB_PASSWORD',
        ],
        'notes' => [
            'The baseline is the database Laravel is configured to use right now, so production automatically compares against the production database connection.',
            'The candidate database comes only from NETWORK_DIAGNOSTICS_TARGET_DB_* values in .env.',
        ],
    ];
}

function fakeMetric(string $label, float $averageMs): array
{
    return [
        'label' => $label,
        'samples' => 3,
        'success_count' => 3,
        'failure_count' => 0,
        'average_ms' => $averageMs,
        'min_ms' => round($averageMs - 0.4, 2),
        'max_ms' => round($averageMs + 0.4, 2),
        'jitter_ms' => 0.8,
        'last_error' => null,
    ];
}

function fakeComparison(string $label, float $currentMs, float $targetMs): array
{
    $differenceMs = round($targetMs - $currentMs, 2);

    return [
        'metric_label' => $label,
        'current_label' => 'Current environment database',
        'current_ms' => $currentMs,
        'target_label' => 'Target database',
        'target_ms' => $targetMs,
        'difference_ms' => $differenceMs,
        'target_faster' => $differenceMs < 0,
        'summary' => $differenceMs < 0
            ? sprintf('Target database is %.2f ms faster on %s than Current environment database.', abs($differenceMs), strtolower($label))
            : sprintf('Target database is %.2f ms slower on %s than Current environment database.', abs($differenceMs), strtolower($label)),
    ];
}
