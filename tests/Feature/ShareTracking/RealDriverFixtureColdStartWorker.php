<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Tests\Support\RealDriverFixture;

if (PHP_SAPI !== 'cli' || realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) {
    return;
}

$options = getopt('', ['ready-dir:', 'slot:']);
$readyDirectory = (string) ($options['ready-dir'] ?? '');
$slot = (int) ($options['slot'] ?? -1);

$fail = static function (string $message, int $exit = 1): never {
    echo json_encode(['ok' => false, 'error' => $message], JSON_THROW_ON_ERROR)."\n";

    exit($exit);
};

if ($readyDirectory === '' || ! is_dir($readyDirectory) || $slot < 0 || $slot > 7) {
    $fail('Missing or invalid fixture bootstrap barrier arguments.');
}

$basePath = realpath(__DIR__.'/../../../');

if (! is_string($basePath) || ! is_file($basePath.'/bootstrap/app.php')) {
    $fail('Unable to locate the application base path.');
}

require $basePath.'/vendor/autoload.php';

$app = require $basePath.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (file_put_contents($readyDirectory.'/ready-'.$slot, (string) getmypid()) === false) {
    $fail('Unable to notify the parent that this worker reached the fixture barrier.');
}

$deadline = microtime(true) + 60;

while (! is_file($readyDirectory.'/release')) {
    if (microtime(true) > $deadline) {
        $fail('Fixture bootstrap parent did not release the start barrier.');
    }

    usleep(5000);
}

try {
    $config = RealDriverFixture::connectionConfig();
    RealDriverFixture::guardBaseConfig($config);
} catch (Throwable $exception) {
    $fail(get_class($exception).': '.$exception->getMessage());
}

echo json_encode([
    'ok' => true,
    'drivers' => [(string) $config['driver']],
], JSON_THROW_ON_ERROR)."\n";
