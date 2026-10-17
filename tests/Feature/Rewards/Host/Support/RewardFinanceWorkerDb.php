<?php

declare(strict_types=1);

namespace Tests\Feature\Rewards\Host\Support;

use PHPUnit\Framework\Assert;
use Tests\Support\RealDriverFixture;

/**
 * Builds the finance-worker schema from current original migrations. The
 * shared real-driver fixture owns server and connection lifecycle.
 */
final class RewardFinanceWorkerDb
{
    public const string WORKER_CONNECTION = 'rewards_finance_worker';

    public static function guardWorkerDatabaseName(string $database): void
    {
        Assert::assertMatchesRegularExpression(
            '/^share_rewards_test_rewards_finance_[a-z0-9_]{1,27}$/',
            $database,
            'Worker databases stay inside the isolated rewards-finance namespace.',
        );
    }

    /**
     * Runs the actual migrations against the worker connection. Fails
     * loudly unless exactly the thirty rewards package files, one users,
     * and three voucher files run. Users + vouchers back the coupon
     * issuance/usage races; plain-UUID columns keep every file
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
        $rewards = glob(base_path('vendor/aiarmada/rewards/database/migrations/2001_05_01_*_create_reward_*.php'));
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

        Assert::assertIsArray($rewards, 'Reward package migrations must exist.');
        sort($rewards);
        Assert::assertCount(30, $rewards, 'Exactly the thirty reward package migrations must run.');
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

        foreach ([...$rewards, ...$users, ...$comms, ...$slots, ...$corrections, ...$vouchers, ...$observability] as $file) {
            $migration = require $file;
            $migration->up();
        }
    }

    /**
     * @return array{0: string, 1: string} [database, previousDefaultConnection]
     */
    public static function setUp(string $test): array
    {
        $database = RealDriverFixture::workerDatabaseName(
            $test,
            'share_rewards_test_rewards_finance_',
        );
        self::guardWorkerDatabaseName($database);
        RealDriverFixture::createDatabase($database);
        $previous = RealDriverFixture::useWorkerDatabase($database, self::WORKER_CONNECTION);

        self::migrateWorkerDatabase();

        return [$database, $previous];
    }

    public static function tearDown(string $database, string $previous): void
    {
        RealDriverFixture::restoreDatabase($previous, self::WORKER_CONNECTION);
        self::guardWorkerDatabaseName($database);
        RealDriverFixture::dropDatabase($database);
    }
}
