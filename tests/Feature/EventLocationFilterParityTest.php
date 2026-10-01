<?php

use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Support\AddressingTableResolver;
use AIArmada\Events\Models\EventLocation;
use App\Data\Api\Frontend\Search\EventListData;
use App\Data\EventDiscoveryCriteriaFactory;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Venue;
use App\Services\CalendarService;
use App\Services\EventSearchService;
use App\Services\PostgresEventDiscovery;
use App\Support\EventDiscovery\EventCardRelationshipProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function parityPublicAttributes(array $overrides = []): array
{
    return array_merge([
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now(),
        'starts_at' => now()->addDays(2),
        'delivery_mode' => 'physical',
    ], $overrides);
}

function parityDiscoveryTitles(array $filters): array
{
    $criteria = app(EventDiscoveryCriteriaFactory::class)->fromSearch(null, $filters, 50, 'time');

    return collect(app(PostgresEventDiscovery::class)->search($criteria)->items())
        ->pluck('title')
        ->all();
}

function parityApiTitles(array $filters): array
{
    $query = http_build_query(['filter' => $filters, 'per_page' => 50]);

    return collect(test()->getJson('/api/v1/events?'.$query)->assertOk()->json('data'))
        ->pluck('title')
        ->all();
}

function detachParityVenueAddresses(Venue $venue): void
{
    DB::table(AddressingTableResolver::resolve('addressables'))
        ->where('addressable_type', $venue->getMorphClass())
        ->where('addressable_id', (string) $venue->getKey())
        ->delete();
}

function paritySecondaryAddress(Venue|Institution $owner, array $attributes): Address
{
    $normalized = normalizeTestAddressAttributes($attributes);
    unset($normalized['area_assignments']);

    $address = Address::query()->create($normalized);
    $owner->attachAddress($address, 'secondary', false);

    return $address;
}

it('filters api events by the default venue primary address instead of the institution address', function (): void {
    $selangor = createTestPackageGeography('Selangor', 'Petaling', 'Subang');
    $kualaLumpur = createTestPackageGeography('Wilayah Persekutuan Kuala Lumpur', 'Lembah Pantai');

    $venue = Venue::factory()->create(['name' => 'Dewan Parity Selangor']);
    syncPrimaryAddressForTest($venue, [...$selangor['address'], 'country_code' => 'MY']);

    $institution = Institution::factory()->create(['name' => 'Masjid Parity KL']);
    syncPrimaryAddressForTest($institution, [...$kualaLumpur['address'], 'country_code' => 'MY']);

    Event::factory()->create(parityPublicAttributes([
        'title' => 'Parity Default Venue Place',
        'institution_id' => $institution->getKey(),
        'default_venue_id' => $venue->getKey(),
    ]));

    expect(parityApiTitles(['state_id' => (string) $selangor['state']->getKey()]))
        ->toContain('Parity Default Venue Place')
        ->and(parityApiTitles(['state_id' => (string) $kualaLumpur['state']->getKey()]))
        ->not->toContain('Parity Default Venue Place')
        ->and(parityApiTitles(['area_assignments' => [
            'administrative_district' => (string) $selangor['district']->getKey(),
        ]]))->toContain('Parity Default Venue Place');
});

it('ignores secondary owner addresses in api location filters', function (): void {
    $selangor = createTestPackageGeography('Selangor', 'Petaling', 'Subang');
    $kualaLumpur = createTestPackageGeography('Wilayah Persekutuan Kuala Lumpur', 'Lembah Pantai');

    $venue = Venue::factory()->create(['name' => 'Dewan Parity Secondary']);
    syncPrimaryAddressForTest($venue, [...$selangor['address'], 'country_code' => 'MY']);
    paritySecondaryAddress($venue, [...$kualaLumpur['address'], 'country_code' => 'MY']);

    Event::factory()->create(parityPublicAttributes([
        'title' => 'Parity Secondary Ignored',
        'institution_id' => null,
        'default_venue_id' => $venue->getKey(),
    ]));

    expect(parityApiTitles(['state_id' => (string) $selangor['state']->getKey()]))
        ->toContain('Parity Secondary Ignored')
        ->and(parityApiTitles(['state_id' => (string) $kualaLumpur['state']->getKey()]))
        ->not->toContain('Parity Secondary Ignored');
});

it('matches database discovery filters to the api selected-address contract', function (): void {
    $selangor = createTestPackageGeography('Selangor', 'Petaling', 'Subang');
    $kualaLumpur = createTestPackageGeography('Wilayah Persekutuan Kuala Lumpur', 'Lembah Pantai');

    $venue = Venue::factory()->create(['name' => 'Dewan Parity Discovery']);
    syncPrimaryAddressForTest($venue, [...$selangor['address'], 'country_code' => 'MY']);

    $institution = Institution::factory()->create(['name' => 'Masjid Parity Discovery KL']);
    syncPrimaryAddressForTest($institution, [...$kualaLumpur['address'], 'country_code' => 'MY']);

    Event::factory()->create(parityPublicAttributes([
        'title' => 'Parity Discovery Contract',
        'institution_id' => $institution->getKey(),
        'default_venue_id' => $venue->getKey(),
    ]));

    $selangorId = (string) $selangor['state']->getKey();
    $kualaLumpurId = (string) $kualaLumpur['state']->getKey();

    expect(parityDiscoveryTitles(['state_id' => $selangorId]))->toContain('Parity Discovery Contract')
        ->and(parityDiscoveryTitles(['state_id' => $kualaLumpurId]))->not->toContain('Parity Discovery Contract')
        ->and(parityApiTitles(['state_id' => $selangorId]))->toContain('Parity Discovery Contract')
        ->and(parityApiTitles(['state_id' => $kualaLumpurId]))->not->toContain('Parity Discovery Contract');
});

it('suppresses the package primary venue when a different default venue is set', function (): void {
    $selangor = createTestPackageGeography('Selangor', 'Petaling', 'Subang');
    $kualaLumpur = createTestPackageGeography('Wilayah Persekutuan Kuala Lumpur', 'Lembah Pantai');

    $defaultVenue = Venue::factory()->create(['name' => 'Dewan Parity Default KL']);
    syncPrimaryAddressForTest($defaultVenue, [...$kualaLumpur['address'], 'country_code' => 'MY']);

    $packageVenue = Venue::factory()->create(['name' => 'Dewan Parity Package Selangor']);
    syncPrimaryAddressForTest($packageVenue, [...$selangor['address'], 'country_code' => 'MY']);

    $institution = Institution::factory()->create(['name' => 'Masjid Parity Suppressed']);
    syncPrimaryAddressForTest($institution, [...$kualaLumpur['address'], 'country_code' => 'MY']);

    $event = Event::factory()->create(parityPublicAttributes([
        'title' => 'Parity Default Suppresses Package',
        'institution_id' => $institution->getKey(),
        'default_venue_id' => $defaultVenue->getKey(),
    ]));
    $event->syncLocation((string) $packageVenue->getKey());

    $selangorId = (string) $selangor['state']->getKey();
    $kualaLumpurId = (string) $kualaLumpur['state']->getKey();

    expect($event->fresh()->resolvedLocationName())->toBe('Dewan Parity Default KL')
        ->and(parityDiscoveryTitles(['state_id' => $selangorId]))->not->toContain('Parity Default Suppresses Package')
        ->and(parityDiscoveryTitles(['state_id' => $kualaLumpurId]))->toContain('Parity Default Suppresses Package')
        ->and(parityApiTitles(['state_id' => $selangorId]))->not->toContain('Parity Default Suppresses Package');
});

it('places primary-only events at the package venue instead of the institution', function (): void {
    $selangor = createTestPackageGeography('Selangor', 'Petaling', 'Subang');
    $kualaLumpur = createTestPackageGeography('Wilayah Persekutuan Kuala Lumpur', 'Lembah Pantai');

    $packageVenue = Venue::factory()->create(['name' => 'Dewan Parity Primary Only']);
    syncPrimaryAddressForTest($packageVenue, [...$selangor['address'], 'country_code' => 'MY']);

    $institution = Institution::factory()->create(['name' => 'Masjid Parity Primary Only KL']);
    syncPrimaryAddressForTest($institution, [...$kualaLumpur['address'], 'country_code' => 'MY']);

    $event = Event::factory()->create(parityPublicAttributes([
        'title' => 'Parity Primary Only Place',
        'institution_id' => $institution->getKey(),
        'default_venue_id' => null,
    ]));
    $event->syncLocation((string) $packageVenue->getKey());

    expect($event->fresh()->hasExplicitVenueSelection())->toBeTrue()
        ->and($event->fresh()->resolvedLocationName())->toBe('Dewan Parity Primary Only')
        ->and(parityDiscoveryTitles(['state_id' => (string) $selangor['state']->getKey()]))
        ->toContain('Parity Primary Only Place')
        ->and(parityDiscoveryTitles(['state_id' => (string) $kualaLumpur['state']->getKey()]))
        ->not->toContain('Parity Primary Only Place');
});

it('never borrows the institution when the selected venue has no address or is missing', function (): void {
    $selangor = createTestPackageGeography('Selangor', 'Petaling', 'Subang');

    $venue = Venue::factory()->create(['name' => 'Dewan Parity No Address']);
    syncPrimaryAddressForTest($venue, [...$selangor['address'], 'country_code' => 'MY']);
    detachParityVenueAddresses($venue);

    $institution = Institution::factory()->create(['name' => 'Masjid Parity No Borrow']);
    syncPrimaryAddressForTest($institution, [...$selangor['address'], 'country_code' => 'MY']);

    $otherInstitution = Institution::factory()->create(['name' => 'Masjid Parity Missing Marker']);
    syncPrimaryAddressForTest($otherInstitution, [...$selangor['address'], 'country_code' => 'MY']);

    $noAddressEvent = Event::factory()->create(parityPublicAttributes([
        'title' => 'Parity Venue Without Address',
        'institution_id' => $institution->getKey(),
        'default_venue_id' => $venue->getKey(),
    ]));
    $missingVenueEvent = Event::factory()->create(parityPublicAttributes([
        'title' => 'Parity Missing Venue Marker',
        'institution_id' => $otherInstitution->getKey(),
        'default_venue_id' => (string) Str::uuid(),
    ]));

    $titles = parityDiscoveryTitles(['state_id' => (string) $selangor['state']->getKey()]);

    expect($noAddressEvent->fresh()->hasExplicitVenueSelection())->toBeTrue()
        ->and($noAddressEvent->fresh()->resolvedLocationAddress())->toBeNull()
        ->and($missingVenueEvent->fresh()->hasExplicitVenueSelection())->toBeTrue()
        ->and($missingVenueEvent->fresh()->resolvedLocationAddress())->toBeNull()
        ->and($titles)->not->toContain('Parity Venue Without Address', 'Parity Missing Venue Marker');
});

it('never places events at the organizer involvement institution', function (): void {
    $selangor = createTestPackageGeography('Selangor', 'Petaling', 'Subang');

    $organizer = Institution::factory()->create(['name' => 'Masjid Parity Organizer']);
    syncPrimaryAddressForTest($organizer, [...$selangor['address'], 'country_code' => 'MY']);

    $event = Event::factory()->create(parityPublicAttributes([
        'title' => 'Parity Organizer Never Places',
        'institution_id' => null,
        'default_venue_id' => null,
    ]));
    withGlobalOwnerContext(fn () => $event->setPrimaryOrganizer($organizer));

    $fresh = $event->fresh();

    expect($fresh->organizer)->toBeInstanceOf(Institution::class)
        ->and($fresh->resolvedLocationAddress())->toBeNull()
        ->and($fresh->resolvedLocationName())->toBeNull()
        ->and(parityDiscoveryTitles(['state_id' => (string) $selangor['state']->getKey()]))
        ->not->toContain('Parity Organizer Never Places');
});

it('treats institution-only events as the current place in filters and payloads', function (): void {
    $selangor = createTestPackageGeography('Selangor', 'Petaling', 'Subang');

    $institution = Institution::factory()->create(['name' => 'Masjid Parity Home Ground']);
    syncPrimaryAddressForTest($institution, [...$selangor['address'], 'country_code' => 'MY']);

    $event = Event::factory()->create(parityPublicAttributes([
        'title' => 'Parity Institution Home',
        'institution_id' => $institution->getKey(),
        'default_venue_id' => null,
    ]));

    $fresh = $event->fresh();

    expect($fresh->hasExplicitVenueSelection())->toBeFalse()
        ->and($fresh->resolvedLocationAddress()?->getKey())->toBe($institution->primaryAddress()?->getKey())
        ->and(parityApiTitles(['state_id' => (string) $selangor['state']->getKey()]))
        ->toContain('Parity Institution Home')
        ->and(EventListData::fromModel($fresh)->location)->toContain('Masjid Parity Home Ground');
});

it('measures nearby distance from the selected owner address', function (): void {
    config(['scout.driver' => 'database']);

    $nearVenue = Venue::factory()->create(['name' => 'Dewan Parity Near']);
    syncPrimaryAddressForTest($nearVenue, ['latitude' => 3.1390, 'longitude' => 101.6869]);

    $farInstitution = Institution::factory()->create(['name' => 'Masjid Parity Far Home']);
    syncPrimaryAddressForTest($farInstitution, ['latitude' => 3.2600, 'longitude' => 101.8600]);

    $farVenue = Venue::factory()->create(['name' => 'Dewan Parity Far Default']);
    syncPrimaryAddressForTest($farVenue, ['latitude' => 3.2600, 'longitude' => 101.8600]);

    $packageNearVenue = Venue::factory()->create(['name' => 'Dewan Parity Package Near']);
    syncPrimaryAddressForTest($packageNearVenue, ['latitude' => 3.1390, 'longitude' => 101.6869]);

    Event::factory()->create(parityPublicAttributes([
        'title' => 'Parity Nearby Default Wins',
        'institution_id' => $farInstitution->getKey(),
        'default_venue_id' => $nearVenue->getKey(),
    ]));

    $suppressed = Event::factory()->create(parityPublicAttributes([
        'title' => 'Parity Nearby Suppressed Package',
        'institution_id' => null,
        'default_venue_id' => $farVenue->getKey(),
    ]));
    $suppressed->syncLocation((string) $packageNearVenue->getKey());

    $packageOnly = Event::factory()->create(parityPublicAttributes([
        'title' => 'Parity Nearby Package Only',
        'institution_id' => $farInstitution->getKey(),
        'default_venue_id' => null,
    ]));
    $packageOnly->syncLocation((string) $packageNearVenue->getKey());

    $events = app(EventSearchService::class)->searchNearby(
        lat: 3.1390,
        lng: 101.6869,
        radiusKm: 12,
        filters: [],
        perPage: 20,
    );

    $titles = collect($events->items())->pluck('title')->all();
    $nearest = collect($events->items())->firstWhere('title', 'Parity Nearby Default Wins');

    expect($titles)->toContain('Parity Nearby Default Wins', 'Parity Nearby Package Only')
        ->and($titles)->not->toContain('Parity Nearby Suppressed Package')
        ->and($nearest)->not->toBeNull()
        ->and($nearest->distance_km ?? null)->not->toBeNull();
});

it('renders calendar location from the selected venue', function (): void {
    $venue = Venue::factory()->create(['name' => 'Dewan Kalendar Parity']);
    $institution = Institution::factory()->create(['name' => 'Masjid Kalendar Bukan Lokasi']);

    $event = Event::factory()->create(parityPublicAttributes([
        'title' => 'Parity Calendar Place',
        'institution_id' => $institution->getKey(),
        'default_venue_id' => $venue->getKey(),
    ]));

    $fresh = $event->fresh();
    $googleUrl = app(CalendarService::class)->googleCalendarUrl($fresh);
    parse_str((string) parse_url($googleUrl, PHP_URL_QUERY), $googleParams);

    expect($googleParams['location'] ?? null)->toContain('Dewan Kalendar Parity')
        ->and($googleParams['location'] ?? null)->not->toContain('Masjid Kalendar Bukan Lokasi')
        ->and(app(CalendarService::class)->generateIcs($fresh))->toContain('Dewan Kalendar Parity');
});

it('serializes the selected package venue place in the list payload', function (): void {
    $selangor = createTestPackageGeography('Selangor', 'Petaling', 'Subang');
    $kualaLumpur = createTestPackageGeography('Wilayah Persekutuan Kuala Lumpur', 'Lembah Pantai');

    $packageVenue = Venue::factory()->create(['name' => 'Dewan Payload Parity']);
    syncPrimaryAddressForTest($packageVenue, [...$selangor['address'], 'country_code' => 'MY']);

    $institution = Institution::factory()->create(['name' => 'Masjid Payload Bukan Lokasi']);
    syncPrimaryAddressForTest($institution, [...$kualaLumpur['address'], 'country_code' => 'MY']);

    $event = Event::factory()->create(parityPublicAttributes([
        'title' => 'Parity Payload Package Place',
        'institution_id' => $institution->getKey(),
        'default_venue_id' => null,
    ]));
    $event->syncLocation((string) $packageVenue->getKey());

    $location = EventListData::fromModel($event->fresh())->location;

    expect($location)->toContain('Dewan Payload Parity')
        ->and($location)->toContain('Selangor')
        ->and($location)->not->toContain('Masjid Payload Bukan Lokasi');
});

it('places a leading no-venue primary row at the institution in sql filters', function (): void {
    $selangor = createTestPackageGeography('Selangor', 'Petaling', 'Subang');
    $kualaLumpur = createTestPackageGeography('Wilayah Persekutuan Kuala Lumpur', 'Lembah Pantai');

    $laterVenue = Venue::factory()->create(['name' => 'Dewan Parity Later Row']);
    syncPrimaryAddressForTest($laterVenue, [...$selangor['address'], 'country_code' => 'MY']);

    $institution = Institution::factory()->create(['name' => 'Masjid Parity First Null']);
    syncPrimaryAddressForTest($institution, [...$kualaLumpur['address'], 'country_code' => 'MY']);

    $event = Event::factory()->create(parityPublicAttributes([
        'title' => 'Parity First Null Place',
        'institution_id' => $institution->getKey(),
        'default_venue_id' => null,
    ]));

    EventLocation::create([
        'event_id' => (string) $event->getKey(),
        'event_occurrence_id' => null,
        'event_session_id' => null,
        'location_role' => 'primary',
        'venue_id' => null,
        'venue_space_id' => null,
        'visibility' => 'public',
        'status' => 'active',
        'sort_order' => 0,
    ]);
    EventLocation::create([
        'event_id' => (string) $event->getKey(),
        'event_occurrence_id' => null,
        'event_session_id' => null,
        'location_role' => 'primary',
        'venue_id' => (string) $laterVenue->getKey(),
        'venue_space_id' => null,
        'visibility' => 'public',
        'status' => 'active',
        'sort_order' => 1,
    ]);

    $fresh = $event->fresh();

    expect($fresh->primaryLocationVenue)->toBeNull()
        ->and($fresh->resolvedLocationName())->toBe('Masjid Parity First Null')
        ->and(parityDiscoveryTitles(['state_id' => (string) $kualaLumpur['state']->getKey()]))
        ->toContain('Parity First Null Place')
        ->and(parityDiscoveryTitles(['state_id' => (string) $selangor['state']->getKey()]))
        ->not->toContain('Parity First Null Place')
        ->and(parityApiTitles(['state_id' => (string) $kualaLumpur['state']->getKey()]))
        ->toContain('Parity First Null Place')
        ->and(parityApiTitles(['state_id' => (string) $selangor['state']->getKey()]))
        ->not->toContain('Parity First Null Place');
});

it('measures nearby distance from the selected row when the first primary venue is null or missing', function (): void {
    config(['scout.driver' => 'database']);

    $homeInstitution = Institution::factory()->create(['name' => 'Masjid Parity Near Home']);
    syncPrimaryAddressForTest($homeInstitution, ['latitude' => 3.1390, 'longitude' => 101.6869]);

    $farVenue = Venue::factory()->create(['name' => 'Dewan Parity Later Far']);
    syncPrimaryAddressForTest($farVenue, ['latitude' => 3.2600, 'longitude' => 101.8600]);

    $nullFirst = Event::factory()->create(parityPublicAttributes([
        'title' => 'Parity Nearby First Null',
        'institution_id' => $homeInstitution->getKey(),
        'default_venue_id' => null,
    ]));

    EventLocation::create([
        'event_id' => (string) $nullFirst->getKey(),
        'event_occurrence_id' => null,
        'event_session_id' => null,
        'location_role' => 'primary',
        'venue_id' => null,
        'venue_space_id' => null,
        'visibility' => 'public',
        'status' => 'active',
        'sort_order' => 0,
    ]);
    EventLocation::create([
        'event_id' => (string) $nullFirst->getKey(),
        'event_occurrence_id' => null,
        'event_session_id' => null,
        'location_role' => 'primary',
        'venue_id' => (string) $farVenue->getKey(),
        'venue_space_id' => null,
        'visibility' => 'public',
        'status' => 'active',
        'sort_order' => 1,
    ]);

    $orphanFirst = Event::factory()->create(parityPublicAttributes([
        'title' => 'Parity Nearby First Missing',
        'institution_id' => $homeInstitution->getKey(),
        'default_venue_id' => null,
    ]));

    EventLocation::create([
        'event_id' => (string) $orphanFirst->getKey(),
        'event_occurrence_id' => null,
        'event_session_id' => null,
        'location_role' => 'primary',
        'venue_id' => (string) Str::uuid(),
        'venue_space_id' => null,
        'visibility' => 'public',
        'status' => 'active',
        'sort_order' => 0,
    ]);
    EventLocation::create([
        'event_id' => (string) $orphanFirst->getKey(),
        'event_occurrence_id' => null,
        'event_session_id' => null,
        'location_role' => 'primary',
        'venue_id' => (string) $farVenue->getKey(),
        'venue_space_id' => null,
        'visibility' => 'public',
        'status' => 'active',
        'sort_order' => 1,
    ]);

    $events = app(EventSearchService::class)->searchNearby(
        lat: 3.1390,
        lng: 101.6869,
        radiusKm: 12,
        filters: [],
        perPage: 20,
    );

    $titles = collect($events->items())->pluck('title')->all();

    // The null first row measures from the near institution place; the
    // orphan first marker matches no address at all, like an orphan
    // default venue. Neither borrows the later far venue.
    expect($titles)->toContain('Parity Nearby First Null')
        ->and($titles)->not->toContain('Parity Nearby First Missing');
});

it('matches the venue_id filter to the winning explicit place only', function (): void {
    $defaultVenue = Venue::factory()->create(['name' => 'Dewan Parity Default A']);
    $packageVenue = Venue::factory()->create(['name' => 'Dewan Parity Package B']);
    $institution = Institution::factory()->create(['name' => 'Masjid Parity Venue Filter']);

    $defaultWins = Event::factory()->create(parityPublicAttributes([
        'title' => 'Parity Venue Default Wins',
        'institution_id' => $institution->getKey(),
        'default_venue_id' => $defaultVenue->getKey(),
    ]));
    $defaultWins->syncLocation((string) $packageVenue->getKey());

    $packageOnly = Event::factory()->create(parityPublicAttributes([
        'title' => 'Parity Venue Package Only',
        'institution_id' => $institution->getKey(),
        'default_venue_id' => null,
    ]));
    $packageOnly->syncLocation((string) $packageVenue->getKey());

    $missingDefault = Event::factory()->create(parityPublicAttributes([
        'title' => 'Parity Venue Missing Default',
        'institution_id' => $institution->getKey(),
        'default_venue_id' => (string) Str::uuid(),
    ]));
    $missingDefault->syncLocation((string) $packageVenue->getKey());

    $defaultId = (string) $defaultVenue->getKey();
    $packageId = (string) $packageVenue->getKey();

    expect($defaultWins->fresh()->resolvedLocationName())->toBe('Dewan Parity Default A')
        ->and(parityDiscoveryTitles(['venue_id' => $defaultId]))->toContain('Parity Venue Default Wins')
        // An explicit default A plus package primary B never matches
        // filter B, despite the canonically selected package row.
        ->and(parityDiscoveryTitles(['venue_id' => $packageId]))
        ->toContain('Parity Venue Package Only')
        ->not->toContain('Parity Venue Default Wins', 'Parity Venue Missing Default');
});

it('reuses eager-loaded primary places without per-item queries', function (): void {
    $packageVenue = Venue::factory()->create(['name' => 'Dewan Bound Parity']);
    $institution = Institution::factory()->create(['name' => 'Masjid Bound Home']);

    $ids = [];

    foreach (range(1, 5) as $index) {
        $event = Event::factory()->create(parityPublicAttributes([
            'title' => "Parity Bound Place {$index}",
            'institution_id' => $institution->getKey(),
            'default_venue_id' => null,
        ]));
        $event->syncLocation((string) $packageVenue->getKey());
        $ids[] = $event->getKey();
    }

    $events = Event::query()
        ->whereKey($ids)
        ->with(app(EventCardRelationshipProvider::class)->relations())
        ->orderBy('title')
        ->get();

    DB::enableQueryLog();
    DB::flushQueryLog();

    foreach ($events as $event) {
        $event->resolvedLocationAddress();
        $event->resolvedLocationName();
    }

    expect(DB::getQueryLog())->toHaveCount(0);

    DB::flushQueryLog();

    $locations = [];

    foreach ($events as $event) {
        $locations[] = EventListData::fromModel($event)->location;
    }

    expect(count(DB::getQueryLog()))->toBeLessThan($events->count())
        ->and($locations)->each->toContain('Dewan Bound Parity');
});
