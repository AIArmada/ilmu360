<?php

declare(strict_types=1);

use App\Features\Events\Api\EventCollections;
use App\Features\Events\Api\EventDirectoryProjectionProvider;
use App\Features\Events\Api\EventDiscoveryReader;
use App\Features\Events\Api\EventScheduleReads;
use App\Features\Events\Models\Event;
use App\Features\Events\Queries\Postgres\EventFilterQueryBuilder;
use App\Features\Events\Queries\Postgres\EventHomepageAndCollectionQueries;
use App\Features\Events\Queries\Postgres\EventScheduleHydrator;
use App\Features\Events\Queries\Postgres\EventScheduleOccurrencePaginator;
use App\Features\Events\Queries\PostgresEventDiscovery;
use App\Features\Institutions\Models\Institution;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

it('runs discovery search performance index migration idempotently and registers expected indexes', function (): void {
    $migration = require database_path('migrations/2026_11_25_000001_add_discovery_search_performance_indexes.php');
    $migration->up();

    $involvementsTable = (string) config('events.database.tables.event_involvements', 'event_involvements');
    $timeExpressionsTable = (string) config('events.database.tables.event_time_expressions', 'event_time_expressions');

    $involvementIndexes = collect(Schema::getIndexes($involvementsTable));
    $timeExpressionIndexes = collect(Schema::getIndexes($timeExpressionsTable));

    expect($involvementIndexes->contains(static fn (array $index): bool => ($index['name'] ?? null) === 'event_involvements_updated_at_idx'
        && ($index['columns'] ?? null) === ['updated_at']))->toBeTrue()
        ->and($timeExpressionIndexes->contains(static fn (array $index): bool => ($index['name'] ?? null) === 'event_time_expressions_event_anchor_type_idx'
            && ($index['columns'] ?? null) === ['event_id', 'anchor_type']))->toBeTrue()
        ->and($timeExpressionIndexes->contains(static fn (array $index): bool => ($index['name'] ?? null) === 'event_time_expressions_occ_anchor_type_idx'
            && ($index['columns'] ?? null) === ['event_occurrence_id', 'anchor_type']))->toBeTrue();
});

it('wires PostgresEventDiscovery as the canonical facade across all discovery, schedule, directory, and collection contracts', function (): void {
    $discovery = app(PostgresEventDiscovery::class);

    expect(app(EventDiscoveryReader::class))->toBeInstanceOf(PostgresEventDiscovery::class)
        ->and(app(EventScheduleReads::class))->toBeInstanceOf(PostgresEventDiscovery::class)
        ->and(app(EventDirectoryProjectionProvider::class))->toBeInstanceOf(PostgresEventDiscovery::class)
        ->and(app(EventCollections::class))->toBeInstanceOf(PostgresEventDiscovery::class)
        ->and(app(EventFilterQueryBuilder::class))->toBeInstanceOf(EventFilterQueryBuilder::class)
        ->and(app(EventScheduleOccurrencePaginator::class))->toBeInstanceOf(EventScheduleOccurrencePaginator::class)
        ->and(app(EventHomepageAndCollectionQueries::class))->toBeInstanceOf(EventHomepageAndCollectionQueries::class)
        ->and(app(EventScheduleHydrator::class))->toBeInstanceOf(EventScheduleHydrator::class)
        ->and($discovery)->toBeInstanceOf(EventCollections::class);
});

it('delegates EventCollections reads through PostgresEventDiscovery and EventHomepageAndCollectionQueries', function (): void {
    $institution = Institution::factory()->create(['status' => 'verified']);
    $event = Event::factory()->create([
        'institution_id' => $institution->getKey(),
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHour(),
    ]);

    $result = app(EventCollections::class)->forInstitution(
        institutionId: (string) $institution->getKey(),
        past: false,
        perPage: 10,
    );

    expect($result['total'])->toBe(1)
        ->and(collect($result['items'])->pluck('id')->all())->toBe([(string) $event->getKey()]);
});

it('runs the postgresql extensions migration safely on sqlite without executing pgsql statements', function (): void {
    $migration = require database_path('migrations/0001_01_00_000000_enable_postgresql_extensions.php');
    $migration->up();

    expect(true)->toBeTrue();
});

it('creates all postgresql search and addressing extensions when pretending or running as superuser', function (): void {
    $migration = require database_path('migrations/0001_01_00_000000_enable_postgresql_extensions.php');

    $pretendStatements = [];
    $pretendConnection = Mockery::mock(Connection::class);
    $pretendConnection->shouldReceive('getDriverName')->andReturn('pgsql');
    $pretendConnection->shouldReceive('pretending')->andReturnTrue();
    $pretendConnection->shouldReceive('statement')->andReturnUsing(static function (string $sql) use (&$pretendStatements): bool {
        $pretendStatements[] = $sql;

        return true;
    });

    $migration->enableExtensions($pretendConnection);

    expect($pretendStatements)->toBe([
        'CREATE EXTENSION IF NOT EXISTS "pg_trgm"',
        'CREATE EXTENSION IF NOT EXISTS "unaccent"',
        'CREATE EXTENSION IF NOT EXISTS "btree_gin"',
        'CREATE EXTENSION IF NOT EXISTS "btree_gist"',
        'CREATE EXTENSION IF NOT EXISTS "postgis"',
        'CREATE EXTENSION IF NOT EXISTS "h3"',
        'CREATE EXTENSION IF NOT EXISTS "h3_postgis"',
    ]);

    $superStatements = [];
    $superConnection = Mockery::mock(Connection::class);
    $superConnection->shouldReceive('getDriverName')->andReturn('pgsql');
    $superConnection->shouldReceive('pretending')->andReturnFalse();
    $superConnection->shouldReceive('select')->andReturnUsing(static function (string $sql): array {
        if (str_contains($sql, 'FROM pg_extension')) {
            return [(object) ['extname' => 'plpgsql']];
        }

        return [
            (object) ['name' => 'pg_trgm', 'trusted' => true],
            (object) ['name' => 'unaccent', 'trusted' => true],
            (object) ['name' => 'btree_gin', 'trusted' => true],
            (object) ['name' => 'btree_gist', 'trusted' => true],
            (object) ['name' => 'postgis', 'trusted' => false],
            (object) ['name' => 'h3', 'trusted' => false],
            (object) ['name' => 'h3_postgis', 'trusted' => false],
        ];
    });
    $superConnection->shouldReceive('scalar')->andReturn(true);
    $superConnection->shouldReceive('statement')->andReturnUsing(static function (string $sql) use (&$superStatements): bool {
        $superStatements[] = $sql;

        return true;
    });

    $migration->enableExtensions($superConnection);

    expect($superStatements)->toBe([
        'CREATE EXTENSION IF NOT EXISTS "pg_trgm"',
        'CREATE EXTENSION IF NOT EXISTS "unaccent"',
        'CREATE EXTENSION IF NOT EXISTS "btree_gin"',
        'CREATE EXTENSION IF NOT EXISTS "btree_gist"',
        'CREATE EXTENSION IF NOT EXISTS "postgis"',
        'CREATE EXTENSION IF NOT EXISTS "h3"',
        'CREATE EXTENSION IF NOT EXISTS "h3_postgis"',
    ]);
});

it('creates trusted extensions as non-superuser, warns on disabled untrusted extensions, and throws when required spatial extension lacks privileges', function (): void {
    $migration = require database_path('migrations/0001_01_00_000000_enable_postgresql_extensions.php');

    config()->set('addressing.features.spatial.postgis', false);
    config()->set('addressing.features.spatial.geohash', false);
    config()->set('addressing.features.spatial.h3', false);

    $statements = [];
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->andReturn('pgsql');
    $connection->shouldReceive('pretending')->andReturnFalse();
    $connection->shouldReceive('select')->andReturnUsing(static function (string $sql): array {
        if (str_contains($sql, 'FROM pg_extension')) {
            return [(object) ['extname' => 'pg_trgm']];
        }

        return [
            (object) ['name' => 'pg_trgm', 'trusted' => true],
            (object) ['name' => 'unaccent', 'trusted' => true],
            (object) ['name' => 'btree_gin', 'trusted' => true],
            (object) ['name' => 'btree_gist', 'trusted' => true],
            (object) ['name' => 'postgis', 'trusted' => false],
            (object) ['name' => 'h3', 'trusted' => false],
            (object) ['name' => 'h3_postgis', 'trusted' => false],
        ];
    });
    $connection->shouldReceive('scalar')->andReturn(false);
    $connection->shouldReceive('statement')->andReturnUsing(static function (string $sql) use (&$statements): bool {
        $statements[] = $sql;

        return true;
    });

    Log::shouldReceive('warning')->times(3);

    $migration->enableExtensions($connection);

    expect($statements)->toBe([
        'CREATE EXTENSION IF NOT EXISTS "unaccent"',
        'CREATE EXTENSION IF NOT EXISTS "btree_gin"',
        'CREATE EXTENSION IF NOT EXISTS "btree_gist"',
    ]);

    config()->set('addressing.features.spatial.postgis', true);

    expect(static fn () => $migration->enableExtensions($connection))
        ->toThrow(RuntimeException::class, 'CREATE EXTENSION IF NOT EXISTS "postgis";');
});
