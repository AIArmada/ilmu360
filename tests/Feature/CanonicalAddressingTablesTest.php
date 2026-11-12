<?php

declare(strict_types=1);

use AIArmada\Authz\Models\Role;
use App\Shared\Contributions\Enums\ContributionSubjectType;
use App\Shared\Events\Enums\EventAgeGroup;
use App\Shared\Events\Enums\EventFormat;
use App\Shared\Events\Enums\EventGenderRestriction;
use App\Shared\Events\Enums\EventVisibility;
use App\Models\Institution;
use App\Models\Person;
use App\Models\User;
use App\Shared\Prayer\EventPrayerTime;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/**
 * Point every addressing consumer at renamed package tables. Any code
 * still reading the obsolete `addressing.tables.*` keys or hardcoded
 * table names fails loudly instead of silently reading the defaults.
 */
function configureCanonicalAddressingTablesForRegression(): void
{
    $tables = [
        'countries' => ['countries', 'canonical_regression_countries'],
        'states' => ['states', 'canonical_regression_states'],
        'cities' => ['cities', 'canonical_regression_cities'],
        'areas' => ['address_areas', 'canonical_regression_address_areas'],
        'addresses' => ['addresses', 'canonical_regression_addresses'],
        'addressables' => ['addressables', 'canonical_regression_addressables'],
        'address_area_assignments' => ['address_area_assignments', 'canonical_regression_address_area_assignments'],
    ];

    foreach ($tables as $key => [$source, $target]) {
        Schema::rename($source, $target);
        config()->set("addressing.database.tables.{$key}", $target);
    }
}

function canonicalAddressingRegressionAdminUser(): User
{
    setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    if (! Role::query()->where('name', 'super_admin')->where('guard_name', 'web')->exists()) {
        $role = new Role;
        $role->forceFill([
            'id' => (string) Str::uuid(),
            'name' => 'super_admin',
            'guard_name' => 'web',
        ])->save();
    }

    $user = User::factory()->create();
    $user->assignRole('super_admin');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

it('validates admin address references against the configured addressing tables', function (): void {
    $geo = createTestPackageGeography(cityName: 'Petaling Jaya');
    configureCanonicalAddressingTablesForRegression();

    Sanctum::actingAs(canonicalAddressingRegressionAdminUser());

    $this->postJson('/api/v1/admin/institutions', [
        'name' => 'Institusi Jadual Kanonik',
        'type' => 'masjid',
        'status' => 'verified',
        'address' => [
            'country_id' => (string) $geo['country']->getKey(),
            'state_id' => (string) $geo['state']->getKey(),
            'area_assignments' => [
                'administrative_district' => (string) $geo['district']->getKey(),
            ],
        ],
    ])->assertCreated();

    $this->postJson('/api/v1/admin/people', [
        'name' => 'Penceramah Jadual Kanonik Admin',
        'gender' => 'male',
        'status' => 'verified',
        'address' => [
            'country_id' => (string) $geo['country']->getKey(),
            'area_assignments' => [
                'administrative_district' => (string) $geo['district']->getKey(),
            ],
        ],
    ])->assertCreated();

    $this->postJson('/api/v1/admin/venues', [
        'name' => 'Dewan Jadual Kanonik',
        'type' => 'dewan',
        'status' => 'verified',
        'address' => [
            'country_id' => (string) $geo['country']->getKey(),
            'area_assignments' => [
                'administrative_district' => (string) $geo['district']->getKey(),
            ],
        ],
    ])->assertCreated();
});

it('validates admin event submission country references against the configured addressing tables', function (): void {
    $geo = createTestPackageGeography();
    configureCanonicalAddressingTablesForRegression();

    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);

    Sanctum::actingAs(canonicalAddressingRegressionAdminUser());

    $this->postJson('/api/v1/admin/events', [
        'title' => 'Majlis Jadual Kanonik Admin',
        'event_date' => '2026-05-09',
        'prayer_time' => EventPrayerTime::SelepasMaghrib->value,
        'timezone' => 'Asia/Kuala_Lumpur',
        'event_format' => EventFormat::Physical->value,
        'visibility' => EventVisibility::Public->value,
        'gender' => EventGenderRestriction::All->value,
        'age_group' => [EventAgeGroup::AllAges->value],
        'event_category_ids' => [eventCategoryId('aktiviti_keagamaan')],
        'primary_organizer_id' => (string) $institution->getKey(),
        'institution_id' => (string) $institution->getKey(),
        'submission_country_id' => (string) $geo['country']->getKey(),
        'status' => 'draft',
    ])->assertCreated();
});

it('validates contribution address references against the configured addressing tables', function (): void {
    $geo = createTestPackageGeography(cityName: 'Petaling Jaya');
    configureCanonicalAddressingTablesForRegression();

    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->postJson(route('api.client.contributions.persons.store'), [
        'name' => 'Penceramah Jadual Kanonik Sumbangan',
        'gender' => 'male',
        'address' => [
            'country_id' => (string) $geo['country']->getKey(),
            'area_assignments' => [
                'administrative_district' => (string) $geo['district']->getKey(),
            ],
        ],
    ])->assertCreated();

    $institution = Institution::factory()->create(['status' => 'verified']);

    $this->postJson(route('api.client.contributions.suggest.store', [
        'subjectType' => ContributionSubjectType::Institution->publicRouteSegment(),
        'subject' => $institution->slug,
    ]), [
        'address' => [
            'country_id' => (string) $geo['country']->getKey(),
            'state_id' => (string) $geo['state']->getKey(),
            'city_id' => (string) $geo['city']->getKey(),
            'area_assignments' => [
                'administrative_district' => (string) $geo['district']->getKey(),
            ],
        ],
    ])->assertCreated()
        ->assertJsonPath('data.mode', 'review');
});

it('resolves nearby institution joins against the configured addressing tables', function (): void {
    $geo = createTestPackageGeography(cityName: 'Petaling Jaya');
    configureCanonicalAddressingTablesForRegression();

    $institution = Institution::factory()->create([
        'name' => 'Masjid Jadual Kanonik Berdekatan',
        'status' => 'verified',
    ]);
    syncPrimaryAddressForTest($institution, [
        'country_id' => (string) $geo['country']->getKey(),
        'lat' => 3.1390,
        'lng' => 101.6869,
    ]);

    $names = collect($this->getJson('/api/v1/institutions?lat=3.1390&lng=101.6869&radius_km=12&per_page=10')
        ->assertOk()
        ->json('data'))->pluck('name')->all();

    expect($names)->toContain('Masjid Jadual Kanonik Berdekatan');
});

it('renders hierarchy area names from the configured addressing tables', function (): void {
    $geo = createTestPackageGeography(subdistrictName: 'Damansara', cityName: 'Petaling Jaya');
    configureCanonicalAddressingTablesForRegression();

    $person = Person::factory()->create(['status' => 'verified']);
    syncPrimaryAddressForTest($person, [
        'country_id' => (string) $geo['country']->getKey(),
        'state_id' => (string) $geo['state']->getKey(),
        'area_assignments' => [
            'administrative_district' => (string) $geo['district']->getKey(),
            'administrative_subdivision' => (string) $geo['subdistrict']->getKey(),
        ],
    ]);

    Livewire::test('pages.persons.show', ['person' => $person])
        ->assertSee($person->name)
        ->assertSee('Damansara');
});
