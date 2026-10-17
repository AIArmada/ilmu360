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
use Tests\Support\RealDriverFixture;
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
    public static function worker(string $test): array
    {
        $database = RealDriverFixture::createWorkerDatabase($test);
        $previous = RealDriverFixture::useWorkerDatabase($database, Wp1RealDriverHarness::WORKER_CONNECTION);

        try {
            Wp2RealDriverHarness::migrateWorkerDatabase();

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
            self::teardown($database, $previous);

            throw $exception;
        }

        return [$database, $previous];
    }

    public static function teardown(string $database, string $previous): void
    {
        RealDriverFixture::restoreDatabase($previous, Wp1RealDriverHarness::WORKER_CONNECTION);
        RealDriverFixture::dropDatabase($database);
    }

    /**
     * @param  array{database: string, app_url: string, mode: string, payload: array<string, mixed>, worker?: string}  $job
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

        file_put_contents(
            $readyDir.'/payload.json',
            json_encode($job['payload'], JSON_THROW_ON_ERROR)
        );

        $processes = [];

        foreach ([0, 1] as $slot) {
            $processes[$slot] = new Process([
                PHP_BINARY,
                $worker,
                '--database='.$job['database'],
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

    /** @return array<string, mixed> */
    public static function guestPayload(string $url): array
    {
        $request = Request::create($url, 'GET');

        return app(ShareTrackingService::class)->sharePayload(null, $url, 'Share this page', 'Shared Page', 'web', $request);
    }
}
