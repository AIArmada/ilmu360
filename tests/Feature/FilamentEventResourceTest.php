<?php

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventSession;
use AIArmada\FilamentEvents\Resources\EventOccurrenceResource;
use AIArmada\FilamentEvents\Resources\EventResource;
use AIArmada\FilamentEvents\Resources\EventResource\Pages\EditEvent;
use AIArmada\FilamentEvents\Resources\EventSessionResource;
use AIArmada\FilamentSeating\RelationManagers\SeatMapsRelationManager;
use AIArmada\FilamentTicketing\RelationManagers\TicketTypesRelationManager;
use App\Shared\Events\Enums\EventAgeGroup;
use App\Shared\Events\Enums\EventGenderRestriction;
use App\Filament\Resources\Events\RelationManagers\ReferencesRelationManager;
use App\Models\Event;
use App\Models\Institution;
use App\Models\User;
use App\Models\Venue;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Event as EventFacade;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    // Livewire's test request broker disables HTTP middleware, so the app's
    // explicit-global owner middleware never runs. Set it on the matched
    // request so Filament resource queries resolve the same way as in HTTP.
    EventFacade::listen(RouteMatched::class, function (): void {
        OwnerContext::setForRequest(null);
    });
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

it('exposes edit access from the events list and view page', function (): void {
    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $event = Event::factory()->create();

    $table = EventResource::table(Table::make(Mockery::mock(HasTable::class)));
    $editAction = collect($table->getRecordActions())
        ->first(fn ($action): bool => $action instanceof EditAction);

    expect($editAction)->not->toBeNull();

    $this->actingAs($administrator)
        ->get(EventResource::getUrl('index', panel: 'admin'))
        ->assertSuccessful()
        ->assertSee(EventResource::getUrl('edit', ['record' => $event], panel: 'admin'), false);

    $this->actingAs($administrator)
        ->get(EventResource::getUrl('view', ['record' => $event], panel: 'admin'))
        ->assertSuccessful()
        ->assertSee(EventResource::getUrl('edit', ['record' => $event], panel: 'admin'), false);
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

it('syncs the event primary location when the admin form changes the venue', function (): void {
    Filament::setCurrentPanel('admin');

    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $oldVenue = Venue::factory()->create();
    $newVenue = Venue::factory()->create();
    $event = Event::factory()->create([
        'default_venue_id' => $oldVenue->getKey(),
    ]);
    $event->syncLocation((string) $oldVenue->getKey());

    OwnerContext::withOwner(null, function () use ($administrator, $event, $newVenue): void {
        Livewire::actingAs($administrator)
            ->test(EditEvent::class, ['record' => $event->getRouteKey()])
            ->fillForm([
                'default_venue_id' => $newVenue->getKey(),
                'gender' => EventGenderRestriction::All->value,
                'age_group' => [EventAgeGroup::AllAges->value],
            ])
            ->call('save')
            ->assertHasNoFormErrors();
    });

    $event->refresh();

    expect($event->default_venue_id)->toBe((string) $newVenue->getKey())
        ->and($event->primaryLocationVenueId())->toBe((string) $newVenue->getKey());
});

it('clears the event primary location when the admin form removes the venue', function (): void {
    Filament::setCurrentPanel('admin');

    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $institution = Institution::factory()->create();
    $venue = Venue::factory()->create();
    $event = Event::factory()->create([
        'institution_id' => $institution->getKey(),
        'default_venue_id' => $venue->getKey(),
    ]);
    $event->syncLocation((string) $venue->getKey());

    OwnerContext::withOwner(null, function () use ($administrator, $event): void {
        Livewire::actingAs($administrator)
            ->test(EditEvent::class, ['record' => $event->getRouteKey()])
            ->fillForm([
                'default_venue_id' => null,
                'gender' => EventGenderRestriction::All->value,
                'age_group' => [EventAgeGroup::AllAges->value],
            ])
            ->call('save')
            ->assertHasNoFormErrors();
    });

    $event->refresh();

    expect($event->default_venue_id)->toBeNull()
        ->and($event->primaryLocation()->exists())->toBeFalse()
        ->and($event->resolvedLocationName())->toBe($institution->name);
});
