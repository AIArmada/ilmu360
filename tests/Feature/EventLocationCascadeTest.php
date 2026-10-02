<?php

use AIArmada\Addressing\Models\AddressAreaStateLink;
use AIArmada\Addressing\Models\State;
use App\Livewire\Pages\Events\Index;
use App\Models\Event;
use App\Models\Venue;
use Livewire\Livewire;

function createCascadeTestEvent(Venue $venue, string $title): Event
{
    // ends_at must be pinned alongside starts_at: the factory's random
    // ends_at can otherwise land before the overridden start and fail
    // occurrence sync intermittently (~3% per event).
    $startsAt = now()->addDays(2);

    return Event::factory()->for($venue)->create([
        'title' => $title,
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now(),
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->copy()->addHours(2),
    ]);
}

function cascadeTestFieldNames($component): array
{
    return collect($component->instance()->getForm('form')->getFlatFields(withHidden: true))
        ->map(fn (mixed $field): string => $field->getName())
        ->all();
}

it('builds Indonesian slot fields and filters events down the cascade', function (): void {
    app()->setLocale('ms');

    $indonesia = ensureTestAddressCountry('ID', 'Indonesia', 'IDN', ['Asia/Jakarta'], '62');
    $indonesiaId = (string) $indonesia->getKey();

    $province = State::query()->firstOrCreate(
        ['country_id' => $indonesiaId, 'name' => 'Jawa Barat'],
        ['code' => null],
    );
    $provinceArea = createTestAddressArea('Jawa Barat', 1, country: $indonesia, type: 'province');
    AddressAreaStateLink::query()->firstOrCreate(
        ['address_area_id' => $provinceArea->getKey(), 'state_id' => $province->getKey()],
        ['hierarchy_type' => 'administrative'],
    );
    $bandung = createTestAddressArea('Bandung', 2, parent: $provinceArea, country: $indonesia, type: 'regency');
    $cimahi = createTestAddressArea('Cimahi', 3, parent: $bandung, country: $indonesia, type: 'district');
    $cibinong = createTestAddressArea('Cibinong', 3, parent: $bandung, country: $indonesia, type: 'district');

    $matchVenue = Venue::factory()->create();
    syncPrimaryAddressForTest($matchVenue, [
        'country_id' => $indonesiaId,
        'country_code' => 'ID',
        'state_id' => (string) $province->getKey(),
        'area_assignments' => [
            'regency' => (string) $bandung->getKey(),
            'district' => (string) $cimahi->getKey(),
        ],
    ]);

    $otherVenue = Venue::factory()->create();
    syncPrimaryAddressForTest($otherVenue, [
        'country_id' => $indonesiaId,
        'country_code' => 'ID',
        'state_id' => (string) $province->getKey(),
        'area_assignments' => [
            'regency' => (string) $bandung->getKey(),
            'district' => (string) $cibinong->getKey(),
        ],
    ]);

    createCascadeTestEvent($matchVenue, 'Cascade Match Bandung');
    createCascadeTestEvent($otherVenue, 'Cascade Other Cibinong');

    $component = Livewire::withQueryParams([
        'country_id' => $indonesiaId,
        'state_id' => (string) $province->getKey(),
        'area_assignments' => [
            'regency' => (string) $bandung->getKey(),
            'district' => (string) $cimahi->getKey(),
        ],
    ])->test(Index::class);

    $fields = collect($component->instance()->getForm('form')->getFlatFields(withHidden: true))
        ->keyBy(fn (mixed $field): string => $field->getName());

    expect($fields)->toHaveKey('area_assignments.regency')
        ->and($fields)->toHaveKey('area_assignments.district')
        ->and($fields['area_assignments.regency']->getLabel())->toBe('Kabupaten')
        ->and($fields['area_assignments.district']->getLabel())->toBe('Kecamatan');

    $titles = $component->instance()->events->getCollection()->pluck('title')->all();

    expect($titles)->toContain('Cascade Match Bandung')->not->toContain('Cascade Other Cibinong');
});

it('builds the stateless Singapore cascade and filters events by planning area', function (): void {
    app()->setLocale('ms');

    $singapore = ensureTestAddressCountry('SG', 'Singapore', 'SGP', ['Asia/Singapore'], '65');
    $singaporeId = (string) $singapore->getKey();

    State::query()->firstOrCreate(
        ['country_id' => $singaporeId, 'name' => 'North West'],
        ['code' => '03'],
    );

    $central = createTestAddressArea('Central', 1, country: $singapore, type: 'region');
    $bishan = createTestAddressArea('Bishan', 2, parent: $central, country: $singapore, type: 'planning_area');
    $geylang = createTestAddressArea('Geylang', 2, parent: $central, country: $singapore, type: 'planning_area');

    $matchVenue = Venue::factory()->create();
    syncPrimaryAddressForTest($matchVenue, [
        'country_id' => $singaporeId,
        'country_code' => 'SG',
        'area_assignments' => [
            'region' => (string) $central->getKey(),
            'planning_area' => (string) $bishan->getKey(),
        ],
    ]);

    $otherVenue = Venue::factory()->create();
    syncPrimaryAddressForTest($otherVenue, [
        'country_id' => $singaporeId,
        'country_code' => 'SG',
        'area_assignments' => [
            'region' => (string) $central->getKey(),
            'planning_area' => (string) $geylang->getKey(),
        ],
    ]);

    createCascadeTestEvent($matchVenue, 'Cascade Match Bishan');
    createCascadeTestEvent($otherVenue, 'Cascade Other Geylang');

    $component = Livewire::withQueryParams([
        'country_id' => $singaporeId,
        'area_assignments' => [
            'region' => (string) $central->getKey(),
            'planning_area' => (string) $bishan->getKey(),
        ],
    ])->test(Index::class);

    $fields = collect($component->instance()->getForm('form')->getFlatFields(withHidden: true))
        ->keyBy(fn (mixed $field): string => $field->getName());

    expect($fields)->toHaveKey('area_assignments.region')
        ->and($fields)->toHaveKey('area_assignments.planning_area')
        ->and($fields['area_assignments.region']->getLabel())->toBe('Wilayah Perancangan')
        ->and($component->instance()->states())->toBeEmpty();

    $titles = $component->instance()->events->getCollection()->pluck('title')->all();

    expect($titles)->toContain('Cascade Match Bishan')->not->toContain('Cascade Other Geylang');
});

it('keeps the Malaysian event cascade on district and subdivision fields', function (): void {
    app()->setLocale('ms');

    $geo = createTestPackageGeography('Selangor', 'Petaling', 'Subang');

    $matchVenue = Venue::factory()->create();
    syncPrimaryAddressForTest($matchVenue, [
        ...$geo['address'],
        'country_code' => 'MY',
    ]);

    $otherDistrict = createTestAddressArea('Klang', 2, parent: $geo['area_tree_root'], country: $geo['country']);
    $otherVenue = Venue::factory()->create();
    syncPrimaryAddressForTest($otherVenue, [
        ...$geo['address'],
        'country_code' => 'MY',
        'area_assignments' => [
            'administrative_district' => (string) $otherDistrict->getKey(),
        ],
    ]);

    createCascadeTestEvent($matchVenue, 'Cascade Match Subang');
    createCascadeTestEvent($otherVenue, 'Cascade Other Klang');

    $component = Livewire::withQueryParams([
        'country_id' => (string) $geo['country']->getKey(),
        'state_id' => (string) $geo['state']->getKey(),
        'area_assignments' => [
            'administrative_district' => (string) $geo['district']->getKey(),
            'administrative_subdivision' => (string) $geo['subdistrict']->getKey(),
        ],
    ])->test(Index::class);

    expect(cascadeTestFieldNames($component))
        ->toContain('area_assignments.administrative_district')
        ->toContain('area_assignments.administrative_subdivision');

    $titles = $component->instance()->events->getCollection()->pluck('title')->all();

    expect($titles)->toContain('Cascade Match Subang')->not->toContain('Cascade Other Klang');
});
