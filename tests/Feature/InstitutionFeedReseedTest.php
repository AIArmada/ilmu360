<?php

use App\Enums\InstitutionStatus;
use App\Models\Institution;
use Database\Seeders\MalaysiaPoskodMasjidSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('renames a pending slug back on reseed without duplicating, twice', function () {
    seedCanonicalMasjidFeedGeography();

    $run = fn () => (new MalaysiaPoskodMasjidSeeder(base_path('tests/Fixtures/masjid_feed_canonical_test_fixture.csv')))->run();
    $run();

    $original = Institution::query()->where('slug', 'surau-al-aliatul-102')->firstOrFail();
    $original->forceFill([
        'status' => InstitutionStatus::Pending->value,
        'slug' => 'renamed-by-admin',
        'name' => 'Renamed By Admin',
    ])->save();

    $run();

    $refreshed = Institution::query()->where('source', 'masjid-csv')->where('external_ref', '102')->firstOrFail();
    expect((string) $refreshed->getKey())->toBe((string) $original->getKey())
        ->and($refreshed->slug)->toBe('surau-al-aliatul-102')
        ->and($refreshed->name)->toBe("Surau Al-'Aliatul")
        ->and($refreshed->status)->toBe(InstitutionStatus::Pending)
        ->and(Institution::query()->where('slug', 'renamed-by-admin')->exists())->toBeFalse()
        ->and(Institution::query()->count())->toBe(6);

    $importedAt = $refreshed->getAttribute('imported_at');

    $run();

    $twice = Institution::query()->where('source', 'masjid-csv')->where('external_ref', '102')->firstOrFail();
    expect((string) $twice->getKey())->toBe((string) $original->getKey())
        ->and($twice->slug)->toBe('surau-al-aliatul-102')
        ->and($twice->status)->toBe(InstitutionStatus::Pending)
        ->and((string) $twice->getAttribute('imported_at'))->toBe((string) $importedAt)
        ->and(Institution::query()->count())->toBe(6);
});

it('leaves verified, rejected, and inactive graphs completely untouched on reseed', function () {
    seedCanonicalMasjidFeedGeography();

    $run = fn () => (new MalaysiaPoskodMasjidSeeder(base_path('tests/Fixtures/masjid_feed_canonical_test_fixture.csv')))->run();
    $run();

    $frozenAt = now()->subDay()->startOfSecond();
    $cases = [
        'masjid-negara-101' => InstitutionStatus::Verified,
        'osm-madrasah-an-nur-201' => InstitutionStatus::Rejected,
        'masjid-klcc-104' => InstitutionStatus::Inactive,
    ];

    foreach ($cases as $slug => $status) {
        $institution = Institution::query()->where('slug', $slug)->firstOrFail();
        $institution->forceFill([
            'status' => $status->value,
            'name' => "Manual {$slug}",
            'slug' => "manual-{$slug}",
            'description' => "Manual description {$slug}",
            'inactive_at' => $status === InstitutionStatus::Inactive ? $frozenAt->copy()->subDays(10) : null,
        ])->save();
        $institution->primaryAddress()->forceFill(['line1' => "Manual line {$slug}"])->save();
        Institution::query()->whereKey($institution->getKey())->update(['updated_at' => $frozenAt]);
    }

    $before = Institution::query()->with('addresses')->get()->keyBy(fn (Institution $i) => (string) $i->getKey());

    $run();

    expect(Institution::query()->count())->toBe(6);

    foreach ($before as $id => $previous) {
        $current = Institution::query()->with('addresses')->findOrFail($id);

        expect($current->name)->toBe($previous->name)
            ->and($current->slug)->toBe($previous->slug)
            ->and($current->description)->toBe($previous->description)
            ->and($current->primaryAddress()?->line1)->toBe($previous->primaryAddress()?->line1)
            ->and($current->updated_at->toIso8601String())->toBe($previous->updated_at->toIso8601String())
            ->and((string) $current->getAttribute('imported_at'))->toBe((string) $previous->getAttribute('imported_at'));
    }

    // Renamed verified slugs stay attached: no duplicate under the feed slug.
    expect(Institution::query()->where('slug', 'masjid-negara-101')->exists())->toBeFalse()
        ->and(Institution::query()->where('slug', 'manual-masjid-negara-101')->exists())->toBeTrue();
});

it('stamps new verified rows and retains pending status timestamps on refresh', function () {
    seedCanonicalMasjidFeedGeography();

    $run = fn () => (new MalaysiaPoskodMasjidSeeder(base_path('tests/Fixtures/masjid_feed_canonical_test_fixture.csv')))->run();
    $run();

    $fresh = Institution::query()->where('slug', 'masjid-negara-101')->firstOrFail();
    expect($fresh->status)->toBe(InstitutionStatus::Verified)
        ->and($fresh->getAttribute('verified_at'))->not()->toBeNull()
        ->and($fresh->getAttribute('last_state_change_at'))->not()->toBeNull();

    $target = Institution::query()->where('slug', 'surau-al-aliatul-102')->firstOrFail();
    $frozen = now()->subDays(30)->startOfSecond();
    $verifiedAt = $target->getAttribute('verified_at');

    Institution::query()->whereKey($target->getKey())->update([
        'status' => InstitutionStatus::Pending->value,
        'name' => 'Stale Pending Name',
        'slug' => 'stale-pending-slug',
        'last_state_change_at' => $frozen,
    ]);

    $run();

    $refreshed = Institution::query()->where('source', 'masjid-csv')->where('external_ref', '102')->firstOrFail();
    expect($refreshed->name)->toBe("Surau Al-'Aliatul")
        ->and($refreshed->slug)->toBe('surau-al-aliatul-102')
        ->and($refreshed->status)->toBe(InstitutionStatus::Pending)
        ->and($refreshed->getAttribute('last_state_change_at')->toIso8601String())->toBe($frozen->toIso8601String())
        ->and((string) $refreshed->getAttribute('verified_at'))->toBe((string) $verifiedAt);
});

it('preserves a manually written description and imported_at on pending refresh', function () {
    seedCanonicalMasjidFeedGeography();

    $run = fn () => (new MalaysiaPoskodMasjidSeeder(base_path('tests/Fixtures/masjid_feed_canonical_test_fixture.csv')))->run();
    $run();

    $target = Institution::query()->where('slug', 'surau-al-aliatul-102')->firstOrFail();
    $importedAt = (string) $target->getAttribute('imported_at');

    $target->forceFill([
        'status' => InstitutionStatus::Pending->value,
        'name' => 'Stale Pending Name',
        'description' => 'Manually written description.',
    ])->save();

    $run();

    $refreshed = Institution::query()->where('source', 'masjid-csv')->where('external_ref', '102')->firstOrFail();
    expect($refreshed->name)->toBe("Surau Al-'Aliatul")
        ->and($refreshed->slug)->toBe('surau-al-aliatul-102')
        ->and($refreshed->status)->toBe(InstitutionStatus::Pending)
        ->and($refreshed->description)->toBe('Manually written description.')
        ->and((string) $refreshed->getAttribute('imported_at'))->toBe($importedAt);
});

it('rejects late invalid rows with zero partial writes', function (string $fixture, string $expectedMessage) {
    seedCanonicalMasjidFeedGeography();

    $seeder = new MalaysiaPoskodMasjidSeeder(base_path("tests/Fixtures/{$fixture}"));

    expect(fn () => $seeder->run())->toThrow(RuntimeException::class, $expectedMessage);
    expect(Institution::query()->count())->toBe(0);
})->with([
    'late too-long name' => ['masjid_feed_late_long_name_fixture.csv', "'nama_display' exceeds 255 characters"],
    'late too-long slug' => ['masjid_feed_late_long_slug_fixture.csv', "'slug' exceeds 255 characters"],
    'late too-long source' => ['masjid_feed_late_long_source_fixture.csv', "'source' exceeds 255 characters"],
    'late unknown state' => ['masjid_feed_late_unknown_state_fixture.csv', "unknown state_code '99'"],
]);

it('persists unresolved source geography labels in address metadata without inventing assignments', function () {
    seedCanonicalMasjidFeedGeography();
    (new MalaysiaPoskodMasjidSeeder(base_path('tests/Fixtures/masjid_feed_canonical_test_fixture.csv')))->run();

    $klcc = Institution::query()->where('slug', 'masjid-klcc-104')->firstOrFail();
    expect($klcc->primaryAddress()?->areaAssignments)->toHaveCount(0)
        ->and($klcc->primaryAddress()?->metadata['feed_geography'] ?? null)->toBe(['district_name' => 'Kuala Lumpur']);

    $locale = Institution::query()->where('slug', 'masjid-unresolved-locale-106')->firstOrFail();
    $localeAssignments = $locale->primaryAddress()?->areaAssignments->pluck('address_area_id', 'role')->all() ?? [];
    expect($localeAssignments['administrative_district'] ?? null)->not()->toBeNull()
        ->and($localeAssignments['administrative_subdivision'] ?? null)->toBeNull()
        ->and($locale->primaryAddress()?->metadata['feed_geography'] ?? null)
        ->toBe(['district_name' => 'Petaling', 'subdistrict_name' => 'Bandar Khayalan']);

    $negara = Institution::query()->where('slug', 'masjid-negara-101')->firstOrFail();
    expect($negara->primaryAddress()?->metadata['feed_geography'] ?? null)->toBe([]);

    $subang = Institution::query()->where('slug', 'osm-surau-taman-subang-202')->firstOrFail();
    expect($subang->primaryAddress()?->metadata['feed_geography'] ?? null)
        ->toBe(['district_name' => 'Petaling', 'subdistrict_name' => 'Damansara', 'locality_name' => 'Subang Jaya']);
});

it('matches source identities byte-identically across reseeds', function () {
    seedCanonicalMasjidFeedGeography();

    $run = fn () => (new MalaysiaPoskodMasjidSeeder(base_path('tests/Fixtures/masjid_feed_spaced_identity_fixture.csv')))->run();
    $run();

    $institution = Institution::query()->firstOrFail();
    expect($institution->getAttribute('source'))->toBe('padded-source ')
        ->and($institution->getAttribute('external_ref'))->toBe(' P1');

    Institution::query()->whereKey($institution->getKey())->update(['status' => InstitutionStatus::Pending->value]);

    $run();

    expect(Institution::query()->count())->toBe(1)
        ->and(Institution::query()->firstOrFail()->getAttribute('source'))->toBe('padded-source ')
        ->and(Institution::query()->firstOrFail()->getAttribute('external_ref'))->toBe(' P1');
});

it('fails rather than stealing a slug owned by another identity', function () {
    seedCanonicalMasjidFeedGeography();

    $manual = Institution::factory()->create([
        'name' => 'Unrelated Hall',
        'status' => InstitutionStatus::Verified->value,
    ]);

    // Creation regenerates manual slugs from the name, so plant the
    // colliding slug with a slug-only update (no name change) on a freshly
    // re-queried instance: the just-created instance still reports
    // wasRecentlyCreated, which the observer treats as a creation and
    // regenerates the slug from the name.
    $manual = Institution::query()->whereKey($manual->getKey())->firstOrFail();
    $manual->forceFill(['slug' => 'masjid-negara-101'])->save();

    expect($manual->refresh()->slug)->toBe('masjid-negara-101')
        ->and(Institution::query()->where('slug', 'masjid-negara-101')->exists())->toBeTrue();

    $seeder = new MalaysiaPoskodMasjidSeeder(base_path('tests/Fixtures/masjid_feed_canonical_test_fixture.csv'));

    expect(fn () => $seeder->run())->toThrow(ValidationException::class);
    expect(Institution::query()->where('source', 'masjid-csv')->where('external_ref', '101')->exists())->toBeFalse();
    expect(Institution::query()->where('slug', 'masjid-negara-101')->count())->toBe(1);
    expect((string) Institution::query()->where('slug', 'masjid-negara-101')->firstOrFail()->getKey())
        ->toBe((string) $manual->getKey());
});
