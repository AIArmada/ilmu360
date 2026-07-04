<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('uses the seating package defaults after the extraction', function (): void {
    expect(config('seating.database.tables'))->toMatchArray([
        'seat_maps' => 'seat_maps',
        'seat_sections' => 'seat_sections',
        'seats' => 'seats',
        'seat_holds' => 'seat_holds',
        'seat_allocations' => 'seat_allocations',
    ])
        ->and(config('seating.owner'))->toBe([
            'enabled' => true,
            'include_global' => false,
            'auto_assign_on_create' => true,
        ])
        ->and(config('events.database.tables.event_seat_maps'))->toBeNull()
        ->and(config('events.database.tables.event_seat_sections'))->toBeNull()
        ->and(config('events.database.tables.event_seats'))->toBeNull()
        ->and(config('events.database.tables.event_seat_holds'))->toBeNull()
        ->and(config('events.database.tables.event_seat_allocations'))->toBeNull()
        ->and(Schema::hasTable('seat_maps'))->toBeTrue()
        ->and(Schema::hasTable('seat_sections'))->toBeTrue()
        ->and(Schema::hasTable('seats'))->toBeTrue()
        ->and(Schema::hasTable('seat_holds'))->toBeTrue()
        ->and(Schema::hasTable('seat_allocations'))->toBeTrue()
        ->and(Schema::hasTable('event_seat_maps'))->toBeFalse()
        ->and(Schema::hasTable('event_seat_sections'))->toBeFalse()
        ->and(Schema::hasTable('event_seats'))->toBeFalse()
        ->and(Schema::hasTable('event_seat_holds'))->toBeFalse()
        ->and(Schema::hasTable('event_seat_allocations'))->toBeFalse();
});
