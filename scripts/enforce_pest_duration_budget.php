<?php

declare(strict_types=1);

require __DIR__.'/PestDurationBudget.php';

$input = $argv[1] ?? 'artifacts/pest-timing/report.csv';
$baselineMinutes = isset($argv[2]) ? (float) $argv[2] : 0.0;

if ($baselineMinutes <= 0 || ! is_file($input)) {
    fwrite(STDERR, "Pest duration budget cannot evaluate without a baseline and timing artifact.\n");
    exit(2);
}

$handle = fopen($input, 'rb');
$rows = [];

if ($handle !== false) {
    fgetcsv($handle);

    while (($row = fgetcsv($handle)) !== false) {
        if (isset($row[0], $row[1]) && is_numeric($row[1])) {
            $rows[] = ['file' => $row[0], 'elapsed_seconds' => (float) $row[1]];
        }
    }

    fclose($handle);
}

$result = (new PestDurationBudget)->evaluate($rows, $baselineMinutes);
fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL);

exit($result['status'] === 'failure' ? 1 : 0);
