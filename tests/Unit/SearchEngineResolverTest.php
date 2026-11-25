<?php

declare(strict_types=1);

use App\Platform\Search\ScoutSearchEngineResolver;
use App\Shared\Contracts\SearchEngineResolver;
use App\Shared\Contracts\SearchHealthCheck;
use Tests\TestCase;

pest()->extend(TestCase::class);

it('resolves discovery search driver override before falling back to scout driver', function (): void {
    config()->set('scout.driver', 'typesense');
    config()->set('discovery.search.driver', 'meilisearch');

    $resolver = app(SearchEngineResolver::class);

    expect($resolver)->toBeInstanceOf(ScoutSearchEngineResolver::class)
        ->and($resolver->activeDriver())->toBe('meilisearch')
        ->and($resolver->usesScout())->toBeTrue()
        ->and($resolver->usesTypesense())->toBeFalse();

    config()->set('discovery.search.driver', null);

    expect($resolver->activeDriver())->toBe('typesense')
        ->and($resolver->usesTypesense())->toBeTrue();
});

it('reports scout and remote availability across supported drivers', function (): void {
    $healthCheck = Mockery::mock(SearchHealthCheck::class);
    $healthCheck->shouldReceive('isAvailable')->once()->andReturn(true);
    app()->instance(SearchHealthCheck::class, $healthCheck);

    $resolver = app(SearchEngineResolver::class);

    config()->set('discovery.search.driver', null);
    config()->set('scout.driver', 'typesense');
    expect($resolver->usesScout())->toBeTrue()
        ->and($resolver->usesTypesense())->toBeTrue()
        ->and($resolver->isRemoteEngineAvailable())->toBeTrue();

    config()->set('scout.driver', 'meilisearch');
    expect($resolver->usesScout())->toBeTrue()
        ->and($resolver->usesTypesense())->toBeFalse()
        ->and($resolver->isRemoteEngineAvailable())->toBeTrue();

    config()->set('scout.driver', 'algolia');
    expect($resolver->usesScout())->toBeTrue()
        ->and($resolver->usesTypesense())->toBeFalse()
        ->and($resolver->isRemoteEngineAvailable())->toBeTrue();

    config()->set('scout.driver', 'database');
    expect($resolver->usesScout())->toBeTrue()
        ->and($resolver->usesTypesense())->toBeFalse()
        ->and($resolver->isRemoteEngineAvailable())->toBeFalse();

    config()->set('scout.driver', 'collection');
    expect($resolver->usesScout())->toBeFalse()
        ->and($resolver->usesTypesense())->toBeFalse()
        ->and($resolver->isRemoteEngineAvailable())->toBeFalse();
});

it('reads bounded result limit from discovery search config', function (): void {
    $resolver = app(SearchEngineResolver::class);

    config()->set('discovery.search.result_limit', 500);
    expect($resolver->resultLimit())->toBe(500);

    config()->set('discovery.search.result_limit', 10);
    expect($resolver->resultLimit())->toBe(50);
});
