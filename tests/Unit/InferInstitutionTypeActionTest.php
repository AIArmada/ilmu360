<?php

use App\Actions\Institutions\InferInstitutionTypeAction;
use App\Enums\InstitutionType;

it('maps supported Google place types to institution categories', function (string $googleType, InstitutionType $expected): void {
    expect(InferInstitutionTypeAction::run(['primaryType' => $googleType]))->toBe($expected);
})->with([
    'mosque' => ['mosque', InstitutionType::Masjid],
    'school' => ['secondary_school', InstitutionType::Sekolah],
    'university' => ['university', InstitutionType::Universiti],
]);

it('prefers a specific institution name cue over a generic Google mosque category', function (): void {
    expect(InferInstitutionTypeAction::run([
        'displayName' => ['text' => 'Surau Al-Hidayah'],
        'primaryType' => 'mosque',
    ]))->toBe(InstitutionType::Surau);
});

it('keeps a recognized primary institution category when secondary Google types are broad or mixed', function (): void {
    expect(InferInstitutionTypeAction::run([
        'displayName' => ['text' => 'Sultan Salahuddin Shah'],
        'primaryType' => 'mosque',
        'types' => ['mosque', 'tourist_attraction', 'place_of_worship', 'establishment'],
    ]))->toBe(InstitutionType::Masjid);
});

it('keeps a primary mosque category when the name also contains a university cue', function (): void {
    expect(InferInstitutionTypeAction::run([
        'displayName' => ['text' => 'Masjid Universiti Putra Malaysia'],
        'primaryType' => 'mosque',
        'types' => ['mosque', 'tourist_attraction', 'place_of_worship', 'establishment'],
    ]))->toBe(InstitutionType::Masjid);
});

it('recognizes supported institution names with word boundaries', function (string $name, InstitutionType $expected): void {
    expect(InferInstitutionTypeAction::run(['displayName' => ['text' => $name]]))->toBe($expected);
})->with([
    'madrasah' => ['Madrasah Al-Falah', InstitutionType::Madrasah],
    'pondok' => ['Pondok Tahfiz Darul Quran', InstitutionType::Pondok],
    'kolej' => ['Kolej Komuniti Shah Alam', InstitutionType::Kolej],
]);

it('does not infer from institution-like words when Google identifies another business category', function (): void {
    expect(InferInstitutionTypeAction::run([
        'displayName' => ['text' => 'Pondok Selera'],
        'primaryType' => 'restaurant',
        'types' => ['restaurant', 'food', 'establishment'],
    ]))->toBeNull();
});

it('leaves unknown Google places unclassified', function (): void {
    expect(InferInstitutionTypeAction::run([
        'displayName' => ['text' => 'Community Hall'],
        'primaryType' => 'community_center',
        'types' => ['point_of_interest', 'establishment'],
    ]))->toBeNull();
});
