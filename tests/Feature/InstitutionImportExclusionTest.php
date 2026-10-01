<?php

use App\Enums\InstitutionStatus;
use App\Models\Institution;
use App\Models\InstitutionImportExclusion;
use App\Models\Space;
use Database\Seeders\MalaysiaPoskodMasjidSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\DeletedModels\Models\DeletedModel;

uses(RefreshDatabase::class);

it('records source-backed deletions as permanent exclusions', function () {
    seedCanonicalMasjidFeedGeography();
    (new MalaysiaPoskodMasjidSeeder(base_path('tests/Fixtures/masjid_feed_canonical_test_fixture.csv')))->run();

    $institution = Institution::query()->where('slug', 'masjid-negara-101')->firstOrFail();
    $institutionId = (string) $institution->getKey();
    $institution->delete();

    $exclusion = InstitutionImportExclusion::forIdentity('masjid-csv', '101');
    expect($exclusion)->not()->toBeNull()
        ->and($exclusion->source)->toBe('masjid-csv')
        ->and($exclusion->external_ref)->toBe('101')
        ->and((string) $exclusion->institution_id)->toBe($institutionId)
        ->and($exclusion->deleted_at)->not()->toBeNull();
});

it('ignores deletions of rows without provenance', function () {
    $institution = Institution::factory()->create(['status' => InstitutionStatus::Verified->value]);
    $institution->delete();

    expect(InstitutionImportExclusion::query()->count())->toBe(0);
});

it('records exclusion identities byte-identically without rewriting', function () {
    $institution = Institution::factory()->create(['status' => InstitutionStatus::Verified->value]);

    Institution::query()->whereKey($institution->getKey())->update([
        'source' => ' padded-source ',
        'external_ref' => ' P1 ',
    ]);

    $institution->refresh()->delete();

    $exclusion = InstitutionImportExclusion::forIdentity(' padded-source ', ' P1 ');
    expect($exclusion)->not()->toBeNull()
        ->and($exclusion->source)->toBe(' padded-source ')
        ->and($exclusion->external_ref)->toBe(' P1 ');
});

it('keeps excluded identities out of reseeds even after snapshot pruning', function () {
    seedCanonicalMasjidFeedGeography();

    $run = fn () => (new MalaysiaPoskodMasjidSeeder(base_path('tests/Fixtures/masjid_feed_canonical_test_fixture.csv')))->run();
    $run();

    Institution::query()->where('slug', 'masjid-negara-101')->firstOrFail()->delete();

    expect(DeletedModel::query()->count())->toBeGreaterThan(0);

    // Snapshot retention expires; the exclusion must not depend on it.
    DeletedModel::query()->delete();

    $run();

    expect(Institution::query()->where('source', 'masjid-csv')->where('external_ref', '101')->exists())->toBeFalse()
        ->and(Institution::query()->count())->toBe(5)
        ->and(InstitutionImportExclusion::forIdentity('masjid-csv', '101'))->not()->toBeNull();
});

it('skips an identity excluded between the locked lookup and the create', function () {
    seedCanonicalMasjidFeedGeography();

    // Simulate a concurrent delete committing while the locked identity
    // lookup waits: the exclusion lands after the seeder's initial check
    // but before its post-lock recheck, so only the recheck can skip the
    // row instead of recreating it.
    $simulated = false;

    DB::listen(function (QueryExecuted $query) use (&$simulated): void {
        if ($simulated) {
            return;
        }

        if (! str_contains($query->sql, 'institutions') || ! str_contains($query->sql, 'external_ref')) {
            return;
        }

        $simulated = true;

        InstitutionImportExclusion::query()->create([
            'source' => 'padded-source ',
            'external_ref' => ' P1',
            'deleted_at' => now(),
        ]);
    });

    (new MalaysiaPoskodMasjidSeeder(base_path('tests/Fixtures/masjid_feed_spaced_identity_fixture.csv')))->run();

    expect($simulated)->toBeTrue()
        ->and(Institution::query()->count())->toBe(0)
        ->and(InstitutionImportExclusion::forIdentity('padded-source ', ' P1'))->not()->toBeNull();
});

it('never lets the importer clear an exclusion and never deletes related spaces', function () {
    seedCanonicalMasjidFeedGeography();

    $run = fn () => (new MalaysiaPoskodMasjidSeeder(base_path('tests/Fixtures/masjid_feed_canonical_test_fixture.csv')))->run();
    $run();

    $space = Space::factory()->create(['venue_id' => null]);
    $institution = Institution::query()->where('slug', 'osm-madrasah-an-nur-201')->firstOrFail();
    $institution->spaces()->sync([(string) $space->getKey() => ['capacity' => 50]]);
    $institution->delete();

    $flaggedAt = (string) InstitutionImportExclusion::forIdentity('osm', '201')?->deleted_at;

    $run();
    $run();

    $exclusion = InstitutionImportExclusion::forIdentity('osm', '201');
    expect($exclusion)->not()->toBeNull()
        ->and((string) $exclusion->deleted_at)->toBe($flaggedAt)
        ->and(Institution::query()->where('source', 'osm')->where('external_ref', '201')->exists())->toBeFalse()
        ->and(Space::query()->whereKey($space->getKey())->exists())->toBeTrue();
});
