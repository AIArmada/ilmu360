<?php

declare(strict_types=1);

use AIArmada\Addressing\Support\AddressingTableResolver;
use AIArmada\Addressing\Support\GeoDistance;
use App\Features\Events\Models\Event;
use App\Features\Institutions\Models\Institution;
use App\Platform\Location\SpatialAddressQuery;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Database\Query\Processors\PostgresProcessor;
use Illuminate\Database\SQLiteConnection;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

pest()->extend(TestCase::class);

/**
 * @template TModel of Model
 *
 * @param  class-string<TModel>  $modelClass
 * @return EloquentBuilder<TModel>
 */
function makePostgresEloquentBuilder(string $modelClass): EloquentBuilder
{
    $mockConnection = Mockery::mock(Connection::class);
    $mockConnection->shouldReceive('getDriverName')->andReturn('pgsql');
    $mockConnection->shouldReceive('getTablePrefix')->andReturn('');
    $mockConnection->shouldReceive('getQueryGrammar')->andReturn(new PostgresGrammar($mockConnection));
    $mockConnection->shouldReceive('getPostProcessor')->andReturn(new PostgresProcessor);
    $mockConnection->shouldReceive('query')->andReturnUsing(
        static fn (): QueryBuilder => new QueryBuilder(
            $mockConnection,
            new PostgresGrammar($mockConnection),
            new PostgresProcessor,
        ),
    );

    /** @var EloquentBuilder<TModel> $builder */
    $builder = new EloquentBuilder(
        new QueryBuilder(
            $mockConnection,
            new PostgresGrammar($mockConnection),
            new PostgresProcessor,
        ),
    );
    $builder->setModel(new $modelClass);

    return $builder;
}

it('parses valid WGS 84 bounding boxes and rejects malformed or out-of-range inputs', function (): void {
    $spatial = new SpatialAddressQuery;

    expect($spatial->parseBoundingBox('101.5, 3.0, 101.8, 3.3'))->toBe([
        'west' => 101.5,
        'south' => 3.0,
        'east' => 101.8,
        'north' => 3.3,
    ])
        ->and($spatial->parseBoundingBox(['west' => '101.5', 'south' => 3, 'east' => 101.8, 'north' => 3.3]))->toBe([
            'west' => 101.5,
            'south' => 3.0,
            'east' => 101.8,
            'north' => 3.3,
        ])
        ->and($spatial->parseBoundingBox([101.5, 3.0, 101.8, 3.3]))->toBe([
            'west' => 101.5,
            'south' => 3.0,
            'east' => 101.8,
            'north' => 3.3,
        ])
        ->and($spatial->parseBoundingBox('101.8,3.0,101.5,3.3'))->toBeNull()
        ->and($spatial->parseBoundingBox('101.5,3.5,101.8,3.0'))->toBeNull()
        ->and($spatial->parseBoundingBox('101.5,95.0,101.8,96.0'))->toBeNull()
        ->and($spatial->parseBoundingBox('-185.0,3.0,101.8,3.3'))->toBeNull()
        ->and($spatial->parseBoundingBox('101.5,3.0,101.8'))->toBeNull()
        ->and($spatial->parseBoundingBox('invalid'))->toBeNull()
        ->and($spatial->parseBoundingBox(null))->toBeNull();
});

it('normalizes valid WKT POLYGON and MULTIPOLYGON strings and rejects invalid geometries', function (): void {
    $spatial = new SpatialAddressQuery;
    $polygon = '  POLYGON((101.6 3.0, 101.8 3.0, 101.8 3.2, 101.6 3.2, 101.6 3.0))  ';
    $multiPolygon = 'multipolygon(((101.6 3.0, 101.8 3.0, 101.8 3.2, 101.6 3.2, 101.6 3.0)))';

    expect($spatial->normalizePolygonWkt($polygon))
        ->toBe('POLYGON((101.6 3.0, 101.8 3.0, 101.8 3.2, 101.6 3.2, 101.6 3.0))')
        ->and($spatial->normalizePolygonWkt($multiPolygon))->toBe($multiPolygon)
        ->and($spatial->normalizePolygonWkt('POLYGON((101.6 3.0, 101.8 3.0)'))->toBeNull()
        ->and($spatial->normalizePolygonWkt('POINT(101.6 3.1)'))->toBeNull()
        ->and($spatial->normalizePolygonWkt(''))->toBeNull()
        ->and($spatial->normalizePolygonWkt(123))->toBeNull();
});

it('builds PostGIS ST_DWithin and ST_Distance SQL for primary address nearby and distance queries on pgsql', function (): void {
    config()->set('addressing.features.spatial.postgis', true);
    $spatial = new SpatialAddressQuery;

    $nearbyQuery = makePostgresEloquentBuilder(Institution::class);
    $spatial->applyPrimaryAddressNearby(
        $nearbyQuery,
        'institutions',
        'id',
        'institution',
        3.1390,
        101.6869,
        15,
        'inst',
        ['institutions.*'],
    );

    $nearbySql = $nearbyQuery->toSql();
    expect($nearbySql)->toContain('ST_DWithin(inst_addresses.location, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)')
        ->and($nearbySql)->toContain('(ST_Distance(inst_addresses.location, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) / 1000.0) as distance_km')
        ->and($nearbySql)->toContain('order by "distance_km" asc')
        ->and($nearbyQuery->getBindings())->toBe([
            101.6869,
            3.1390,
            'institution',
            true,
            101.6869,
            3.1390,
            15000.0,
        ]);

    $distanceQuery = makePostgresEloquentBuilder(Institution::class);
    $spatial->selectPrimaryAddressDistance(
        $distanceQuery,
        'institutions',
        'id',
        'institution',
        3.1390,
        101.6869,
        'dist',
        ['institutions.*'],
    );

    $distanceSql = $distanceQuery->toSql();
    expect($distanceSql)->toContain('left join')
        ->and($distanceSql)->toContain('case when dist_addresses.location is not null then (ST_Distance(dist_addresses.location, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) / 1000.0) end as distance_km')
        ->and($distanceSql)->toContain('order by case when dist_addresses.location is null then 1 else 0 end asc, "distance_km" asc')
        ->and($distanceQuery->getBindings())->toBe([
            101.6869,
            3.1390,
            'institution',
            true,
        ]);
});

it('builds PostGIS ST_Intersects SQL for primary address bounds and polygon queries on pgsql', function (): void {
    config()->set('addressing.features.spatial.postgis', true);
    $spatial = new SpatialAddressQuery;
    $addressesTable = AddressingTableResolver::resolve('addresses');

    $boundsQuery = makePostgresEloquentBuilder(Institution::class);
    $spatial->applyPrimaryAddressWithinBounds($boundsQuery, 'institutions', 'id', 'institution', 101.5, 3.0, 101.8, 3.3);

    expect($boundsQuery->toSql())->toContain("ST_Intersects({$addressesTable}.location, ST_MakeEnvelope(?, ?, ?, ?, 4326)::geography)")
        ->and($boundsQuery->getBindings())->toBe(['institution', true, 101.5, 3.0, 101.8, 3.3]);

    $wkt = 'POLYGON((101.5 3.0, 101.8 3.0, 101.8 3.3, 101.5 3.3, 101.5 3.0))';
    $polygonQuery = makePostgresEloquentBuilder(Institution::class);
    $spatial->applyPrimaryAddressWithinPolygon($polygonQuery, 'institutions', 'id', 'institution', $wkt);

    expect($polygonQuery->toSql())->toContain("ST_Intersects({$addressesTable}.location, ST_GeomFromText(?, 4326)::geography)")
        ->and($polygonQuery->getBindings())->toBe(['institution', true, $wkt]);
});

it('builds PostGIS dual-owner ST_DWithin, ST_MakeEnvelope, and ST_GeomFromText SQL for events on pgsql', function (): void {
    config()->set('addressing.features.spatial.postgis', true);
    $spatial = new SpatialAddressQuery;
    $effectiveVenue = 'coalesce(events.default_venue_id, (select el.venue_id from event_locations as el))';
    $venueExists = "exists (select 1 from venues where venues.id = ({$effectiveVenue}))";

    $nearbyEvents = makePostgresEloquentBuilder(Event::class);
    $spatial->applyEventNearby(
        $nearbyEvents,
        3.1390,
        101.6869,
        20,
        'events',
        $effectiveVenue,
        $venueExists,
        'venue',
        'institution',
        ['events.*'],
    );

    $nearbySql = $nearbyEvents->toSql();
    expect($nearbySql)->toContain('ST_DWithin(institution_addresses.location, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)')
        ->and($nearbySql)->toContain('ST_DWithin(venue_addresses.location, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)')
        ->and($nearbySql)->toContain('ST_Distance(')
        ->and($nearbyEvents->getBindings())->toBe([
            101.6869,
            3.1390,
            'venue',
            true,
            'institution',
            true,
            101.6869,
            3.1390,
            20000.0,
            101.6869,
            3.1390,
            20000.0,
        ]);

    $boundsEvents = makePostgresEloquentBuilder(Event::class);
    $spatial->applyEventWithinBounds($boundsEvents, 101.5, 3.0, 101.8, 3.3, 'events', $effectiveVenue, $venueExists, 'venue', 'institution');
    expect($boundsEvents->toSql())->toContain('ST_MakeEnvelope(?, ?, ?, ?, 4326)::geography');

    $wkt = 'POLYGON((101.5 3.0, 101.8 3.0, 101.8 3.3, 101.5 3.3, 101.5 3.0))';
    $polygonEvents = makePostgresEloquentBuilder(Event::class);
    $spatial->applyEventWithinPolygon($polygonEvents, $wkt, 'events', $effectiveVenue, $venueExists, 'venue', 'institution');
    expect($polygonEvents->toSql())->toContain('ST_GeomFromText(?, 4326)::geography');
});

it('falls back to GeoDistance Haversine and bounding-box SQL on sqlite connections', function (): void {
    $spatial = new SpatialAddressQuery;
    $query = Institution::query();

    expect($query->getConnection())->toBeInstanceOf(SQLiteConnection::class)
        ->and($spatial->isPostgisActive($query->getConnection()))->toBeFalse();

    $spatial->applyPrimaryAddressNearby($query, 'institutions', 'id', 'institution', 3.1390, 101.6869, 10, 'sqlite_inst', ['institutions.*']);
    $sql = strtolower($query->toSql());
    $box = GeoDistance::boundingBox(3.1390, 101.6869, 10.0);

    expect($sql)->not->toContain('st_dwithin')
        ->and($sql)->toContain('sqlite_inst_addresses')
        ->and($sql)->toContain('distance_km')
        ->and($query->getBindings())->toBe([
            3.1390,
            101.6869,
            3.1390,
            'institution',
            true,
            $box['min_lat'],
            $box['max_lat'],
            $box['min_lng'],
            $box['max_lng'],
            3.1390,
            101.6869,
            3.1390,
            10,
        ]);

    $boundsQuery = Institution::query();
    $spatial->applyPrimaryAddressWithinBounds($boundsQuery, 'institutions', 'id', 'institution', 101.5, 3.0, 101.8, 3.3);
    $boundsSql = strtolower($boundsQuery->toSql());

    expect($boundsSql)->not->toContain('st_makeenvelope')
        ->and($boundsSql)->toContain('between ? and ?')
        ->and($boundsQuery->getBindings())->toBe(['institution', true, 3.0, 3.3, 101.5, 101.8]);
});

it('falls back to GeoDistance and bounding-box SQL for dual-owner event queries on sqlite connections', function (): void {
    $spatial = new SpatialAddressQuery;
    $effectiveVenue = 'coalesce(events.default_venue_id, (select el.venue_id from event_locations as el))';
    $venueExists = "exists (select 1 from venues where venues.id = ({$effectiveVenue}))";

    $nearbyEvents = Event::query();
    $spatial->applyEventNearby(
        $nearbyEvents,
        3.1390,
        101.6869,
        20,
        'events',
        $effectiveVenue,
        $venueExists,
        'venue',
        'institution',
        ['events.*'],
    );

    $nearbySql = strtolower($nearbyEvents->toSql());
    $box = GeoDistance::boundingBox(3.1390, 101.6869, 20.0);

    expect($nearbySql)->not->toContain('st_dwithin')
        ->and($nearbySql)->toContain('venue_addresses')
        ->and($nearbySql)->toContain('institution_addresses')
        ->and($nearbySql)->toContain('between cast(? as real) and cast(? as real)')
        ->and($nearbyEvents->getBindings())->toBe([
            3.1390,
            101.6869,
            3.1390,
            'venue',
            true,
            'institution',
            true,
            $box['min_lat'],
            $box['max_lat'],
            $box['min_lng'],
            $box['max_lng'],
            3.1390,
            101.6869,
            3.1390,
            20,
        ]);

    $boundsEvents = Event::query();
    $spatial->applyEventWithinBounds($boundsEvents, 101.5, 3.0, 101.8, 3.3, 'events', $effectiveVenue, $venueExists, 'venue', 'institution');
    $boundsSql = strtolower($boundsEvents->toSql());

    expect($boundsSql)->not->toContain('st_makeenvelope')
        ->and($boundsSql)->toContain('between ? and ?')
        ->and($boundsEvents->getBindings())->toBe([
            'venue',
            true,
            3.0,
            3.3,
            101.5,
            101.8,
            'institution',
            true,
            3.0,
            3.3,
            101.5,
            101.8,
        ]);
});

it('requires PostGIS for polygon boundaries and rejects malformed WKT before querying', function (): void {
    $spatial = new SpatialAddressQuery;
    $wkt = 'POLYGON((101.5 3.0, 101.8 3.0, 101.8 3.3, 101.5 3.3, 101.5 3.0))';

    expect(fn () => $spatial->applyPrimaryAddressWithinPolygon(Institution::query(), 'institutions', 'id', 'institution', $wkt))
        ->toThrow(LogicException::class, 'Enable [addressing.features.spatial.postgis]');

    config()->set('addressing.features.spatial.postgis', true);

    expect(fn () => $spatial->applyPrimaryAddressWithinPolygon(
        makePostgresEloquentBuilder(Institution::class),
        'institutions',
        'id',
        'institution',
        'POINT(101.5 3.0)',
    ))->toThrow(InvalidArgumentException::class, 'Expected a valid WKT POLYGON or MULTIPOLYGON geometry.');
});

it('rejects out-of-range coordinates and inverted bounds before building SQL', function (): void {
    $spatial = new SpatialAddressQuery;

    expect(fn () => $spatial->applyPrimaryAddressNearby(Institution::query(), 'institutions', 'id', 'institution', 91.0, 101.6869, 15))
        ->toThrow(InvalidArgumentException::class, 'Expected latitude and longitude in WGS 84 degrees.')
        ->and(fn () => $spatial->applyPrimaryAddressWithinBounds(Institution::query(), 'institutions', 'id', 'institution', 101.8, 3.0, 101.5, 3.3))
        ->toThrow(InvalidArgumentException::class, 'Bounding box requires south <= north and west <= east.');
});

it('returns no covering areas when PostGIS is not active', function (): void {
    $areas = (new SpatialAddressQuery)->resolveCoveringAreas(3.1390, 101.6869, 'MY');

    expect($areas)->toBeEmpty()
        ->and($areas)->toBeInstanceOf(Illuminate\Database\Eloquent\Collection::class);
});
