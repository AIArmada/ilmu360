<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\ParallelTesting;
use PHPUnit\Framework\Assert;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Self-provisioned PostgreSQL 17 fixture for real-database tests.
 */
final class RealDriverFixture
{
    public const string BASE_CONNECTION = 'real_driver_fixture_base';

    public const string POSTGRES_PORT = '45432';

    private const string BASE_DATABASE = 'share_rewards_test';

    private const string DATABASE_ROLE = 'share_rewards_test';

    /** @var array<string, string>|null */
    private static ?array $binaries = null;

    private static bool $shutdownRegistered = false;

    private static bool $leaseAcquired = false;

    /** @var resource|null */
    private static mixed $stateLock = null;

    public static function ensureRunning(): void
    {
        if (self::$leaseAcquired) {
            return;
        }

        self::registerShutdownHandler();
        $binaries = self::binaries();
        $lock = self::acquireLock();

        try {
            $state = self::readState();
            $state['leases'] = self::liveLeases($state['leases']);

            if ($state['leases'] === []) {
                self::reapPreviousServer($state, $binaries);
                $state = self::emptyState();
            }

            $server = $state['server'] ?? null;
            $dataDirectory = self::dataDirectory();
            $binary = $binaries['server'];
            $port = (int) self::POSTGRES_PORT;

            if ($state['leases'] !== []) {
                if (! is_array($server) || ! (bool) ($server['owned_by_fixture'] ?? false)) {
                    throw new RuntimeException('Active PostgreSQL fixture leases have no owned server record.');
                }

                self::assertOwnerMarker($binary, $dataDirectory);

                if (! self::listenerIsOpen($port) || ! self::serverProcessMatches($binary, $dataDirectory, self::readServerPid())) {
                    throw new RuntimeException('The owned PostgreSQL fixture stopped while parallel workers still held leases.');
                }
            } else {
                if (self::listenerIsOpen($port)) {
                    throw new RuntimeException("Port {$port} is occupied by a foreign process; refusing to use or stop it for PostgreSQL tests.");
                }

                self::prepareDataDirectory($binaries, $binary, $dataDirectory);
                $state['server'] = [
                    'owned_by_fixture' => true,
                    'binary' => $binary,
                    'data_directory' => $dataDirectory,
                    'pid' => null,
                    'ready' => false,
                ];
                self::writeState($state);
                self::startPostgres($binaries, $dataDirectory);
                self::waitForServer($binary, $dataDirectory);
                $state['server']['pid'] = self::readServerPid();
                $state['server']['ready'] = true;
                self::ensureRoleAndDatabase($binaries);
            }

            $processId = getmypid();

            if ($processId === false) {
                throw new RuntimeException('Unable to determine the real PostgreSQL test worker PID.');
            }

            $processCommand = self::processCommand($processId);

            if ($processCommand === '') {
                throw new RuntimeException("Unable to verify the real PostgreSQL test worker command line for PID {$processId}.");
            }

            $state['leases'][(string) $processId] = [
                'pid' => $processId,
                'command' => $processCommand,
            ];
            self::writeState($state);
            self::$leaseAcquired = true;
        } finally {
            self::releaseLock($lock);
        }
    }

    /** @return array<string, mixed> */
    public static function connectionConfig(?string $database = null): array
    {
        self::ensureRunning();

        $config = [
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => (int) self::POSTGRES_PORT,
            'database' => self::BASE_DATABASE,
            'username' => self::DATABASE_ROLE,
            'password' => '',
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ];
        self::guardBaseConfig($config);

        if ($database !== null) {
            self::guardWorkerDatabaseName($database);
            $config['database'] = $database;
        }

        return $config;
    }

    /** @param array<string, mixed> $config */
    public static function guardBaseConfig(array $config): void
    {
        Assert::assertSame('pgsql', (string) ($config['driver'] ?? ''), 'The real-driver fixture supports PostgreSQL only.');
        Assert::assertSame('127.0.0.1', (string) ($config['host'] ?? ''), 'Isolated fixtures are loopback only.');
        Assert::assertSame((int) self::POSTGRES_PORT, (int) ($config['port'] ?? 0), 'Refusing a non-isolated database port.');
        Assert::assertSame(self::BASE_DATABASE, (string) ($config['database'] ?? ''), 'Refusing a non-isolated database.');
    }

    public static function guardWorkerDatabaseName(string $database): void
    {
        Assert::assertMatchesRegularExpression(
            '/^share_rewards_test_[a-z0-9_]{1,43}$/',
            $database,
            'Worker databases stay inside the isolated share_rewards_test namespace.',
        );
    }

    public static function workerDatabaseName(
        string $test,
        string $databasePrefix = 'share_rewards_test_',
        string $namePrefix = '',
    ): string {
        Assert::assertMatchesRegularExpression('/^share_rewards_test(?:_rewards_finance)?_$/', $databasePrefix);
        $token = ParallelTesting::token();
        $raw = $namePrefix.($token !== false ? $token : 'serial').'_'.getmypid().'_'.$test;
        $suffix = strtolower((string) preg_replace('/[^a-z0-9]+/', '_', $raw));
        $name = $databasePrefix.substr($suffix, 0, 62 - strlen($databasePrefix));
        self::guardWorkerDatabaseName($name);

        return $name;
    }

    public static function baseConnection(): Connection
    {
        config(['database.connections.'.self::BASE_CONNECTION => self::connectionConfig()]);
        DB::purge(self::BASE_CONNECTION);

        $connection = DB::connection(self::BASE_CONNECTION);
        self::assertServerIdentity($connection);

        return $connection;
    }

    public static function assertServerIdentity(Connection $connection): void
    {
        $version = (string) $connection->selectOne('select version() as version')->version;
        Assert::assertStringStartsWith('PostgreSQL 17.', $version, 'Expected the isolated PostgreSQL 17 fixture.');
    }

    public static function createWorkerDatabase(
        string $test,
        string $databasePrefix = 'share_rewards_test_',
        string $namePrefix = '',
    ): string {
        $database = self::workerDatabaseName($test, $databasePrefix, $namePrefix);
        self::createDatabase($database);

        return $database;
    }

    public static function createDatabase(string $database): void
    {
        self::guardWorkerDatabaseName($database);
        $connection = self::baseConnection();
        self::dropDatabaseWithConnection($database, $connection);
        $connection->statement('CREATE DATABASE "'.$database.'"');
    }

    public static function dropDatabase(string $database): void
    {
        self::guardWorkerDatabaseName($database);
        DB::purge(self::BASE_CONNECTION);
        self::dropDatabaseWithConnection($database, self::baseConnection());
        DB::purge(self::BASE_CONNECTION);
    }

    public static function useWorkerDatabase(string $database, string $connectionName): string
    {
        $config = self::connectionConfig($database);
        $previous = (string) config('database.default');
        config(['database.connections.'.$connectionName => $config]);
        config(['database.default' => $connectionName]);
        DB::purge($connectionName);
        DB::reconnect($connectionName);

        return $previous;
    }

    public static function restoreDatabase(string $previousDefault, string $connectionName): void
    {
        DB::purge($connectionName);
        config(['database.default' => $previousDefault]);
        DB::purge($connectionName);
        DB::purge(self::BASE_CONNECTION);
    }

    private static function registerShutdownHandler(): void
    {
        if (self::$shutdownRegistered) {
            return;
        }

        self::$shutdownRegistered = true;
        register_shutdown_function(static function (): void {
            try {
                self::releaseLease();
            } catch (Throwable $exception) {
                fwrite(STDERR, 'RealDriverFixture cleanup failed: '.$exception->getMessage().PHP_EOL);
                exit(1);
            }
        });
    }

    private static function releaseLease(): void
    {
        if (! self::$leaseAcquired) {
            return;
        }

        $lock = self::acquireLock();

        try {
            $state = self::readState();
            $state['leases'] = self::liveLeases($state['leases']);
            unset($state['leases'][(string) getmypid()]);

            if ($state['leases'] !== []) {
                self::writeState($state);

                return;
            }

            self::stopOwnedServer($state, self::binaries());
            self::writeState(self::emptyState());
            self::$leaseAcquired = false;
        } finally {
            self::releaseLock($lock);
        }
    }

    /** @param array<string, string> $binaries */
    private static function prepareDataDirectory(array $binaries, string $binary, string $dataDirectory): void
    {
        $directory = dirname($dataDirectory);

        if (is_link($directory) || is_link($dataDirectory)) {
            throw new RuntimeException("PostgreSQL data directory [{$dataDirectory}] must not be a symbolic link.");
        }

        self::ensureDirectory($directory);
        self::ensureDirectory($dataDirectory);
        self::assertOwnerMarker($binary, $dataDirectory, allowMissing: true);

        if (is_file($dataDirectory.'/PG_VERSION')) {
            self::assertOwnerMarker($binary, $dataDirectory);

            return;
        }

        if (self::directoryHasEntries($dataDirectory)) {
            throw new RuntimeException("PostgreSQL data directory [{$dataDirectory}] is incomplete and will not be overwritten.");
        }

        self::writeOwnerMarker($binary, $dataDirectory);
        self::runProcess([
            $binaries['initdb'],
            '-D',
            $dataDirectory,
            '--username='.self::DATABASE_ROLE,
            '--auth=trust',
            '--no-locale',
            '--encoding=UTF8',
            '--set=dynamic_shared_memory_type=mmap',
        ], timeout: 120);
    }

    /** @param array<string, string> $binaries */
    private static function startPostgres(array $binaries, string $dataDirectory): void
    {
        $directory = dirname($dataDirectory);
        $options = '-h 127.0.0.1 -p '.self::POSTGRES_PORT
            .' -c listen_addresses=127.0.0.1'
            .' -c unix_socket_directories='.$directory;
        self::runProcess([
            $binaries['pg_ctl'],
            '-D', $dataDirectory,
            '-l', $directory.'/postgresql.log',
            '-o', $options,
            '-w', '-t', '60', 'start',
        ], timeout: 80);
    }

    private static function waitForServer(string $binary, string $dataDirectory): void
    {
        $deadline = microtime(true) + 30;
        $port = (int) self::POSTGRES_PORT;

        do {
            if (self::listenerIsOpen($port) && self::serverProcessMatches($binary, $dataDirectory, self::readServerPid())) {
                return;
            }

            usleep(100_000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException("PostgreSQL did not become ready on 127.0.0.1:{$port}.");
    }

    /** @param array<string, string> $binaries */
    private static function ensureRoleAndDatabase(array $binaries): void
    {
        $arguments = [
            $binaries['psql'],
            '-h', '127.0.0.1', '-p', self::POSTGRES_PORT,
            '-U', self::DATABASE_ROLE,
            '-d', 'postgres',
            '-v', 'ON_ERROR_STOP=1',
        ];
        $databaseExists = self::runProcess([
            ...$arguments,
            '-tAc',
            "SELECT 1 FROM pg_database WHERE datname = '".self::BASE_DATABASE."'",
        ]);

        if (trim($databaseExists->getOutput()) === '1') {
            return;
        }

        self::runProcess([
            $binaries['createdb'],
            '-h', '127.0.0.1', '-p', self::POSTGRES_PORT,
            '-U', self::DATABASE_ROLE,
            '-O', self::DATABASE_ROLE,
            self::BASE_DATABASE,
        ], timeout: 45);
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, string> $binaries
     */
    private static function reapPreviousServer(array $state, array $binaries): void
    {
        $server = $state['server'] ?? null;
        $dataDirectory = self::dataDirectory();
        $binary = $binaries['server'];

        if (is_array($server) && (bool) ($server['owned_by_fixture'] ?? false)) {
            self::assertOwnerMarker($binary, $dataDirectory);
            $pid = self::readServerPid();

            if ($pid !== null && self::serverProcessMatches($binary, $dataDirectory, $pid)) {
                self::stopServer($binaries, $dataDirectory, $binary);
            } elseif (self::listenerIsOpen((int) self::POSTGRES_PORT)) {
                throw new RuntimeException('The isolated PostgreSQL port is open but its owner cannot be proven.');
            } else {
                self::removeStalePidFile($dataDirectory);
            }

            return;
        }

        if (self::listenerIsOpen((int) self::POSTGRES_PORT)) {
            throw new RuntimeException('Port 45432 is occupied by a process not owned by this fixture.');
        }

        if (is_file($dataDirectory.'/postmaster.pid')) {
            self::assertOwnerMarker($binary, $dataDirectory);
            self::removeStalePidFile($dataDirectory);
        }
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, string> $binaries
     */
    private static function stopOwnedServer(array $state, array $binaries): void
    {
        $server = $state['server'] ?? null;

        if (! is_array($server) || ! (bool) ($server['owned_by_fixture'] ?? false)) {
            if (self::listenerIsOpen((int) self::POSTGRES_PORT)) {
                throw new RuntimeException('Refusing to stop a PostgreSQL process without fixture ownership.');
            }

            return;
        }

        $dataDirectory = self::dataDirectory();
        $binary = $binaries['server'];
        self::assertOwnerMarker($binary, $dataDirectory);
        $pid = self::readServerPid();

        if ($pid !== null && self::serverProcessMatches($binary, $dataDirectory, $pid)) {
            self::stopServer($binaries, $dataDirectory, $binary);
        } elseif (self::listenerIsOpen((int) self::POSTGRES_PORT)) {
            throw new RuntimeException('The isolated PostgreSQL port is open but its owner cannot be proven.');
        } else {
            self::removeStalePidFile($dataDirectory);
        }
    }

    /** @param array<string, string> $binaries */
    private static function stopServer(array $binaries, string $dataDirectory, string $binary): void
    {
        $pid = self::readServerPid();

        if ($pid === null || ! self::serverProcessMatches($binary, $dataDirectory, $pid)) {
            throw new RuntimeException('Refusing to stop an unverified PostgreSQL process.');
        }

        self::runProcess([$binaries['pg_ctl'], '-D', $dataDirectory, '-m', 'fast', '-w', '-t', '60', 'stop'], timeout: 80);
        $deadline = microtime(true) + 20;

        do {
            if (! self::processExists($pid) && ! self::listenerIsOpen((int) self::POSTGRES_PORT)) {
                return;
            }

            usleep(100_000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException('The owned PostgreSQL fixture did not stop cleanly.');
    }

    private static function removeStalePidFile(string $dataDirectory): void
    {
        $pidFile = $dataDirectory.'/postmaster.pid';

        if (is_link($pidFile)) {
            throw new RuntimeException('Refusing to remove a symbolic PostgreSQL PID file.');
        }

        if (is_file($pidFile) && ! unlink($pidFile)) {
            throw new RuntimeException('Unable to remove the stale PostgreSQL PID file.');
        }
    }

    private static function assertOwnerMarker(string $binary, string $dataDirectory, bool $allowMissing = false): void
    {
        $path = self::ownerMarkerPath();

        if (! is_file($path)) {
            if ($allowMissing) {
                return;
            }

            throw new RuntimeException("PostgreSQL owner marker [{$path}] is missing.");
        }

        if (! self::ownerMarkerMatches($binary, $dataDirectory)) {
            throw new RuntimeException("PostgreSQL owner marker [{$path}] does not match this fixture.");
        }
    }

    private static function ownerMarkerMatches(string $binary, string $dataDirectory): bool
    {
        $marker = json_decode((string) @file_get_contents(self::ownerMarkerPath()), true);

        return is_array($marker)
            && ($marker['engine'] ?? null) === 'pgsql'
            && ($marker['binary'] ?? null) === $binary
            && ($marker['data_directory'] ?? null) === $dataDirectory;
    }

    private static function writeOwnerMarker(string $binary, string $dataDirectory): void
    {
        $path = self::ownerMarkerPath();
        self::ensureDirectory(dirname($path));
        $marker = [
            'engine' => 'pgsql',
            'binary' => $binary,
            'data_directory' => $dataDirectory,
            'created_at' => gmdate(DATE_ATOM),
        ];

        if (file_put_contents($path, json_encode($marker, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL, LOCK_EX) === false || ! chmod($path, 0600)) {
            throw new RuntimeException("Unable to write PostgreSQL owner marker [{$path}].");
        }
    }

    private static function serverProcessMatches(string $binary, string $dataDirectory, ?int $pid): bool
    {
        if ($pid === null || ! self::ownerMarkerMatches($binary, $dataDirectory)) {
            return false;
        }

        $command = self::processCommand($pid);

        return $command !== ''
            && str_contains($command, 'postgres')
            && str_contains($command, $dataDirectory);
    }

    private static function readServerPid(): ?int
    {
        $path = self::dataDirectory().'/postmaster.pid';

        if (! is_file($path)) {
            return null;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if (! isset($lines[0]) || ! ctype_digit(trim($lines[0])) || trim((string) ($lines[1] ?? '')) !== self::dataDirectory()) {
            return null;
        }

        return (int) trim($lines[0]);
    }

    private static function processCommand(int $pid): string
    {
        $process = new Process(['/bin/ps', '-ww', '-p', (string) $pid, '-o', 'command=']);
        $process->run();

        return $process->isSuccessful() ? trim($process->getOutput()) : '';
    }

    private static function processExists(int $pid): bool
    {
        return $pid > 1 && posix_kill($pid, 0);
    }

    /** @return array{leases: array<string, mixed>, server?: array<string, mixed>} */
    private static function emptyState(): array
    {
        return ['leases' => []];
    }

    /** @return array{leases: array<string, mixed>, server?: array<string, mixed>} */
    private static function readState(): array
    {
        $path = self::statePath();

        if (! is_file($path)) {
            return self::emptyState();
        }

        $state = json_decode((string) file_get_contents($path), true);
        if (! is_array($state) || ! is_array($state['leases'] ?? null)) {
            throw new RuntimeException("Real-driver state file [{$path}] is malformed; refusing to manage the PostgreSQL server.");
        }

        return $state;
    }

    /**
     * @param array<array-key, mixed> $leases
     * @return array<int, array{pid: int, command: string}>
     */
    private static function liveLeases(array $leases): array
    {
        $live = [];

        foreach ($leases as $lease) {
            if (! is_array($lease) || ! is_int($lease['pid'] ?? null) || ! is_string($lease['command'] ?? null)) {
                continue;
            }

            $pid = $lease['pid'];
            $command = self::processCommand($pid);
            if (self::processExists($pid) && $command !== '' && $command === $lease['command']) {
                $live[(string) $pid] = ['pid' => $pid, 'command' => $command];
            }
        }

        return $live;
    }

    /** @param array<string, mixed> $state */
    private static function writeState(array $state): void
    {
        $path = self::statePath();
        $temporaryPath = $path.'.'.getmypid().'.tmp';
        $json = json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;

        if (file_put_contents($temporaryPath, $json, LOCK_EX) === false || ! chmod($temporaryPath, 0600) || ! rename($temporaryPath, $path)) {
            throw new RuntimeException("Unable to write real-driver state file [{$path}].");
        }
    }

    /** @return resource */
    private static function acquireLock(): mixed
    {
        $path = self::lockPath();
        $lock = fopen($path, 'c');
        if ($lock === false || ! flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }

            throw new RuntimeException("Unable to lock real-driver fixture state [{$path}].");
        }

        self::$stateLock = $lock;

        return $lock;
    }

    /** @param resource $lock */
    private static function releaseLock(mixed $lock): void
    {
        $isStateLock = self::$stateLock === $lock;
        flock($lock, LOCK_UN);
        fclose($lock);

        if ($isStateLock) {
            self::$stateLock = null;
        }
    }

    private static function rootDirectory(): string
    {
        $root = base_path('storage/framework/testing/real-driver');
        if (is_link($root)) {
            throw new RuntimeException("Real-driver root [{$root}] must not be a symbolic link.");
        }

        self::ensureDirectory($root);
        $realRoot = realpath($root);
        $testingRoot = realpath(base_path('storage/framework/testing'));
        if ($realRoot === false || $testingRoot === false || ! str_starts_with($realRoot, $testingRoot.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException("Real-driver root [{$root}] must remain under storage/framework/testing.");
        }

        return $realRoot;
    }

    private static function statePath(): string
    {
        return self::rootDirectory().'/state.json';
    }

    private static function lockPath(): string
    {
        return self::rootDirectory().'/state.lock';
    }

    private static function dataDirectory(): string
    {
        return self::rootDirectory().'/postgresql/data';
    }

    private static function ownerMarkerPath(): string
    {
        return self::rootDirectory().'/postgresql/owner.json';
    }

    private static function dropDatabaseWithConnection(string $database, Connection $connection): void
    {
        $connection->statement('DROP DATABASE IF EXISTS "'.$database.'"');
    }

    private static function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create real-driver directory [{$directory}].");
        }
    }

    private static function directoryHasEntries(string $directory): bool
    {
        $entries = scandir($directory);

        return $entries !== false && count($entries) > 2;
    }

    private static function listenerIsOpen(int $port): bool
    {
        $socket = @fsockopen('127.0.0.1', $port, $errorCode, $errorMessage, 0.2);
        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }

    /** @return array<string, string> */
    private static function binaries(): array
    {
        if (self::$binaries !== null) {
            return self::$binaries;
        }

        $directories = [
            '/Users/Shared/DBngin/postgresql/17.0/bin',
            '/opt/homebrew/opt/postgresql@17/bin',
            '/usr/local/opt/postgresql@17/bin',
            '/opt/homebrew/opt/postgresql/bin',
            '/usr/local/opt/postgresql/bin',
        ];
        $binaryDirectory = getenv('ILMU360_PG17_BIN_DIR');
        $resolve = static function (string $name) use ($directories, $binaryDirectory): string {
            $searchDirectories = [];
            if (is_string($binaryDirectory) && $binaryDirectory !== '') {
                $searchDirectories[] = rtrim($binaryDirectory, DIRECTORY_SEPARATOR);
            }
            foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $pathDirectory) {
                if ($pathDirectory !== '') {
                    $searchDirectories[] = $pathDirectory;
                }
            }
            foreach (array_values(array_unique([...$searchDirectories, ...$directories])) as $directory) {
                $candidate = $directory.'/'.$name;
                if (is_file($candidate) && is_executable($candidate)) {
                    return realpath($candidate) ?: $candidate;
                }
            }

            throw new RuntimeException("Required PostgreSQL 17 binary [{$name}] was not found. Set ILMU360_PG17_BIN_DIR to its bin directory.");
        };
        self::$binaries = [
            'server' => $resolve('postgres'),
            'pg_ctl' => $resolve('pg_ctl'),
            'initdb' => $resolve('initdb'),
            'psql' => $resolve('psql'),
            'createdb' => $resolve('createdb'),
        ];

        foreach (['server', 'pg_ctl', 'initdb'] as $name) {
            $process = self::runProcess([self::$binaries[$name], '--version']);
            $version = trim($process->getOutput().$process->getErrorOutput());
            if (preg_match('/PostgreSQL\) 17\./', $version) !== 1) {
                throw new RuntimeException("Expected PostgreSQL 17 {$name}; [".self::$binaries[$name]."] reported [{$version}].");
            }
        }

        return self::$binaries;
    }

    /** @param list<string> $command */
    private static function runProcess(array $command, float $timeout = 45): Process
    {
        $process = new Process($command, base_path(), timeout: $timeout);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                'Real PostgreSQL command failed (exit '.$process->getExitCode().'): '
                .implode(' ', $command).PHP_EOL
                .$process->getOutput().$process->getErrorOutput(),
            );
        }

        return $process;
    }
}
