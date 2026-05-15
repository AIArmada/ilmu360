<?php

use App\Models\Institution;
use App\Support\Institutions\GeneratedPoskodInstitutionData;
use Database\Seeders\GeneratedFileFinalFixedPoskodSeeder;
use Database\Seeders\ProductionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('imports the postcode csv against the production geography seed', function () {
    $this->seed(ProductionSeeder::class);
    $this->seed(GeneratedFileFinalFixedPoskodSeeder::class);

    $postcodeSlugs = GeneratedPoskodInstitutionData::allCanonicalSlugs();

    $expectedInstitutionCount = count($postcodeSlugs);
    $postcodeInstitutions = fn () => Institution::query()->whereIn('slug', $postcodeSlugs);
    $findInstitution = fn (string $slug): ?Institution => Institution::query()
        ->where('slug', $slug)
        ->with(['address.state', 'address.district', 'address.subdistrict'])
        ->first();

    expect($expectedInstitutionCount)->toBe(6935)
        ->and($postcodeInstitutions()->count())->toBe($expectedInstitutionCount)
        ->and($postcodeInstitutions()->whereHas('address')->count())->toBe($expectedInstitutionCount)
        ->and($postcodeInstitutions()->whereHas('address', fn ($query) => $query->whereNull('district_id'))->count())->toBeGreaterThan(1);

    $menora = $findInstitution(GeneratedPoskodInstitutionData::canonicalSlug('MASJID AL - MUNARIAH', '500'));
    expect($menora)->not()->toBeNull();
    expect($menora?->address?->state?->name)->toBe('Perak');
    expect($menora?->address?->district?->name)->toBe('Kuala Kangsar');

    $tekam = $findInstitution(GeneratedPoskodInstitutionData::canonicalSlug('MASJID RIDZUANIAH FELDA SG TEKAM GETAH', '1880'));
    expect($tekam)->not()->toBeNull();
    expect($tekam?->address?->district?->name)->toBe('Jerantut');
    expect($tekam?->address?->subdistrict?->name)->toBe('Bandar Pusat Jengka');

    $jengka = $findInstitution(GeneratedPoskodInstitutionData::canonicalSlug('MASJID ARRAHMANIAH FELDA JENGKA 17', '1882'));
    expect($jengka)->not()->toBeNull();
    expect($jengka?->address?->district?->name)->toBe('Maran');
    expect($jengka?->address?->subdistrict?->name)->toBe('Bandar Tun Abdul Razak');

    $pusa = $findInstitution(GeneratedPoskodInstitutionData::canonicalSlug('MASJID RAHMANIAH,', '4437'));
    expect($pusa)->not()->toBeNull();
    expect($pusa?->address?->district?->name)->toBe('Betong');
    expect($pusa?->address?->subdistrict?->name)->toBe('Pusa');

    $maludam = $findInstitution(GeneratedPoskodInstitutionData::canonicalSlug('MASJID DARUL MUALIMIN MALUDAM', '4448'));
    expect($maludam)->not()->toBeNull();
    expect($maludam?->address?->district?->name)->toBe('Betong');
    expect($maludam?->address?->subdistrict?->name)->toBe('Maludam');

    $padangRengas = $findInstitution(GeneratedPoskodInstitutionData::canonicalSlug('masjid al hadri', '6091'));
    expect($padangRengas)->not()->toBeNull();
    expect($padangRengas?->address?->district?->name)->toBe('Kuala Kangsar');
    expect($padangRengas?->address?->subdistrict?->name)->toBe('Padang Rengas');

    $ajil = $findInstitution(GeneratedPoskodInstitutionData::canonicalSlug('MASJID AJIL', '28'));
    expect($ajil)->not()->toBeNull();
    expect($ajil?->name)->toBe('Masjid Ajil');
    expect($ajil?->address?->line1)->toBe('Ajil, Hulu Terengganu');
    expect($ajil?->address?->district?->name)->toBe('Hulu Terengganu');
    expect($ajil?->address?->subdistrict?->name)->toBe('Ajil');

    $temerloh = $findInstitution(GeneratedPoskodInstitutionData::canonicalSlug('MASJID ABU BAKAR TEMERLOH', '106'));
    expect($temerloh)->not()->toBeNull();
    expect($temerloh?->name)->toBe('Masjid Abu Bakar Temerloh');
    expect($temerloh?->address?->line1)->toBe('Bandar Temerloh');

    $bracketedName = $findInstitution(GeneratedPoskodInstitutionData::canonicalSlug('[01] MASJID KAMPUNG BUKIT LADA', '5667'));
    expect($bracketedName)->not()->toBeNull();
    expect($bracketedName?->name)->toBe('Masjid Kampung Bukit Lada');

    $estateName = $findInstitution(GeneratedPoskodInstitutionData::canonicalSlug('(ESTATE) MASJID AL-MUHAJIRIN', '6412'));
    expect($estateName)->not()->toBeNull();
    expect($estateName?->name)->toBe('Masjid Al-Muhajirin (ESTATE)');

    $junkSarawak = $findInstitution(GeneratedPoskodInstitutionData::canonicalSlug('masjid nurulllllllllllll', '6082'));
    expect($junkSarawak)->not()->toBeNull();
    expect($junkSarawak?->slug)->toBe('masjid-nurulllllllllllll-6082');
    expect($junkSarawak)->not()->toBeNull();
    expect($junkSarawak?->address?->state?->name)->toBe('Sarawak');
    expect($junkSarawak?->address?->district)->toBeNull();
    expect($junkSarawak?->address?->subdistrict)->toBeNull();

    $federalTerritoryInstitutionCount = Institution::query()
        ->whereIn('slug', $postcodeSlugs)
        ->whereHas('address.state', fn ($query) => $query->whereIn('name', ['Kuala Lumpur', 'Putrajaya', 'Labuan']))
        ->count();

    $federalTerritoryInstitutionsWithDistrictCount = Institution::query()
        ->whereIn('slug', $postcodeSlugs)
        ->whereHas('address.state', fn ($query) => $query->whereIn('name', ['Kuala Lumpur', 'Putrajaya', 'Labuan']))
        ->whereHas('address', fn ($query) => $query->whereNotNull('district_id'))
        ->count();

    expect($federalTerritoryInstitutionCount)->toBeGreaterThan(0)
        ->and($federalTerritoryInstitutionsWithDistrictCount)->toBe(0);

    $keladi = $findInstitution(GeneratedPoskodInstitutionData::canonicalSlug('ABDUL RAHMAN PUTRA KARIAH KELADI', '6809'));
    expect($keladi)->not()->toBeNull();
    expect($keladi?->slug)->toBe('abdul-rahman-putra-kariah-keladi-6809');
});
