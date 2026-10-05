<?php

use App\Enums\TimingMode;
use App\Models\Event;

it('coerces factory windows where the end precedes the start', function (): void {
    // Definition ends_at is random; combined with an explicit earlier end
    // (or a starts_at override past it) the occurrence sync would reject
    // the window. The factory coerces only that invalid combination.
    $event = Event::factory()->create([
        'timing_mode' => TimingMode::Absolute->value,
        'starts_at' => now()->addDays(10),
        'ends_at' => now()->addDays(10)->subHour(),
    ]);

    $occurrence = $event->occurrences()->firstOrFail();

    expect($occurrence->ends_at)->not->toBeNull()
        ->and($occurrence->ends_at->greaterThan($occurrence->starts_at))->toBeTrue();
});

it('accepts a starts_at override without an explicit end', function (): void {
    // Regression pin for the flaky InvalidArgumentException: a starts_at
    // override near the end of the definition range almost surely drew an
    // earlier random end before coercion existed.
    $event = Event::factory()->create([
        'timing_mode' => TimingMode::Absolute->value,
        'starts_at' => now()->addDays(55),
    ]);

    $occurrence = $event->occurrences()->firstOrFail();
    $endsAt = $occurrence->ends_at;

    // Prayer-relative definition draws carry no end; absolute draws must
    // always land after the overridden start.
    expect($endsAt === null || $endsAt->greaterThan($occurrence->starts_at))->toBeTrue();
});
