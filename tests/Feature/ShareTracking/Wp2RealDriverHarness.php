<?php

declare(strict_types=1);

namespace Tests\Feature\ShareTracking;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;
use Symfony\Component\Process\Process;
use Tests\Support\RealDriverFixture;

/**
 * WP2 real-driver extensions over the guarded WP1 harness.
 *
 * WP2 outcome flows additionally need the ORIGINAL affiliates
 * attribution/conversion/touchpoint/fraud/balance migrations, bookmark
 * and event outcome tables, and the new app share tables, loaded here
 * from their current original files. Outcome workers separately load the
 * reward-award and milestone-contribution tables. The
 * PostgreSQL workers run the full original migration set through the WP1
 * schema entrypoint, which already includes every required table.
 *
 * Guarded test-only worker databases: loopback isolated ports/databases
 * only. Never the live application database (ilmu360 / 127.0.0.1:5432).
 */
final class Wp2RealDriverHarness
{
    public static function migrateWorkerDatabase(): void
    {
        Wp1RealDriverHarness::migrateWorkerDatabase();
        DB::connection(Wp1RealDriverHarness::WORKER_CONNECTION)->statement("SET TIME ZONE 'UTC'");
    }

    public static function landingRequest(string $url, ?string $cookie = null): Request
    {
        $request = Request::create($url, 'GET');

        $route = app('router')->getRoutes()->match($request);

        $request->setRouteResolver(static fn (): mixed => $route);

        if (is_string($cookie) && $cookie !== '') {
            $request->cookies->set((string) config('dawah-share.cookie.name', 'mi_dawah_share'), $cookie);
        }

        return $request;
    }

    /**
     * Run two independent race workers as separate OS processes with
     * independent connections, started together behind a file barrier.
     *
     * @param  array{database: string, app_url: string, mode: string, payload: array<string, mixed>}  $job
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public static function runRace(array $job): array
    {
        $readyDir = sys_get_temp_dir().'/wp2-race-'.$job['database'];

        if (! is_dir($readyDir) && ! mkdir($readyDir, 0777, true) && ! is_dir($readyDir)) {
            Assert::fail('Unable to create race barrier directory.');
        }

        $worker = __DIR__.'/Wp2RaceWorker.php';

        Assert::assertFileExists($worker);

        RealDriverFixture::guardWorkerDatabaseName($job['database']);

        // Slot inputs travel through the barrier directory: cookie state
        // and keys are argv-hostile, and both workers must read identical
        // bytes.
        file_put_contents(
            $readyDir.'/payload.json',
            json_encode($job['payload'], JSON_THROW_ON_ERROR)
        );

        $processes = [];

        foreach ([0, 1] as $slot) {
            $processes[$slot] = new Process(
                command: [
                    PHP_BINARY,
                    $worker,
                    '--database='.$job['database'],
                    '--app-url='.$job['app_url'],
                    '--mode='.$job['mode'],
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
                "Race worker {$slot} failed (exit {$process->getExitCode()}): {$output} {$process->getErrorOutput()}"
            );
            Assert::assertIsArray($decoded, "Race worker {$slot} must report JSON.");
            Assert::assertTrue((bool) ($decoded['ok'] ?? false), "Race worker {$slot} must succeed: ".json_encode($decoded));

            $results[$slot] = $decoded;
        }

        return [$results[0], $results[1]];
    }

    /**
     * Run two landing workers as separate OS processes with a controlled
     * commit order: both boot behind the worker start barrier, then the
     * parent releases $firstSlot's turn, waits for its landing to commit,
     * and only then releases the other slot. Real OS processes, real
     * connections, real selection-row locking — with a deterministic
     * winner instead of a timing lottery.
     *
     * @param  array{database: string, app_url: string, mode: string, payload: array<string, mixed>}  $job
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public static function runOrderedLandings(array $job, int $firstSlot): array
    {
        Assert::assertContains($firstSlot, [0, 1], 'Ordered landings need slot 0 or 1 first.');
        Assert::assertSame('landing_ordered', $job['mode'], 'Ordered landings need the landing_ordered worker mode.');

        $secondSlot = $firstSlot === 0 ? 1 : 0;
        $readyDir = sys_get_temp_dir().'/wp2-race-'.$job['database'];

        if (! is_dir($readyDir) && ! mkdir($readyDir, 0777, true) && ! is_dir($readyDir)) {
            Assert::fail('Unable to create race barrier directory.');
        }

        // A crashed run must never satisfy this run's barrier or turns.
        foreach (glob($readyDir.'/ready-*') ?: [] as $stale) {
            @unlink($stale);
        }

        foreach (glob($readyDir.'/turn-*') ?: [] as $stale) {
            @unlink($stale);
        }

        foreach (glob($readyDir.'/done-*') ?: [] as $stale) {
            @unlink($stale);
        }

        $worker = __DIR__.'/Wp2RaceWorker.php';

        Assert::assertFileExists($worker);

        RealDriverFixture::guardWorkerDatabaseName($job['database']);

        file_put_contents(
            $readyDir.'/payload.json',
            json_encode($job['payload'], JSON_THROW_ON_ERROR)
        );

        $processes = [];

        foreach ([0, 1] as $slot) {
            $processes[$slot] = new Process(
                command: [
                    PHP_BINARY,
                    $worker,
                    '--database='.$job['database'],
                    '--app-url='.$job['app_url'],
                    '--mode='.$job['mode'],
                    '--ready-dir='.$readyDir,
                    '--slot='.$slot,
                ],
                env: ['APP_ENV' => 'testing'],
                timeout: 150,
            );

            $processes[$slot]->start();
        }

        // Both workers must reach the start barrier before either lands.
        self::waitForRaceFile($readyDir.'/ready-0', 'First ordered worker never reached the start barrier.');
        self::waitForRaceFile($readyDir.'/ready-1', 'Second ordered worker never reached the start barrier.');

        file_put_contents($readyDir.'/turn-'.$firstSlot, 'go');
        self::waitForRaceFile($readyDir.'/done-'.$firstSlot, 'First ordered landing never committed.', 120);

        file_put_contents($readyDir.'/turn-'.$secondSlot, 'go');
        self::waitForRaceFile($readyDir.'/done-'.$secondSlot, 'Second ordered landing never committed.', 120);

        $results = [];

        foreach ($processes as $slot => $process) {
            $process->wait();

            $output = trim($process->getOutput());
            $decoded = json_decode($output, true);

            Assert::assertSame(
                0,
                $process->getExitCode(),
                "Ordered worker {$slot} failed (exit {$process->getExitCode()}): {$output} {$process->getErrorOutput()}"
            );
            Assert::assertIsArray($decoded, "Ordered worker {$slot} must report JSON.");
            Assert::assertTrue((bool) ($decoded['ok'] ?? false), "Ordered worker {$slot} must succeed: ".json_encode($decoded));

            $results[$slot] = $decoded;
        }

        return [$results[0], $results[1]];
    }

    private static function waitForRaceFile(string $path, string $failure, int $seconds = 60): void
    {
        $deadline = microtime(true) + $seconds;

        while (! is_file($path)) {
            if (microtime(true) > $deadline) {
                Assert::fail($failure.' ['.$path.']');
            }

            usleep(5000);
        }
    }
}
