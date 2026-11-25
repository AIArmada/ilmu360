<?php

declare(strict_types=1);

use App\Features\Events\Api\EventInvolvementFingerprint;
use App\Features\Institutions\Api\InstitutionListingCacheVersion;
use App\Features\Persons\Api\PersonListingCacheVersion;
use App\Features\References\Api\ReferenceListingCacheVersion;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

pest()->extend(TestCase::class);

it('bumps owner listing versions independently', function (): void {
    // This unit test only exercises independent cache bumps; the Events
    // fingerprint integration is exercised separately with its table below.
    app()->instance(EventInvolvementFingerprint::class, new class implements EventInvolvementFingerprint
    {
        public function current(): string
        {
            return '0|';
        }
    });

    $institutions = app(InstitutionListingCacheVersion::class);
    $persons = app(PersonListingCacheVersion::class);
    $references = app(ReferenceListingCacheVersion::class);
    $institutionBefore = $institutions->current()['version'];
    $personBefore = $persons->current()['version'];
    $referenceBefore = $references->current()['version'];

    $institutions->bump();
    expect($institutions->current()['version'])->not->toBe($institutionBefore)
        ->and($persons->current()['version'])->toBe($personBefore)
        ->and($references->current()['version'])->toBe($referenceBefore);

    $persons->bump();
    expect($persons->current()['version'])->not->toBe($personBefore)
        ->and($references->current()['version'])->toBe($referenceBefore);

    $references->bump();
    expect($references->current()['version'])->not->toBe($referenceBefore);
});

it('uses configured discovery cache version keys', function (): void {
    app(InstitutionListingCacheVersion::class)->bump();
    app(PersonListingCacheVersion::class)->bump();
    app(ReferenceListingCacheVersion::class)->bump();

    expect(Cache::get((string) config('discovery.cache.versions.institutions_listing')))->toBeString()
        ->and(Cache::get((string) config('discovery.cache.versions.persons_listing')))->toBeString()
        ->and(Cache::get((string) config('discovery.cache.versions.references_listing')))->toBeString();
});

it('composes the person version with the Events-owned involvement fingerprint', function (): void {
    $versions = app(PersonListingCacheVersion::class);
    $originalTable = config('events.database.tables.event_involvements', 'event_involvements');
    $table = 'test_event_involvements_fingerprint';
    config()->set('events.database.tables.event_involvements', $table);

    Schema::create($table, function (Blueprint $blueprint): void {
        $blueprint->uuid('id')->primary();
        $blueprint->timestampTz('updated_at')->nullable();
    });

    try {
        $emptyPersonVersion = $versions->current()['version'];
        $baseVersion = Cache::get((string) config('discovery.cache.versions.persons_listing'));

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
        config()->set('events.database.tables.event_involvements', $originalTable);
    }
});
