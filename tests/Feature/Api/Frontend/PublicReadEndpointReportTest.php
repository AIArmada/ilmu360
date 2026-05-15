<?php

use App\Models\Institution;
use App\Models\Reference;
use App\Models\Speaker;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('resolves public directory detail endpoints by uuid', function (string $resource): void {
    [$routeName, $routeParameter, $payloadKey, $record] = match ($resource) {
        'institution' => [
            'api.client.institutions.show',
            'institutionKey',
            'institution',
            Institution::factory()->create(['status' => 'verified', 'is_active' => true]),
        ],
        'speaker' => [
            'api.client.speakers.show',
            'speakerKey',
            'speaker',
            Speaker::factory()->create(['status' => 'verified', 'is_active' => true]),
        ],
        'reference' => [
            'api.client.references.show',
            'referenceKey',
            'reference',
            Reference::factory()->create(['status' => 'verified', 'is_active' => true]),
        ],
    };

    $this->getJson(route($routeName, [$routeParameter => $record->getKey()]))
        ->assertOk()
        ->assertJsonPath("data.{$payloadKey}.id", (string) $record->getKey());
})->with([
    'institution',
    'speaker',
    'reference',
]);

it('accepts q as an alias for the unified public search query', function (): void {
    $institution = Institution::factory()->create([
        'name' => 'Masjid Query Alias Search',
        'status' => 'verified',
        'is_active' => true,
    ]);

    $response = $this->getJson('/api/v1/search?q='.urlencode('Query Alias Search'))
        ->assertOk()
        ->assertJsonPath('meta.search', 'Query Alias Search');

    expect(collect($response->json('data.institutions.items'))->pluck('id')->all())
        ->toContain((string) $institution->id);
});

it('lists followed directory resources through the public listing following filter', function (): void {
    $user = User::factory()->create();
    $followedInstitution = Institution::factory()->create(['status' => 'verified', 'is_active' => true]);
    $otherInstitution = Institution::factory()->create(['status' => 'verified', 'is_active' => true]);
    $followedSpeaker = Speaker::factory()->create(['status' => 'verified', 'is_active' => true]);
    $otherSpeaker = Speaker::factory()->create(['status' => 'verified', 'is_active' => true]);
    $followedReference = Reference::factory()->create(['status' => 'verified', 'is_active' => true]);
    $otherReference = Reference::factory()->create(['status' => 'verified', 'is_active' => true]);

    $user->follow($followedInstitution);
    $user->follow($followedSpeaker);
    $user->follow($followedReference);

    Sanctum::actingAs($user);

    $institutionIds = collect($this->getJson('/api/v1/institutions?following=true')
        ->assertOk()
        ->json('data'))->pluck('id');
    $speakerIds = collect($this->getJson('/api/v1/speakers?following=true')
        ->assertOk()
        ->json('data'))->pluck('id');
    $referenceIds = collect($this->getJson('/api/v1/references?following=true')
        ->assertOk()
        ->json('data'))->pluck('id');

    expect($institutionIds->all())->toContain((string) $followedInstitution->id)
        ->not->toContain((string) $otherInstitution->id)
        ->and($speakerIds->all())->toContain((string) $followedSpeaker->id)
        ->not->toContain((string) $otherSpeaker->id)
        ->and($referenceIds->all())->toContain((string) $followedReference->id)
        ->not->toContain((string) $otherReference->id);
});

it('requires coordinates for the nearby institution alias', function (): void {
    $this->getJson('/api/v1/institutions/near')
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_error')
        ->assertJsonPath('error.details.fields.near.0', 'Provide `near=lat,lng` or both `lat` and `lng` to use the nearby institution endpoint.');
});
