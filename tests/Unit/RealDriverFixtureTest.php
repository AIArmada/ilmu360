<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\Assert;
use Tests\Support\RealDriverFixture;

it('accepts only the isolated PostgreSQL base connection', function (): void {
    $connection = [
        'driver' => 'pgsql',
        'host' => '127.0.0.1',
        'port' => 45432,
        'database' => 'share_rewards_test',
    ];

    RealDriverFixture::guardBaseConfig($connection);

    foreach ([
        ['driver' => 'sqlite'],
        ['host' => 'localhost'],
        ['port' => 5432],
        ['database' => 'ilmu360'],
    ] as $overrides) {
        try {
            RealDriverFixture::guardBaseConfig(array_replace($connection, $overrides));
        } catch (ExpectationFailedException $exception) {
            expect($exception->getMessage())->toContain('Failed asserting');

            continue;
        }

        Assert::fail('The fixture accepted a non-isolated PostgreSQL connection config.');
    }

    RealDriverFixture::guardWorkerDatabaseName('share_rewards_test_wp1_worker_1');

    try {
        RealDriverFixture::guardWorkerDatabaseName('ilmu360');
    } catch (ExpectationFailedException $exception) {
        expect($exception->getMessage())->toContain('Worker databases stay inside');

        return;
    }

    Assert::fail('The fixture accepted a worker database outside its isolated namespace.');
});

it('supports concurrent cold PostgreSQL fixture initialization', function (): void {
    $worker = dirname(__DIR__, 2).'/tests/Feature/ShareTracking/RealDriverFixtureColdStartWorker.php';
    $readyDirectory = sys_get_temp_dir().'/real-driver-cold-start-'.bin2hex(random_bytes(8));

    expect($worker)->toBeFile()
        ->and(mkdir($readyDirectory, 0700))->toBeTrue();

    $processes = [];

    try {
        foreach (range(0, 7) as $slot) {
            $process = new Process(
                [PHP_BINARY, $worker, '--ready-dir='.$readyDirectory, '--slot='.$slot],
                env: ['APP_ENV' => 'testing'],
                timeout: 180,
            );
            $process->start();
            $processes[$slot] = $process;
        }

        $deadline = microtime(true) + 60;

        while (count(glob($readyDirectory.'/ready-*') ?: []) < count($processes)) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Fixture initialization workers did not reach the start barrier.');
            }

            usleep(5000);
        }

        file_put_contents($readyDirectory.'/release', 'ready');

        foreach ($processes as $process) {
            $process->wait();
            $output = trim($process->getOutput());
            $decoded = json_decode($output, true);

            expect($process->getExitCode())->toBe(0, 'Fixture worker failed: '.$output.' '.$process->getErrorOutput())
                ->and($decoded)->toBeArray()
                ->and($decoded['ok'] ?? false)->toBeTrue()
                ->and($decoded['drivers'] ?? [])->toBe(['pgsql']);
        }
    } finally {
        file_put_contents($readyDirectory.'/release', 'ready');

        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }

        foreach (glob($readyDirectory.'/*') ?: [] as $path) {
            unlink($path);
        }

        rmdir($readyDirectory);
    }
});
