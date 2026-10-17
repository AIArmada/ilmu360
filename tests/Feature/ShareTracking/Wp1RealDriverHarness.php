<?php

declare(strict_types=1);

namespace Tests\Feature\ShareTracking;

use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Assert;
use Symfony\Component\Process\Process;
use Tests\Support\RealDriverFixture;

/** Guarded PostgreSQL 17 harness for share-tracking workflow proof. */
final class Wp1RealDriverHarness
{
    public const string WORKER_CONNECTION = 'wp1_worker';

    public static function migrateWorkerDatabase(): void
    {
        $exit = Artisan::call('migrate', [
            '--database' => self::WORKER_CONNECTION,
            '--force' => true,
        ]);
        Assert::assertSame(0, $exit, 'Full original migrations must run on the isolated PostgreSQL worker database. '.Artisan::output());
    }

    /**
     * @param array{database: string, user_id: string, url: string, title: string, app_url: string} $job
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
        RealDriverFixture::guardWorkerDatabaseName($job['database']);
        $processes = [];

        foreach ([0, 1] as $slot) {
            $processes[$slot] = new Process(
                command: [
                    PHP_BINARY,
                    $worker,
                    '--database='.$job['database'],
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
            Assert::assertSame(0, $process->getExitCode(), "Race worker {$slot} failed (exit {$process->getExitCode()}): {$output} {$process->getErrorOutput()}");
            Assert::assertIsArray($decoded, "Race worker {$slot} must report JSON.");
            Assert::assertTrue((bool) ($decoded['ok'] ?? false), "Race worker {$slot} must succeed: ".json_encode($decoded));
            $results[$slot] = $decoded;
        }

        return [$results[0], $results[1]];
    }
}
