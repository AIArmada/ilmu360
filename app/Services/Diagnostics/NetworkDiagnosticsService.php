<?php

namespace App\Services\Diagnostics;

use App\Support\Diagnostics\NetworkDiagnosticsEnvironment;
use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * @phpstan-type DiagnosticsMetric array{
 *     label: string,
 *     samples: int,
 *     success_count: int,
 *     failure_count: int,
 *     average_ms: float|null,
 *     min_ms: float|null,
 *     max_ms: float|null,
 *     jitter_ms: float|null,
 *     last_error: string|null
 * }
 * @phpstan-type DiagnosticsMetricComparison array{
 *     metric_label: string,
 *     current_label: string,
 *     current_ms: float,
 *     target_label: string,
 *     target_ms: float,
 *     difference_ms: float,
 *     target_faster: bool,
 *     summary: string
 * }
 * @phpstan-type NormalizedConnection array{
 *     label: string,
 *     source: string,
 *     connection_name: string|null,
 *     driver: string|null,
 *     url: string|null,
 *     host: string|null,
 *     port: int|null,
 *     database: string|null,
 *     username: string|null,
 *     password: string|null,
 *     socket: string|null,
 *     charset: string|null,
 *     sslmode: string|null,
 *     using_url: bool,
 *     networked: bool
 * }
 * @phpstan-type DatabaseProbe array{
 *     label: string,
 *     source: string,
 *     connection_name: string|null,
 *     driver: string|null,
 *     host: string|null,
 *     port: int|null,
 *     socket: string|null,
 *     database: string|null,
 *     username: string|null,
 *     using_url: bool,
 *     network_target: string|null,
 *     resolved_ips: list<string>,
 *     server_version: string|null,
 *     summary: string,
 *     errors: list<string>,
 *     tcp_metric: DiagnosticsMetric|null,
 *     connect_metric: DiagnosticsMetric,
 *     query_metric: DiagnosticsMetric
 * }
 * @phpstan-type DiagnosticsReport array{
 *     generated_at: string,
 *     environment: string,
 *     settings: array{sample_count: int, timeout_ms: int},
 *     current_database: DatabaseProbe,
 *     target_database: DatabaseProbe|null,
 *     target_database_configured: bool,
 *     comparison: array{
 *         headline: string|null,
 *         tcp_connect: DiagnosticsMetricComparison|null,
 *         db_connect: DiagnosticsMetricComparison|null,
 *         query_round_trip: DiagnosticsMetricComparison|null
 *     }|null,
 *     target_environment_variables: list<string>,
 *     notes: list<string>
 * }
 */
class NetworkDiagnosticsService
{
    public function __construct(
        protected NetworkDiagnosticsEnvironment $environment,
    ) {}

    /**
     * @return DiagnosticsReport
     */
    public function run(): array
    {
        $sampleCount = $this->sampleCount();
        $timeoutMs = $this->timeoutMs();

        $currentDatabase = $this->probeDatabase($this->currentDatabaseConfig(), $sampleCount, $timeoutMs);
        $targetConfig = $this->targetDatabaseConfig();
        $targetDatabase = $targetConfig !== null
            ? $this->probeDatabase($targetConfig, $sampleCount, $timeoutMs)
            : null;

        $tcpComparison = $targetDatabase !== null
            ? $this->buildMetricComparison(
                metricLabel: 'TCP connect',
                currentLabel: $currentDatabase['label'],
                currentMetric: $currentDatabase['tcp_metric'],
                targetLabel: $targetDatabase['label'],
                targetMetric: $targetDatabase['tcp_metric'],
            )
            : null;

        $connectComparison = $targetDatabase !== null
            ? $this->buildMetricComparison(
                metricLabel: 'DB connect',
                currentLabel: $currentDatabase['label'],
                currentMetric: $currentDatabase['connect_metric'],
                targetLabel: $targetDatabase['label'],
                targetMetric: $targetDatabase['connect_metric'],
            )
            : null;

        $queryComparison = $targetDatabase !== null
            ? $this->buildMetricComparison(
                metricLabel: 'Query round-trip',
                currentLabel: $currentDatabase['label'],
                currentMetric: $currentDatabase['query_metric'],
                targetLabel: $targetDatabase['label'],
                targetMetric: $targetDatabase['query_metric'],
            )
            : null;

        $notes = [
            'The baseline is the database Laravel is configured to use right now, so production automatically compares against the production database connection.',
            'The candidate database comes only from NETWORK_DIAGNOSTICS_TARGET_DB_* values in .env.',
            'Each sample measures raw TCP reachability when applicable, PDO connect latency, and a simple SELECT 1 round-trip.',
        ];

        if ($targetDatabase === null) {
            $notes[] = 'Set the target database credentials in .env before this page can compute a side-by-side comparison.';
        }

        return [
            'generated_at' => now()->toIso8601String(),
            'environment' => app()->environment(),
            'settings' => [
                'sample_count' => $sampleCount,
                'timeout_ms' => $timeoutMs,
            ],
            'current_database' => $currentDatabase,
            'target_database' => $targetDatabase,
            'target_database_configured' => $targetDatabase !== null,
            'comparison' => $targetDatabase !== null
                ? [
                    'headline' => $queryComparison['summary'] ?? $connectComparison['summary'] ?? $tcpComparison['summary'] ?? null,
                    'tcp_connect' => $tcpComparison,
                    'db_connect' => $connectComparison,
                    'query_round_trip' => $queryComparison,
                ]
                : null,
            'target_environment_variables' => $this->targetEnvironmentVariables(),
            'notes' => $notes,
        ];
    }

    protected function sampleCount(): int
    {
        return max(1, min(5, $this->envInt('NETWORK_DIAGNOSTICS_SAMPLE_COUNT', 3)));
    }

    protected function timeoutMs(): int
    {
        return max(250, min(5000, $this->envInt('NETWORK_DIAGNOSTICS_TIMEOUT_MS', 1000)));
    }

    /**
     * @param  NormalizedConnection  $connection
     * @return DatabaseProbe
     */
    protected function probeDatabase(array $connection, int $sampleCount, int $timeoutMs): array
    {
        $resolvedIps = $this->resolveHost($connection['host']);
        $tcpMetric = $connection['networked'] && is_string($connection['host']) && is_int($connection['port'])
            ? $this->probeTcpMetric($connection['host'], $connection['port'], $sampleCount, $timeoutMs)
            : null;

        $validationError = $this->connectionValidationError($connection);

        if ($validationError !== null) {
            return [
                'label' => $connection['label'],
                'source' => $connection['source'],
                'connection_name' => $connection['connection_name'],
                'driver' => $connection['driver'],
                'host' => $connection['host'],
                'port' => $connection['port'],
                'socket' => $connection['socket'],
                'database' => $connection['database'],
                'username' => $connection['username'],
                'using_url' => $connection['using_url'],
                'network_target' => $this->networkTarget($connection),
                'resolved_ips' => $resolvedIps,
                'server_version' => null,
                'summary' => $validationError,
                'errors' => [$validationError],
                'tcp_metric' => $tcpMetric,
                'connect_metric' => $this->failedMetric('DB connect', $sampleCount, $validationError),
                'query_metric' => $this->failedMetric('Query round-trip', $sampleCount, $validationError),
            ];
        }

        $connectLatencies = [];
        $queryLatencies = [];
        $connectError = null;
        $queryError = null;
        $serverVersion = null;

        for ($attempt = 0; $attempt < $sampleCount; $attempt++) {
            $probe = $this->measureDatabaseRoundTrip($connection, $timeoutMs);

            if (is_float($probe['connect_ms'])) {
                $connectLatencies[] = $probe['connect_ms'];
            }

            if (is_float($probe['query_ms'])) {
                $queryLatencies[] = $probe['query_ms'];
            }

            if (is_string($probe['connect_error']) && $probe['connect_error'] !== '') {
                $connectError = $probe['connect_error'];
            }

            if (is_string($probe['query_error']) && $probe['query_error'] !== '') {
                $queryError = $probe['query_error'];
            }

            if ($serverVersion === null && is_string($probe['server_version']) && $probe['server_version'] !== '') {
                $serverVersion = $probe['server_version'];
            }
        }

        $connectMetric = $this->buildMetric('DB connect', $connectLatencies, $sampleCount, $connectError);
        $queryMetric = $this->buildMetric('Query round-trip', $queryLatencies, $sampleCount, $queryError);

        $errors = array_values(array_unique(array_filter([
            $tcpMetric['last_error'] ?? null,
            $connectMetric['last_error'],
            $queryMetric['last_error'],
        ], static fn (?string $value): bool => is_string($value) && $value !== '')));

        return [
            'label' => $connection['label'],
            'source' => $connection['source'],
            'connection_name' => $connection['connection_name'],
            'driver' => $connection['driver'],
            'host' => $connection['host'],
            'port' => $connection['port'],
            'socket' => $connection['socket'],
            'database' => $connection['database'],
            'username' => $connection['username'],
            'using_url' => $connection['using_url'],
            'network_target' => $this->networkTarget($connection),
            'resolved_ips' => $resolvedIps,
            'server_version' => $serverVersion,
            'summary' => $this->databaseSummary($connection, $tcpMetric, $connectMetric, $queryMetric),
            'errors' => $errors,
            'tcp_metric' => $tcpMetric,
            'connect_metric' => $connectMetric,
            'query_metric' => $queryMetric,
        ];
    }

    /**
     * @param  NormalizedConnection  $connection
     * @return array{connect_ms: float|null, query_ms: float|null, server_version: string|null, connect_error: string|null, query_error: string|null}
     */
    protected function measureDatabaseRoundTrip(array $connection, int $timeoutMs): array
    {
        $pdo = null;

        try {
            $connectStartedAt = hrtime(true);
            $pdo = $this->createPdo($connection, $timeoutMs);
            $connectMs = round((hrtime(true) - $connectStartedAt) / 1_000_000, 2);
            $serverVersion = $this->serverVersion($pdo);
        } catch (Throwable $throwable) {
            return [
                'connect_ms' => null,
                'query_ms' => null,
                'server_version' => null,
                'connect_error' => $this->exceptionMessage($throwable),
                'query_error' => null,
            ];
        }

        try {
            $queryStartedAt = hrtime(true);
            $statement = $pdo->query('SELECT 1');

            if ($statement === false) {
                throw new InvalidArgumentException('SELECT 1 returned no result handle.');
            }

            $statement->fetchColumn();
            $queryMs = round((hrtime(true) - $queryStartedAt) / 1_000_000, 2);

            return [
                'connect_ms' => $connectMs,
                'query_ms' => $queryMs,
                'server_version' => $serverVersion,
                'connect_error' => null,
                'query_error' => null,
            ];
        } catch (Throwable $throwable) {
            return [
                'connect_ms' => $connectMs,
                'query_ms' => null,
                'server_version' => $serverVersion,
                'connect_error' => null,
                'query_error' => $this->exceptionMessage($throwable),
            ];
        } finally {
            $pdo = null;
        }
    }

    /**
     * @param  NormalizedConnection  $connection
     */
    protected function createPdo(array $connection, int $timeoutMs): PDO
    {
        $driver = $connection['driver'];

        if (! is_string($driver) || $driver === '') {
            throw new InvalidArgumentException('Database driver is not configured.');
        }

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];

        if (in_array($driver, ['mysql', 'mariadb', 'sqlite', 'sqlsrv'], true)) {
            $options[PDO::ATTR_TIMEOUT] = max(1, (int) ceil($timeoutMs / 1000));
        }

        if (in_array($driver, ['mysql', 'mariadb'], true) && defined('PDO::ATTR_EMULATE_PREPARES')) {
            $options[PDO::ATTR_EMULATE_PREPARES] = false;
        }

        return new PDO(
            $this->buildDsn($connection, $timeoutMs),
            $connection['username'],
            $connection['password'],
            $options,
        );
    }

    /**
     * @param  NormalizedConnection  $connection
     */
    protected function buildDsn(array $connection, int $timeoutMs): string
    {
        $driver = $connection['driver'];

        if (! is_string($driver) || $driver === '') {
            throw new InvalidArgumentException('Database driver is not configured.');
        }

        return match ($driver) {
            'mysql', 'mariadb' => $this->buildMySqlDsn($connection),
            'pgsql' => $this->buildPgSqlDsn($connection, $timeoutMs),
            'sqlsrv' => $this->buildSqlSrvDsn($connection, $timeoutMs),
            'sqlite' => $this->buildSqliteDsn($connection),
            default => throw new InvalidArgumentException(sprintf('Unsupported database driver [%s].', $driver)),
        };
    }

    /**
     * @param  NormalizedConnection  $connection
     */
    protected function buildMySqlDsn(array $connection): string
    {
        $segments = [];

        if (is_string($connection['socket']) && $connection['socket'] !== '') {
            $segments[] = 'unix_socket='.$connection['socket'];
        } else {
            if (! is_string($connection['host']) || $connection['host'] === '') {
                throw new InvalidArgumentException('MySQL target needs a host or unix socket.');
            }

            $segments[] = 'host='.$connection['host'];

            if (is_int($connection['port'])) {
                $segments[] = 'port='.$connection['port'];
            }
        }

        if (is_string($connection['database']) && $connection['database'] !== '') {
            $segments[] = 'dbname='.$connection['database'];
        }

        if (is_string($connection['charset']) && $connection['charset'] !== '') {
            $segments[] = 'charset='.$connection['charset'];
        }

        return 'mysql:'.implode(';', $segments);
    }

    /**
     * @param  NormalizedConnection  $connection
     */
    protected function buildPgSqlDsn(array $connection, int $timeoutMs): string
    {
        if (! is_string($connection['host']) || $connection['host'] === '') {
            throw new InvalidArgumentException('PostgreSQL target needs a host.');
        }

        $segments = [
            'host='.$connection['host'],
            'port='.($connection['port'] ?? 5432),
            'dbname='.($connection['database'] ?? ''),
            'connect_timeout='.max(1, (int) ceil($timeoutMs / 1000)),
        ];

        if (is_string($connection['sslmode']) && $connection['sslmode'] !== '') {
            $segments[] = 'sslmode='.$connection['sslmode'];
        }

        return 'pgsql:'.implode(';', $segments);
    }

    /**
     * @param  NormalizedConnection  $connection
     */
    protected function buildSqlSrvDsn(array $connection, int $timeoutMs): string
    {
        if (! is_string($connection['host']) || $connection['host'] === '') {
            throw new InvalidArgumentException('SQL Server target needs a host.');
        }

        $server = $connection['host'];

        if (is_int($connection['port'])) {
            $server .= ','.$connection['port'];
        }

        $segments = [
            'Server='.$server,
            'Database='.($connection['database'] ?? ''),
            'LoginTimeout='.max(1, (int) ceil($timeoutMs / 1000)),
        ];

        return 'sqlsrv:'.implode(';', $segments);
    }

    /**
     * @param  NormalizedConnection  $connection
     */
    protected function buildSqliteDsn(array $connection): string
    {
        if (! is_string($connection['database']) || $connection['database'] === '') {
            throw new InvalidArgumentException('SQLite target needs a database path.');
        }

        if ($connection['database'] === ':memory:') {
            return 'sqlite::memory:';
        }

        return 'sqlite:'.$connection['database'];
    }

    protected function serverVersion(PDO $pdo): ?string
    {
        try {
            $version = trim((string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION));

            return $version !== '' ? $version : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return DiagnosticsMetric
     */
    protected function probeTcpMetric(string $host, int $port, int $sampleCount, int $timeoutMs): array
    {
        $latencies = [];
        $lastError = null;

        for ($attempt = 0; $attempt < $sampleCount; $attempt++) {
            $result = $this->measureTcpConnect($host, $port, $timeoutMs);

            if (is_float($result['latency_ms'])) {
                $latencies[] = $result['latency_ms'];
            }

            if (is_string($result['error']) && $result['error'] !== '') {
                $lastError = $result['error'];
            }
        }

        return $this->buildMetric('TCP connect', $latencies, $sampleCount, $lastError);
    }

    /**
     * @return array{latency_ms: float|null, error: string|null}
     */
    protected function measureTcpConnect(string $host, int $port, int $timeoutMs): array
    {
        $errorNumber = 0;
        $errorMessage = '';

        $startedAt = hrtime(true);
        $stream = @stream_socket_client(
            sprintf('tcp://%s:%d', $host, $port),
            $errorNumber,
            $errorMessage,
            $timeoutMs / 1000,
            STREAM_CLIENT_CONNECT,
        );
        $elapsedMs = (hrtime(true) - $startedAt) / 1_000_000;

        if (is_resource($stream)) {
            fclose($stream);

            return [
                'latency_ms' => round($elapsedMs, 2),
                'error' => null,
            ];
        }

        return [
            'latency_ms' => null,
            'error' => trim((string) $errorMessage) !== ''
                ? trim((string) $errorMessage)
                : sprintf('Connection failed (%d).', $errorNumber),
        ];
    }

    /**
     * @param  list<float>  $latencies
     * @return DiagnosticsMetric
     */
    protected function buildMetric(string $label, array $latencies, int $sampleCount, ?string $lastError): array
    {
        sort($latencies);

        $averageMs = $latencies !== []
            ? round(array_sum($latencies) / count($latencies), 2)
            : null;
        $minMs = $latencies !== [] ? round($latencies[0], 2) : null;
        $maxMs = $latencies !== [] ? round($latencies[count($latencies) - 1], 2) : null;

        return [
            'label' => $label,
            'samples' => $sampleCount,
            'success_count' => count($latencies),
            'failure_count' => $sampleCount - count($latencies),
            'average_ms' => $averageMs,
            'min_ms' => $minMs,
            'max_ms' => $maxMs,
            'jitter_ms' => $minMs !== null && $maxMs !== null
                ? round($maxMs - $minMs, 2)
                : null,
            'last_error' => $lastError,
        ];
    }

    /**
     * @return DiagnosticsMetric
     */
    protected function failedMetric(string $label, int $sampleCount, string $error): array
    {
        return [
            'label' => $label,
            'samples' => $sampleCount,
            'success_count' => 0,
            'failure_count' => $sampleCount,
            'average_ms' => null,
            'min_ms' => null,
            'max_ms' => null,
            'jitter_ms' => null,
            'last_error' => $error,
        ];
    }

    /**
     * @param  DiagnosticsMetric|null  $currentMetric
     * @param  DiagnosticsMetric|null  $targetMetric
     * @return DiagnosticsMetricComparison|null
     */
    protected function buildMetricComparison(string $metricLabel, string $currentLabel, ?array $currentMetric, string $targetLabel, ?array $targetMetric): ?array
    {
        $currentMs = $currentMetric['average_ms'] ?? null;
        $targetMs = $targetMetric['average_ms'] ?? null;

        if (! is_float($currentMs) || ! is_float($targetMs)) {
            return null;
        }

        $differenceMs = round($targetMs - $currentMs, 2);
        $targetFaster = $differenceMs < 0;

        return [
            'metric_label' => $metricLabel,
            'current_label' => $currentLabel,
            'current_ms' => $currentMs,
            'target_label' => $targetLabel,
            'target_ms' => $targetMs,
            'difference_ms' => $differenceMs,
            'target_faster' => $targetFaster,
            'summary' => $targetFaster
                ? sprintf('%s is %.2f ms faster on %s than %s.', $targetLabel, abs($differenceMs), strtolower($metricLabel), $currentLabel)
                : sprintf('%s is %.2f ms slower on %s than %s.', $targetLabel, abs($differenceMs), strtolower($metricLabel), $currentLabel),
        ];
    }

    /**
     * @return NormalizedConnection
     */
    protected function currentDatabaseConfig(): array
    {
        $connectionName = (string) config('database.default', '');
        $connection = config('database.connections.'.$connectionName);

        return $this->normalizeConnectionConfig(
            is_array($connection) ? $connection : [],
            'Current environment database',
            'Laravel default connection',
            $connectionName !== '' ? $connectionName : null,
        );
    }

    /**
     * @return NormalizedConnection|null
     */
    protected function targetDatabaseConfig(): ?array
    {
        $rawConfig = [
            'driver' => $this->envString('NETWORK_DIAGNOSTICS_TARGET_DB_DRIVER'),
            'url' => $this->envString('NETWORK_DIAGNOSTICS_TARGET_DB_URL'),
            'host' => $this->envString('NETWORK_DIAGNOSTICS_TARGET_DB_HOST'),
            'port' => $this->envIntOrNull('NETWORK_DIAGNOSTICS_TARGET_DB_PORT'),
            'database' => $this->envString('NETWORK_DIAGNOSTICS_TARGET_DB_DATABASE'),
            'username' => $this->envString('NETWORK_DIAGNOSTICS_TARGET_DB_USERNAME'),
            'password' => $this->envString('NETWORK_DIAGNOSTICS_TARGET_DB_PASSWORD'),
            'socket' => $this->envString('NETWORK_DIAGNOSTICS_TARGET_DB_SOCKET'),
            'charset' => $this->envString('NETWORK_DIAGNOSTICS_TARGET_DB_CHARSET'),
            'sslmode' => $this->envString('NETWORK_DIAGNOSTICS_TARGET_DB_SSLMODE'),
        ];
        $hasAnyValue = array_any($rawConfig, fn ($value) => $value !== null && $value !== '');

        if (! $hasAnyValue) {
            return null;
        }

        return $this->normalizeConnectionConfig(
            $rawConfig,
            $this->envString('NETWORK_DIAGNOSTICS_TARGET_DB_LABEL', 'Target database') ?? 'Target database',
            'NETWORK_DIAGNOSTICS_TARGET_DB_*',
            null,
        );
    }

    /**
     * @param  array<string, mixed>  $connection
     * @return NormalizedConnection
     */
    protected function normalizeConnectionConfig(array $connection, string $label, string $source, ?string $connectionName): array
    {
        $url = $this->nullableString($connection['url'] ?? null);
        $parsedUrl = $this->parseDatabaseUrl($url);
        $driver = $this->normalizeDriver($this->nullableString($connection['driver'] ?? null) ?? ($parsedUrl['driver'] ?? null));
        $host = $this->nullableString($connection['host'] ?? null) ?? ($parsedUrl['host'] ?? null);
        $port = $this->nullableInt($connection['port'] ?? null) ?? ($parsedUrl['port'] ?? null) ?? $this->defaultPort($driver);
        $database = $this->nullableString($connection['database'] ?? null) ?? ($parsedUrl['database'] ?? null);
        $username = $this->nullableString($connection['username'] ?? null) ?? ($parsedUrl['username'] ?? null);
        $password = $this->nullableString($connection['password'] ?? null) ?? ($parsedUrl['password'] ?? null);
        $socket = $this->nullableString($connection['unix_socket'] ?? null)
            ?? $this->nullableString($connection['socket'] ?? null)
            ?? ($parsedUrl['socket'] ?? null);
        $charset = $this->nullableString($connection['charset'] ?? null) ?? ($parsedUrl['charset'] ?? null);
        $sslmode = $this->nullableString($connection['sslmode'] ?? null) ?? ($parsedUrl['sslmode'] ?? null);

        return [
            'label' => $label,
            'source' => $source,
            'connection_name' => $connectionName,
            'driver' => $driver,
            'url' => $url,
            'host' => $host,
            'port' => $port,
            'database' => $database,
            'username' => $username,
            'password' => $password,
            'socket' => $socket,
            'charset' => $charset,
            'sslmode' => $sslmode,
            'using_url' => $url !== null,
            'networked' => $driver !== 'sqlite' && $host !== null && $port !== null,
        ];
    }

    /**
     * @return array{driver?: string, host?: string, port?: int, database?: string, username?: string, password?: string, socket?: string, charset?: string, sslmode?: string}
     */
    protected function parseDatabaseUrl(?string $url): array
    {
        if ($url === null || trim($url) === '') {
            return [];
        }

        $parts = parse_url($url);

        if (! is_array($parts)) {
            return [];
        }

        $query = [];

        if (isset($parts['query']) && is_string($parts['query'])) {
            parse_str($parts['query'], $query);
        }

        $driver = $this->normalizeDriver($parts['scheme'] ?? null);

        $database = null;

        if ($driver === 'sqlite') {
            $database = isset($parts['path']) ? rawurldecode($parts['path']) : null;
        } elseif (isset($parts['path']) && is_string($parts['path'])) {
            $trimmedPath = ltrim($parts['path'], '/');
            $database = $trimmedPath !== '' ? rawurldecode($trimmedPath) : null;
        }

        return array_filter([
            'driver' => $driver,
            'host' => $parts['host'] ?? null,
            'port' => $parts['port'] ?? null,
            'database' => $database,
            'username' => isset($parts['user']) ? rawurldecode($parts['user']) : null,
            'password' => isset($parts['pass']) ? rawurldecode($parts['pass']) : null,
            'socket' => is_string($query['socket'] ?? null) ? trim($query['socket']) : null,
            'charset' => is_string($query['charset'] ?? null) ? trim($query['charset']) : null,
            'sslmode' => is_string($query['sslmode'] ?? null) ? trim($query['sslmode']) : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  NormalizedConnection  $connection
     */
    protected function connectionValidationError(array $connection): ?string
    {
        $driver = $connection['driver'];

        if (! is_string($driver) || $driver === '') {
            return 'Database driver is missing. Check the connection settings in the active environment.';
        }

        return match ($driver) {
            'sqlite' => ! is_string($connection['database']) || $connection['database'] === ''
                ? 'SQLite diagnostics require a database path.'
                : null,
            'mysql', 'mariadb' => (! is_string($connection['host']) || $connection['host'] === '') && (! is_string($connection['socket']) || $connection['socket'] === '')
                ? 'MySQL diagnostics require a host:port or unix socket.'
                : ((! is_string($connection['database']) || $connection['database'] === '')
                    ? 'MySQL diagnostics require a database name.'
                    : null),
            'pgsql', 'sqlsrv' => ! is_string($connection['host']) || $connection['host'] === ''
                ? 'This database driver requires a host to probe latency.'
                : ((! is_string($connection['database']) || $connection['database'] === '')
                    ? 'This database driver requires a database name.'
                    : null),
            default => sprintf('Unsupported database driver [%s].', $driver),
        };
    }

    /**
     * @param  NormalizedConnection  $connection
     * @param  DiagnosticsMetric|null  $tcpMetric
     * @param  DiagnosticsMetric  $connectMetric
     * @param  DiagnosticsMetric  $queryMetric
     */
    protected function databaseSummary(array $connection, ?array $tcpMetric, array $connectMetric, array $queryMetric): string
    {
        $queryAverageMs = $queryMetric['average_ms'];

        if (is_float($queryAverageMs)) {
            if ($queryAverageMs <= 5.0) {
                return 'Excellent query round-trip latency for database work.';
            }

            if ($queryAverageMs <= 15.0) {
                return 'Healthy query round-trip latency for most application workloads.';
            }

            if ($queryAverageMs <= 40.0) {
                return 'Usable query latency, but chatty ORM patterns will start to feel it.';
            }

            return 'High query latency. Expect noticeable overhead on synchronous database-heavy requests.';
        }

        $connectAverageMs = $connectMetric['average_ms'];

        if (is_float($connectAverageMs)) {
            return 'The database accepts connections, but the simple query probe failed afterward.';
        }

        if ($tcpMetric !== null && is_float($tcpMetric['average_ms'])) {
            return 'The network path is reachable, but the database connection itself failed.';
        }

        if ($connection['networked']) {
            return 'The TCP probe did not reach the database host, so the target is likely blocked, closed, or offline.';
        }

        return 'The probe could not establish a working connection for this database configuration.';
    }

    /**
     * @param  NormalizedConnection  $connection
     */
    protected function networkTarget(array $connection): ?string
    {
        if ($connection['networked'] && is_string($connection['host']) && is_int($connection['port'])) {
            return $connection['host'].':'.$connection['port'];
        }

        if (is_string($connection['socket']) && $connection['socket'] !== '') {
            return $connection['socket'];
        }

        if ($connection['driver'] === 'sqlite') {
            return $connection['database'];
        }

        return null;
    }

    /**
     * @return list<string>
     */
    protected function resolveHost(?string $host): array
    {
        if (! is_string($host) || $host === '') {
            return [];
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $resolvedIps = gethostbynamel($host);

        if (! is_array($resolvedIps)) {
            return [];
        }

        return array_values(array_filter(array_unique($resolvedIps), static fn (string $value): bool => $value !== ''));
    }

    protected function normalizeDriver(?string $driver): ?string
    {
        if ($driver === null) {
            return null;
        }

        $normalizedDriver = strtolower(trim($driver));

        return match ($normalizedDriver) {
            'postgres', 'postgresql' => 'pgsql',
            'mysql', 'mariadb', 'pgsql', 'sqlite', 'sqlsrv' => $normalizedDriver,
            default => $normalizedDriver !== '' ? $normalizedDriver : null,
        };
    }

    protected function defaultPort(?string $driver): ?int
    {
        return match ($driver) {
            'mysql', 'mariadb' => 3306,
            'pgsql' => 5432,
            'sqlsrv' => 1433,
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    protected function targetEnvironmentVariables(): array
    {
        return [
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
    }

    protected function exceptionMessage(Throwable $throwable): string
    {
        $message = trim($throwable->getMessage());

        return $message !== '' ? $message : $throwable::class;
    }

    protected function envString(string $key, ?string $default = null): ?string
    {
        return $this->environment->string($key, $default);
    }

    protected function envInt(string $key, int $default): int
    {
        return $this->environment->int($key, $default);
    }

    protected function envIntOrNull(string $key): ?int
    {
        return $this->environment->intOrNull($key);
    }

    protected function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $resolved = trim((string) $value);

        return $resolved !== '' ? $resolved : null;
    }

    protected function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }
}
