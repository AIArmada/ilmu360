<?php

declare(strict_types=1);

use App\Features\SavedSearches\Models\SavedSearch;
use App\Models\User;
use App\Support\ShareTracking\ShareOutcomeBusinessKey;
use Laravel\Sanctum\Sanctum;

it('lists only searches with the authenticated user owner type and identity', function (): void {
    $user = User::factory()->create();
    $ownedSearch = SavedSearch::factory()->create(['user_id' => $user->id]);
    SavedSearch::factory()->create([
        'user_type' => 'institution',
        'user_id' => $user->id,
    ]);
    Sanctum::actingAs($user);

    $response = $this->getJson(route('api.saved-searches.index'));

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $ownedSearch->id);
});

it('returns 403 for a different owner type with the authenticated user UUID without changing its search or attribution', function (
    string $method,
    string $routeName,
    array $payload,
): void {
    $user = User::factory()->create();
    $foreignSearch = SavedSearch::factory()->create([
        'user_type' => 'institution',
        'user_id' => $user->id,
        'name' => 'Protected search',
    ]);
    $businessKey = ShareOutcomeBusinessKey::savedSearch($foreignSearch->id);
    Sanctum::actingAs($user);

    $response = $this->json($method, route($routeName, ['savedSearch' => $foreignSearch->id]), $payload);

    $response->assertForbidden();
    $this->assertModelExists($foreignSearch);
    $this->assertDatabaseHas('saved_searches', [
        'id' => $foreignSearch->id,
        'user_type' => 'institution',
        'user_id' => $user->id,
        'name' => 'Protected search',
    ]);
    $this->assertDatabaseMissing('share_outcome_corrections', ['business_key' => $businessKey]);
})->with([
    'show' => ['GET', 'api.saved-searches.show', []],
    'update' => ['PUT', 'api.saved-searches.update', ['name' => 'Tampered search']],
    'delete' => ['DELETE', 'api.saved-searches.destroy', []],
    'execute' => ['POST', 'api.saved-searches.execute', []],
]);
