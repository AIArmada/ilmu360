<?php

use App\Models\Event;
use App\Models\Institution;
use App\Models\Speaker;
use App\Models\Venue;
use App\States\EventStatus\Approved;
use App\States\EventStatus\Draft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('models have active scopes', function () {
    // Speaker
    Speaker::factory()->create(['is_active' => true]);
    Speaker::factory()->create(['is_active' => false]);
    expect(Speaker::active()->count())->toBe(1);

    // Institution
    Institution::factory()->create(['is_active' => true]);
    Institution::factory()->create(['is_active' => false]);
    expect(Institution::active()->count())->toBe(1);

    // Venue
    Venue::factory()->create(['is_active' => true]);
    Venue::factory()->create(['is_active' => false]);
    expect(Venue::active()->count())->toBe(1);

    // Event (uses is_active + status/visibility)
    Event::factory()->create([
        'status' => Approved::class,
        'visibility' => 'public',
        'is_active' => true,
    ]);
    Event::factory()->create([
        'status' => Draft::class,
        'visibility' => 'public',
        'is_active' => true,
    ]);
    Event::factory()->create([
        'status' => Approved::class,
        'visibility' => 'public',
        'is_active' => false,
    ]);
    expect(Event::active()->count())->toBe(1);
});
