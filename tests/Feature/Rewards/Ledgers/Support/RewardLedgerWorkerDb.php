<?php

declare(strict_types=1);

namespace Tests\Feature\Rewards\Ledgers\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\ParallelTesting;
use PHPUnit\Framework\Assert;

/**
 * Guarded real-driver harness for rewards-ledger concurrency proof. Own
 * implementation: worker-namespaced databases on the isolated PostgreSQL 17
 * (127.0.0.1:45432) and MariaDB 11.4 (127.0.0.1:43306) fixtures, schema built
 * from the actual original migrations — all six core plus all ten ledger
 * files, unmodified. Never touches the live application database.
 */
final class RewardLedgerWorkerDb
{
    public const string WORKER_CONNECTION = 'rewards_ledger_worker';

    public const string BASE_CONNECTION = 'rewards_ledger_base';

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
                'timezone' => '+00:00',
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
            '/^share_rewards_test_rewards_ledger_[a-z0-9_]{1,28}$/',
            $database,
            'Worker databases stay inside the isolated rewards-ledger namespace.',
        );
    }

    public static function workerDatabaseName(string $driver, string $test): string
    {
        $token = ParallelTesting::token();
        $raw = $driver.'_'.($token !== false ? $token : 'serial').'_'.getmypid().'_'.$test;
        $suffix = strtolower((string) preg_replace('/[^a-z0-9]+/', '_', $raw));

        // The namespace prefix is 34 chars; PostgreSQL truncates
        // identifiers past 63 bytes, so the suffix keeps 28 max (62 total)
        // and create/connect always address the same database.
        $name = 'share_rewards_test_rewards_ledger_'.substr($suffix, 0, 28);

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

    public static function dropWorkerDatabase(string $driver, string $database): void
    {
        self::guardWorkerDatabaseName($database);
        $connection = self::baseConnection($driver);

        if ($driver === 'pgsql') {
            $connection->statement('DROP DATABASE IF EXISTS "'.$database.'"');
        } else {
            $connection->statement('DROP DATABASE IF EXISTS `'.$database.'`');
        }
    }

    public static function createWorkerDatabase(string $driver, string $test): string
    {
        $database = self::workerDatabaseName($driver, $test);
        $connection = self::baseConnection($driver);

        self::assertServerIdentity($driver, $connection);
        self::dropWorkerDatabase($driver, $database);

        if ($driver === 'pgsql') {
            $connection->statement('CREATE DATABASE "'.$database.'"');
        } else {
            $connection->statement('CREATE DATABASE `'.$database.'`');
        }

        return $database;
    }

    public static function useWorkerDatabase(string $driver, string $database): string
    {
        self::guardWorkerDatabaseName($database);
        $config = self::driverConfigs()[$driver] ?? null;

        Assert::assertIsArray($config, "Unknown worker driver [{$driver}].");
        self::guardBaseConfig($driver, $config);

        $config['database'] = $database;

        config(['database.connections.'.self::WORKER_CONNECTION => $config]);
        DB::purge(self::WORKER_CONNECTION);

        $previous = (string) DB::getDefaultConnection();
        DB::setDefaultConnection(self::WORKER_CONNECTION);

        return $previous;
    }

    public static function restoreDefaultConnection(string $previous): void
    {
        DB::setDefaultConnection($previous);
        DB::purge(self::WORKER_CONNECTION);
        DB::purge(self::BASE_CONNECTION);
    }

    /**
     * Runs the actual original migrations against the worker connection.
     * Fails loudly unless exactly the six core plus ten ledger files run —
     * no schema shortening, no index bypass. Award approval now plans the
     * real gratitude digest, so the users, preference and delivery-slot
     * originals ride along as well: the production hook runs unmodified.
     */
    public static function migrateWorkerDatabase(): void
    {
        $core = glob(database_path('migrations/2026_10_07_02000*_create_reward_*.php'));
        $ledger = glob(database_path('migrations/2026_10_07_0300*_create_reward_*.php'));
        $users = glob(database_path('migrations/0001_01_01_000000_create_users_table.php'));
        $preferences = glob(base_path('vendor/aiarmada/communications/database/migrations/2000_01_01_000011_create_communication_preferences_table.php'));
        $slots = glob(database_path('migrations/2026_10_08_040001_create_share_delivery_tables.php'));

        Assert::assertIsArray($core, 'Reward-core migrations must exist.');
        Assert::assertIsArray($ledger, 'Reward-ledger migrations must exist.');
        sort($core);
        sort($ledger);
        Assert::assertCount(6, $core, 'Exactly the six original reward-core migrations must run.');
        Assert::assertCount(10, $ledger, 'Exactly the ten original reward-ledger migrations must run.');
        Assert::assertCount(1, $users ?: [], 'The users migration must run.');
        Assert::assertCount(1, $preferences ?: [], 'The communication-preferences migration must run.');
        Assert::assertCount(1, $slots ?: [], 'The share-delivery migration must run.');

        foreach ([...$core, ...$ledger, ...$users, ...$preferences, ...$slots] as $file) {
            $migration = require $file;
            $migration->up();
        }
    }

    /**
     * @return array{0: string, 1: string} [database, previousDefaultConnection]
     */
    public static function setUp(string $driver, string $test): array
    {
        $database = self::createWorkerDatabase($driver, $test);
        $previous = self::useWorkerDatabase($driver, $database);
        self::migrateWorkerDatabase();

        return [$database, $previous];
    }

    public static function tearDown(string $driver, string $database, string $previous): void
    {
        self::restoreDefaultConnection($previous);
        self::dropWorkerDatabase($driver, $database);
    }
}
