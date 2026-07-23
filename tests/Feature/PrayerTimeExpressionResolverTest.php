<?php

use AIArmada\Events\Models\EventTimeExpression;
use App\Models\Event;
use App\Services\PrayerTimeExpressionResolver;
use App\Services\PrayerTimeService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns null when anchor type is not prayer', function () {
    $expression = EventTimeExpression::factory()->create([
        'time_mode' => 'fixed',
        'anchor_type' => 'fixed',
        'anchor_code' => null,
    ]);

    $result = app(PrayerTimeExpressionResolver::class)->resolve($expression);

    expect($result)->toBeNull();
});

it('returns null when anchor code is null', function () {
    $expression = EventTimeExpression::factory()->create([
        'time_mode' => 'prayer_time',
        'anchor_type' => 'prayer',
        'anchor_code' => null,
    ]);

    $result = app(PrayerTimeExpressionResolver::class)->resolve($expression);

    expect($result)->toBeNull();
});

it('returns null when anchor code is not a valid prayer reference', function () {
    $expression = EventTimeExpression::factory()->create([
        'time_mode' => 'prayer_time',
        'anchor_type' => 'prayer',
        'anchor_code' => 'invalid_prayer',
    ]);

    $result = app(PrayerTimeExpressionResolver::class)->resolve($expression);

    expect($result)->toBeNull();
});

it('returns null when parent event is not found', function () {
    $existingEvent = Event::factory()->create();
    $expression = EventTimeExpression::factory()->create([
        'event_id' => $existingEvent->id,
        'time_mode' => 'prayer_time',
        'anchor_type' => 'prayer',
        'anchor_code' => 'maghrib',
    ]);

    $expression->event_id = '00000000-0000-0000-0000-000000000000';

    $result = app(PrayerTimeExpressionResolver::class)->resolve($expression);

    expect($result)->toBeNull();
});

it('resolves a valid prayer time expression', function () {
    $event = Event::factory()->create([
        'status' => 'approved',
        'published_at' => now(),
        'starts_at' => now()->addDays(7),
        'timezone' => 'Asia/Kuala_Lumpur',
    ]);

    $expression = EventTimeExpression::factory()->create([
        'event_id' => $event->id,
        'time_mode' => 'prayer_time',
        'anchor_type' => 'prayer',
        'anchor_code' => 'maghrib',
        'offset_minutes' => 30,
    ]);

    $mockService = Mockery::mock(PrayerTimeService::class);
    $mockService->shouldReceive('calculateStartTime')
        ->once()
        ->andReturn(Carbon::now());

    $resolver = new PrayerTimeExpressionResolver($mockService);

    $result = $resolver->resolve($expression);

    expect($result)->not->toBeNull();
});
