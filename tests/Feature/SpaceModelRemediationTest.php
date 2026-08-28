<?php

use AIArmada\Events\Models\EventLocation;
use AIArmada\Events\Models\VenueSpaceType;
use App\Actions\Spaces\SaveSpaceAction;
use App\Contracts\SpaceEligibilityResolver;
use App\Data\Api\Event\EventPayloadData;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Space;
use App\Models\User;
use App\Models\Venue;
use App\Policies\SpacePolicy;
use Database\Seeders\SpaceSeeder;
use Database\Seeders\VenueSpaceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('enforces catalog and owner-scoped space eligibility for every selected id', function (): void {
    $institution = Institution::factory()->create();
    $otherInstitution = Institution::factory()->create();
    $venue = Venue::factory()->create();
    $otherVenue = Venue::factory()->create();

    $catalog = Space::factory()->create(['venue_id' => null]);
    $institutionSpace = Space::factory()->create(['venue_id' => null]);
    $otherInstitutionSpace = Space::factory()->create(['venue_id' => null]);
    $venueSpace = Space::factory()->create(['venue_id' => $venue->getKey()]);
    $otherVenueSpace = Space::factory()->create(['venue_id' => $otherVenue->getKey()]);

    $institutionSpace->institutions()->attach($institution);
    $otherInstitutionSpace->institutions()->attach($otherInstitution);

    $resolver = app(SpaceEligibilityResolver::class);

    expect($resolver->catalogQuery()->pluck('id')->map(strval(...))->all())
        ->toContain((string) $catalog->getKey(), (string) $institutionSpace->getKey())
        ->not->toContain((string) $venueSpace->getKey());

    expect($resolver->institutionQuery((string) $institution->getKey())->pluck('id')->map(strval(...))->all())
        ->toContain((string) $catalog->getKey(), (string) $institutionSpace->getKey())
        ->not->toContain((string) $otherInstitutionSpace->getKey(), (string) $venueSpace->getKey());

    expect($resolver->venueQuery((string) $venue->getKey())->pluck('id')->map(strval(...))->all())
        ->toContain((string) $catalog->getKey(), (string) $venueSpace->getKey())
        ->not->toContain((string) $otherVenueSpace->getKey());

    expect(fn (): mixed => $resolver->validateVenueSelection((string) $venue->getKey(), [
        (string) $venueSpace->getKey(),
        (string) $otherVenueSpace->getKey(),
    ]))->toThrow(ValidationException::class);
});

it('uses scoped slug uniqueness and stores venue ownership', function (): void {
    $venue = Venue::factory()->create();
    $otherVenue = Venue::factory()->create();
    $action = app(SaveSpaceAction::class);

    $catalog = $action->handle(['name' => 'Catalog Hall', 'slug' => 'shared-hall']);
    $owned = $action->handle([
        'name' => 'Venue Hall',
        'slug' => 'shared-hall',
        'venue_id' => $venue->getKey(),
    ]);
    $otherOwned = $action->handle([
        'name' => 'Other Venue Hall',
        'slug' => 'shared-hall',
        'venue_id' => $otherVenue->getKey(),
    ]);

    expect($catalog->venue_id)->toBeNull()
        ->and($owned->venue_id)->toBe((string) $venue->getKey())
        ->and($otherOwned->venue_id)->toBe((string) $otherVenue->getKey());

    expect(fn (): mixed => $action->handle([
        'name' => 'Duplicate Venue Hall',
        'slug' => 'shared-hall',
        'venue_id' => $venue->getKey(),
    ]))->toThrow(ValidationException::class);
});

it('seeds taxonomy, propagates the type, and preserves historical space names', function (): void {
    (new VenueSpaceTypeSeeder)->run();
    (new VenueSpaceTypeSeeder)->run();

    expect(VenueSpaceType::query()->where('code', 'hall')->count())->toBe(1);

    $space = Space::factory()->create([
        'name' => 'Historical Hall',
        'space_type' => 'hall',
    ]);
    $event = Event::factory()->create();

    $event->syncLocation(null, [(string) $space->getKey()]);

    $location = EventLocation::query()->where('event_id', $event->getKey())->firstOrFail();
    $hallType = VenueSpaceType::query()->where('code', 'hall')->firstOrFail();

    expect($location->space_name_snapshot)->toBe('Historical Hall')
        ->and((string) $location->venue_space_type_id)->toBe((string) $hallType->getKey());

    $space->update(['name' => 'Renamed Hall']);
    $event->syncLocation(null, [(string) $space->getKey()]);

    expect(EventLocation::query()->where('event_id', $event->getKey())->value('space_name_snapshot'))
        ->toBe('Historical Hall');

    $movedSpace = Space::factory()->create(['name' => 'Moved Hall', 'space_type' => 'hall']);
    $event->syncLocation(null, [(string) $movedSpace->getKey()]);

    expect(EventLocation::query()->where('event_id', $event->getKey())->value('space_name_snapshot'))
        ->toBe('Moved Hall');

    $legacyEvent = Event::factory()->create();
    EventLocation::create([
        'event_id' => $legacyEvent->getKey(),
        'location_role' => 'primary',
        'venue_space_id' => $space->getKey(),
        'visibility' => 'public',
        'status' => 'active',
        'sort_order' => 0,
    ]);

    (new SpaceSeeder)->run();

    expect(EventLocation::query()->where('event_id', $legacyEvent->getKey())->value('space_name_snapshot'))
        ->toBe('Renamed Hall');
});

it('rejects inactive taxonomy codes and institution links on venue-owned spaces', function (): void {
    (new VenueSpaceTypeSeeder)->run();
    VenueSpaceType::query()->where('code', 'hall')->update(['is_active' => false]);

    $action = app(SaveSpaceAction::class);
    $venue = Venue::factory()->create();
    $institution = Institution::factory()->create();

    expect(fn (): mixed => $action->handle([
        'name' => 'Invalid Type',
        'slug' => 'invalid-type',
        'space_type' => 'hall',
    ]))->toThrow(ValidationException::class);

    expect(fn (): mixed => $action->handle([
        'name' => 'Venue Institution Space',
        'slug' => 'venue-institution-space',
        'venue_id' => (string) $venue->getKey(),
        'institutions' => [(string) $institution->getKey()],
    ]))->toThrow(ValidationException::class);
});

it('uses institution pivot capacity overrides in event payloads', function (): void {
    $institution = Institution::factory()->create();
    $venue = Venue::factory()->create();
    $space = Space::factory()->create(['capacity' => 300]);

    app(SaveSpaceAction::class)->handle([
        'name' => $space->name,
        'slug' => $space->slug,
        'institutions' => [(string) $institution->getKey()],
        'institution_space_overrides' => [[
            'institution_id' => (string) $institution->getKey(),
            'capacity' => 120,
        ]],
    ], $space);

    $event = Event::factory()->create(['institution_id' => $institution->getKey()]);
    $event->syncLocation(null, [(string) $space->getKey()]);

    $payload = EventPayloadData::fromModel($event->fresh(['institution']))->toArray();

    expect(data_get($payload, 'institution_space.capacity'))->toBe(120);

    $venueEvent = Event::factory()->create([
        'institution_id' => $institution->getKey(),
        'default_venue_id' => $venue->getKey(),
    ]);
    $venueEvent->syncLocation((string) $venue->getKey(), [(string) $space->getKey()]);
    $venuePayload = EventPayloadData::fromModel($venueEvent->fresh(['institution']))->toArray();

    expect(data_get($venuePayload, 'institution_space'))->toBeNull()
        ->and(data_get($venuePayload, 'venue_space.capacity'))->toBe(300);
});

it('clears an institution capacity override when the selected override is removed', function (): void {
    $institution = Institution::factory()->create();
    $space = Space::factory()->create();

    app(SaveSpaceAction::class)->handle([
        'name' => $space->name,
        'slug' => $space->slug,
        'institutions' => [(string) $institution->getKey()],
        'institution_space_overrides' => [[
            'institution_id' => (string) $institution->getKey(),
            'capacity' => 120,
        ]],
    ], $space);

    app(SaveSpaceAction::class)->handle([
        'name' => $space->name,
        'slug' => $space->slug,
        'institutions' => [(string) $institution->getKey()],
        'institution_space_overrides' => [],
    ], $space->fresh());

    expect($institution->spaces()->whereKey($space->getKey())->firstOrFail()->pivot->capacity)
        ->toBeNull();
});

it('clears all institution capacity overrides without unlinking institutions when the override list is empty', function (): void {
    $firstInstitution = Institution::factory()->create();
    $secondInstitution = Institution::factory()->create();
    $space = Space::factory()->create();

    $space->institutions()->attach([
        $firstInstitution->getKey() => ['capacity' => 120],
        $secondInstitution->getKey() => ['capacity' => 180],
    ]);

    app(SaveSpaceAction::class)->handle([
        'name' => $space->name,
        'slug' => $space->slug,
        'institution_space_overrides' => [],
    ], $space->fresh());

    $space->refresh()->load('institutions');

    expect($space->institutions)->toHaveCount(2)
        ->and($space->institutions->pluck('pivot.capacity')->all())->each->toBeNull();
});

it('preserves unlisted institution links and capacities when applying partial overrides', function (): void {
    $firstInstitution = Institution::factory()->create();
    $secondInstitution = Institution::factory()->create();
    $space = Space::factory()->create();

    $space->institutions()->attach([
        $firstInstitution->getKey() => ['capacity' => 120],
        $secondInstitution->getKey() => ['capacity' => 180],
    ]);

    app(SaveSpaceAction::class)->handle([
        'name' => $space->name,
        'slug' => $space->slug,
        'institution_space_overrides' => [[
            'institution_id' => (string) $firstInstitution->getKey(),
            'capacity' => 90,
        ]],
    ], $space->fresh());

    $space->refresh()->load('institutions');
    $capacities = $space->institutions->mapWithKeys(
        fn (Institution $institution): array => [(string) $institution->getKey() => $institution->pivot->capacity],
    );

    expect($capacities->all())->toBe([
        (string) $firstInstitution->getKey() => 90,
        (string) $secondInstitution->getKey() => 180,
    ]);
});

it('blocks deletion of spaces referenced by event locations', function (): void {
    $user = Mockery::mock(User::class);
    $user->shouldReceive('hasRole')->with('super_admin')->andReturnTrue();
    $space = Space::factory()->create();
    $event = Event::factory()->create();
    $event->syncLocation(null, [(string) $space->getKey()]);

    $policy = new SpacePolicy;

    expect($policy->delete($user, $space))->toBeFalse();
    expect(fn (): bool => (bool) $space->delete())
        ->toThrow(ValidationException::class);

    $unreferenced = Space::factory()->create(['slug' => Str::slug('unreferenced-space').'-'.Str::random(5)]);

    expect($policy->delete($user, $unreferenced))->toBeTrue();
});
