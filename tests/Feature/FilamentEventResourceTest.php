<?php

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventSession;
use AIArmada\FilamentEvents\Resources\EventOccurrenceResource;
use AIArmada\FilamentEvents\Resources\EventResource as PackageEventResource;
use AIArmada\FilamentEvents\Resources\EventSessionResource;
use AIArmada\FilamentSeating\RelationManagers\SeatMapsRelationManager;
use AIArmada\FilamentTicketing\RelationManagers\TicketTypesRelationManager;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Events\Pages\CreateEvent;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use App\Models\User;
use App\Models\Venue;
use App\Shared\Events\Enums\EventAgeGroup;
use App\Shared\Events\Enums\EventFormat;
use App\Shared\Events\Enums\EventGenderRestriction;
use App\Shared\Prayer\EventPrayerTime;
use Database\Seeders\AIArmada\EventRoleSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Event as EventFacade;
use Livewire\Component as LivewireComponent;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->seed(EventRoleSeeder::class);

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
    $organizer = Institution::factory()->create();
    $event->setPrimaryOrganizer($organizer);

    expect(EventResource::getRelations())
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
        ->assertSee('Primary organizer')
        ->assertSee('Schedule')
        ->assertSee('Prayer time')
        ->assertSee('Event date')
        ->assertSee('Audience');
});

it('registers only the app event resource in both panels and exposes the authoring field contract', function (): void {
    $adminResources = Filament::getPanel('admin')->getResources();
    $memberResources = Filament::getPanel('ahli')->getResources();

    expect($adminResources)
        ->toContain(EventResource::class)
        ->not->toContain(PackageEventResource::class)
        ->and($memberResources)
        ->toContain(EventResource::class)
        ->not->toContain(PackageEventResource::class)
        ->and(EventResource::getPages())->toHaveKeys(['index', 'create', 'view', 'edit'])
        ->and(config('filament-events.resources.enabled.event'))->toBeFalse();

    $livewire = Mockery::mock(LivewireComponent::class, HasSchemas::class);
    $fieldNames = collect(EventResource::form(Schema::make($livewire))->getFlatFields(withHidden: true))
        ->map(fn ($field): string => $field->getName())
        ->all();

    expect($fieldNames)
        ->toContain('event_format', 'event_date', 'end_date', 'prayer_time', 'primary_organizer_id', 'venue_id')
        ->not->toContain('delivery_mode', 'default_venue_id', 'slug', 'summary');
});

it('creates events from the app admin form through the Events authoring action', function (): void {
    Filament::setCurrentPanel('admin');

    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');
    $organizer = Institution::factory()->create();
    $venue = Venue::factory()->create();
    $speaker = Person::factory()->create();
    $reference = Reference::factory()->create();
    $eventDate = now()->addDays(10)->toDateString();
    $categoryId = eventCategoryId('kuliah_ceramah');

    OwnerContext::withOwner(null, function () use ($administrator, $organizer, $venue, $speaker, $reference, $eventDate, $categoryId): void {
        Livewire::actingAs($administrator)
            ->test(CreateEvent::class)
            ->fillForm([
                'title' => 'Action Authored Programme',
                'status' => 'draft',
                'visibility' => 'public',
                'event_format' => EventFormat::Physical->value,
                'event_date' => $eventDate,
                'prayer_time' => EventPrayerTime::LainWaktu->value,
                'custom_time' => '10:00',
                'end_date' => $eventDate,
                'end_time' => '11:00',
                'timezone' => 'Asia/Kuala_Lumpur',
                'schedule_kind' => 'single',
                'primary_organizer_id' => (string) $organizer->getKey(),
                'venue_id' => (string) $venue->getKey(),
                'persons' => [(string) $speaker->getKey()],
                'references' => [(string) $reference->getKey()],
                'event_category_ids' => [$categoryId],
                'gender' => EventGenderRestriction::All->value,
                'age_group' => [EventAgeGroup::AllAges->value],
            ])
            ->call('create')
            ->assertHasNoFormErrors();
    });

    $event = Event::query()->where('title', 'Action Authored Programme')->firstOrFail();

    expect($event->slug)->not->toBeEmpty()
        ->and($event->primaryOrganizerInvolvement?->involveable_id)->toBe((string) $organizer->getKey())
        ->and($event->default_venue_id)->toBe((string) $venue->getKey())
        ->and($event->primaryLocationVenueId())->toBe((string) $venue->getKey())
        ->and($event->keyPeople()->where('involveable_id', $speaker->getKey())->exists())->toBeTrue()
        ->and($event->references()->whereKey($reference->getKey())->exists())->toBeTrue()
        ->and($event->starts_at)->not->toBeNull()
        ->and($event->ends_at)->not->toBeNull();
});

it('exposes edit access from the events list and view page', function (): void {
    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $event = Event::factory()->create(['delivery_mode' => EventFormat::Online->value]);

    $table = EventResource::table(Table::make(Mockery::mock(HasTable::class)));
    $editAction = collect($table->getRecordActions())
        ->first(fn ($action): bool => $action instanceof EditAction);

    expect($editAction)->not->toBeNull()
        ->and(array_keys($table->getColumns()))
        ->toContain('event_format')
        ->not->toContain('delivery_mode');

    $this->actingAs($administrator)
        ->get(EventResource::getUrl('index', panel: 'admin'))
        ->assertSuccessful()
        ->assertSee(EventResource::getUrl('edit', ['record' => $event], panel: 'admin'), false);

    $this->actingAs($administrator)
        ->get(EventResource::getUrl('view', ['record' => $event], panel: 'admin'))
        ->assertSuccessful()
        ->assertSee(__('Online'))
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
    $organizer = Institution::factory()->create();
    $event = Event::factory()->create([
        'default_venue_id' => $oldVenue->getKey(),
    ]);
    $event->setPrimaryOrganizer($organizer);
    $event->syncLocation((string) $oldVenue->getKey());

    OwnerContext::withOwner(null, function () use ($administrator, $event, $newVenue): void {
        Livewire::actingAs($administrator)
            ->test(EditEvent::class, ['record' => $event->getRouteKey()])
            ->fillForm([
                'venue_id' => $newVenue->getKey(),
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
    $event->setPrimaryOrganizer($institution);
    $event->syncLocation((string) $venue->getKey());

    OwnerContext::withOwner(null, function () use ($administrator, $event): void {
        Livewire::actingAs($administrator)
            ->test(EditEvent::class, ['record' => $event->getRouteKey()])
            ->fillForm([
                'venue_id' => null,
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
