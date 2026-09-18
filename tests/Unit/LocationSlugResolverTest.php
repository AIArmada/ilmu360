<?php

use AIArmada\Addressing\Models\State;
use App\Support\Location\LocationSlugResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('maps country slugs from names with an id roundtrip', function () {
    withGlobalOwnerContext(function (): void {
        $country = ensureTestMalaysiaCountry();

        $maps = app(LocationSlugResolver::class)->countryMaps();

        expect($maps['slugToId']['malaysia'])->toBe((string) $country->getKey())
            ->and($maps['idToSlug'][(string) $country->getKey()])->toBe('malaysia')
            ->and($maps['options']['malaysia'])->toBe('Malaysia');
    });
});

it('derives state slugs scoped to their country', function () {
    withGlobalOwnerContext(function (): void {
        $geo = createTestPackageGeography('Negeri Slug Unit', 'Daerah Slug Unit');

        $maps = app(LocationSlugResolver::class)->stateMaps((string) $geo['country']->getKey());

        expect($maps['slugToId']['negeri-slug-unit'])->toBe((string) $geo['state']->getKey())
            ->and($maps['idToSlug'][(string) $geo['state']->getKey()])->toBe('negeri-slug-unit');
    });
});

it('suffixes duplicate state names deterministically', function () {
    withGlobalOwnerContext(function (): void {
        $country = ensureTestMalaysiaCountry();

        $first = State::query()->firstOrCreate(
            ['country_id' => (string) $country->getKey(), 'name' => 'Lankaran', 'code' => 'U1'],
            ['country_code' => $country->iso2],
        );
        $second = State::query()->firstOrCreate(
            ['country_id' => (string) $country->getKey(), 'name' => 'Lankaran', 'code' => 'U2'],
            ['country_code' => $country->iso2],
        );

        $maps = app(LocationSlugResolver::class)->stateMaps((string) $country->getKey());

        $slugs = [$maps['idToSlug'][(string) $first->getKey()], $maps['idToSlug'][(string) $second->getKey()]];
        sort($slugs);

        expect($slugs)->toBe(['lankaran', 'lankaran-2'])
            ->and($maps['slugToId']['lankaran'])->not->toBe($maps['slugToId']['lankaran-2']);
    });
});

it('resolves district slugs from stored area slugs within the state scope', function () {
    withGlobalOwnerContext(function (): void {
        $geo = createTestPackageGeography('Negeri Slug Daerah', 'Daerah Slug Dies');

        $maps = app(LocationSlugResolver::class)->districtMaps(
            (string) $geo['state']->getKey(),
            (string) $geo['country']->getKey(),
        );

        $slug = (string) $geo['district']->slug;

        expect($maps['slugToId'][$slug])->toBe((string) $geo['district']->getKey())
            ->and($maps['idToSlug'][(string) $geo['district']->getKey()])->toBe($slug)
            ->and($maps['options'][$slug])->toBe('Daerah Slug Dies');
    });
});

it('cleans incoming slugs for lookup', function () {
    expect(LocationSlugResolver::cleanSlug('  Johor Bahru '))->toBe('johor-bahru')
        ->and(LocationSlugResolver::cleanSlug(''))->toBeNull()
        ->and(LocationSlugResolver::cleanSlug(null))->toBeNull();
});

it('returns ID-keyed state options gated by the provider', function () {
    withGlobalOwnerContext(function (): void {
        $geo = createTestPackageGeography('Negeri Pilihan Unit', 'Daerah Pilihan Unit');
        $malaysiaId = (string) $geo['country']->getKey();

        $singapore = ensureTestAddressCountry('SG', 'Singapore', 'SGP', ['Asia/Singapore'], '65');
        State::query()->firstOrCreate(
            ['country_id' => (string) $singapore->getKey(), 'name' => 'North West'],
            ['code' => '03'],
        );

        $resolver = app(LocationSlugResolver::class);

        expect($resolver->stateOptionsForCountry($malaysiaId))->toBe([(string) $geo['state']->getKey() => 'Negeri Pilihan Unit'])
            ->and($resolver->stateOptionsForCountry((string) $singapore->getKey()))->toBe([])
            ->and($resolver->stateOptionsForCountry(null))->toBe([]);
    });
});
