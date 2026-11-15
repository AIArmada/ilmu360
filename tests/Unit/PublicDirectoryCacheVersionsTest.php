<?php

declare(strict_types=1);

use App\Features\Directory\Api\PublicDirectoryCacheVersions;
use App\Features\Events\Api\EventInvolvementFingerprint;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

it('bumps the directory version for configured media morph types', function (string $modelType, string $versionKey): void {
    $versions = app(PublicDirectoryCacheVersions::class);
    $before = Cache::get($versionKey);

    $versions->bumpForMediaType($modelType);

    expect(Cache::get($versionKey))->toBeString()->not->toBe($before);
})->with([
    'institution alias' => ['institution', 'public_directory:institutions:version:v1'],
    'person alias' => ['person', 'public_directory:persons:version:v1'],
]);

it('does not bump directory versions for unrelated media types', function (): void {
    app(PublicDirectoryCacheVersions::class)->bumpForMediaType('event');

    expect(Cache::has('public_directory:institutions:version:v1'))->toBeFalse()
        ->and(Cache::has('public_directory:persons:version:v1'))->toBeFalse();
});

it('bumps all matching directory versions for addressable morph types', function (): void {
    app(PublicDirectoryCacheVersions::class)->bumpForAddressableTypes(['institution', 'person', 'event']);

    expect(Cache::get('public_directory:institutions:version:v1'))->toBeString()
        ->and(Cache::get('public_directory:persons:version:v1'))->toBeString();
});

it('composes the person version with the Events-owned involvement fingerprint', function (): void {
    $versions = app(PublicDirectoryCacheVersions::class);
    $table = (string) config('events.database.tables.event_involvements', 'event_involvements');

    Schema::create($table, function (Blueprint $blueprint): void {
        $blueprint->uuid('id')->primary();
        $blueprint->timestampTz('updated_at')->nullable();
    });

    try {
        $emptyPersonVersion = $versions->person()['version'];
        $baseVersion = Cache::get('public_directory:persons:version:v1');

        expect($emptyPersonVersion)->toBe($baseVersion.'|0|');

        $latestUpdate = '2026-11-14 12:00:00';
        DB::table($table)->insert([
            'id' => (string) Str::uuid(),
            'updated_at' => $latestUpdate,
        ]);

        $latestUpdate = (string) DB::table($table)->max('updated_at');
        $fingerprint = app(EventInvolvementFingerprint::class)->current();
        $personVersion = $versions->person()['version'];

        expect($fingerprint)->toBe('1|'.$latestUpdate)
            ->and($personVersion)->toBe($baseVersion.'|1|'.$latestUpdate);
    } finally {
        Schema::dropIfExists($table);
    }
});
