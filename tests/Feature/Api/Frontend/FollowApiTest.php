<?php

use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('serializes follow state payloads for each followable type', function (string $type, string $subject, array $attributes): void {
    $user = User::factory()->create();

    $record = match ($type) {
        'institution' => Institution::factory()->create($attributes),
        'person' => Person::factory()->create($attributes),
        'reference' => Reference::factory()->create($attributes),
        'series' => Series::factory()->create($attributes),
    };

    $user->follow($record);

    Sanctum::actingAs($user);

    $response = $this->getJson(route('api.client.follows.show', ['type' => $type, 'subject' => $subject === 'slug' ? $record->slug : $record->getKey()]));

    $response->assertOk()
        ->assertJsonPath('data.type', $type)
        ->assertJsonPath('data.id', $record->getKey())
        ->assertJsonPath('data.slug', $record->slug)
        ->assertJsonPath('data.is_following', true)
        ->assertJsonPath('meta.request_id', fn (string $requestId) => filled($requestId));
})->with([
    'institution by slug' => ['institution', 'slug', ['status' => 'verified']],
    'person by slug' => ['person', 'slug', ['status' => 'verified']],
    'reference by slug' => ['reference', 'slug', ['status' => 'verified']],
    'reference by uuid' => ['reference', 'id', ['status' => 'verified']],
    'series by slug' => ['series', 'slug', ['visibility' => 'public', 'status' => 'active']],
]);

it('returns the same follow payload shape across store and destroy', function () {
    $user = User::factory()->create();
    $person = Person::factory()->create([
        'status' => 'verified',
    ]);

    Sanctum::actingAs($user);

    $storeResponse = $this->postJson(route('api.client.follows.store', ['type' => 'person', 'subject' => $person->slug]));

    $storeResponse->assertCreated()
        ->assertJsonPath('data.type', 'person')
        ->assertJsonPath('data.id', $person->id)
        ->assertJsonPath('data.slug', $person->slug)
        ->assertJsonPath('data.is_following', true)
        ->assertJsonPath('meta.request_id', fn (string $requestId) => filled($requestId));

    expect($user->fresh()->isFollowing($person))->toBeTrue();

    $destroyResponse = $this->deleteJson(route('api.client.follows.destroy', ['type' => 'person', 'subject' => $person->slug]));

    $destroyResponse->assertOk()
        ->assertJsonPath('data.type', 'person')
        ->assertJsonPath('data.id', $person->id)
        ->assertJsonPath('data.slug', $person->slug)
        ->assertJsonPath('data.is_following', false)
        ->assertJsonPath('meta.request_id', fn (string $requestId) => filled($requestId));

    expect($user->fresh()->isFollowing($person))->toBeFalse();
});
