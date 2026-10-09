<?php

declare(strict_types=1);

namespace Tests\Feature\Rewards\Host\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\ParallelTesting;
use PHPUnit\Framework\Assert;

/**
 * Guarded real-driver harness for finance/benefit concurrency proof. Own
 * implementation: worker-namespaced databases on the isolated PostgreSQL 17
 * (127.0.0.1:45432) and MariaDB 11.4 (127.0.0.1:43306) fixtures, schema built
 * from the actual original migrations — six core, ten ledger, one
 * reservation-submission, one finance, one funding-source, one benefit,
 * one users, seven communications, one delivery-slot, one
 * corrections, and three voucher files. Never touches the live
 * application database.
 */
final class RewardFinanceWorkerDb
{
    public const string WORKER_CONNECTION = 'rewards_finance_worker';

    public const string BASE_CONNECTION = 'rewards_finance_base';

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
            '/^share_rewards_test_rewards_finance_[a-z0-9_]{1,27}$/',
            $database,
            'Worker databases stay inside the isolated rewards-finance namespace.',
        );
    }

    public static function workerDatabaseName(string $driver, string $test): string
    {
        $token = ParallelTesting::token();
        $raw = $driver.'_'.($token !== false ? $token : 'serial').'_'.getmypid().'_'.$test;
        $suffix = strtolower((string) preg_replace('/[^a-z0-9]+/', '_', $raw));

        // The namespace prefix is 35 chars; PostgreSQL truncates
        // identifiers past 63 bytes, so the suffix keeps 27 max (62 total).
        $name = 'share_rewards_test_rewards_finance_'.substr($suffix, 0, 27);

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
     * Fails loudly unless exactly the six core, ten ledger, one
     * submission, one finance, one funding-source, one benefit, one
     * users, and three voucher files run. Users + vouchers back the
     * coupon issuance/usage races; plain-UUID columns keep every file
     * standalone with no cross-table constraint. The communications
     * subset is the eight tables the raced rewards boundary actually
     * touches (dispatch, guarded channels with the external-mail
     * attempt boundary, cancel, reconcile, and
     * preference/destination resolution); batch/thread/event/
     * template/suppression/attachment/reference/tracking tables are
     * never read or written on this path.
     */
    public static function migrateWorkerDatabase(): void
    {
        $core = glob(database_path('migrations/2026_10_07_02000*_create_reward_*.php'));
        $ledger = glob(database_path('migrations/2026_10_07_0300*_create_reward_*.php'));
        $submissions = glob(database_path('migrations/2026_10_08_010001_create_reward_point_reservation_submissions_table.php'));
        $finance = glob(database_path('migrations/2026_10_08_020001_create_reward_finance_tables.php'));
        $sources = glob(database_path('migrations/2026_10_08_020002_create_reward_funding_sources_table.php'));
        $benefit = glob(database_path('migrations/2026_10_08_030001_create_reward_benefit_tables.php'));
        $users = glob(database_path('migrations/0001_01_01_000000_create_users_table.php'));
        // Award approval plans the real gratitude digest and digest races
        // send through the real transport, so the touched ORIGINAL
        // communications package tables and delivery-slot tables ride
        // along unmodified.
        $comms = [];

        foreach (['03', '04', '05', '06', '07', '11', '16', '17'] as $sequence) {
            $found = glob(base_path('vendor/aiarmada/communications/database/migrations/2000_01_01_0000'.$sequence.'_*.php'));

            if (is_array($found)) {
                array_push($comms, ...$found);
            }
        }
        $slots = glob(database_path('migrations/2026_10_08_040001_create_share_delivery_tables.php'));
        $corrections = glob(database_path('migrations/2026_10_06_201909_create_share_outcome_corrections_table.php'));
        $vouchers = glob(base_path('vendor/aiarmada/vouchers/database/migrations/2001_04_01_00000*_create_voucher*.php'));
        $observability = [
            database_path('migrations/2026_01_11_090456_create_audits_table.php'),
            database_path('migrations/2026_01_21_095013_create_activity_log_table.php'),
        ];

        Assert::assertIsArray($core, 'Reward-core migrations must exist.');
        Assert::assertIsArray($ledger, 'Reward-ledger migrations must exist.');
        sort($core);
        sort($ledger);
        Assert::assertCount(6, $core, 'Exactly the six original reward-core migrations must run.');
        Assert::assertCount(10, $ledger, 'Exactly the ten original reward-ledger migrations must run.');
        Assert::assertCount(1, $submissions ?: [], 'The reservation-submission migration must run.');
        Assert::assertCount(1, $finance ?: [], 'The finance migration must run.');
        Assert::assertCount(1, $sources ?: [], 'The funding-source migration must run.');
        Assert::assertCount(1, $benefit ?: [], 'The benefit migration must run.');
        Assert::assertCount(1, $users ?: [], 'The users migration must run.');
        sort($comms);
        Assert::assertCount(8, $comms, 'Exactly the eight touched original communications migrations must run.');
        Assert::assertCount(1, $slots ?: [], 'The share-delivery migration must run.');
        Assert::assertCount(1, $corrections ?: [], 'The share-outcome-corrections migration must run.');
        Assert::assertIsArray($vouchers, 'Voucher migrations must exist.');
        sort($vouchers);
        Assert::assertCount(3, $vouchers, 'Exactly the three original voucher migrations must run.');

        foreach ($observability as $file) {
            Assert::assertFileExists($file);
        }

        foreach ([...$core, ...$ledger, ...$submissions, ...$finance, ...$sources, ...$benefit, ...$users, ...$comms, ...$slots, ...$corrections, ...$vouchers, ...$observability] as $file) {
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
