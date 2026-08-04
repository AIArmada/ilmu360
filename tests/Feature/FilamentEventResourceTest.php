<?php

use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventSession;
use AIArmada\FilamentEvents\Resources\EventOccurrenceResource;
use AIArmada\FilamentEvents\Resources\EventResource;
use AIArmada\FilamentEvents\Resources\EventSessionResource;
use App\Filament\Resources\Events\RelationManagers\ReferencesRelationManager;
use App\Filament\Resources\Events\RelationManagers\SeatMapsRelationManager;
use App\Filament\Resources\Events\RelationManagers\TicketTypesRelationManager;
use App\Models\Event;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
});

it('exposes the event domain graph on the admin edit resource', function (): void {
    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $event = Event::factory()->create([
        'title' => 'Admin Resource Programme',
    ]);

    expect(EventResource::getRelations())
        ->toContain(ReferencesRelationManager::class)
        ->toContain(TicketTypesRelationManager::class)
        ->toContain(SeatMapsRelationManager::class);

    expect(EventOccurrenceResource::getRelations())
        ->toContain(TicketTypesRelationManager::class)
        ->toContain(SeatMapsRelationManager::class);

    expect(EventSessionResource::getRelations())
        ->toContain(TicketTypesRelationManager::class)
        ->toContain(SeatMapsRelationManager::class);

    $this->actingAs($administrator)
        ->get(EventResource::getUrl('edit', ['record' => $event], panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Event Context')
        ->assertSee('Institution')
        ->assertSee('Venue')
        ->assertSee('Speakers')
        ->assertSee('References')
        ->assertSee('Schedule')
        ->assertSee('Audience');
});

it('exposes independent cover and schedule controls on occurrence and session resources', function (): void {
    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $event = Event::factory()->create();
    $occurrence = EventOccurrence::query()->create([
        'event_id' => $event->getKey(),
        'title' => 'Second date',
        'slug' => 'second-date',
        'starts_at' => now()->addDays(2),
        'ends_at' => now()->addDays(2)->addHours(2),
        'timezone' => 'Asia/Kuala_Lumpur',
        'status' => 'scheduled',
        'visibility' => 'public',
        'delivery_mode' => 'physical',
    ]);
    $session = EventSession::query()->create([
        'event_id' => $event->getKey(),
        'event_occurrence_id' => $occurrence->getKey(),
        'title' => 'Opening session',
        'slug' => 'opening-session',
        'starts_at' => now()->addDays(2),
        'ends_at' => now()->addDays(2)->addHour(),
        'timezone' => 'Asia/Kuala_Lumpur',
        'status' => 'scheduled',
        'visibility' => 'public',
        'delivery_mode' => 'physical',
        'sort_order' => 1,
    ]);

    expect(EventOccurrenceResource::getPages())->toHaveKeys(['create', 'view', 'edit'])
        ->and(EventSessionResource::getPages())->toHaveKeys(['create', 'view', 'edit']);

    $this->actingAs($administrator)
        ->get(EventOccurrenceResource::getUrl('edit', ['record' => $occurrence], panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Cover image')
        ->assertSee('Timezone')
        ->assertSee('Delivery mode')
        ->assertSee('Capacity');

    $this->actingAs($administrator)
        ->get(EventSessionResource::getUrl('edit', ['record' => $session], panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Cover image')
        ->assertSee('Timezone')
        ->assertSee('Delivery mode')
        ->assertSee('Sort order');
});
