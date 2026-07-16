<?php

require_once __DIR__.'/../../scripts/PestDurationBudget.php';

it('reports warning and failure thresholds from a measured baseline', function () {
    $budget = new PestDurationBudget;

    $warning = $budget->evaluate([
        ['file' => 'tests/Feature/Slow.php', 'elapsed_seconds' => 76 * 60],
    ], 60);
    $failure = $budget->evaluate([
        ['file' => 'tests/Feature/Slow.php', 'elapsed_seconds' => 91 * 60],
    ], 60);

    expect($warning['status'])->toBe('warning')
        ->and($failure['status'])->toBe('failure')
        ->and($failure['slowest_file'])->toBe('tests/Feature/Slow.php');
});

it('reports missing timing artifacts without inventing a budget', function () {
    $result = (new PestDurationBudget)->evaluate([], 10);

    expect($result['status'])->toBe('missing')
        ->and($result['slowest_file'])->toBeNull();
});
