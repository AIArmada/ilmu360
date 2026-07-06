<?php

use AIArmada\Events\Models\EventRole;
use AIArmada\Events\Models\EventTaxonomy;
use AIArmada\Events\Models\EventTerm;
use App\Enums\EventKeyPersonRole;
use App\Enums\EventType;
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

it('seeds EventTaxonomy and EventTerm for event types', function (): void {
    seed(FoundationSeeder::class);

    $taxonomy = EventTaxonomy::query()->where('code', 'event_type')->first();
    expect($taxonomy)->not->toBeNull()
        ->and($taxonomy->name)->toBe('Event Type')
        ->and($taxonomy->is_hierarchical)->toBeFalse()
        ->and($taxonomy->is_active)->toBeTrue();

    $terms = EventTerm::query()
        ->where('event_taxonomy_id', $taxonomy->id)
        ->orderBy('sort_order')
        ->get();

    expect($terms)->toHaveCount(count(EventType::cases()));

    expect($terms->first()->code)->toBe(EventType::KuliahCeramah->value);
    expect($terms->last()->code)->toBe(EventType::Other->value);
});

it('is idempotent (safe to run multiple times)', function (): void {
    seed(FoundationSeeder::class);
    $firstCount = EventRole::query()->count();

    seed(FoundationSeeder::class);

    expect(EventRole::query()->count())->toBe($firstCount);
});
