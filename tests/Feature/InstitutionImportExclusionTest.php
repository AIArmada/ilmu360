<?php

use App\Enums\InstitutionStatus;
use App\Models\Institution;
use App\Models\InstitutionImportExclusion;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('records source-backed deletions as permanent exclusions', function () {
    $institution = Institution::factory()->create(['status' => InstitutionStatus::Verified->value]);

    Institution::query()->whereKey($institution->getKey())->update([
        'source' => 'masjid-csv',
        'external_ref' => '101',
    ]);

    $institutionId = (string) $institution->getKey();
    $institution->refresh()->delete();

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
