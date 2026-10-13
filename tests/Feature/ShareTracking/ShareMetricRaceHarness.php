<?php

declare(strict_types=1);

namespace Tests\Feature\ShareTracking;

use AIArmada\Affiliates\Models\Affiliate;
use AIArmada\Affiliates\Models\AffiliateLink;
use AIArmada\Signals\Models\SignalEvent;
use AIArmada\Signals\Models\TrackedProperty;
use App\Models\User;
use App\Services\ShareTrackingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Shared worker-database harness for the share metric/cohort/signal
 * race tests. Guarded test-only worker databases: loopback isolated
 * ports/databases only. Never the live application database.
 */
final class ShareMetricRaceHarness
{
    /**
     * @return array{0: string, 1: string}
     */
    public static function worker(string $driver, string $test): array
    {
        $database = Wp1RealDriverHarness::createWorkerDatabase($driver, $test);
        $previous = Wp1RealDriverHarness::useWorkerDatabase($driver, $database);

        try {
            Wp2RealDriverHarness::migrateWorkerDatabase($driver);

            if ($driver === 'mariadb') {
                config(['rewards.database.json_column_type' => 'json']);

                $rewardMigrations = glob(base_path('/vendor/aiarmada/rewards/database/migrations/2001_05_01_*_create_reward_*.php'));

                Assert::assertIsArray($rewardMigrations, 'Reward package migrations must exist.');
                sort($rewardMigrations);
                Assert::assertCount(30, $rewardMigrations, 'Exactly the thirty reward package migrations must run.');

                // The WP1/WP2 MariaDB subset stops before guest-claim tables;
                // the claimed-guest ownership leg needs the ORIGINAL claim
                // migrations too. The complete rewards package schema lets
                // the real reward-evaluation listener and payout lifecycle
                // run unmodified instead of being faked away. The
                // committed-share observer produces real trusted Signals on
                // every share, so the ORIGINAL signals package migrations
                // ride along as well. The WP1 harness file itself is never
                // modified.
                foreach ([
                    base_path('/database/migrations/2026_10_07_000002_create_share_guest_claim_capabilities_table.php'),
                    base_path('/database/migrations/2026_10_07_000003_create_share_guest_claims_table.php'),
                    ...$rewardMigrations,
                    base_path('/database/migrations/2026_10_08_040001_create_share_delivery_tables.php'),
                    base_path('/database/migrations/2026_10_08_100001_create_share_signal_reader_cursors_table.php'),
                    ...glob(base_path('/vendor/aiarmada/signals/database/migrations/*.php')) ?: [],
                    // Reward payout lifecycle races: canonical affiliate
                    // payout/balance/method/operation/event tables. PG runs
                    // full migrations; Maria needs the explicit subset.
                    base_path('/vendor/aiarmada/affiliates/database/migrations/2000_01_01_000004_create_affiliate_payouts_table.php'),
                    base_path('/vendor/aiarmada/affiliates/database/migrations/2000_01_01_000005_create_affiliate_payout_events_table.php'),
                    base_path('/vendor/aiarmada/affiliates/database/migrations/2000_01_01_000013_create_affiliate_payout_methods_table.php'),
                    base_path('/vendor/aiarmada/affiliates/database/migrations/2000_01_01_000026_create_affiliate_payout_operations_table.php'),
                ] as $file) {
                    Assert::assertFileExists($file);

                    $migration = require $file;

                    Assert::assertIsObject($migration);
                    $migration->up();
                }
            }

            // The worker database seeds the same global tracked property the
            // test bootstrap seeds, so real trusted ingestion resolves.
            Assert::assertTrue(
                Schema::hasTable((new SignalEvent)->getTable()),
                'Worker database is missing the signals event log.'
            );

            TrackedProperty::query()->firstOrCreate(
                ['slug' => 'ilmu360'],
                [
                    'name' => 'Worker Fixture Website',
                    'write_key' => Str::random(40),
                    'domain' => 'localhost',
                    'type' => 'website',
                    'timezone' => 'UTC',
                    'currency' => 'MYR',
                    'is_active' => true,
                ]
            );
        } catch (Throwable $exception) {
            self::teardown($driver, $database, $previous);

            throw $exception;
        }

        return [$database, $previous];
    }

    public static function teardown(string $driver, string $database, string $previous): void
    {
        Wp1RealDriverHarness::restoreDatabase($previous);
        Wp1RealDriverHarness::dropWorkerDatabase($driver, $database);
    }

    /**
     * @param  array{driver: string, database: string, app_url: string, mode: string, payload: array<string, mixed>, worker?: string}  $job
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public static function runRace(array $job): array
    {
        $readyDir = sys_get_temp_dir().'/smr-race-'.$job['database'];

        if (! is_dir($readyDir) && ! mkdir($readyDir, 0777, true) && ! is_dir($readyDir)) {
            Assert::fail('Unable to create race barrier directory.');
        }

        $worker = $job['worker'] ?? __DIR__.'/ShareMetricRaceWorker.php';

        Assert::assertFileExists($worker);

        $baseConfig = Wp1RealDriverHarness::driverConfigs()[$job['driver']];

        file_put_contents(
            $readyDir.'/payload.json',
            json_encode($job['payload'], JSON_THROW_ON_ERROR)
        );

        $processes = [];

        foreach ([0, 1] as $slot) {
            $processes[$slot] = new Process([
                PHP_BINARY,
                $worker,
                '--driver='.$job['driver'],
                '--host='.$baseConfig['host'],
                '--port='.(string) $baseConfig['port'],
                '--database='.$job['database'],
                '--username='.(string) $baseConfig['username'],
                '--password='.(string) $baseConfig['password'],
                '--app-url='.$job['app_url'],
                '--mode='.$job['mode'],
                '--ready-dir='.$readyDir,
                '--slot='.(string) $slot,
            ]);

            $processes[$slot]->start();
        }

        $reports = [];

        foreach ([0, 1] as $slot) {
            $processes[$slot]->wait();

            Assert::assertTrue(
                $processes[$slot]->isSuccessful(),
                'Race worker failed: '.$processes[$slot]->getErrorOutput().$processes[$slot]->getOutput()
            );

            $decoded = json_decode(trim($processes[$slot]->getOutput()), true);

            Assert::assertIsArray($decoded);
            Assert::assertTrue((bool) ($decoded['ok'] ?? false), 'Race worker reported failure: '.$processes[$slot]->getOutput());

            $reports[$slot] = $decoded['report'] ?? [];
        }

        return [$reports[0], $reports[1]];
    }

    public static function link(User $sharer, string $url): AffiliateLink
    {
        app(ShareTrackingService::class)->createOrReuseLink($sharer, $url, 'Majlis', 'web');

        $affiliate = Affiliate::query()
            ->where('owner_type', $sharer->getMorphClass())
            ->where('owner_id', $sharer->getKey())
            ->firstOrFail();

        return AffiliateLink::query()->where('affiliate_id', $affiliate->getKey())->latest('created_at')->firstOrFail();
    }

    public static function landing(string $url, ?string $cookie = null): ?string
    {
        return app(ShareTrackingService::class)->captureRequest(
            Wp2RealDriverHarness::landingRequest($url, $cookie)
        );
    }

    public static function guestPayload(string $url): array
    {
        $request = Request::create($url, 'GET');

        return app(ShareTrackingService::class)->sharePayload(null, $url, 'Share this page', 'Shared Page', 'web', $request);
    }
}
