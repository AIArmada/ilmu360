<?php

use AIArmada\Addressing\Models\AddressArea;
use AIArmada\Events\Features\Taxonomy\Models\EventTaxonomy;
use AIArmada\Events\Features\Taxonomy\Models\EventTerm;
use AIArmada\Persons\Enums\TitleUsagePosition;
use AIArmada\Persons\Models\Title;
use AIArmada\Persons\Models\TitleCategory;
use App\Features\Events\Actions\Projection\InvalidateEventTermCatalogAction;
use App\Features\Events\Api\EventSubmissionOptionsCache;
use App\Platform\Language\Models\Language;
use App\Shared\Cache\SelectionCatalog;
use App\Shared\Cache\SelectionCatalogCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class);

beforeEach(function (): void {
    app(SelectionCatalog::class)->bustAll();
});

it('binds SelectionCatalog contract to SelectionCatalogCache', function (): void {
    expect(app(SelectionCatalog::class))->toBeInstanceOf(SelectionCatalogCache::class);
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

    $catalog = app(SelectionCatalog::class);

    expect($catalog->titleOptions())->toMatchArray([(string) $firstTitle->getKey() => 'Ustaz']);

    $secondTitle = Title::query()->create([
        'category_id' => $category->getKey(),
        'name' => 'Ustazah',
        'usage_position' => TitleUsagePosition::BeforeName,
        'sort_order' => 2,
    ]);

    expect($catalog->titleOptions())->toHaveKey((string) $secondTitle->getKey())
        ->and($catalog->titleSearchOptions('ust'))->toMatchArray([
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

    $catalog = app(SelectionCatalog::class);

    expect($catalog->languageOptions('code', ttlSeconds: 3600)['zz-test'])->toBe('First Language Label');

    DB::table('languages')
        ->where('id', $language->getKey())
        ->update(['name' => 'Second Language Label']);

    expect($catalog->languageOptions('code', ttlSeconds: 3600)['zz-test'])->toBe('First Language Label');

    $this->travel(61)->minutes();

    expect($catalog->languageOptions('code', ttlSeconds: 3600)['zz-test'])->toBe('Second Language Label');
});

it('caches taxonomy term rows and options and busts them via InvalidateEventTermCatalogAction', function (): void {
    $taxonomy = EventTaxonomy::query()->create([
        'code' => 'domain',
        'name' => 'Domain',
        'is_active' => true,
    ]);

    $first = EventTerm::query()->create([
        'event_taxonomy_id' => $taxonomy->getKey(),
        'code' => 'aqidah',
        'name' => 'Aqidah',
        'sort_order' => 1,
        'is_active' => true,
    ]);

    $catalog = app(SelectionCatalog::class);
    $submissionCache = app(EventSubmissionOptionsCache::class);
    $submissionCache->rememberTaxonomyTagOptions('submit_tags_domain', 'ms', static fn (): array => ['cached' => 'Cached']);

    $versionBefore = $catalog->taxonomyTermsVersion();

    expect($catalog->taxonomyTermOptions('domain'))->toBe([(string) $first->getKey() => 'Aqidah'])
        ->and($catalog->taxonomyTermRows('domain'))->toBe([
            ['id' => (string) $first->getKey(), 'name' => 'Aqidah', 'code' => 'aqidah'],
        ]);

    $second = EventTerm::query()->create([
        'event_taxonomy_id' => $taxonomy->getKey(),
        'code' => 'fiqh',
        'name' => 'Fiqh',
        'sort_order' => 2,
        'is_active' => true,
    ]);

    app(InvalidateEventTermCatalogAction::class)->saved($second);

    expect($catalog->taxonomyTermsVersion())->not->toBe($versionBefore)
        ->and($catalog->taxonomyTermOptions('domain'))->toBe([
            (string) $first->getKey() => 'Aqidah',
            (string) $second->getKey() => 'Fiqh',
        ]);

    $reloaded = $submissionCache->rememberTaxonomyTagOptions('submit_tags_domain', 'ms', static fn (): array => ['fresh' => 'Fresh']);
    expect($reloaded)->toBe(['fresh' => 'Fresh']);
});

it('batch resolves and caches address area names by ids', function (): void {
    $country = ensureTestMalaysiaCountry();
    $areaA = createTestAddressArea('Petaling', 2, country: $country);
    $areaB = createTestAddressArea('Klang', 2, country: $country);

    $catalog = app(SelectionCatalog::class);

    $resolved = $catalog->addressAreaNamesByIds([
        (string) $areaA->getKey(),
        (string) $areaB->getKey(),
        'not-a-uuid',
    ]);

    expect($resolved)->toBe([
        (string) $areaA->getKey() => 'Petaling',
        (string) $areaB->getKey() => 'Klang',
    ]);

    DB::table((new AddressArea)->getTable())
        ->where('id', $areaA->getKey())
        ->update(['name' => 'Petaling Updated']);

    expect($catalog->addressAreaNamesByIds([(string) $areaA->getKey(), (string) $areaB->getKey()]))
        ->toBe([
            (string) $areaA->getKey() => 'Petaling',
            (string) $areaB->getKey() => 'Klang',
        ]);

    $catalog->bustAddress();

    expect($catalog->addressAreaNamesByIds([(string) $areaA->getKey(), (string) $areaB->getKey()]))
        ->toBe([
            (string) $areaA->getKey() => 'Petaling Updated',
            (string) $areaB->getKey() => 'Klang',
        ]);
});

it('exposes catalog version getters and language catalog options', function (): void {
    Language::query()->create([
        'code' => 'ms',
        'name' => 'Malay',
        'native' => 'Bahasa Melayu',
    ]);

    $catalog = app(SelectionCatalog::class);

    expect($catalog->geographyVersion())->not->toBeEmpty()
        ->and($catalog->languagesVersion())->not->toBeEmpty()
        ->and($catalog->donationBanksVersion())->not->toBeEmpty()
        ->and($catalog->taxonomyTermsVersion())->not->toBeEmpty()
        ->and($catalog->languageCatalogOptions())->toContain([
            'id' => Language::query()->where('code', 'ms')->value('id'),
            'label' => 'Malay',
        ]);
});
