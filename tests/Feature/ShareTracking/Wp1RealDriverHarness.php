<?php

declare(strict_types=1);

namespace Tests\Feature\ShareTracking;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\ParallelTesting;
use PHPUnit\Framework\Assert;
use Symfony\Component\Process\Process;

/**
 * Guarded real-driver harness for share-tracking workflow proof.
 *
 * Spins up worker-namespaced databases on the isolated PostgreSQL 17
 * (127.0.0.1:45432) and MariaDB 11.4 (127.0.0.1:43306) fixtures, builds
 * schema from the current original migrations, and points the default
 * connection at the worker database so the actual application service
 * runs its real queries (and real model observers) against a real
 * driver. Never touches the live application database.
 */
final class Wp1RealDriverHarness
{
    public const string WORKER_CONNECTION = 'wp1_worker';

    public const string BASE_CONNECTION = 'wp1_base';

    /**
     * @return array{pgsql: array<string, mixed>, mariadb: array<string, mixed>}
     */
    public static function driverConfigs(): array
    {
        return [
            'pgsql' => [
                'driver' => 'pgsql',
                'host' => '127.0.0.1',
                'port' => 45432,
                'database' => 'share_rewards_test',
                'username' => 'share_rewards_test',
                'password' => '',
                'charset' => 'utf8',
                'prefix' => '',
                'prefix_indexes' => true,
                'search_path' => 'public',
                'sslmode' => 'prefer',
            ],
            'mariadb' => [
                'driver' => 'mariadb',
                'host' => '127.0.0.1',
                'port' => 43306,
                'database' => 'share_rewards_test',
                'username' => 'root',
                'password' => '',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'prefix_indexes' => true,
                'strict' => true,
                'engine' => null,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function guardBaseConfig(string $driver, array $config): void
    {
        $expectedPort = $driver === 'pgsql' ? 45432 : 43306;

        Assert::assertSame('127.0.0.1', (string) ($config['host'] ?? ''), 'Isolated fixtures are loopback only.');
        Assert::assertSame($expectedPort, (int) ($config['port'] ?? 0), 'Refusing a non-isolated database port.');
        Assert::assertSame('share_rewards_test', (string) ($config['database'] ?? ''), 'Refusing a non-isolated database.');
    }

    public static function guardWorkerDatabaseName(string $database): void
    {
        Assert::assertMatchesRegularExpression(
            '/^share_rewards_test_[a-z0-9_]{1,43}$/',
            $database,
            'Worker databases stay inside the isolated share_rewards_test namespace.',
        );
    }

    public static function workerDatabaseName(string $driver, string $test): string
    {
        $token = ParallelTesting::token();
        $raw = $driver.'_'.($token !== false ? $token : 'serial').'_'.getmypid().'_'.$test;
        $suffix = strtolower((string) preg_replace('/[^a-z0-9]+/', '_', $raw));

        $name = 'share_rewards_test_'.substr($suffix, 0, 43);

        self::guardWorkerDatabaseName($name);

        return $name;
    }

    public static function baseConnection(string $driver): Connection
    {
        $config = self::driverConfigs()[$driver] ?? null;

        Assert::assertIsArray($config, "Unknown worker driver [{$driver}].");
        self::guardBaseConfig($driver, $config);

        config(['database.connections.'.self::BASE_CONNECTION => $config]);
        DB::purge(self::BASE_CONNECTION);

        return DB::connection(self::BASE_CONNECTION);
    }

    public static function assertServerIdentity(string $driver, Connection $connection): void
    {
        $version = (string) $connection->selectOne('select version() as version')->version;

        if ($driver === 'pgsql') {
            Assert::assertStringStartsWith('PostgreSQL 17.', $version, 'Expected the isolated PostgreSQL 17 fixture.');
        } else {
            Assert::assertStringContainsString('MariaDB', $version, 'Expected the isolated MariaDB fixture.');
            Assert::assertStringContainsString('11.4.', $version, 'Expected the isolated MariaDB 11.4 fixture.');
        }
    }

    public static function createWorkerDatabase(string $driver, string $test): string
    {
        $database = self::workerDatabaseName($driver, $test);
        $connection = self::baseConnection($driver);

        self::assertServerIdentity($driver, $connection);

        // Idempotent: a crashed run must never block the next one. The
        // guarded namespace makes drop-if-exists safe.
        self::dropWorkerDatabase($driver, $database);

        if ($driver === 'pgsql') {
            $connection->statement('CREATE DATABASE "'.$database.'"');
        } else {
            $connection->statement('CREATE DATABASE `'.$database.'`');
        }

        return $database;
    }

    /**
     * @return array<string, mixed>
     */
    public static function workerConfig(string $driver, string $database): array
    {
        $config = self::driverConfigs()[$driver] ?? null;

        Assert::assertIsArray($config, "Unknown worker driver [{$driver}].");

        $config['database'] = $database;

        return $config;
    }

    /**
     * Switch the default connection to the worker database. Returns the
     * previous default connection name so callers can restore it.
     */
    public static function useWorkerDatabase(string $driver, string $database): string
    {
        self::guardBaseConfig($driver, self::driverConfigs()[$driver]);
        self::guardWorkerDatabaseName($database);

        $previous = (string) config('database.default');

        config(['database.connections.'.self::WORKER_CONNECTION => self::workerConfig($driver, $database)]);
        config(['database.default' => self::WORKER_CONNECTION]);
        DB::purge(self::WORKER_CONNECTION);
        DB::reconnect(self::WORKER_CONNECTION);

        return $previous;
    }

    public static function restoreDatabase(string $previousDefault): void
    {
        DB::purge(self::WORKER_CONNECTION);
        config(['database.default' => $previousDefault]);
        DB::purge(self::WORKER_CONNECTION);
        DB::purge(self::BASE_CONNECTION);
    }

    public static function migrateWorkerDatabase(string $driver): void
    {
        if ($driver === 'pgsql') {
            $exit = Artisan::call('migrate', [
                '--database' => self::WORKER_CONNECTION,
                '--force' => true,
            ]);

            Assert::assertSame(0, $exit, 'Full original migrations must run on the isolated PostgreSQL worker database. '.Artisan::output());

            return;
        }

        // Full migration is impossible on the isolated MariaDB: an unrelated
        // communications migration declares an auto index name longer than
        // MySQL's 64-character identifier limit. Run the subset of current
        // ORIGINAL migration files the real workflow touches instead, with
        // every real model observer still firing. No app source is mocked.
        // Every migration must run fully: any failure fails the test. No
        // default-string-length override: required tables use their
        // canonical original definitions on both drivers.
        foreach (self::minimalMariaDbMigrationFiles() as $file) {
            Assert::assertFileExists($file);

            // require (not require_once): each call must return a fresh
            // anonymous migration instance.
            $migration = require $file;

            Assert::assertIsObject($migration, "Migration file [{$file}] must return a migration instance.");

            $migration->up();
        }
    }

    /**
     * @return list<string>
     */
    private static function minimalMariaDbMigrationFiles(): array
    {
        $base = base_path();

        return [
            // Persisted users plus framework tables in the same file.
            $base.'/database/migrations/0001_01_01_000000_create_users_table.php',
            // Canonical share-profile identity (code unique index included).
            $base.'/vendor/aiarmada/affiliates/database/migrations/2000_01_01_000001_create_affiliates_table.php',
            // Sender-owned share links.
            $base.'/vendor/aiarmada/affiliates/database/migrations/2000_01_01_000019_create_affiliate_links_table.php',
            // Links-managed short URLs written by CreateTrackingLink.
            $base.'/vendor/aiarmada/links/database/migrations/2001_01_01_000001_create_tracked_links_table.php',
            // Contact rows synced by the Affiliate saved hook.
            $base.'/vendor/aiarmada/contacting/database/migrations/2000_01_01_000001_create_contact_methods_table.php',
            // Activity rows written by the affiliates audit hook on create.
            $base.'/database/migrations/2026_01_21_095013_create_activity_log_table.php',
        ];
    }

    public static function dropWorkerDatabase(string $driver, string $database): void
    {
        self::guardWorkerDatabaseName($database);

        DB::purge(self::WORKER_CONNECTION);

        $connection = self::baseConnection($driver);

        if ($driver === 'pgsql') {
            $connection->statement('DROP DATABASE IF EXISTS "'.$database.'"');
        } else {
            $connection->statement('DROP DATABASE IF EXISTS `'.$database.'`');
        }

        DB::purge(self::BASE_CONNECTION);
    }

    /**
     * Run two independent race workers as separate OS processes with
     * independent connections, started together behind a file barrier.
     *
     * @param  array{driver: string, database: string, user_id: string, url: string, title: string, app_url: string}  $job
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public static function runLinkCreationRace(array $job): array
    {
        $readyDir = sys_get_temp_dir().'/wp1-race-'.$job['database'];

        if (! is_dir($readyDir) && ! mkdir($readyDir, 0777, true) && ! is_dir($readyDir)) {
            Assert::fail('Unable to create race barrier directory.');
        }

        $worker = __DIR__.'/Wp1RaceWorker.php';

        Assert::assertFileExists($worker);

        $baseConfig = self::driverConfigs()[$job['driver']];

        $processes = [];

        foreach ([0, 1] as $slot) {
            $processes[$slot] = new Process(
                command: [
                    PHP_BINARY,
                    $worker,
                    '--driver='.$job['driver'],
                    '--host='.$baseConfig['host'],
                    '--port='.$baseConfig['port'],
                    '--database='.$job['database'],
                    '--username='.$baseConfig['username'],
                    '--password='.$baseConfig['password'],
                    '--user-id='.$job['user_id'],
                    '--url='.$job['url'],
                    '--title='.$job['title'],
                    '--app-url='.$job['app_url'],
                    '--ready-dir='.$readyDir,
                    '--slot='.$slot,
                ],
                env: ['APP_ENV' => 'testing'],
                timeout: 150,
            );

            $processes[$slot]->start();
        }

        $results = [];

        foreach ($processes as $slot => $process) {
            $process->wait();

            $output = trim($process->getOutput());
            $decoded = json_decode($output, true);

            Assert::assertSame(
                0,
                $process->getExitCode(),
                "Race worker {$slot} failed (exit {$process->getExitCode()}): {$output} {$process->getErrorOutput()}",
            );
            Assert::assertIsArray($decoded, "Race worker {$slot} must report JSON.");
            Assert::assertTrue((bool) ($decoded['ok'] ?? false), "Race worker {$slot} must succeed: ".json_encode($decoded));

            $results[$slot] = $decoded;
        }

        return [$results[0], $results[1]];
    }
}
