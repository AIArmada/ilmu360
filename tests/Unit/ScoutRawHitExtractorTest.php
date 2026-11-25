<?php

declare(strict_types=1);

use App\Features\Institutions\Models\Institution;
use App\Platform\Search\ScoutRawHitExtractor;
use Tests\TestCase;

pest()->extend(TestCase::class);

it('extracts normalized ids from typesense, meilisearch, algolia, and generic scout payloads', function (): void {
    $extractor = new ScoutRawHitExtractor;

    $typesense = [
        'hits' => [
            ['document' => ['id' => 'id-1']],
            ['document' => ['id' => 'id-2']],
            ['document' => ['id' => 'id-1']],
            ['document' => []],
        ],
    ];

    $meilisearch = [
        'hits' => [
            ['id' => 'ms-1', 'name' => 'Alpha'],
            ['id' => 42],
        ],
    ];

    $algolia = [
        'hits' => [
            ['objectID' => 'alg-1'],
            ['objectID' => '  alg-2  '],
        ],
    ];

    $model = new Institution(['name' => 'Test']);
    $model->setAttribute('id', 'model-1');

    $genericResults = [
        'results' => [$model, ['id' => 'arr-1']],
    ];

    expect($extractor->extractIds($typesense))->toBe(['id-1', 'id-2'])
        ->and($extractor->extractIds($meilisearch))->toBe(['ms-1', '42'])
        ->and($extractor->extractIds($algolia))->toBe(['alg-1', 'alg-2'])
        ->and($extractor->extractIds($genericResults))->toBe(['model-1', 'arr-1'])
        ->and($extractor->extractIds(['raw-1', 'raw-2']))->toBe(['raw-1', 'raw-2'])
        ->and($extractor->extractIds(null))->toBeEmpty()
        ->and($extractor->extractIds([]))->toBeEmpty();
});
