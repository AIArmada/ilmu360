<?php

use App\Enums\InstitutionType;
use App\Models\Institution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('generates a type-matching name when built with ofType', function (InstitutionType $type, array $prefixes) {
    $institution = withGlobalOwnerContext(fn () => Institution::factory()->ofType($type)->create());

    expect($institution->type)->toBe($type)
        ->and(Str::startsWith($institution->name, $prefixes))->toBeTrue()
        ->and($institution->slug)->toStartWith(Str::slug($institution->name));
})->with([
    'masjid' => [InstitutionType::Masjid, ['Masjid']],
    'surau' => [InstitutionType::Surau, ['Surau']],
    'madrasah' => [InstitutionType::Madrasah, ['Pusat Islam', 'Madrasah', 'Maahad Tahfiz', 'Kompleks Islam', 'Markaz Tarbiah', 'Akademi Tahfiz']],
]);

it('accepts a raw string type in ofType', function () {
    $institution = withGlobalOwnerContext(fn () => Institution::factory()->ofType('surau')->create());

    expect($institution->type)->toBe(InstitutionType::Surau)
        ->and($institution->name)->toStartWith('Surau');
});
