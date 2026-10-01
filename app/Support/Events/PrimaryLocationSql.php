<?php

namespace App\Support\Events;

use App\Models\Event;
use Illuminate\Database\Query\Builder as QueryBuilder;

class PrimaryLocationSql
{
    /**
     * Canonical event-level primary ordering shared by the Event::primaryLocation
     * relation, the nearby scalar subquery, and the location filter constraints:
     * sort_order, created_at, id. The selected row may carry a null venue id;
     * callers must not skip it, matching the PHP resolver exactly.
     */
    public static function table(): string
    {
        return config('events.database.tables.event_locations', 'event_locations');
    }

    public static function venueId(?string $eventsTable = null): string
    {
        $locationTable = self::table();
        $eventsTable ??= (new Event)->getTable();

        return "(select {$locationTable}.venue_id from {$locationTable}"
            ." where {$locationTable}.event_id = {$eventsTable}.id"
            .self::selectedScopeSql($locationTable)
            .self::selectedOrderSql($locationTable).' limit 1)';
    }

    /**
     * Scalar id of the selected canonical primary row, correlated to the
     * given locations table reference. Through-relations pin their
     * intermediate row to this id BEFORE joining venues, so a leading row
     * with a null or missing venue resolves null instead of silently
     * matching a later primary row's venue through the inner join.
     */
    public static function selectedId(string $locationsTable): string
    {
        $table = self::table();

        return "(select selected.id from {$table} as selected"
            ." where selected.event_id = {$locationsTable}.event_id"
            .self::selectedScopeSql('selected')
            .self::selectedOrderSql('selected').' limit 1)';
    }

    private static function selectedScopeSql(string $alias): string
    {
        return " and {$alias}.event_occurrence_id is null"
            ." and {$alias}.event_session_id is null"
            ." and {$alias}.location_role = 'primary'";
    }

    private static function selectedOrderSql(string $alias): string
    {
        return " order by {$alias}.sort_order asc, {$alias}.created_at asc, {$alias}.id asc";
    }

    /**
     * Constrain an event_locations query to the single selected canonical
     * primary row for its event.
     *
     * This helper only manipulates SQL constraints, so it lives at the
     * query layer: Eloquent callers pass their underlying query builder.
     */
    public static function constrainToSelected(QueryBuilder $query): void
    {
        $table = self::table();

        $query->whereNull("{$table}.event_occurrence_id")
            ->whereNull("{$table}.event_session_id")
            ->where("{$table}.location_role", 'primary')
            ->whereIn("{$table}.id", function (QueryBuilder $subquery) use ($table): void {
                $subquery->select('selected.id')
                    ->from("{$table} as selected")
                    ->whereColumn('selected.event_id', "{$table}.event_id")
                    ->whereNull('selected.event_occurrence_id')
                    ->whereNull('selected.event_session_id')
                    ->where('selected.location_role', 'primary')
                    ->orderBy('selected.sort_order')
                    ->orderBy('selected.created_at')
                    ->orderBy('selected.id')
                    ->limit(1);
            });
    }
}
