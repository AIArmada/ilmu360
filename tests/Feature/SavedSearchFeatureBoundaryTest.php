<?php

declare(strict_types=1);

use App\Actions\SavedSearches\CreateSavedSearchAction;
use App\Contracts\ShareTrackingContract;
use App\Features\SavedSearches\Api\SavedSearchCommands;
use App\Features\SavedSearches\Api\SavedSearchReader;
use App\Features\SavedSearches\Models\SavedSearch;
use App\Models\User;
use Dedoc\Scramble\Generator;
use Dedoc\Scramble\Scramble;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Routing\Route;
use Laravel\Sanctum\Sanctum;

it('documents snapshot-bound saved-search path identities as required UUID strings', function (): void {
    $config = clone Scramble::getGeneratorConfig(Scramble::DEFAULT_API);
    $config->routes(static fn (Route $route): bool => str_contains($route->uri(), 'saved-searches'));

    $document = app(Generator::class)($config);

    foreach ([
        ['/saved-searches/{savedSearch}', 'get'],
        ['/saved-searches/{savedSearch}', 'put'],
        ['/saved-searches/{savedSearch}', 'delete'],
        ['/saved-searches/{savedSearch}/execute', 'post'],
    ] as [$path, $method]) {
        $parameter = collect($document['paths'][$path][$method]['parameters'])->firstWhere('name', 'savedSearch');
        expect($parameter['required'])->toBeTrue()
            ->and($parameter['schema']['type'])->toBe('string')
            ->and($parameter['schema']['format'])->toBe('uuid');
    }
});

it('preserves the complete serialized API record through public snapshot binding and updates', function (): void {
    $owner = User::factory()->create();
    $search = SavedSearch::factory()->create([
        'user_id' => $owner->id,
        'query' => 'kuliah',
        'filters' => ['language_codes' => ['ms']],
        'meta' => ['source' => 'api parity'],
        'notify' => 'weekly',
    ])->refresh();
    Sanctum::actingAs($owner);

    $this->getJson(route('api.saved-searches.show', $search))
        ->assertOk()->assertJsonPath('data', $search->toArray());
    $this->getJson(route('api.saved-searches.index'))
        ->assertOk()->assertJsonPath('data', [$search->toArray()]);
    $updated = $this->putJson(route('api.saved-searches.update', $search), ['name' => 'Updated name']);

    $updated->assertOk()->assertJsonPath('message', 'Saved search updated.')
        ->assertJsonPath('data', $search->refresh()->toArray());
});

it('preserves creation payload keys without adding database defaults through a reload', function (): void {
    $owner = User::factory()->create();
    Sanctum::actingAs($owner);

    $response = $this->postJson(route('api.saved-searches.store'), [
        'name' => 'Created search', 'query' => 'kuliah', 'notify' => 'daily',
    ]);

    $response->assertCreated()->assertJsonPath('data.user_id', $owner->id)
        ->assertJsonPath('data.user_type', $owner->getMorphClass());
    expect(array_keys($response->json('data')))->toEqualCanonicalizing([
        'name', 'query', 'filters', 'radius_km', 'lat', 'lng', 'notify',
        'user_id', 'user_type', 'id', 'created_at', 'updated_at',
    ]);
});

it('does not resolve another owner type through the public owned reader', function (): void {
    $owner = User::factory()->create();
    $search = SavedSearch::factory()->create(['user_id' => $owner->id]);
    $reader = app(SavedSearchReader::class);

    expect($reader->findForOwner($owner->getMorphClass(), $owner->id, $search->id)?->id)->toBe($search->id)
        ->and($reader->findForOwner('institution', $owner->id, $search->id))->toBeNull();
});

it('refuses a public update across owner types before mutating the row', function (): void {
    $owner = User::factory()->create();
    $search = SavedSearch::factory()->create(['user_id' => $owner->id, 'name' => 'Protected search']);

    expect(fn () => app(SavedSearchCommands::class)->update('institution', $owner->id, $search->id, ['name' => 'Tampered']))
        ->toThrow(ModelNotFoundException::class);

    $this->assertDatabaseHas('saved_searches', ['id' => $search->id, 'name' => 'Protected search']);
});

it('refuses a public delete across owner types before deleting the row', function (): void {
    $owner = User::factory()->create();
    $search = SavedSearch::factory()->create(['user_id' => $owner->id]);

    expect(fn () => app(SavedSearchCommands::class)->delete('institution', $owner->id, $search->id))
        ->toThrow(ModelNotFoundException::class);

    $this->assertModelExists($search);
});

it('rolls back the owned create when attribution fails in the outer workflow', function (): void {
    $owner = User::factory()->create();
    $this->mock(ShareTrackingContract::class)
        ->shouldReceive('recordOutcome')->once()->andThrow(new RuntimeException('Attribution failed'));

    expect(fn () => app(CreateSavedSearchAction::class)->handle($owner, [
        'name' => 'Rolled back search', 'query' => null, 'notify' => 'daily',
    ]))->toThrow(RuntimeException::class, 'Attribution failed');

    $this->assertDatabaseMissing('saved_searches', ['user_id' => $owner->id, 'name' => 'Rolled back search']);
});
