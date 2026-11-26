<?php

declare(strict_types=1);

namespace App\Platform\Location;

use AIArmada\Addressing\Features\Geography\Models\AddressArea;
use AIArmada\Addressing\Support\AddressingTableResolver;
use AIArmada\Addressing\Support\GeoDistance;
use AIArmada\Addressing\Support\SpatialLocation;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Canonical spatial proximity, bounding-box, polygon, and boundary queries
 * built on `aiarmada/addressing` PostGIS columns with SQLite/GeoDistance fallback.
 */
final readonly class SpatialAddressQuery
{
    public function isPostgisActive(ConnectionInterface $connection): bool
    {
        return $connection instanceof Connection
            && $connection->getDriverName() === 'pgsql'
            && config('addressing.features.spatial.postgis') === true;
    }

    /**
     * Parse either `west,south,east,north` or `{west,south,east,north}` into validated WGS 84 bounds.
     *
     * @return array{west: float, south: float, east: float, north: float}|null
     */
    public function parseBoundingBox(mixed $value): ?array
    {
        $west = null;
        $south = null;
        $east = null;
        $north = null;

        if (is_string($value)) {
            $parts = preg_split('/\s*,\s*/', trim($value));

            if (! is_array($parts) || count($parts) !== 4) {
                return null;
            }

            if (! is_numeric($parts[0]) || ! is_numeric($parts[1]) || ! is_numeric($parts[2]) || ! is_numeric($parts[3])) {
                return null;
            }

            $west = (float) $parts[0];
            $south = (float) $parts[1];
            $east = (float) $parts[2];
            $north = (float) $parts[3];
        } elseif (is_array($value)) {
            $rawWest = $value['west'] ?? $value[0] ?? null;
            $rawSouth = $value['south'] ?? $value[1] ?? null;
            $rawEast = $value['east'] ?? $value[2] ?? null;
            $rawNorth = $value['north'] ?? $value[3] ?? null;

            if (! is_numeric($rawWest) || ! is_numeric($rawSouth) || ! is_numeric($rawEast) || ! is_numeric($rawNorth)) {
                return null;
            }

            $west = (float) $rawWest;
            $south = (float) $rawSouth;
            $east = (float) $rawEast;
            $north = (float) $rawNorth;
        } else {
            return null;
        }

        if (
            ! is_finite($west) || ! is_finite($south) || ! is_finite($east) || ! is_finite($north)
            || abs($south) > 90.0 || abs($north) > 90.0
            || abs($west) > 180.0 || abs($east) > 180.0
            || $south > $north || $west > $east
        ) {
            return null;
        }

        return [
            'west' => $west,
            'south' => $south,
            'east' => $east,
            'north' => $north,
        ];
    }

    public function normalizePolygonWkt(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '' || mb_strlen($trimmed) > 65535) {
            return null;
        }

        if (preg_match('/\A(?:MULTI)?POLYGON\s*\(\s*\(.*\)\s*\)\z/is', $trimmed) !== 1) {
            return null;
        }

        return substr_count($trimmed, '(') === substr_count($trimmed, ')') ? $trimmed : null;
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>|null  $columns
     */
    public function applyPrimaryAddressNearby(
        Builder $query,
        string $ownerTable,
        string $ownerKeyColumn,
        string $morphType,
        float $lat,
        float $lng,
        int $radiusKm,
        string $aliasPrefix = 'spatial',
        ?array $columns = null,
    ): void {
        SpatialLocation::validateCoordinates($lat, $lng);

        $radiusKm = max(1, $radiusKm);
        $connection = $query->getConnection();
        [, $addressesAlias] = $this->joinPrimaryAddress($query, $ownerTable, $ownerKeyColumn, $morphType, $aliasPrefix, false, $columns);

        if ($this->isPostgisActive($connection)) {
            $radiusMeters = $radiusKm * 1000.0;

            $query
                ->whereRaw("{$addressesAlias}.location is not null")
                ->whereRaw("ST_DWithin({$addressesAlias}.location, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)", [$lng, $lat, $radiusMeters])
                ->selectRaw("(ST_Distance({$addressesAlias}.location, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) / 1000.0) as distance_km", [$lng, $lat])
                ->orderBy('distance_km');

            return;
        }

        $driver = $connection instanceof Connection ? $connection->getDriverName() : null;
        $box = GeoDistance::boundingBox($lat, $lng, (float) $radiusKm);
        $distanceSql = GeoDistance::haversineSql("{$addressesAlias}.latitude", "{$addressesAlias}.longitude", $driver);
        $distanceBindings = GeoDistance::haversineBindings($lat, $lng);

        $query
            ->whereRaw("{$addressesAlias}.latitude is not null")
            ->whereRaw("{$addressesAlias}.longitude is not null")
            ->whereBetween("{$addressesAlias}.latitude", [$box['min_lat'], $box['max_lat']])
            ->whereBetween("{$addressesAlias}.longitude", [$box['min_lng'], $box['max_lng']])
            ->selectRaw("{$distanceSql} as distance_km", $distanceBindings)
            ->whereRaw("{$distanceSql} <= ?", [...$distanceBindings, $radiusKm])
            ->orderBy('distance_km');
    }

    /**
     * Left-join the owner's primary address to compute `distance_km` without filtering out records lacking coordinates.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>|null  $columns
     */
    public function selectPrimaryAddressDistance(
        Builder $query,
        string $ownerTable,
        string $ownerKeyColumn,
        string $morphType,
        float $lat,
        float $lng,
        string $aliasPrefix = 'distance',
        ?array $columns = null,
    ): void {
        SpatialLocation::validateCoordinates($lat, $lng);

        $connection = $query->getConnection();
        [, $addressesAlias] = $this->joinPrimaryAddress($query, $ownerTable, $ownerKeyColumn, $morphType, $aliasPrefix, true, $columns);

        if ($this->isPostgisActive($connection)) {
            $query
                ->selectRaw("case when {$addressesAlias}.location is not null then (ST_Distance({$addressesAlias}.location, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) / 1000.0) end as distance_km", [$lng, $lat])
                ->orderByRaw("case when {$addressesAlias}.location is null then 1 else 0 end asc")
                ->orderBy('distance_km');

            return;
        }

        $driver = $connection instanceof Connection ? $connection->getDriverName() : null;
        $distanceSql = GeoDistance::haversineSql("{$addressesAlias}.latitude", "{$addressesAlias}.longitude", $driver);
        $distanceBindings = GeoDistance::haversineBindings($lat, $lng);

        $query
            ->selectRaw("case when {$addressesAlias}.latitude is not null and {$addressesAlias}.longitude is not null then {$distanceSql} end as distance_km", $distanceBindings)
            ->orderByRaw("case when {$addressesAlias}.latitude is null or {$addressesAlias}.longitude is null then 1 else 0 end asc")
            ->orderBy('distance_km');
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>|null  $columns
     * @return array{0: string, 1: string}
     */
    private function joinPrimaryAddress(
        Builder $query,
        string $ownerTable,
        string $ownerKeyColumn,
        string $morphType,
        string $aliasPrefix,
        bool $leftJoin,
        ?array $columns,
    ): array {
        $addressablesTable = AddressingTableResolver::resolve('addressables');
        $addressesTable = AddressingTableResolver::resolve('addresses');
        $addressablesAlias = "{$aliasPrefix}_addressables";
        $addressesAlias = "{$aliasPrefix}_addresses";

        if ($columns !== null) {
            $query->select($columns);
        } elseif ($query->getQuery()->columns === null) {
            $query->select("{$ownerTable}.*");
        }

        $joinAddressables = static function ($join) use ($addressablesAlias, $ownerTable, $ownerKeyColumn, $morphType): void {
            $join->on("{$addressablesAlias}.addressable_id", '=', "{$ownerTable}.{$ownerKeyColumn}")
                ->where("{$addressablesAlias}.addressable_type", $morphType)
                ->where("{$addressablesAlias}.is_primary", true);
        };

        if ($leftJoin) {
            $query
                ->leftJoin("{$addressablesTable} as {$addressablesAlias}", $joinAddressables)
                ->leftJoin("{$addressesTable} as {$addressesAlias}", "{$addressesAlias}.id", '=', "{$addressablesAlias}.address_id");
        } else {
            $query
                ->join("{$addressablesTable} as {$addressablesAlias}", $joinAddressables)
                ->join("{$addressesTable} as {$addressesAlias}", "{$addressesAlias}.id", '=', "{$addressablesAlias}.address_id");
        }

        return [$addressablesAlias, $addressesAlias];
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    public function applyPrimaryAddressWithinBounds(
        Builder $query,
        string $ownerTable,
        string $ownerKeyColumn,
        string $morphType,
        float $west,
        float $south,
        float $east,
        float $north,
    ): void {
        $this->assertValidBounds($west, $south, $east, $north);

        $addressablesTable = AddressingTableResolver::resolve('addressables');
        $addressesTable = AddressingTableResolver::resolve('addresses');
        $connection = $query->getConnection();
        $isPostgis = $this->isPostgisActive($connection);

        $query->whereExists(function ($subQuery) use ($addressablesTable, $addressesTable, $ownerTable, $ownerKeyColumn, $morphType, $isPostgis, $west, $south, $east, $north): void {
            $subQuery
                ->selectRaw('1')
                ->from($addressablesTable)
                ->join($addressesTable, "{$addressesTable}.id", '=', "{$addressablesTable}.address_id")
                ->whereRaw("{$addressablesTable}.addressable_id = {$ownerTable}.{$ownerKeyColumn}")
                ->where("{$addressablesTable}.addressable_type", $morphType)
                ->where("{$addressablesTable}.is_primary", true);

            if ($isPostgis) {
                $subQuery
                    ->whereRaw("{$addressesTable}.location is not null")
                    ->whereRaw(
                        "ST_Intersects({$addressesTable}.location, ST_MakeEnvelope(?, ?, ?, ?, 4326)::geography)",
                        [$west, $south, $east, $north],
                    );

                return;
            }

            $subQuery
                ->whereRaw("{$addressesTable}.latitude is not null")
                ->whereRaw("{$addressesTable}.longitude is not null")
                ->whereBetween("{$addressesTable}.latitude", [$south, $north])
                ->whereBetween("{$addressesTable}.longitude", [$west, $east]);
        });
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    public function applyPrimaryAddressWithinPolygon(
        Builder $query,
        string $ownerTable,
        string $ownerKeyColumn,
        string $morphType,
        string $wkt,
    ): void {
        $connection = $query->getConnection();
        if (! $connection instanceof Connection) {
            throw new InvalidArgumentException('Polygon spatial queries require an active database connection.');
        }

        SpatialLocation::requireEnabled($connection, 'postgis');

        $normalizedWkt = $this->normalizePolygonWkt($wkt);
        if ($normalizedWkt === null) {
            throw new InvalidArgumentException('Expected a valid WKT POLYGON or MULTIPOLYGON geometry.');
        }

        $addressablesTable = AddressingTableResolver::resolve('addressables');
        $addressesTable = AddressingTableResolver::resolve('addresses');

        $query->whereExists(function ($subQuery) use ($addressablesTable, $addressesTable, $ownerTable, $ownerKeyColumn, $morphType, $normalizedWkt): void {
            $subQuery
                ->selectRaw('1')
                ->from($addressablesTable)
                ->join($addressesTable, "{$addressesTable}.id", '=', "{$addressablesTable}.address_id")
                ->whereRaw("{$addressablesTable}.addressable_id = {$ownerTable}.{$ownerKeyColumn}")
                ->where("{$addressablesTable}.addressable_type", $morphType)
                ->where("{$addressablesTable}.is_primary", true)
                ->whereRaw("{$addressesTable}.location is not null")
                ->whereRaw(
                    "ST_Intersects({$addressesTable}.location, ST_GeomFromText(?, 4326)::geography)",
                    [$normalizedWkt],
                );
        });
    }

    /**
     * Join the event's effective venue (default, else selected package primary venue)
     * and institution owner address, using GiST-indexed PostGIS ST_DWithin/ST_Distance
     * on PostgreSQL when enabled and GeoDistance Haversine on SQLite/fallback.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>|null  $columns
     */
    public function applyEventNearby(
        Builder $query,
        float $lat,
        float $lng,
        int $radiusKm,
        string $eventsTable,
        string $effectiveVenueSql,
        string $venueExistsSql,
        string $venueMorphType,
        string $institutionMorphType,
        ?array $columns = null,
    ): void {
        SpatialLocation::validateCoordinates($lat, $lng);

        $radiusKm = max(1, $radiusKm);
        $addressablesTable = AddressingTableResolver::resolve('addressables');
        $addressesTable = AddressingTableResolver::resolve('addresses');
        $institutionIdExpression = "{$eventsTable}.institution_id";
        $connection = $query->getConnection();

        $query
            ->leftJoin("{$addressablesTable} as venue_addressables", function ($join) use ($venueMorphType, $effectiveVenueSql): void {
                $join->whereRaw("venue_addressables.addressable_id = {$effectiveVenueSql}")
                    ->where('venue_addressables.addressable_type', $venueMorphType)
                    ->where('venue_addressables.is_primary', true);
            })
            ->leftJoin("{$addressesTable} as venue_addresses", 'venue_addresses.id', '=', 'venue_addressables.address_id')
            ->leftJoin("{$addressablesTable} as institution_addressables", function ($join) use ($institutionIdExpression, $institutionMorphType): void {
                $join->whereRaw("institution_addressables.addressable_id = {$institutionIdExpression}")
                    ->where('institution_addressables.addressable_type', $institutionMorphType)
                    ->where('institution_addressables.is_primary', true);
            })
            ->leftJoin("{$addressesTable} as institution_addresses", 'institution_addresses.id', '=', 'institution_addressables.address_id');

        if ($this->isPostgisActive($connection)) {
            $radiusMeters = $radiusKm * 1000.0;
            $locationExpression = "case when ({$effectiveVenueSql}) is null then institution_addresses.location when {$venueExistsSql} then venue_addresses.location end";

            $query
                ->whereRaw(
                    "( (({$effectiveVenueSql}) is null and institution_addresses.location is not null and ST_DWithin(institution_addresses.location, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)) or (({$effectiveVenueSql}) is not null and {$venueExistsSql} and venue_addresses.location is not null and ST_DWithin(venue_addresses.location, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)) )",
                    [$lng, $lat, $radiusMeters, $lng, $lat, $radiusMeters],
                )
                ->select($columns ?? ["{$eventsTable}.*"])
                ->selectRaw("(ST_Distance({$locationExpression}, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) / 1000.0) as distance_km", [$lng, $lat])
                ->orderBy('distance_km', 'asc');

            return;
        }

        $driver = $connection instanceof Connection ? $connection->getDriverName() : null;
        $castType = $driver === 'sqlite' ? 'real' : 'double precision';
        $latitudeExpression = "case when ({$effectiveVenueSql}) is null then institution_addresses.latitude when {$venueExistsSql} then venue_addresses.latitude end";
        $longitudeExpression = "case when ({$effectiveVenueSql}) is null then institution_addresses.longitude when {$venueExistsSql} then venue_addresses.longitude end";
        $box = GeoDistance::boundingBox($lat, $lng, (float) $radiusKm);
        $distanceSql = GeoDistance::haversineSql($latitudeExpression, $longitudeExpression, $driver);
        $distanceBindings = GeoDistance::haversineBindings($lat, $lng);

        $query
            ->whereRaw("{$latitudeExpression} is not null")
            ->whereRaw("{$longitudeExpression} is not null")
            ->whereRaw("({$latitudeExpression}) between cast(? as {$castType}) and cast(? as {$castType})", [$box['min_lat'], $box['max_lat']])
            ->whereRaw("({$longitudeExpression}) between cast(? as {$castType}) and cast(? as {$castType})", [$box['min_lng'], $box['max_lng']])
            ->select($columns ?? ["{$eventsTable}.*"])
            ->selectRaw("{$distanceSql} as distance_km", $distanceBindings)
            ->whereRaw("{$distanceSql} <= ?", [...$distanceBindings, $radiusKm])
            ->orderBy('distance_km', 'asc');
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    public function applyEventWithinBounds(
        Builder $query,
        float $west,
        float $south,
        float $east,
        float $north,
        string $eventsTable,
        string $effectiveVenueSql,
        string $venueExistsSql,
        string $venueMorphType,
        string $institutionMorphType,
    ): void {
        $this->assertValidBounds($west, $south, $east, $north);

        $addressablesTable = AddressingTableResolver::resolve('addressables');
        $addressesTable = AddressingTableResolver::resolve('addresses');
        $isPostgis = $this->isPostgisActive($query->getConnection());

        $constrainAddress = static function ($subQuery) use ($addressesTable, $isPostgis, $west, $south, $east, $north): void {
            if ($isPostgis) {
                $subQuery
                    ->whereRaw("{$addressesTable}.location is not null")
                    ->whereRaw(
                        "ST_Intersects({$addressesTable}.location, ST_MakeEnvelope(?, ?, ?, ?, 4326)::geography)",
                        [$west, $south, $east, $north],
                    );

                return;
            }

            $subQuery
                ->whereRaw("{$addressesTable}.latitude is not null")
                ->whereRaw("{$addressesTable}.longitude is not null")
                ->whereBetween("{$addressesTable}.latitude", [$south, $north])
                ->whereBetween("{$addressesTable}.longitude", [$west, $east]);
        };

        $this->applyEventDualOwnerExists($query, $eventsTable, $effectiveVenueSql, $venueExistsSql, $venueMorphType, $institutionMorphType, $addressablesTable, $addressesTable, $constrainAddress);
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    public function applyEventWithinPolygon(
        Builder $query,
        string $wkt,
        string $eventsTable,
        string $effectiveVenueSql,
        string $venueExistsSql,
        string $venueMorphType,
        string $institutionMorphType,
    ): void {
        $connection = $query->getConnection();
        if (! $connection instanceof Connection) {
            throw new InvalidArgumentException('Polygon spatial queries require an active database connection.');
        }

        SpatialLocation::requireEnabled($connection, 'postgis');

        $normalizedWkt = $this->normalizePolygonWkt($wkt);
        if ($normalizedWkt === null) {
            throw new InvalidArgumentException('Expected a valid WKT POLYGON or MULTIPOLYGON geometry.');
        }

        $addressablesTable = AddressingTableResolver::resolve('addressables');
        $addressesTable = AddressingTableResolver::resolve('addresses');

        $constrainAddress = static function ($subQuery) use ($addressesTable, $normalizedWkt): void {
            $subQuery
                ->whereRaw("{$addressesTable}.location is not null")
                ->whereRaw(
                    "ST_Intersects({$addressesTable}.location, ST_GeomFromText(?, 4326)::geography)",
                    [$normalizedWkt],
                );
        };

        $this->applyEventDualOwnerExists($query, $eventsTable, $effectiveVenueSql, $venueExistsSql, $venueMorphType, $institutionMorphType, $addressablesTable, $addressesTable, $constrainAddress);
    }

    /**
     * Reverse-resolve covering administrative areas when PostGIS boundary polygons are loaded.
     *
     * @return EloquentCollection<int, AddressArea>
     */
    public function resolveCoveringAreas(float $lat, float $lng, ?string $countryCode = null): EloquentCollection
    {
        SpatialLocation::validateCoordinates($lat, $lng);

        $probe = AddressArea::query();

        if (! $this->isPostgisActive($probe->getConnection())) {
            return $probe->getModel()->newCollection();
        }

        $probe
            ->where('is_active', true)
            ->when(
                is_string($countryCode) && trim($countryCode) !== '',
                static fn (Builder $query) => $query->where('country_code', strtoupper(trim($countryCode))),
            )
            ->tap(static function (Builder $query) use ($lat, $lng): void {
                /** @var Builder<AddressArea> $query */
                $query->coveringPoint($lat, $lng);
            })
            ->orderBy('level')
            ->orderBy('name');

        return $probe->get();
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  \Closure(\Illuminate\Database\Query\Builder): void  $constrainAddress
     */
    private function applyEventDualOwnerExists(
        Builder $query,
        string $eventsTable,
        string $effectiveVenueSql,
        string $venueExistsSql,
        string $venueMorphType,
        string $institutionMorphType,
        string $addressablesTable,
        string $addressesTable,
        \Closure $constrainAddress,
    ): void {
        $query->where(function (Builder $spatialQuery) use (
            $eventsTable,
            $effectiveVenueSql,
            $venueExistsSql,
            $venueMorphType,
            $institutionMorphType,
            $addressablesTable,
            $addressesTable,
            $constrainAddress,
        ): void {
            $spatialQuery
                ->where(function (Builder $venueBranch) use (
                    $effectiveVenueSql,
                    $venueExistsSql,
                    $venueMorphType,
                    $addressablesTable,
                    $addressesTable,
                    $constrainAddress,
                ): void {
                    $venueBranch
                        ->whereRaw("({$effectiveVenueSql}) is not null")
                        ->whereRaw($venueExistsSql)
                        ->whereExists(function ($subQuery) use ($addressablesTable, $addressesTable, $effectiveVenueSql, $venueMorphType, $constrainAddress): void {
                            $subQuery
                                ->selectRaw('1')
                                ->from($addressablesTable)
                                ->join($addressesTable, "{$addressesTable}.id", '=', "{$addressablesTable}.address_id")
                                ->whereRaw("{$addressablesTable}.addressable_id = {$effectiveVenueSql}")
                                ->where("{$addressablesTable}.addressable_type", $venueMorphType)
                                ->where("{$addressablesTable}.is_primary", true);

                            $constrainAddress($subQuery);
                        });
                })
                ->orWhere(function (Builder $institutionBranch) use (
                    $eventsTable,
                    $effectiveVenueSql,
                    $institutionMorphType,
                    $addressablesTable,
                    $addressesTable,
                    $constrainAddress,
                ): void {
                    $institutionBranch
                        ->whereRaw("({$effectiveVenueSql}) is null")
                        ->whereNotNull("{$eventsTable}.institution_id")
                        ->whereExists(function ($subQuery) use ($addressablesTable, $addressesTable, $eventsTable, $institutionMorphType, $constrainAddress): void {
                            $subQuery
                                ->selectRaw('1')
                                ->from($addressablesTable)
                                ->join($addressesTable, "{$addressesTable}.id", '=', "{$addressablesTable}.address_id")
                                ->whereColumn("{$addressablesTable}.addressable_id", "{$eventsTable}.institution_id")
                                ->where("{$addressablesTable}.addressable_type", $institutionMorphType)
                                ->where("{$addressablesTable}.is_primary", true);

                            $constrainAddress($subQuery);
                        });
                });
        });
    }

    private function assertValidBounds(float $west, float $south, float $east, float $north): void
    {
        SpatialLocation::validateCoordinates($south, $west);
        SpatialLocation::validateCoordinates($north, $east);

        if ($south > $north || $west > $east) {
            throw new InvalidArgumentException('Bounding box requires south <= north and west <= east.');
        }
    }
}
