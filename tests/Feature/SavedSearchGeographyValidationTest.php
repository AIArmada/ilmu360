<?php

declare(strict_types=1);

use AIArmada\Addressing\Models\AddressArea;
use AIArmada\Addressing\Models\AddressCountry;
use AIArmada\Addressing\Models\City;
use AIArmada\Addressing\Models\State;
use App\Features\SavedSearches\Models\SavedSearch;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

dataset('saved search geography filters', [
    'country' => ['country_id', 'filters.country_id'],
    'state' => ['state_id', 'filters.state_id'],
    'city' => ['city_id', 'filters.city_id'],
    'area assignment' => ['area_assignments', 'filters.area_assignments.administrative_district'],
]);

/**
 * @return array{country: AddressCountry, state: State, city: City, area: AddressArea}
 */
function configureSavedSearchCanonicalGeographyTables(): array
{
    $country = AddressCountry::factory()->create();
    $state = State::factory()->create(['country_id' => $country->id]);
    $city = City::factory()->create([
        'country_id' => $country->id,
        'state_id' => $state->id,
    ]);
    $area = AddressArea::factory()->create(['country_id' => $country->id]);

    Schema::rename('countries', 'saved_search_countries');
    Schema::rename('states', 'saved_search_states');
    Schema::rename('cities', 'saved_search_cities');
    Schema::rename('address_areas', 'saved_search_address_areas');

    config()->set('addressing.database.tables.countries', 'saved_search_countries');
    config()->set('addressing.database.tables.states', 'saved_search_states');
    config()->set('addressing.database.tables.cities', 'saved_search_cities');
    config()->set('addressing.database.tables.areas', 'saved_search_address_areas');

    return ['country' => $country, 'state' => $state, 'city' => $city, 'area' => $area];
}

/**
 * @return array{country_id: string, state_id: string, city_id: string, area_assignments: array{administrative_district: string}}
 */
function savedSearchCanonicalGeographyFilters(AddressCountry $country, State $state, City $city, AddressArea $area): array
{
    return [
        'country_id' => (string) $country->id,
        'state_id' => (string) $state->id,
        'city_id' => (string) $city->id,
        'area_assignments' => ['administrative_district' => (string) $area->id],
    ];
}

it('accepts discovery geography ids from canonical configured tables', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    ['country' => $country, 'state' => $state, 'city' => $city, 'area' => $area] = configureSavedSearchCanonicalGeographyTables();
    $filters = savedSearchCanonicalGeographyFilters($country, $state, $city, $area);

    $response = $this->postJson('/api/v1/saved-searches', [
        'name' => 'Canonical Geography Search',
        'filters' => $filters,
        'notify' => 'daily',
    ])->assertCreated()
        ->assertJsonPath('data.filters.country_id', $filters['country_id'])
        ->assertJsonPath('data.filters.state_id', $filters['state_id'])
        ->assertJsonPath('data.filters.city_id', $filters['city_id'])
        ->assertJsonPath('data.filters.area_assignments.administrative_district', $filters['area_assignments']['administrative_district']);

    expect(SavedSearch::query()->findOrFail($response->json('data.id'))->filters)->toBe($filters);
});

it('accepts canonical configured geography ids on partial updates', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    ['country' => $country, 'state' => $state, 'city' => $city, 'area' => $area] = configureSavedSearchCanonicalGeographyTables();
    $filters = savedSearchCanonicalGeographyFilters($country, $state, $city, $area);
    $savedSearch = SavedSearch::factory()->create([
        'user_id' => $user->id,
        'name' => 'Preserved Canonical Search',
        'filters' => ['language_codes' => ['ms']],
        'notify' => 'weekly',
    ]);

    $this->patchJson("/api/v1/saved-searches/{$savedSearch->id}", [
        'filters' => $filters,
    ])->assertOk()
        ->assertJsonPath('data.filters.country_id', $filters['country_id'])
        ->assertJsonPath('data.filters.area_assignments.administrative_district', $filters['area_assignments']['administrative_district']);

    $fresh = $savedSearch->fresh();

    expect($fresh->name)->toBe('Preserved Canonical Search')
        ->and($fresh->filters)->toBe($filters)
        ->and($fresh->notify)->toBe('weekly');
});

it('rejects missing configured geography ids without creating a saved search', function (string $filter, string $errorKey): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    configureSavedSearchCanonicalGeographyTables();

    $value = $filter === 'area_assignments'
        ? ['administrative_district' => (string) Str::uuid()]
        : (string) Str::uuid();

    $this->postJson('/api/v1/saved-searches', [
        'name' => 'Missing Configured Geography',
        'filters' => [$filter => $value],
        'notify' => 'daily',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors([$errorKey]);

    expect(SavedSearch::query()->where('user_id', $user->id)->count())->toBe(0);
})->with('saved search geography filters');

it('rejects missing configured geography ids on partial updates without mutation', function (string $filter, string $errorKey): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    configureSavedSearchCanonicalGeographyTables();
    $savedSearch = SavedSearch::factory()->create([
        'user_id' => $user->id,
        'name' => 'Guarded Canonical Search',
        'filters' => ['language_codes' => ['ms']],
    ]);

    $value = $filter === 'area_assignments'
        ? ['administrative_district' => (string) Str::uuid()]
        : (string) Str::uuid();

    $this->patchJson("/api/v1/saved-searches/{$savedSearch->id}", [
        'name' => 'Must Not Persist',
        'filters' => [$filter => $value],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors([$errorKey]);

    $fresh = $savedSearch->fresh();

    expect($fresh->name)->toBe('Guarded Canonical Search')
        ->and($fresh->filters)->toBe(['language_codes' => ['ms']]);
})->with('saved search geography filters');

it('rejects a country id that exists only in the obsolete default table', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    Schema::rename('countries', 'saved_search_countries');
    config()->set('addressing.database.tables.countries', 'saved_search_countries');
    Schema::create('countries', function (Blueprint $table): void {
        $table->uuid('id')->primary();
    });
    $obsoleteId = (string) Str::uuid();
    DB::table('countries')->insert(['id' => $obsoleteId]);

    $this->postJson('/api/v1/saved-searches', [
        'name' => 'Obsolete Geography Search',
        'filters' => ['country_id' => $obsoleteId],
        'notify' => 'daily',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['filters.country_id']);

    expect(SavedSearch::query()->where('user_id', $user->id)->count())->toBe(0);
});
