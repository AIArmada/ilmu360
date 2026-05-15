<?php

use App\Models\Institution;
use App\Models\Reference;
use App\Models\Series;
use App\Models\Speaker;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('serializes follow state payloads for each followable type', function (string $type, string $subject, array $attributes): void {
    $user = User::factory()->create();

    $record = match ($type) {
        'institution' => Institution::factory()->create($attributes),
        'speaker' => Speaker::factory()->create($attributes),
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
    'institution by slug' => ['institution', 'slug', ['status' => 'verified', 'is_active' => true]],
    'speaker by slug' => ['speaker', 'slug', ['status' => 'verified', 'is_active' => true]],
    'reference by slug' => ['reference', 'slug', ['status' => 'verified', 'is_active' => true]],
    'reference by uuid' => ['reference', 'id', ['is_active' => true]],
    'series by slug' => ['series', 'slug', ['visibility' => 'public', 'is_active' => true]],
]);

it('returns the same follow payload shape across store and destroy', function () {
    $user = User::factory()->create();
    $speaker = Speaker::factory()->create([
        'status' => 'verified',
        'is_active' => true,
    ]);

    Sanctum::actingAs($user);

    $storeResponse = $this->postJson(route('api.client.follows.store', ['type' => 'speaker', 'subject' => $speaker->slug]));

    $storeResponse->assertCreated()
        ->assertJsonPath('data.type', 'speaker')
        ->assertJsonPath('data.id', $speaker->id)
        ->assertJsonPath('data.slug', $speaker->slug)
        ->assertJsonPath('data.is_following', true)
        ->assertJsonPath('meta.request_id', fn (string $requestId) => filled($requestId));

    expect($user->fresh()->isFollowing($speaker))->toBeTrue();

    $destroyResponse = $this->deleteJson(route('api.client.follows.destroy', ['type' => 'speaker', 'subject' => $speaker->slug]));

    $destroyResponse->assertOk()
        ->assertJsonPath('data.type', 'speaker')
        ->assertJsonPath('data.id', $speaker->id)
        ->assertJsonPath('data.slug', $speaker->slug)
        ->assertJsonPath('data.is_following', false)
        ->assertJsonPath('meta.request_id', fn (string $requestId) => filled($requestId));

    expect($user->fresh()->isFollowing($speaker))->toBeFalse();
});
