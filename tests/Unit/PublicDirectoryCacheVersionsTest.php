<?php

declare(strict_types=1);

use App\Features\Institutions\Api\InstitutionListingCacheVersion;
use App\Features\Persons\Api\PersonListingCacheVersion;
use App\Features\Events\Api\EventInvolvementFingerprint;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

it('bumps owner listing versions independently', function (): void {
    $institutions = app(InstitutionListingCacheVersion::class);
    $persons = app(PersonListingCacheVersion::class);
    $institutionBefore = $institutions->current()['version'];
    $personBefore = $persons->current()['version'];

    $institutions->bump();
    expect($institutions->current()['version'])->not->toBe($institutionBefore)
        ->and($persons->current()['version'])->toBe($personBefore);

    $persons->bump();
    expect($persons->current()['version'])->not->toBe($personBefore);
});

it('uses unchanged persistent version keys', function (): void {
    app(InstitutionListingCacheVersion::class)->bump();
    app(PersonListingCacheVersion::class)->bump();

    expect(Cache::get('public_directory:institutions:version:v1'))->toBeString()
        ->and(Cache::get('public_directory:persons:version:v1'))->toBeString();
});

it('composes the person version with the Events-owned involvement fingerprint', function (): void {
    $versions = app(PersonListingCacheVersion::class);
    $table = (string) config('events.database.tables.event_involvements', 'event_involvements');

    Schema::create($table, function (Blueprint $blueprint): void {
        $blueprint->uuid('id')->primary();
        $blueprint->timestampTz('updated_at')->nullable();
    });

    try {
        $emptyPersonVersion = $versions->current()['version'];
        $baseVersion = Cache::get('public_directory:persons:version:v1');

        expect($emptyPersonVersion)->toBe($baseVersion.'|0|');

        $latestUpdate = '2026-11-14 12:00:00';
        DB::table($table)->insert([
            'id' => (string) Str::uuid(),
            'updated_at' => $latestUpdate,
        ]);

        $latestUpdate = (string) DB::table($table)->max('updated_at');
        $fingerprint = app(EventInvolvementFingerprint::class)->current();
        $personVersion = $versions->current()['version'];

        expect($fingerprint)->toBe('1|'.$latestUpdate)
            ->and($personVersion)->toBe($baseVersion.'|1|'.$latestUpdate);
    } finally {
        Schema::dropIfExists($table);
    }
});
