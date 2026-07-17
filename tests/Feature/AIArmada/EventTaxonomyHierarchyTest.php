<?php

use AIArmada\Events\Contracts\EventTaxonomyHierarchy;
use AIArmada\Events\Models\EventTaxonomy;
use AIArmada\Events\Models\EventTerm;
use App\Contracts\EventCategoryPolicyResolver;
use Database\Seeders\AIArmada\EventTaxonomySeeder;
use Database\Seeders\AIArmada\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

it('builds taxonomy paths and expands parent selections', function (): void {
    seed(FoundationSeeder::class);

    $hierarchy = app(EventTaxonomyHierarchy::class);
    $root = EventTerm::query()->where('code', 'ilmu')->firstOrFail();
    $child = EventTerm::query()->where('code', 'kuliah_ceramah')->firstOrFail();

    expect($hierarchy->options('event_category'))
        ->toHaveKey((string) $root->getKey(), 'Ilmu')
        ->toHaveKey((string) $child->getKey(), 'Ilmu › Kuliah / Ceramah');

    expect($hierarchy->descendantIds('event_category', [(string) $root->getKey()]))
        ->toContain((string) $root->getKey(), (string) $child->getKey());
});

it('minimizes a parent and selected descendant to the parent', function (): void {
    seed(FoundationSeeder::class);

    $hierarchy = app(EventTaxonomyHierarchy::class);
    $root = EventTerm::query()->where('code', 'ilmu')->firstOrFail();
    $child = EventTerm::query()->where('code', 'kuliah_ceramah')->firstOrFail();

    expect($hierarchy->minimalTermIds('event_category', [
        (string) $root->getKey(),
        (string) $child->getKey(),
    ]))->toBe([(string) $root->getKey()]);
});

it('applies child policy metadata when a parent category is selected', function (): void {
    seed(FoundationSeeder::class);

    $catalog = app(\App\Contracts\EventCategoryCatalog::class);
    $root = EventTerm::query()
        ->where('event_taxonomy_id', $catalog->taxonomyId())
        ->where('code', 'ilmu')
        ->firstOrFail();
    $community = EventTerm::query()
        ->where('event_taxonomy_id', $catalog->taxonomyId())
        ->where('code', 'komuniti')
        ->firstOrFail();

    expect(app(EventCategoryPolicyResolver::class)->requiresSpeaker([(string) $root->getKey()]))
        ->toBeTrue()
        ->and(app(EventCategoryPolicyResolver::class)->requiresPhysicalDelivery([(string) $community->getKey()]))
        ->toBeTrue();
});

it('validates parent and child IDs before minimizing them', function (): void {
    seed(FoundationSeeder::class);

    $catalog = app(\App\Contracts\EventCategoryCatalog::class);
    $root = EventTerm::query()
        ->where('event_taxonomy_id', $catalog->taxonomyId())
        ->where('code', 'ilmu')
        ->firstOrFail();
    $child = EventTerm::query()
        ->where('event_taxonomy_id', $catalog->taxonomyId())
        ->where('code', 'kuliah_ceramah')
        ->firstOrFail();
    $ids = [(string) $root->getKey(), (string) $child->getKey()];

    expect(Validator::make(
        ['event_category_ids' => $ids],
        ['event_category_ids.*' => ['uuid', \Illuminate\Validation\Rule::in($catalog->validTermIds($ids))]],
    )->passes())->toBeTrue();
    expect($catalog->validateTermIds($ids))->toBe([(string) $root->getKey()]);
});

it('hides an inactive taxonomy from active hierarchy reads', function (): void {
    seed(FoundationSeeder::class);

    EventTaxonomy::query()->where('code', 'event_category')->update(['is_active' => false]);

    expect(app(EventTaxonomyHierarchy::class)->options('event_category'))->toBe([]);
});

it('removes legacy event type terms when reseeding taxonomy data', function (): void {
    seed(FoundationSeeder::class);

    $legacyTaxonomy = EventTaxonomy::factory()->create(['code' => 'event_type']);
    $legacyTerm = EventTerm::factory()->create([
        'event_taxonomy_id' => $legacyTaxonomy->getKey(),
        'metadata' => ['group' => 'Ilmu'],
    ]);

    app(EventTaxonomySeeder::class)->run();

    expect(EventTaxonomy::query()->whereKey($legacyTaxonomy->getKey())->exists())->toBeFalse()
        ->and(EventTerm::query()->whereKey($legacyTerm->getKey())->exists())->toBeFalse();
});

it('does not cross taxonomy boundaries through term relationships', function (): void {
    $firstTaxonomy = EventTaxonomy::factory()->create();
    $secondTaxonomy = EventTaxonomy::factory()->create();
    $parent = EventTerm::factory()->create(['event_taxonomy_id' => $firstTaxonomy->getKey()]);
    $child = EventTerm::factory()->create([
        'event_taxonomy_id' => $secondTaxonomy->getKey(),
        'parent_id' => $parent->getKey(),
    ]);

    expect($child->parent)->toBeNull()
        ->and($parent->children->contains('id', $child->getKey()))->toBeFalse();
});
