<?php

use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Models\AddressArea;
use AIArmada\Addressing\Models\AddressAreaAssignment;
use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Filament\Resources\Institutions\InstitutionResource;
use App\Models\Institution;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;

it('shows the full institution profile on the view page', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $institution = Institution::factory()->create([
        'status' => 'verified',
        'allow_public_event_submission' => true,
    ]);

    $institution->names()->create([
        'name_type' => 'nickname',
        'full_name' => 'Masjid Negara',
        'language_code' => 'ms',
        'is_primary' => true,
    ]);

    $address = Address::query()->create([
        'country_id' => (string) ensureTestMalaysiaCountry()->getKey(),
        'country_code' => 'MY',
        'line1' => 'Jalan Perdana',
        'city' => 'Kuala Lumpur',
        'state' => 'Wilayah Persekutuan',
        'postcode' => '50480',
    ]);
    $institution->attachAddress($address, type: 'primary', isPrimary: true);

    OwnerContext::withOwner(null, function () use ($administrator, $institution): void {
        $this->actingAs($administrator)
            ->get(InstitutionResource::getUrl('view', ['record' => $institution]))
            ->assertSuccessful()
            ->assertSee('Maklumat Asas')
            ->assertSee('Masjid Negara')
            ->assertSee('Jalan Perdana')
            ->assertSee('Wilayah Persekutuan')
            ->assertSee('Status & Kelulusan')
            ->assertSee('Jumlah Laporan');
    });
});

it('shows district and subdistrict on the lokasi tab', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $institution = Institution::factory()->create();

    $countryId = (string) ensureTestMalaysiaCountry()->getKey();
    $address = Address::query()->create([
        'country_id' => $countryId,
        'country_code' => 'MY',
        'city' => 'Bandar Penggaram',
        'state' => 'Johor',
    ]);
    $institution->attachAddress($address, type: 'primary', isPrimary: true);

    $district = AddressArea::query()->create([
        'country_id' => $countryId,
        'country_code' => 'MY',
        'type' => 'district',
        'level' => 2,
        'name' => 'Batu Pahat',
        'slug' => 'batu-pahat',
        'source' => 'test',
        'source_id' => 'test-batu-pahat',
    ]);
    $subdistrict = AddressArea::query()->create([
        'country_id' => $countryId,
        'country_code' => 'MY',
        'type' => 'subdistrict',
        'level' => 3,
        'parent_id' => $district->getKey(),
        'name' => 'Bandar Penggaram',
        'slug' => 'bandar-penggaram',
        'source' => 'test',
        'source_id' => 'test-bandar-penggaram',
    ]);

    AddressAreaAssignment::query()->create([
        'address_id' => $address->getKey(),
        'address_area_id' => $district->getKey(),
        'role' => 'administrative_district',
    ]);
    AddressAreaAssignment::query()->create([
        'address_id' => $address->getKey(),
        'address_area_id' => $subdistrict->getKey(),
        'role' => 'administrative_subdivision',
    ]);

    OwnerContext::withOwner(null, function () use ($administrator, $institution): void {
        $this->actingAs($administrator)
            ->get(InstitutionResource::getUrl('view', ['record' => $institution]))
            ->assertSuccessful()
            ->assertSee('Daerah')
            ->assertSee('Batu Pahat')
            ->assertSee('Mukim / Kawasan')
            ->assertSee('Bandar Penggaram');
    });
});

it('shows the postal locality as the mukim when the address only has a locality', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $institution = Institution::factory()->create();

    $countryId = (string) ensureTestMalaysiaCountry()->getKey();
    $address = Address::query()->create([
        'country_id' => $countryId,
        'country_code' => 'MY',
        'city' => 'Kuala Lumpur',
        'state' => 'WP Kuala Lumpur',
    ]);
    $institution->attachAddress($address, type: 'primary', isPrimary: true);

    $locality = AddressArea::query()->create([
        'country_id' => $countryId,
        'country_code' => 'MY',
        'type' => 'locality',
        'level' => 2,
        'name' => 'Titiwangsa',
        'slug' => 'titiwangsa',
        'source' => 'test',
        'source_id' => 'test-titiwangsa',
    ]);

    AddressAreaAssignment::query()->create([
        'address_id' => $address->getKey(),
        'address_area_id' => $locality->getKey(),
        'role' => 'postal_locality',
    ]);

    OwnerContext::withOwner(null, function () use ($administrator, $institution): void {
        $this->actingAs($administrator)
            ->get(InstitutionResource::getUrl('view', ['record' => $institution]))
            ->assertSuccessful()
            ->assertSee('Mukim / Kawasan')
            ->assertSee('Titiwangsa');
    });
});
