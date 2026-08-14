<?php

use AIArmada\Events\Contracts\EventTaxonomyHierarchy;
use AIArmada\Events\Models\EventTaxonomy;
use AIArmada\Events\Models\EventTerm;
use AIArmada\Events\Models\EventTermPolicy;
use App\Contracts\EventCategoryCatalog;
use App\Contracts\EventCategoryPolicyResolver;
use Database\Seeders\AIArmada\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

it('seeds a flat activity-first event category vocabulary', function (): void {
    seed(FoundationSeeder::class);

    $hierarchy = app(EventTaxonomyHierarchy::class);
    $lecture = EventTerm::query()->where('code', 'kuliah_ceramah')->firstOrFail();

    expect($hierarchy->options('event_category'))
        ->toHaveKey((string) $lecture->getKey(), 'Kuliah / Ceramah')
        ->toHaveCount(8);

    expect(EventTerm::query()->where('code', 'ilmu')->exists())->toBeFalse()
        ->and(EventTerm::query()->where('code', 'kelas_daurah')->exists())->toBeFalse()
        ->and(EventTerm::query()
            ->where('event_taxonomy_id', $lecture->event_taxonomy_id)
            ->whereNull('parent_id')
            ->count())->toBe(8);
});

it('applies activity policies to the new category terms', function (): void {
    seed(FoundationSeeder::class);

    $catalog = app(EventCategoryCatalog::class);
    $lecture = EventTerm::query()
        ->where('event_taxonomy_id', $catalog->taxonomyId())
        ->where('code', 'kuliah_ceramah')
        ->firstOrFail();
    $community = EventTerm::query()
        ->where('event_taxonomy_id', $catalog->taxonomyId())
        ->where('code', 'komuniti_kebajikan')
        ->firstOrFail();

    expect(app(EventCategoryPolicyResolver::class)->requiresSpeaker([(string) $lecture->getKey()]))
        ->toBeTrue()
        ->and(app(EventCategoryPolicyResolver::class)->requiresPhysicalDelivery([(string) $community->getKey()]))
        ->toBeTrue();
});

it('validates the flat category IDs before minimizing them', function (): void {
    seed(FoundationSeeder::class);

    $catalog = app(EventCategoryCatalog::class);
    $lecture = EventTerm::query()
        ->where('event_taxonomy_id', $catalog->taxonomyId())
        ->where('code', 'kuliah_ceramah')
        ->firstOrFail();
    $ids = [(string) $lecture->getKey()];

    expect(Validator::make(
        ['event_category_ids' => $ids],
        ['event_category_ids.*' => ['uuid', Rule::in($catalog->validTermIds($ids))]],
    )->passes())->toBeTrue();
    expect($catalog->validateTermIds($ids))->toBe([(string) $lecture->getKey()]);
});

it('hides an inactive taxonomy from active hierarchy reads', function (): void {
    seed(FoundationSeeder::class);

    EventTaxonomy::query()->where('code', 'event_category')->update(['is_active' => false]);

    expect(app(EventTaxonomyHierarchy::class)->options('event_category'))->toBe([]);
});

it('seeds policy rows for terms with requires_speaker', function (): void {
    seed(FoundationSeeder::class);

    $term = EventTerm::query()->where('code', 'kuliah_ceramah')->firstOrFail();

    expect(EventTermPolicy::query()
        ->where('event_term_id', (string) $term->getKey())
        ->where('policy_code', 'requires_speaker')
        ->where('is_enabled', true)
        ->exists()
    )->toBeTrue();
});

it('seeds policy rows for terms with requires_physical_delivery', function (): void {
    seed(FoundationSeeder::class);

    $term = EventTerm::query()->where('code', 'komuniti_kebajikan')->firstOrFail();

    expect(EventTermPolicy::query()
        ->where('event_term_id', (string) $term->getKey())
        ->where('policy_code', 'requires_physical_delivery')
        ->where('is_enabled', true)
        ->exists()
    )->toBeTrue();
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
