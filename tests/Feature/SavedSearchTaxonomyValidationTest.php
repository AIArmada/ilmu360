<?php

use AIArmada\Events\Models\EventTaxonomy;
use AIArmada\Events\Models\EventTerm;
use App\Features\SavedSearches\Models\SavedSearch;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

dataset('saved search taxonomy vocabularies', [
    'domain' => ['domain_tag_ids', 'domain'],
    'discipline' => ['discipline_tag_ids', 'discipline'],
    'source' => ['source_tag_ids', 'source'],
    'issue' => ['issue_tag_ids', 'issue'],
]);

function savedSearchValidationTerm(string $vocabulary, bool $active = true): EventTerm
{
    $taxonomy = EventTaxonomy::factory()->create(['code' => $vocabulary, 'is_active' => $active]);

    return EventTerm::factory()->create([
        'event_taxonomy_id' => $taxonomy->id,
        'name' => 'Saved search term',
        'is_active' => $active,
    ]);
}

it('creates saved searches using the matching taxonomy vocabulary', function (string $filter, string $vocabulary): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $term = savedSearchValidationTerm($vocabulary);

    $response = $this->postJson('/api/v1/saved-searches', [
        'name' => 'Taxonomy Search',
        'filters' => [$filter => [$term->id]],
        'notify' => 'daily',
    ])->assertCreated()->assertJsonPath("data.filters.{$filter}", [$term->id]);

    expect(SavedSearch::query()->findOrFail($response->json('data.id'))->filters)->toBe([$filter => [$term->id]]);
})->with('saved search taxonomy vocabularies');

it('updates saved searches using the matching taxonomy vocabulary', function (string $filter, string $vocabulary): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $savedSearch = SavedSearch::factory()->create(['user_id' => $user->id, 'name' => 'Original Search']);
    $term = savedSearchValidationTerm($vocabulary);

    $this->patchJson('/api/v1/saved-searches/'.$savedSearch->id, [
        'filters' => [$filter => [$term->id]],
    ])->assertOk()->assertJsonPath("data.filters.{$filter}", [$term->id]);

    expect($savedSearch->fresh()->filters)->toBe([$filter => [$term->id]])
        ->and($savedSearch->fresh()->name)->toBe('Original Search');
})->with('saved search taxonomy vocabularies');

it('rejects taxonomy terms from a different vocabulary without creating a search', function (string $filter, string $vocabulary): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $term = savedSearchValidationTerm('unrelated');

    $this->postJson('/api/v1/saved-searches', [
        'name' => 'Invalid Vocabulary',
        'filters' => [$filter => [$term->id]],
        'notify' => 'daily',
    ])->assertUnprocessable()->assertJsonValidationErrors(["filters.{$filter}.0"]);

    expect(SavedSearch::query()->where('user_id', $user->id)->count())->toBe(0);
})->with('saved search taxonomy vocabularies');

it('rejects missing taxonomy terms without creating a search', function (string $filter, string $vocabulary): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/saved-searches', [
        'name' => 'Missing Term',
        'filters' => [$filter => [(string) Str::uuid()]],
        'notify' => 'daily',
    ])->assertUnprocessable()->assertJsonValidationErrors(["filters.{$filter}.0"]);

    expect(SavedSearch::query()->where('user_id', $user->id)->count())->toBe(0);
})->with('saved search taxonomy vocabularies');

it('rejects cross-vocabulary updates without changing the saved search', function (string $filter, string $vocabulary): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $term = savedSearchValidationTerm($vocabulary);
    $savedSearch = SavedSearch::factory()->create([
        'user_id' => $user->id,
        'name' => 'Keep Original',
        'filters' => [$filter => [$term->id]],
    ]);
    $other = savedSearchValidationTerm('unrelated');

    $this->patchJson('/api/v1/saved-searches/'.$savedSearch->id, [
        'name' => 'Must Not Persist',
        'filters' => [$filter => [$other->id]],
    ])->assertUnprocessable()->assertJsonValidationErrors(["filters.{$filter}.0"]);

    expect($savedSearch->fresh()->name)->toBe('Keep Original')
        ->and($savedSearch->fresh()->filters)->toBe([$filter => [$term->id]]);
})->with('saved search taxonomy vocabularies');

it('uses the configured term and taxonomy tables for saved search validation', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $term = savedSearchValidationTerm('domain');
    Schema::rename((new EventTerm)->getTable(), 'search_validation_terms');
    Schema::rename((new EventTaxonomy)->getTable(), 'search_validation_taxonomies');
    config()->set('events.database.tables.event_terms', 'search_validation_terms');
    config()->set('events.database.tables.event_taxonomies', 'search_validation_taxonomies');

    $response = $this->postJson('/api/v1/saved-searches', [
        'name' => 'Configured Terms',
        'filters' => ['domain_tag_ids' => [$term->id]],
        'notify' => 'daily',
    ])->assertCreated()->assertJsonPath('data.filters.domain_tag_ids', [$term->id]);

    expect(SavedSearch::query()->findOrFail($response->json('data.id'))->filters)->toBe(['domain_tag_ids' => [$term->id]]);
});

it('preserves existing inactive term acceptance for saved search definitions', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $term = savedSearchValidationTerm('domain', active: false);

    $this->postJson('/api/v1/saved-searches', [
        'name' => 'Existing Inactive Term',
        'filters' => ['domain_tag_ids' => [$term->id]],
        'notify' => 'daily',
    ])->assertCreated()->assertJsonPath('data.filters.domain_tag_ids', [$term->id]);

    expect(SavedSearch::query()->where('user_id', $user->id)->sole()->filters)->toBe(['domain_tag_ids' => [$term->id]]);
});

it('keeps vocabulary existence lookups lazy when taxonomy filters are absent', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $queries = [];
    $taxonomyTable = (new EventTaxonomy)->getTable();
    $termTable = (new EventTerm)->getTable();
    DB::listen(function (QueryExecuted $query) use (&$queries, $taxonomyTable, $termTable): void {
        if ((str_contains($query->sql, $taxonomyTable) || str_contains($query->sql, $termTable))
            && array_intersect(['domain', 'discipline', 'source', 'issue'], $query->bindings) !== []) {
            $queries[] = $query->sql;
        }
    });

    $this->postJson('/api/v1/saved-searches', [
        'name' => 'No Taxonomy Filters',
        'notify' => 'daily',
    ])->assertCreated();

    expect($queries)->toBeEmpty();
});
