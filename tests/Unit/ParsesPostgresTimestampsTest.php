<?php

use Tests\Support\NativeDateModel;
use Tests\Support\OptimizedDateModel;
use Tests\TestCase;

uses(TestCase::class);

it('parses identically to native Laravel across timestamp shapes', function (string $value) {
    $native = (new NativeDateModel)->parseDate($value);
    $optimized = (new OptimizedDateModel)->parseDate($value);

    expect($optimized::class)->toBe($native::class)
        ->and($optimized->format('Y-m-d H:i:s.uP'))->toBe($native->format('Y-m-d H:i:s.uP'))
        ->and($optimized->getTimestamp())->toBe($native->getTimestamp())
        ->and($optimized->getTimezone()->getName())->toBe($native->getTimezone()->getName());
})->with([
    '2026-01-05 02:30:00',
    '2026-01-05',
    '2026-01-05 02:30:00+00',
    '2026-01-05 10:30:00+08',
    '2026-01-05 02:30:00-05',
    '2026-01-05 02:30:00+0530',
    '2026-01-05 02:30:00+05:30',
    '2026-01-05 02:30:00Z',
    '2026-01-05 02:30:00.123456',
    '2026-01-05 02:30:00.123456+00',
]);
