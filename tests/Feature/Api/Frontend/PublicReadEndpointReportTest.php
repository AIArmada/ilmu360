<?php

use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('resolves public directory detail endpoints by uuid', function (string $resource): void {
    [$routeName, $routeParameter, $payloadKey, $record] = match ($resource) {
        'institution' => [
            'api.client.institutions.show',
            'institutionKey',
            'institution',
            Institution::factory()->create(['status' => 'verified']),
        ],
        'person' => [
            'api.client.persons.show',
            'personKey',
            'person',
            Person::factory()->create(['status' => 'verified']),
        ],
        'reference' => [
            'api.client.references.show',
            'referenceKey',
            'reference',
            Reference::factory()->create(['status' => 'verified']),
        ],
    };

    $this->getJson(route($routeName, [$routeParameter => $record->getKey()]))
        ->assertOk()
        ->assertJsonPath("data.{$payloadKey}.id", (string) $record->getKey());
})->with([
    'institution',
    'person',
    'reference',
]);

it('accepts q as an alias for the unified public search query', function (): void {
    $institution = Institution::factory()->create([
        'name' => 'Masjid Query Alias Search',
        'status' => 'verified',
    ]);

    $response = $this->getJson('/api/v1/search?q='.urlencode('Query Alias Search'))
        ->assertOk()
        ->assertJsonPath('meta.search', 'Query Alias Search');

    expect(collect($response->json('data.institutions.items'))->pluck('id')->all())
        ->toContain((string) $institution->id);
});

it('lists followed directory resources through the public listing following filter', function (): void {
    $user = User::factory()->create();
    $followedInstitution = Institution::factory()->create(['status' => 'verified']);
    $otherInstitution = Institution::factory()->create(['status' => 'verified']);
    $followedPerson = Person::factory()->create(['status' => 'verified']);
    $otherPerson = Person::factory()->create(['status' => 'verified']);
    $followedReference = Reference::factory()->create(['status' => 'verified']);
    $otherReference = Reference::factory()->create(['status' => 'verified']);

    $user->follow($followedInstitution);
    $user->follow($followedPerson);
    $user->follow($followedReference);

    Sanctum::actingAs($user);

    $institutionIds = collect($this->getJson('/api/v1/institutions?following=true')
        ->assertOk()
        ->json('data'))->pluck('id');
    $personIds = collect($this->getJson('/api/v1/persons?following=true')
        ->assertOk()
        ->json('data'))->pluck('id');
    $referenceIds = collect($this->getJson('/api/v1/references?following=true')
        ->assertOk()
        ->json('data'))->pluck('id');

    expect($institutionIds->all())->toContain((string) $followedInstitution->id)
        ->not->toContain((string) $otherInstitution->id)
        ->and($personIds->all())->toContain((string) $followedPerson->id)
        ->not->toContain((string) $otherPerson->id)
        ->and($referenceIds->all())->toContain((string) $followedReference->id)
        ->not->toContain((string) $otherReference->id);
});

it('requires coordinates for the nearby institution alias', function (): void {
    $this->getJson('/api/v1/institutions/near')
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_error')
        ->assertJsonPath('error.details.fields.near.0', 'Provide `near=lat,lng` or both `lat` and `lng` to use the nearby institution endpoint.');
});
