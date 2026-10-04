<?php

use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Models\AddressArea;
use AIArmada\Addressing\Models\AddressAreaAssignment;
use AIArmada\Addressing\Models\AddressCountry;
use AIArmada\Addressing\Models\City;
use AIArmada\Addressing\Models\State;
use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Enums\InstitutionVenueRole;
use App\Models\Institution;
use App\Models\Venue;
use App\Support\Prayer\PrayerLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function ensurePrayerCandidatesCountry(): AddressCountry
{
    return AddressCountry::query()->firstOrCreate(
        ['iso2' => 'MY'],
        ['name' => 'Malaysia', 'iso3' => 'MYS', 'phone_code' => '60'],
    );
}

function makePrayerCandidatesState(AddressCountry $country, string $name = 'Johor', string $code = '01'): State
{
    return State::query()->create([
        'country_id' => $country->getKey(),
        'country_code' => $country->iso2,
        'name' => $name,
        'code' => $code,
    ]);
}

function makePrayerCandidatesAddress(AddressCountry $country, ?State $state = null, array $attributes = []): Address
{
    return OwnerContext::withOwner(null, fn (): Address => Address::query()->create(array_merge([
        'country_id' => $country->getKey(),
        'country_code' => $country->iso2,
        'state_id' => $state?->getKey(),
    ], $attributes)));
}

function makePrayerCandidatesArea(AddressCountry $country, string $name, string $type, ?string $parentId = null): AddressArea
{
    return AddressArea::query()->create([
        'country_id' => $country->getKey(),
        'parent_id' => $parentId,
        'country_code' => $country->iso2,
        'level' => $parentId === null ? 2 : 3,
        'name' => $name,
        'type' => $type,
        'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
        'source' => 'tests',
        'source_id' => 'prayer-candidates-'.Str::lower(Str::random(10)),
    ]);
}

function attachPrayerCandidatesAddress(Institution|Venue $owner, Address $address): void
{
    OwnerContext::withOwner(null, fn (): mixed => $owner->attachAddress($address, 'primary', true));
}

function detachPrayerCandidatesAddresses(Institution|Venue $owner): void
{
    OwnerContext::withOwner(null, function () use ($owner): void {
        $owner->addresses()->detach();
        $owner->unsetRelation('addresses');
    });
}

function assignPrayerCandidatesArea(Address $address, AddressArea $area, string $role): void
{
    OwnerContext::withOwner(null, function () use ($address, $area, $role): void {
        AddressAreaAssignment::query()->create([
            'address_id' => $address->getKey(),
            'address_area_id' => $area->getKey(),
            'role' => $role,
            'is_primary' => true,
        ]);
    });
}

beforeEach(function () {
    config()->set('prayer.enabled', true);
});

it('extracts the district area as the first candidate', function () {
    $country = ensurePrayerCandidatesCountry();
    $state = makePrayerCandidatesState($country);
    $address = makePrayerCandidatesAddress($country, $state);
    assignPrayerCandidatesArea($address, makePrayerCandidatesArea($country, 'Muar', 'district'), 'administrative_district');

    $location = PrayerLocation::fromAddress($address, 'MY');

    expect($location['districtCandidates'])->toBe(['Muar'])
        ->and($location['stateCode'])->toBe('01');
});

it('climbs from a mukim to its parent district', function () {
    $country = ensurePrayerCandidatesCountry();
    $state = makePrayerCandidatesState($country, 'Selangor', '10');
    $address = makePrayerCandidatesAddress($country, $state);
    $district = makePrayerCandidatesArea($country, 'Gombak', 'district');
    $mukim = makePrayerCandidatesArea($country, 'Hulu Klang', 'mukim', $district->getKey());
    assignPrayerCandidatesArea($address, $mukim, 'administrative_subdivision');

    expect(PrayerLocation::fromAddress($address, 'MY')['districtCandidates'])->toBe(['Hulu Klang', 'Gombak']);
});

it('ranks the subdivision literal ahead of its broader district', function () {
    $country = ensurePrayerCandidatesCountry();
    $state = makePrayerCandidatesState($country, 'Kelantan', '03');
    $address = makePrayerCandidatesAddress($country, $state);
    $district = makePrayerCandidatesArea($country, 'Gua Musang', 'district');
    $mukim = makePrayerCandidatesArea($country, 'Mukim Chiku', 'mukim', $district->getKey());
    assignPrayerCandidatesArea($address, $district, 'administrative_district');
    assignPrayerCandidatesArea($address, $mukim, 'administrative_subdivision');

    // Mukim Chiku resolves KTN01; Gua Musang alone would resolve KTN02.
    expect(PrayerLocation::fromAddress($address, 'MY')['districtCandidates'])->toBe(['Mukim Chiku', 'Gua Musang']);
});

it('falls back to the city when no areas are assigned', function () {
    $country = ensurePrayerCandidatesCountry();
    $state = makePrayerCandidatesState($country, 'Selangor', '10');
    $city = City::query()->create([
        'country_id' => $country->getKey(),
        'state_id' => $state->getKey(),
        'country_code' => 'MY',
        'state_code' => '10',
        'name' => 'Shah Alam',
    ]);
    $address = makePrayerCandidatesAddress($country, $state, ['city_id' => $city->getKey()]);

    expect(PrayerLocation::fromAddress($address, 'MY')['districtCandidates'])->toBe(['Shah Alam']);
});

it('returns no candidates without areas or city', function () {
    $country = ensurePrayerCandidatesCountry();
    $address = makePrayerCandidatesAddress($country, makePrayerCandidatesState($country));

    expect(PrayerLocation::fromAddress($address, 'MY')['districtCandidates'])->toBe([]);
});

it('skips candidate extraction when the provider flag is off', function () {
    config()->set('prayer.enabled', false);

    $country = ensurePrayerCandidatesCountry();
    $state = makePrayerCandidatesState($country);
    $address = makePrayerCandidatesAddress($country, $state);
    assignPrayerCandidatesArea($address, makePrayerCandidatesArea($country, 'Muar', 'district'), 'administrative_district');

    $location = PrayerLocation::fromAddress($address, 'MY');

    expect($location['districtCandidates'])->toBe([])
        ->and($location['stateCode'])->toBe('01');
});

it('prefers the institution address when both targets are set', function () {
    $country = ensurePrayerCandidatesCountry();
    $state = makePrayerCandidatesState($country);
    $venue = OwnerContext::withOwner(null, fn (): Venue => Venue::factory()->create());
    detachPrayerCandidatesAddresses($venue);
    attachPrayerCandidatesAddress($venue, makePrayerCandidatesAddress($country, $state));

    $institution = OwnerContext::withOwner(null, fn (): Institution => Institution::factory()->create());

    detachPrayerCandidatesAddresses($institution);
    $institutionAddress = makePrayerCandidatesAddress($country, $state);
    attachPrayerCandidatesAddress($institution, $institutionAddress);

    expect(PrayerLocation::forTargets((string) $venue->getKey(), (string) $institution->getKey())?->getKey())
        ->toBe($institutionAddress->getKey());
});

it('uses the venue address when no institution target is set', function () {
    $country = ensurePrayerCandidatesCountry();
    $state = makePrayerCandidatesState($country);
    $venue = OwnerContext::withOwner(null, fn (): Venue => Venue::factory()->create());
    detachPrayerCandidatesAddresses($venue);
    $venueAddress = makePrayerCandidatesAddress($country, $state);
    attachPrayerCandidatesAddress($venue, $venueAddress);

    expect(PrayerLocation::forTargets((string) $venue->getKey(), null)?->getKey())
        ->toBe($venueAddress->getKey());
});

it('borrows the owning institution address when the venue has none', function () {
    $country = ensurePrayerCandidatesCountry();
    $state = makePrayerCandidatesState($country);
    $venue = OwnerContext::withOwner(null, fn (): Venue => Venue::factory()->create());
    detachPrayerCandidatesAddresses($venue);
    $owner = OwnerContext::withOwner(null, fn (): Institution => Institution::factory()->create());
    detachPrayerCandidatesAddresses($owner);
    $ownerAddress = makePrayerCandidatesAddress($country, $state);
    attachPrayerCandidatesAddress($owner, $ownerAddress);
    $venue->institutions()->attach($owner->getKey(), ['role' => InstitutionVenueRole::Operated, 'is_primary' => true]);

    expect(PrayerLocation::forTargets((string) $venue->getKey(), null)?->getKey())
        ->toBe($ownerAddress->getKey());
});

it('prefers the primary owner among venue institutions', function () {
    $country = ensurePrayerCandidatesCountry();
    $state = makePrayerCandidatesState($country);
    $venue = OwnerContext::withOwner(null, fn (): Venue => Venue::factory()->create());
    detachPrayerCandidatesAddresses($venue);

    $first = OwnerContext::withOwner(null, fn (): Institution => Institution::factory()->create());

    detachPrayerCandidatesAddresses($first);
    $firstAddress = makePrayerCandidatesAddress($country, $state);
    attachPrayerCandidatesAddress($first, $firstAddress);

    $second = OwnerContext::withOwner(null, fn (): Institution => Institution::factory()->create());

    detachPrayerCandidatesAddresses($second);
    $secondAddress = makePrayerCandidatesAddress($country, $state);
    attachPrayerCandidatesAddress($second, $secondAddress);

    $venue->institutions()->attach($first->getKey(), ['role' => InstitutionVenueRole::Operated, 'is_primary' => false]);
    $venue->institutions()->attach($second->getKey(), ['role' => InstitutionVenueRole::Operated, 'is_primary' => true]);

    expect(PrayerLocation::forTargets((string) $venue->getKey(), null)?->getKey())
        ->toBe($secondAddress->getKey());
});

it('falls back to the target institution and then to null', function () {
    $country = ensurePrayerCandidatesCountry();
    $state = makePrayerCandidatesState($country);
    $institution = OwnerContext::withOwner(null, fn (): Institution => Institution::factory()->create());
    detachPrayerCandidatesAddresses($institution);
    $address = makePrayerCandidatesAddress($country, $state);
    attachPrayerCandidatesAddress($institution, $address);

    expect(PrayerLocation::forTargets(null, (string) $institution->getKey())?->getKey())
        ->toBe($address->getKey())
        ->and(PrayerLocation::forTargets(null, null))->toBeNull();
});
