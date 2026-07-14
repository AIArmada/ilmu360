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
    withGlobalOwnerContext(function (): void {
        Speaker::factory()->create(['status' => 'verified']);
        Speaker::factory()->create(['status' => 'inactive']);
        expect(Speaker::active()->count())->toBe(1);

        Institution::factory()->create(['status' => 'verified']);
        Institution::factory()->create(['status' => 'inactive']);
        expect(Institution::active()->count())->toBe(1);

        Venue::factory()->create(['status' => 'verified']);
        Venue::factory()->create(['status' => 'inactive']);
        expect(Venue::active()->count())->toBe(1);

        Event::factory()->create([
            'status' => Approved::class,
            'visibility' => 'public',
            'published_at' => now(),
        ]);
        Event::factory()->create([
            'status' => Draft::class,
            'visibility' => 'public',
            'published_at' => null,
        ]);
        // Public event factories publish approved events as part of the current
        // lifecycle contract, so the approved fixture above is the sole active
        // event in this scope check.
        expect(Event::active()->count())->toBe(1);
    });
});
