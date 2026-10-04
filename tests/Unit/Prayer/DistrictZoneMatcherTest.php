<?php

use App\Services\Prayer\DistrictZoneMatcher;
use Tests\TestCase;

uses(TestCase::class);

it('matches district names to zones within a state', function (string $candidate, string $stateCode, string $zone) {
    expect(app(DistrictZoneMatcher::class)->matchZone($candidate, $stateCode))->toBe($zone);
})->with([
    'non-capital district' => ['Muar', '01', 'JHR04'],
    'qualifier prefix' => ['Daerah Muar', '01', 'JHR04'],
    'case-insensitive' => ['muar', '01', 'JHR04'],
    'capital district' => ['Johor Bahru', '01', 'JHR02'],
    'parenthetical qualifier' => ['Pokok Sena (Daerah Kecil)', '02', 'KDH01'],
    'abbreviation in zone text' => ['Sungai Siput', '08', 'PRK02'],
    'abbreviation in candidate' => ['Sg. Siput', '08', 'PRK02'],
    'island pair split on dan' => ['Pulau Pemanggil', '01', 'JHR01'],
    'mukim literal' => ['Mukim Chiku', '03', 'KTN01'],
    'mukim literal without qualifier' => ['Chiku', '03', 'KTN01'],
    'parenthetical division note' => ['Gua Musang', '03', 'KTN02'],
    'minor district qualifier' => ['Jajahan Kecil Lojing', '03', 'KTN02'],
    'kuala lumpur territory' => ['Kuala Lumpur', '14', 'WLY01'],
    'putrajaya territory' => ['Putrajaya', '16', 'WLY01'],
    'labuan territory' => ['Labuan', '15', 'WLY02'],
    // Documented: zone text lists Ipoh under PRK02 while the live GPS
    // polygon reports PRK01. Text truth applies here; the GPS memo
    // outranks this step where coordinates exist.
    'ipoh follows zone text' => ['Ipoh', '08', 'PRK02'],
    // Documented: `Bahagian Sandakan` resolves through the explicit
    // `Bandar Sandakan` listing under SBH01 — never by first-listed luck.
    'sabah division follows explicit listing' => ['Bahagian Sandakan', '12', 'SBH01'],
    'directional qualifier sandakan barat' => ['Sandakan Barat', '12', 'SBH02'],
    'directional qualifier sandakan timur' => ['Sandakan Timur', '12', 'SBH01'],
    'directional qualifier tawau barat' => ['Tawau Barat', '12', 'SBH04'],
    'directional qualifier tawau timur' => ['Tawau Timur', '12', 'SBH03'],
    'directional qualifier pendalaman atas' => ['Pendalaman Atas', '12', 'SBH08'],
    'directional qualifier pendalaman bawah' => ['Pendalaman Bawah', '12', 'SBH09'],
    'parenthesized qualifier form' => ['Bahagian Sandakan (Barat)', '12', 'SBH02'],
    'parenthetical special-zone locality' => ['Kampung Patarikan', '13', 'SWK09'],
    'parenthetical locality list member' => ['Mukim Endau', '06', 'PHG07'],
    'locality list base form' => ['Rompin', '06', 'PHG07'],
    'galas locality' => ['Galas', '03', 'KTN02'],
    'bertam locality' => ['Bertam', '03', 'KTN02'],
    'city-name candidate' => ['Shah Alam', '10', 'SGR01'],
]);

it('returns null when nothing matches', function (?string $candidate, ?string $stateCode) {
    expect(app(DistrictZoneMatcher::class)->matchZone($candidate, $stateCode))->toBeNull();
})->with([
    'unknown district' => ['Atlantis', '01'],
    'blank candidate' => ['', '01'],
    'null candidate' => [null, '01'],
    'qualifiers only' => ['Daerah Kecil', '01'],
    'right district wrong state' => ['Muar', '02'],
    'whole-state listing never matches' => ['Melaka Tengah', '04'],
]);

it('matches unique districts without a state scope', function () {
    expect(app(DistrictZoneMatcher::class)->matchZone('Johor Bahru'))->toBe('JHR02');
});

it('rejects ambiguous matches without guessing', function () {
    // Bare directional fragments match several zones: never guessed.
    expect(app(DistrictZoneMatcher::class)->matchZone('Barat'))->toBeNull()
        ->and(app(DistrictZoneMatcher::class)->matchZone('Barat', '12'))->toBeNull()
        ->and(app(DistrictZoneMatcher::class)->matchZone('Timur'))->toBeNull();
});

it('resolves sandakan through its explicit listing at any scope', function () {
    expect(app(DistrictZoneMatcher::class)->matchZone('Sandakan'))->toBe('SBH01')
        ->and(app(DistrictZoneMatcher::class)->matchZone('Bahagian Sandakan'))->toBe('SBH01');
});

it('slots in another country through injected snapshots', function () {
    $matcher = new DistrictZoneMatcher(
        zones: [
            'XX01' => ['state' => 'Northland', 'districts' => 'Alpha, Fort James'],
            'XX02' => ['state' => 'Southland', 'districts' => 'Gamma'],
        ],
        stateKeys: ['Northland' => 'N', 'Southland' => 'S'],
        scopeMap: [],
        qualifiers: ['county'],
        aliases: ['ft' => 'fort'],
    );

    expect($matcher->matchZone('Alpha', 'N'))->toBe('XX01')
        ->and($matcher->matchZone('County Alpha', 'N'))->toBe('XX01')
        ->and($matcher->matchZone('Ft. James', 'N'))->toBe('XX01')
        ->and($matcher->matchZone('Alpha', 'S'))->toBeNull()
        ->and($matcher->matchZone('Gamma'))->toBe('XX02');
});
