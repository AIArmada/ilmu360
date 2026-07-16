<?php

declare(strict_types=1);

final class PestDurationBudget
{
    /**
     * @param  list<array{file: string, elapsed_seconds: float}>  $rows
     * @return array{status: string, baseline_minutes: float, warning_minutes: float, failure_minutes: float, slowest_file: string|null, slowest_seconds: float}
     */
    public function evaluate(array $rows, float $baselineMinutes): array
    {
        usort($rows, static fn (array $left, array $right): int => $right['elapsed_seconds'] <=> $left['elapsed_seconds']);
        $slowest = $rows[0] ?? null;
        $warningMinutes = ceil($baselineMinutes * 1.25);
        $failureMinutes = ceil($baselineMinutes * 1.5);
        $slowestMinutes = is_array($slowest) ? $slowest['elapsed_seconds'] / 60 : 0.0;

        return [
            'status' => $slowest === null ? 'missing' : ($slowestMinutes > $failureMinutes ? 'failure' : ($slowestMinutes > $warningMinutes ? 'warning' : 'ok')),
            'baseline_minutes' => $baselineMinutes,
            'warning_minutes' => (float) $warningMinutes,
            'failure_minutes' => (float) $failureMinutes,
            'slowest_file' => is_array($slowest) ? $slowest['file'] : null,
            'slowest_seconds' => is_array($slowest) ? $slowest['elapsed_seconds'] : 0.0,
        ];
    }
}
