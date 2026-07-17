<?php

use AIArmada\Events\Models\EventRole;
use AIArmada\Events\Models\EventTaxonomy;
use AIArmada\Events\Models\EventTerm;
use App\Contracts\EventCategoryCatalog;
use App\Enums\EventKeyPersonRole;
use Database\Seeders\AIArmada\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

it('seeds EventRole rows from EventKeyPersonRole + organizer', function (): void {
    seed(FoundationSeeder::class);

    expect(EventRole::query()->count())->toBe(count(EventKeyPersonRole::cases()) + 1);

    $organizer = EventRole::query()->where('code', 'organizer')->first();
    expect($organizer)->not->toBeNull()
        ->and($organizer->name)->toBe('Organizer');

    $speaker = EventRole::query()->where('code', 'speaker')->first();
    expect($speaker)->not->toBeNull()
        ->and($speaker->name)->toBe(EventKeyPersonRole::Speaker->getLabel());
});

it('seeds hierarchical EventTaxonomy and EventTerm categories', function (): void {
    seed(FoundationSeeder::class);

    $taxonomy = EventTaxonomy::query()->where('code', EventCategoryCatalog::TAXONOMY_CODE)->first();
    expect($taxonomy)->not->toBeNull()
        ->and($taxonomy->name)->toBe('Event Category')
        ->and($taxonomy->is_hierarchical)->toBeTrue()
        ->and($taxonomy->is_active)->toBeTrue();

    $terms = EventTerm::query()
        ->where('event_taxonomy_id', $taxonomy->id)
        ->orderBy('sort_order')
        ->get();

    expect($terms->whereNull('parent_id')->count())->toBeGreaterThanOrEqual(5)
        ->and($terms->whereNotNull('parent_id')->count())->toBeGreaterThanOrEqual(24);

    expect($terms->where('code', 'ilmu')->first())->not->toBeNull();
    expect($terms->where('code', 'kuliah_ceramah')->first())->not->toBeNull();
});

it('is idempotent (safe to run multiple times)', function (): void {
    seed(FoundationSeeder::class);
    $firstCount = EventRole::query()->count();

    seed(FoundationSeeder::class);

    expect(EventRole::query()->count())->toBe($firstCount);
});
