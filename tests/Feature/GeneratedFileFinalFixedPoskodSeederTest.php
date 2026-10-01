<?php

use App\Enums\InstitutionStatus;
use App\Enums\InstitutionType;
use App\Models\Institution;
use Database\Seeders\MalaysiaPoskodMasjidSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('imports the canonical feed with opaque names, full addresses, and role assignments', function () {
    $geo = seedCanonicalMasjidFeedGeography();

    $seeder = new MalaysiaPoskodMasjidSeeder(base_path('tests/Fixtures/masjid_feed_canonical_test_fixture.csv'));
    $seeder->run();

    expect(Institution::query()->count())->toBe(6)
        ->and(Institution::query()->where('source', 'masjid-csv')->count())->toBe(4)
        ->and(Institution::query()->where('source', 'osm')->count())->toBe(2)
        ->and(Institution::query()->whereHas('addresses')->count())->toBe(6);

    // All curation statuses import: curation is upstream information, not app moderation.
    expect(Institution::query()->where('slug', 'surau-al-aliatul-102')->exists())->toBeTrue()
        ->and(Institution::query()->where('slug', 'osm-madrasah-an-nur-201')->exists())->toBeTrue()
        ->and(Institution::query()->where('slug', 'masjid-klcc-104')->exists())->toBeTrue();

    // Hand-cased apostrophe bytes survive byte-identical.
    $surau = Institution::query()->where('slug', 'surau-al-aliatul-102')->firstOrFail();
    expect($surau->name)->toBe("Surau Al-'Aliatul")
        ->and($surau->type)->toBe(InstitutionType::Surau)
        ->and($surau->status)->toBe(InstitutionStatus::Verified)
        ->and($surau->getAttribute('source'))->toBe('masjid-csv')
        ->and($surau->getAttribute('external_ref'))->toBe('102')
        ->and($surau->getAttribute('imported_at'))->not()->toBeNull();

    $address = $surau->primaryAddress();
    expect($address->line1)->toBe('Jalan Ss2/3')
        ->and($address->postcode)->toBe('47800')
        ->and($address->city)->toBe('Petaling Jaya')
        ->and($address->state)->toBe('Selangor')
        ->and($address->country_code)->toBe('MY')
        ->and((float) $address->latitude)->toBe(3.1147)
        ->and((float) $address->longitude)->toBe(101.6118)
        ->and((string) $address->state_id)->toBe((string) $geo['selangor']['state']->getKey())
        ->and($address->city_id)->toBeNull();

    $assignments = $address->areaAssignments->pluck('address_area_id', 'role')->all();
    expect($assignments['administrative_district'] ?? null)->toBe((string) $geo['selangor']['district']->getKey())
        ->and($assignments['administrative_subdivision'] ?? null)->toBe((string) $geo['selangor']['subdistrict']->getKey())
        ->and($assignments['postal_locality'] ?? null)->toBeNull();

    // Exact city match sets city_id.
    $madrasah = Institution::query()->where('slug', 'osm-madrasah-an-nur-201')->firstOrFail();
    expect($madrasah->type)->toBe(InstitutionType::Madrasah)
        ->and((string) $madrasah->primaryAddress()?->city_id)->toBe((string) $geo['selangor']['city']->getKey());

    // Federal-territory row with blank optionals imports without assignments.
    $negara = Institution::query()->where('slug', 'masjid-negara-101')->firstOrFail();
    expect($negara->primaryAddress()?->state)->toBe('Wilayah Persekutuan Kuala Lumpur')
        ->and($negara->primaryAddress()?->areaAssignments)->toHaveCount(0);

    // Postal locality resolves through the postal hierarchy link.
    $subang = Institution::query()->where('slug', 'osm-surau-taman-subang-202')->firstOrFail();
    expect($subang->primaryAddress()?->areaAssignments->firstWhere('role', 'postal_locality')?->address_area_id)
        ->toBe((string) $geo['locality']->getKey());

    // Unresolved non-blank areas are reported and retain feed text.
    $reports = $seeder->unresolvedAreaReports();
    expect($reports)->toHaveCount(2)
        ->and(implode("\n", $reports))->toContain("administrative_district 'Kuala Lumpur' unresolved")
        ->and(implode("\n", $reports))->toContain("administrative_subdivision 'Bandar Khayalan' unresolved");

    $klcc = Institution::query()->where('slug', 'masjid-klcc-104')->firstOrFail();
    expect($klcc->primaryAddress()?->areaAssignments)->toHaveCount(0)
        ->and($klcc->primaryAddress()?->postcode)->toBe('50450');

    $locale = Institution::query()->where('slug', 'masjid-unresolved-locale-106')->firstOrFail();
    $localeAssignments = $locale->primaryAddress()?->areaAssignments->pluck('address_area_id', 'role')->all() ?? [];
    expect($localeAssignments['administrative_district'] ?? null)->toBe((string) $geo['selangor']['district']->getKey())
        ->and($localeAssignments['administrative_subdivision'] ?? null)->toBeNull();
});

it('fails preflight loudly with no partial writes', function (string $fixture, string $expectedMessage) {
    $seeder = new MalaysiaPoskodMasjidSeeder(base_path("tests/Fixtures/{$fixture}"));

    expect(fn () => $seeder->run())->toThrow(RuntimeException::class, $expectedMessage);
    expect(Institution::query()->count())->toBe(0);
})->with([
    'missing curated name and slug' => ['masjid_feed_missing_identity_fixture.csv', "missing required 'nama_display'"],
    'duplicate source identity' => ['masjid_feed_duplicate_ref_fixture.csv', 'duplicate source/external_ref'],
    'duplicate slug across identities' => ['masjid_feed_duplicate_slug_fixture.csv', "slug 'shared-slug' collides with a different identity on row 2"],
    'out-of-range coordinates' => ['masjid_feed_invalid_coords_fixture.csv', 'invalid latitude'],
    'unknown institution type' => ['masjid_feed_invalid_type_fixture.csv', 'invalid institution_type'],
    'short row width' => ['masjid_feed_bad_width_fixture.csv', 'expected 19 columns'],
]);

it('resets per-run caches and reports when the same instance runs twice', function () {
    seedCanonicalMasjidFeedGeography();

    $seeder = new MalaysiaPoskodMasjidSeeder(base_path('tests/Fixtures/masjid_feed_canonical_test_fixture.csv'));
    $seeder->run();
    expect($seeder->unresolvedAreaReports())->toHaveCount(2);

    Institution::query()->update(['status' => InstitutionStatus::Pending->value]);

    $seeder->run();

    expect($seeder->unresolvedAreaReports())->toHaveCount(2)
        ->and(Institution::query()->count())->toBe(6);
});
