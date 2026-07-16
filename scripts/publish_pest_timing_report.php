<?php

declare(strict_types=1);

/**
 * Aggregate Pest's JUnit output into a per-file timing report.
 *
 * Dry run (also documents the report shape):
 *   php scripts/publish_pest_timing_report.php --dry-run
 */
final class PestTimingReport
{
    /**
     * @param  array<string, string>  $options
     * @return list<array{file: string, elapsed_seconds: float, shard: string, php_version: string, driver: string, worker_count: int}>
     */
    public function rows(array $options): array
    {
        if (($options['dry-run'] ?? null) === '1') {
            return [[
                'file' => 'tests/Feature/ExampleTest.php',
                'elapsed_seconds' => 1.234,
                'shard' => $options['shard'] ?? '1/10',
                'php_version' => $options['php-version'] ?? '8.4',
                'driver' => $options['driver'] ?? 'pgsql',
                'worker_count' => (int) ($options['worker-count'] ?? 1),
            ]];
        }

        /** @var array<string, array{file: string, elapsed_seconds: float, shard: string, php_version: string, driver: string, worker_count: int}> $files */
        $files = [];
        $inputDirectory = $options['input-dir'] ?? 'artifacts/pest-timing';

        foreach (glob(rtrim($inputDirectory, '/').'/*.xml') ?: [] as $path) {
            $document = new DOMDocument;
            $document->preserveWhiteSpace = false;

            if (! @$document->load($path)) {
                continue;
            }

            foreach ($document->getElementsByTagName('testcase') as $testcase) {
                $file = trim($testcase->getAttribute('file'));
                $time = $testcase->getAttribute('time');

                if ($file === '' || ! is_numeric($time)) {
                    continue;
                }

                $files[$file] ??= [
                    'file' => $file,
                    'elapsed_seconds' => 0.0,
                    'shard' => $options['shard'] ?? 'unknown',
                    'php_version' => $options['php-version'] ?? PHP_VERSION,
                    'driver' => $options['driver'] ?? (string) getenv('DB_CONNECTION'),
                    'worker_count' => (int) ($options['worker-count'] ?? 1),
                ];
                $files[$file]['elapsed_seconds'] += (float) $time;
            }
        }

        return array_values($files);
    }

    /**
     * @param  list<array{file: string, elapsed_seconds: float, shard: string, php_version: string, driver: string, worker_count: int}>  $rows
     */
    public function writeCsv(array $rows, string $path): void
    {
        if (! str_starts_with($path, 'php://')) {
            $this->ensureParentDirectory($path);
        }
        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw new RuntimeException("Unable to write {$path}");
        }

        fputcsv($handle, ['file', 'elapsed_seconds', 'shard', 'php_version', 'driver', 'worker_count'], ',', '"', '\\');

        foreach ($rows as $row) {
            fputcsv($handle, [
                $row['file'],
                number_format($row['elapsed_seconds'], 3, '.', ''),
                $row['shard'],
                $row['php_version'],
                $row['driver'],
                $row['worker_count'],
            ], ',', '"', '\\');
        }

        fclose($handle);
    }

    /**
     * @param  list<array{file: string, elapsed_seconds: float, shard: string, php_version: string, driver: string, worker_count: int}>  $rows
     */
    public function writeSummary(array $rows, string $path): void
    {
        usort($rows, static fn (array $left, array $right): int => $right['elapsed_seconds'] <=> $left['elapsed_seconds']);
        $lines = [
            '### Slowest Pest files',
            '',
            '| File | Seconds | Shard | PHP | Driver | Workers |',
            '| --- | ---: | --- | --- | --- | ---: |',
        ];

        foreach (array_slice($rows, 0, 10) as $row) {
            $lines[] = sprintf(
                '| `%s` | %.3f | %s | %s | %s | %d |',
                str_replace('`', '\\`', $row['file']),
                $row['elapsed_seconds'],
                $row['shard'],
                $row['php_version'],
                $row['driver'],
                $row['worker_count'],
            );
        }

        if (count($rows) === 0) {
            $lines[] = '| No JUnit timing records were produced | | | | | |';
        }

        if (! str_starts_with($path, 'php://')) {
            $this->ensureParentDirectory($path);
        }
        file_put_contents($path, implode(PHP_EOL, $lines).PHP_EOL);
    }

    /**
     * @return array<string, string>
     */
    public function options(array $arguments): array
    {
        $options = [];

        foreach ($arguments as $argument) {
            if (! str_starts_with($argument, '--')) {
                continue;
            }

            [$key, $value] = array_pad(explode('=', substr($argument, 2), 2), 2, '1');
            $options[$key] = $value;
        }

        return $options;
    }

    private function ensureParentDirectory(string $path): void
    {
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
    }
}

$report = new PestTimingReport;
$options = $report->options(array_slice($argv, 1));
$rows = $report->rows($options);

if (($options['dry-run'] ?? null) === '1') {
    $report->writeCsv($rows, 'php://stdout');
    $report->writeSummary($rows, 'php://stdout');
    exit(0);
}

$report->writeCsv($rows, $options['output'] ?? 'artifacts/pest-timing/report.csv');
$report->writeSummary($rows, $options['summary'] ?? 'artifacts/pest-timing/summary.md');
