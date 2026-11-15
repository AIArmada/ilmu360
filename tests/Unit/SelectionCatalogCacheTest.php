<?php

use AIArmada\Persons\Enums\TitleUsagePosition;
use AIArmada\Persons\Models\Title;
use AIArmada\Persons\Models\TitleCategory;
use App\Models\Language;
use App\Shared\Cache\SelectionCatalogCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    app(SelectionCatalogCache::class)->bustAll();
});

it('caches and refreshes the title catalog by version', function (): void {
    $category = TitleCategory::query()->create([
        'code' => 'academic',
        'name' => 'Academic',
        'sort_order' => 1,
    ]);
    $firstTitle = Title::query()->create([
        'category_id' => $category->getKey(),
        'name' => 'Ustaz',
        'short_form' => 'U.',
        'usage_position' => TitleUsagePosition::BeforeName,
        'sort_order' => 1,
    ]);

    $catalog = app(SelectionCatalogCache::class);

    expect($catalog->titleOptions())->toMatchArray([(string) $firstTitle->getKey() => 'Ustaz']);

    $secondTitle = Title::query()->create([
        'category_id' => $category->getKey(),
        'name' => 'Ustazah',
        'usage_position' => TitleUsagePosition::BeforeName,
        'sort_order' => 2,
    ]);

    expect($catalog->titleOptions())->toHaveKey((string) $secondTitle->getKey());

    expect($catalog->titleSearchOptions('ust'))->toMatchArray([
        (string) $firstTitle->getKey() => 'Ustaz',
        (string) $secondTitle->getKey() => 'Ustazah',
    ]);
});

it('refreshes language options after their requested cache lifetime', function (): void {
    $this->travelTo('2026-11-15 10:00:00');

    $language = Language::query()->create([
        'code' => 'zz-test',
        'name' => 'First Language Label',
    ]);

    $catalog = app(SelectionCatalogCache::class);

    expect($catalog->languageOptions('code', ttlSeconds: 3600)['zz-test'])->toBe('First Language Label');

    DB::table('languages')
        ->where('id', $language->getKey())
        ->update(['name' => 'Second Language Label']);

    expect($catalog->languageOptions('code', ttlSeconds: 3600)['zz-test'])->toBe('First Language Label');

    $this->travel(61)->minutes();

    expect($catalog->languageOptions('code', ttlSeconds: 3600)['zz-test'])->toBe('Second Language Label');
});
