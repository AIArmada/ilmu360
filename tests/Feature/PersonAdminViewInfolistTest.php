<?php

use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Models\AddressArea;
use AIArmada\Addressing\Models\AddressAreaAssignment;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Persons\Enums\PersonNameType;
use App\Filament\Resources\Persons\Pages\ViewPerson;
use App\Filament\Resources\Persons\PersonResource;
use App\Models\Person;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Livewire\Livewire;

function createAdminPersonViewer(): User
{
    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    return $administrator;
}

it('shows the full person profile on the view page', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $administrator = createAdminPersonViewer();
    $person = Person::factory()->create([
        'speaker_status' => 'active',
        'allow_public_event_submission' => true,
    ]);

    $person->names()->create([
        'name_type' => PersonNameType::Display->value,
        'full_name' => 'Azhar Idrus',
        'language_code' => 'ms',
        'is_primary' => true,
    ]);

    $address = Address::query()->create([
        'country_id' => (string) ensureTestMalaysiaCountry()->getKey(),
        'country_code' => 'MY',
        'line1' => 'Jalan Gasing',
        'city' => 'Petaling Jaya',
        'state' => 'Selangor',
        'postcode' => '46000',
    ]);
    $person->attachAddress($address, type: 'primary', isPrimary: true);

    $person->socialProfiles()->create([
        'platform' => 'youtube',
        'handle' => 'ustazazharidrusofficial',
        'url' => 'https://www.youtube.com/@ustazazharidrusofficial',
    ]);

    OwnerContext::withOwner(null, function () use ($administrator, $person): void {
        $this->actingAs($administrator)
            ->get(PersonResource::getUrl('view', ['record' => $person]))
            ->assertSuccessful()
            ->assertSee('Maklumat Asas')
            ->assertSee('Azhar Idrus')
            ->assertSee('Jalan Gasing')
            ->assertSee('Selangor')
            ->assertSee('Active')
            ->assertSee('Status Penceramah');
    });
});

it('renders the person view page without errors on livewire level', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $administrator = createAdminPersonViewer();
    $person = Person::factory()->create();

    OwnerContext::withOwner(null, function () use ($administrator, $person): void {
        Livewire::actingAs($administrator)
            ->test(ViewPerson::class, ['record' => $person->id])
            ->assertSuccessful()
            ->assertSee('Maklumat Asas');
    });
});

it('shows district and subdistrict on the lokasi tab', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $administrator = createAdminPersonViewer();
    $person = Person::factory()->create();

    $countryId = (string) ensureTestMalaysiaCountry()->getKey();
    $address = Address::query()->create([
        'country_id' => $countryId,
        'country_code' => 'MY',
        'city' => 'Bandar Penggaram',
        'state' => 'Johor',
    ]);
    $person->attachAddress($address, type: 'primary', isPrimary: true);

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

    OwnerContext::withOwner(null, function () use ($administrator, $person): void {
        $this->actingAs($administrator)
            ->get(PersonResource::getUrl('view', ['record' => $person]))
            ->assertSuccessful()
            ->assertSee('Daerah')
            ->assertSee('Batu Pahat')
            ->assertSee('Mukim / Kawasan')
            ->assertSee('Bandar Penggaram');
    });
});
