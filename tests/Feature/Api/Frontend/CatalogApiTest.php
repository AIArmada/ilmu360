<?php

use AIArmada\Addressing\Models\City;
use AIArmada\Addressing\Models\State;
use AIArmada\Membership\Enums\MemberRole;
use App\Enums\EventTaxonomyCode;
use App\Enums\MemberSubjectType;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\User;
use App\Models\Venue;
use Laravel\Sanctum\Sanctum;

it('requires an explicit country for public states catalog options', function () {
    $malaysia = ensureTestMalaysiaCountry();
    $indonesia = ensureTestAddressCountry('ID', 'Indonesia', 'IDN', ['Asia/Jakarta'], '62');

    $malaysiaState = State::query()->create([
        'country_id' => $malaysia->getKey(),
        'name' => 'Catalog API Selangor State',
        'code' => 'SGR',
    ]);
    $indonesiaState = State::query()->create([
        'country_id' => $indonesia->getKey(),
        'name' => 'Catalog API Jawa Barat State',
        'code' => 'JB',
    ]);

    $omittedResponse = $this->getJson(route('api.client.catalogs.states'))
        ->assertOk();

    $explicitResponse = $this->getJson(route('api.client.catalogs.states', ['country_id' => $indonesia->getKey()]))
        ->assertOk();

    expect($omittedResponse->json('data'))->toBe([])
        ->and(collect($explicitResponse->json('data'))->pluck('label')->all())
        ->toContain('Catalog API Jawa Barat State')
        ->not->toContain('Catalog API Selangor State')
        ->and(collect($explicitResponse->json('data'))->pluck('id')->all())
        ->toContain((string) $indonesiaState->getKey())
        ->not->toContain((string) $malaysiaState->getKey());
});

it('lists package cities for a state_id', function () {
    $indonesia = ensureTestAddressCountry('ID', 'Indonesia', 'IDN', ['Asia/Jakarta'], '62');
    $state = State::query()->create([
        'country_id' => $indonesia->getKey(),
        'name' => 'Catalog API City State',
        'code' => 'CS',
    ]);
    $city = City::query()->create([
        'state_id' => $state->getKey(),
        'country_id' => $indonesia->getKey(),
        'name' => 'Catalog API Bandung City',
    ]);

    $omitted = $this->getJson(route('api.client.catalogs.cities'))->assertOk();
    $explicit = $this->getJson(route('api.client.catalogs.cities', ['state_id' => $state->getKey()]))->assertOk();

    expect($omitted->json('data'))->toBe([])
        ->and(collect($explicit->json('data'))->pluck('id')->all())
        ->toContain((string) $city->getKey());
});

it('lists only published verified or pending references in the public catalog', function () {
    $visiblePending = Reference::factory()->pending()->create([
        'title' => 'Published Pending Catalog Reference',
    ]);
    $hiddenPending = Reference::factory()->pending()->unpublished()->create([
        'title' => 'Unpublished Catalog Reference',
    ]);

    $response = $this->getJson(route('api.client.catalogs.references', ['q' => 'Catalog Reference']))
        ->assertOk();

    $ids = collect($response->json('data'))->pluck('id')->all();

    expect($ids)
        ->toContain((string) $visiblePending->getKey())
        ->not->toContain((string) $hiddenPending->getKey());
});

it('requires an explicit administrative district or state for public administrative-subdivision catalog options', function () {
    $country = ensureTestMalaysiaCountry();
    $geo = createTestPackageGeography('Selangor', 'Petaling', 'Shah Alam', country: $country);

    $omittedResponse = $this->getJson(route('api.client.catalogs.administrative-subdivisions'))
        ->assertOk();

    $explicitResponse = $this->getJson(route('api.client.catalogs.administrative-subdivisions', ['administrative_district' => $geo['district']->getKey()]))
        ->assertOk();

    expect($omittedResponse->json('data'))->toBe([])
        ->and(collect($explicitResponse->json('data'))->pluck('label')->all())
        ->toContain('Shah Alam');
});

it('returns public venue catalog options for active visible venues', function () {
    Venue::factory()->create([
        'name' => 'Catalog API Visible Venue',
        'status' => 'verified',
    ]);

    Venue::factory()->create([
        'name' => 'Catalog API Pending Venue',
        'status' => 'pending',
    ]);

    Venue::factory()->create([
        'name' => 'Catalog API Rejected Venue',
        'status' => 'rejected',
    ]);

    Venue::factory()->create([
        'name' => 'Catalog API Inactive Venue',
        'status' => 'inactive',
    ]);

    $response = $this->getJson(route('api.client.catalogs.venues'))
        ->assertOk();

    expect(collect($response->json('data'))->pluck('label')->all())
        ->toContain('Catalog API Visible Venue', 'Catalog API Pending Venue')
        ->not->toContain('Catalog API Rejected Venue', 'Catalog API Inactive Venue');
});

it('lists membership claim subjects from the public catalog endpoint', function () {
    $institution = Institution::factory()->create([
        'name' => 'Catalog API Membership Institution',
        'status' => 'verified',
    ]);

    $response = $this->getJson(route('api.client.catalogs.membership-application-subjects', [
        'subjectType' => MemberSubjectType::Institution->publicRouteSegment(),
        'q' => 'Membership Institution',
    ]));

    $response->assertSuccessful()
        ->assertJsonPath('data.0.id', $institution->id)
        ->assertJsonPath('data.0.slug', $institution->slug)
        ->assertJsonPath('data.0.label', $institution->display_name);
});

it('lists taxonomy terms through the canonical catalog endpoint', function () {
    $term = submitEventTerm(EventTaxonomyCode::Domain->value);

    $response = $this->getJson(route('api.client.catalogs.taxonomy-terms', ['type' => EventTaxonomyCode::Domain->value]))
        ->assertOk();

    expect(collect($response->json('data'))->pluck('id')->all())->toContain((string) $term->getKey());
});

it('returns 404 for the removed tags catalog alias', function () {
    submitEventTerm(EventTaxonomyCode::Domain->value);

    $this->getJson('/api/v1/catalogs/tags/'.EventTaxonomyCode::Domain->value)->assertNotFound();
});

it('lists administrative districts for an explicit state', function () {
    $country = ensureTestMalaysiaCountry();
    $geo = createTestPackageGeography('Selangor', 'Petaling', 'Shah Alam', country: $country);

    $response = $this->getJson(route('api.client.catalogs.administrative-districts', ['state_id' => $geo['state']->getKey()]))
        ->assertOk();

    expect(collect($response->json('data'))->pluck('id')->all())
        ->toContain((string) $geo['district']->getKey());
});

it('lists institution role options for authenticated clients', function () {
    Sanctum::actingAs(User::factory()->create());

    $response = $this->getJson(route('api.client.catalogs.institution-roles'))
        ->assertOk();

    expect($response->json('data'))->toHaveKey(MemberRole::Owner->value);
});

it('requires authentication for institution role options', function () {
    $this->getJson(route('api.client.catalogs.institution-roles'))
        ->assertUnauthorized();
});

it('lists only verified institutions in the prayer institutions catalog', function () {
    $verified = Institution::factory()->create([
        'name' => 'Prayer Catalog Masjid',
        'status' => 'verified',
    ]);
    $pending = Institution::factory()->create([
        'name' => 'Prayer Catalog Pending Surau',
        'status' => 'pending',
    ]);

    $response = $this->getJson(route('api.client.catalogs.prayer-institutions', ['q' => 'Prayer Catalog']))
        ->assertOk();

    $ids = collect($response->json('data'))->pluck('id')->all();

    expect($ids)
        ->toContain((string) $verified->getKey())
        ->not->toContain((string) $pending->getKey());
});

it('lists submittable institutions in the submit institutions catalog', function () {
    $open = Institution::factory()->create([
        'name' => 'Submit Catalog Open Madrasah',
        'status' => 'verified',
        'allow_public_event_submission' => true,
    ]);
    $closed = Institution::factory()->create([
        'name' => 'Submit Catalog Closed Madrasah',
        'status' => 'verified',
        'allow_public_event_submission' => false,
    ]);

    $response = $this->getJson(route('api.client.catalogs.submit-institutions', ['q' => 'Submit Catalog']))
        ->assertOk();

    $ids = collect($response->json('data'))->pluck('id')->all();

    expect($ids)
        ->toContain((string) $open->getKey())
        ->not->toContain((string) $closed->getKey());
});
