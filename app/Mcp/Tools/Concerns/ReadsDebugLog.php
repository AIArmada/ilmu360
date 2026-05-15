<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Concerns;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\File;

trait ReadsDebugLog
{
    /**
     * Read recent log lines, optionally filtered by channel marker.
     *
     * Reads the application Laravel log file, returns only lines that contain
     * the given `filter` string. If `all` is true, all matched lines are returned.
     * Otherwise, the result is truncated to the most recent `lines` entries.
     *
     * @return array{lines: list<string>, total_matched: int, log_path: string, filter: string, all: bool, error?: string}
     */
    protected function readFilteredDebugLog(string $filter, int $lines, bool $all = false): array
    {
        $logPath = config('logging.channels.single.path', storage_path('logs/laravel.log'));
        $normalizedFilter = trim($filter);

        if (! File::exists($logPath)) {
            return [
                'lines' => [],
                'total_matched' => 0,
                'log_path' => $logPath,
                'filter' => $normalizedFilter,
                'all' => $all,
            ];
        }

        /** @var list<string> $matched */
        $matched = [];

        $handle = fopen($logPath, 'r');

        if ($handle === false) {
            return [
                'lines' => [],
                'total_matched' => 0,
                'log_path' => $logPath,
                'filter' => $normalizedFilter,
                'all' => $all,
                'error' => 'Could not open log file (check file permissions).',
            ];
        }

        try {
            while (($line = fgets($handle)) !== false) {
                if ($normalizedFilter === '' || str_contains($line, $normalizedFilter)) {
                    $matched[] = rtrim($line);
                }
            }
        } finally {
            fclose($handle);
        }

        $totalMatched = count($matched);
        $recent = $all ? $matched : array_slice($matched, -$lines);

        return [
            'lines' => $recent,
            'total_matched' => $totalMatched,
            'log_path' => $logPath,
            'filter' => $normalizedFilter,
            'all' => $all,
        ];
    }

    /**
     * @return array<string, Type>
     */
    protected function debugLogInputSchema(JsonSchema $schema): array
    {
        return [
            'filter' => $schema->string()
                ->description('Optional string filter for log lines. Defaults to "mcp.tool_execution". Provide an empty string to disable filtering and scan all log lines.'),
            'lines' => $schema->integer()
                ->min(1)
                ->max(5000)
                ->description('Maximum number of matching log lines to return (most recent first) when `all` is false. Defaults to 500.'),
            'all' => $schema->boolean()
                ->description('When true, return all matching lines and ignore the `lines` limit.'),
        ];
    }

    /**
     * @return array<string, Type>
     */
    protected function debugLogOutputSchema(JsonSchema $schema): array
    {
        return [
            'lines' => $schema->array()->items($schema->string())->required()->description('Matching log lines in chronological order (most recent entry is last).'),
            'total_matched' => $schema->integer()->required()->description('Total number of log lines matched before truncating to the requested limit.'),
            'log_path' => $schema->string()->required()->description('Absolute path of the log file that was read.'),
            'filter' => $schema->string()->required()->description('Filter string that was applied. Empty string means no filtering.'),
            'all' => $schema->boolean()->required()->description('Whether all matching lines were returned without truncation.'),
            'error' => $schema->string()->description('Present only when the log file could not be opened (e.g. permission denied).'),
        ];
    }
}
