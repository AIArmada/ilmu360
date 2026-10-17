<?php

declare(strict_types=1);

namespace Tests\Feature\ShareTracking;

use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Assert;
use Symfony\Component\Process\Process;
use Tests\Support\RealDriverFixture;

// WP3-owned schema additions and OS-process race support. The shared
// real-driver fixture owns database provisioning and connections.
final class Wp3RealDriverHarness
{
    public static function migrateWorkerDatabase(): void
    {
        Wp2RealDriverHarness::migrateWorkerDatabase();
    }

    public static function assertWorkerSchema(array $tables): void
    {
        foreach ($tables as $table) {
            Assert::assertTrue(
                Schema::connection(Wp1RealDriverHarness::WORKER_CONNECTION)->hasTable($table),
                "Worker database is missing migrated table [{$table}]."
            );
        }
    }

    /**
     * Run one solo worker against a parent-held lock, forcing a genuine
     * empty-lookup overlap: the worker must attempt its creation BEFORE
     * the parent's $commitStep commits, so its lookup blocks on the
     * parent-held row lock and then reuses the committed row. Returns the
     * commit report and the worker report. Worker errors and exits are
     * asserted exactly like the two-worker race.
     *
     * @param  array{database: string, app_url: string, mode: string, payload: array<string, mixed>}  $job
     * @param  callable(): array<string, mixed>  $commitStep
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public static function runForcedSolo(array $job, callable $commitStep): array
    {
        $readyDir = sys_get_temp_dir().'/wp3-race-'.$job['database'];

        if (! is_dir($readyDir) && ! mkdir($readyDir, 0777, true) && ! is_dir($readyDir)) {
            Assert::fail('Unable to create race barrier directory.');
        }

        foreach (glob($readyDir.'/attempting-*') ?: [] as $stale) {
            @unlink($stale);
        }

        $worker = __DIR__.'/Wp3RaceWorker.php';

        Assert::assertFileExists($worker);

        RealDriverFixture::guardWorkerDatabaseName($job['database']);

        file_put_contents(
            $readyDir.'/payload.json',
            json_encode($job['payload'] + ['solo' => true], JSON_THROW_ON_ERROR)
        );

        $process = new Process(
            command: [
                PHP_BINARY,
                $worker,
                '--database='.$job['database'],
                '--app-url='.$job['app_url'],
                '--mode='.$job['mode'],
                '--ready-dir='.$readyDir,
                '--slot=0',
            ],
            env: ['APP_ENV' => 'testing'],
            timeout: 150,
        );

        $process->start();

        $deadline = microtime(true) + 60;

        while (! is_file($readyDir.'/attempting-0')) {
            if (microtime(true) > $deadline) {
                $process->stop();

                Assert::fail('Solo race worker never attempted its creation.');
            }

            usleep(5000);
        }

        // Let the worker arrive at the lock wait before committing, so
        // the blocked duration measures a real lock hold, not a boot
        // race. The overlap itself is structural (attempt precedes
        // commit by protocol), never timing luck.
        usleep(1500000);

        $commit = $commitStep();

        $process->wait();

        $output = trim($process->getOutput());
        $decoded = json_decode($output, true);

        Assert::assertSame(
            0,
            $process->getExitCode(),
            "Solo race worker failed (exit {$process->getExitCode()}): {$output} {$process->getErrorOutput()}"
        );
        Assert::assertIsArray($decoded, 'Solo race worker must report JSON.');
        Assert::assertTrue((bool) ($decoded['ok'] ?? false), 'Solo race worker must succeed: '.json_encode($decoded));

        return [$commit, $decoded];
    }

    /**
     * @param  array{database: string, app_url: string, mode: string, payload: array<string, mixed>}  $job
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public static function runRace(array $job): array
    {
        $readyDir = sys_get_temp_dir().'/wp3-race-'.$job['database'];

        if (! is_dir($readyDir) && ! mkdir($readyDir, 0777, true) && ! is_dir($readyDir)) {
            Assert::fail('Unable to create race barrier directory.');
        }

        $worker = __DIR__.'/Wp3RaceWorker.php';

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
}
