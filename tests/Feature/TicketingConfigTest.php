<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('uses the ticketing package defaults after the extraction', function (): void {
    expect(config('ticketing.database.table_prefix'))->toBe('ticket_')
        ->and(config('ticketing.database.tables'))->toMatchArray([
            'ticket_types' => 'ticket_ticket_types',
            'ticket_type_components' => 'ticket_ticket_type_components',
            'ticket_type_products' => 'ticket_ticket_type_products',
            'ticket_type_seating_options' => 'ticket_ticket_type_seating_options',
            'passes' => 'ticket_passes',
            'pass_holders' => 'ticket_pass_holders',
            'pass_transfers' => 'ticket_pass_transfers',
        ])
        ->and(config('events.database.tables.event_ticket_types'))->toBeNull()
        ->and(config('events.database.tables.event_ticket_type_components'))->toBeNull()
        ->and(config('events.database.tables.event_ticket_type_products'))->toBeNull()
        ->and(config('events.database.tables.event_ticket_type_seating_options'))->toBeNull()
        ->and(config('events.database.tables.event_passes'))->toBeNull()
        ->and(Schema::hasTable('ticket_ticket_types'))->toBeTrue()
        ->and(Schema::hasTable('ticket_ticket_type_components'))->toBeTrue()
        ->and(Schema::hasTable('ticket_ticket_type_products'))->toBeTrue()
        ->and(Schema::hasTable('ticket_ticket_type_seating_options'))->toBeTrue()
        ->and(Schema::hasTable('ticket_passes'))->toBeTrue()
        ->and(Schema::hasTable('ticket_pass_holders'))->toBeTrue()
        ->and(Schema::hasTable('ticket_pass_transfers'))->toBeTrue()
        ->and(Schema::hasTable('event_ticket_types'))->toBeFalse()
        ->and(Schema::hasTable('event_ticket_type_components'))->toBeFalse()
        ->and(Schema::hasTable('event_ticket_type_products'))->toBeFalse()
        ->and(Schema::hasTable('event_ticket_type_seating_options'))->toBeFalse()
        ->and(Schema::hasTable('event_passes'))->toBeFalse();
});
