<?php

namespace App\Support\Events;

use App\Models\Event;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\SQLiteConnection;
use InvalidArgumentException;

class PrimaryOccurrenceSql
{
    /**
     * @var list<string>
     */
    private const array SupportedColumns = [
        'starts_at',
        'ends_at',
    ];

    public static function column(string $column, ?string $eventsTable = null): string
    {
        if (! in_array($column, self::SupportedColumns, true)) {
            throw new InvalidArgumentException("Unsupported primary occurrence column [{$column}].");
        }

        return self::expression($column, $eventsTable);
    }

    /**
     * End instant of the event schedule: the effective `ends_at` falling back
     * to `starts_at` when an occurrence has no end time.
     *
     * Grouped public submissions hold many occurrences, so their end instant is
     * the latest effective end across every eligible occurrence. An ongoing
     * grouped event therefore stays upcoming until its last eligible
     * occurrence ends instead of falling out of both time scopes.
     */
    public static function endInstant(?string $eventsTable = null): string
    {
        $occurrencesTable = config('events.database.tables.event_occurrences', 'event_occurrences');
        $eventsTable ??= (new Event)->getTable();

        /** @var PostgresConnection|SQLiteConnection|MySqlConnection $connection */
        $connection = Event::query()->getConnection();

        $groupKeyExists = self::groupKeyExists($eventsTable, $connection);
        $upcomingStatuses = self::sqlStringList(Event::UPCOMING_SCHEDULE_STATUSES);
        $publicVisibilities = self::sqlStringList(Event::PUBLIC_SCHEDULE_VISIBILITIES);
        $effectiveEnd = "coalesce({$occurrencesTable}.ends_at, {$occurrencesTable}.starts_at)";
        $primaryEffectiveEnd = "(select {$effectiveEnd} from {$occurrencesTable} where {$occurrencesTable}.event_id = {$eventsTable}.id order by {$occurrencesTable}.starts_at asc, {$occurrencesTable}.created_at asc, {$occurrencesTable}.id asc limit 1)";
        $groupedEffectiveEnd = "(select max({$effectiveEnd}) from {$occurrencesTable} where {$occurrencesTable}.event_id = {$eventsTable}.id and {$occurrencesTable}.status in ({$upcomingStatuses}) and {$occurrencesTable}.visibility in ({$publicVisibilities}))";

        // The events table has no own date columns: a managed event's date
        // attributes project from its primary occurrence, so that projection is
        // the fallback. Grouped parents are dateless, so they keep the null
        // projection when no eligible occurrence exists instead of surfacing an
        // ineligible (draft, private or terminal) occurrence date.
        return "(case when {$groupKeyExists} then {$groupedEffectiveEnd} else {$primaryEffectiveEnd} end)";
    }

    private static function expression(string $selectExpression, ?string $eventsTable = null): string
    {
        $occurrencesTable = config('events.database.tables.event_occurrences', 'event_occurrences');
        $eventsTable ??= (new Event)->getTable();

        /** @var PostgresConnection|SQLiteConnection|MySqlConnection $connection */
        $connection = Event::query()->getConnection();

        $groupKeyExists = self::groupKeyExists($eventsTable, $connection);
        $upcomingStatuses = self::sqlStringList(Event::UPCOMING_SCHEDULE_STATUSES);
        $publicVisibilities = self::sqlStringList(Event::PUBLIC_SCHEDULE_VISIBILITIES);
        $nowUtc = now()->utc()->format('Y-m-d H:i:s');
        $selectExpression = str_replace('#', $occurrencesTable, $selectExpression);
        $groupedOccurrence = "(select {$selectExpression} from {$occurrencesTable} where {$occurrencesTable}.event_id = {$eventsTable}.id and {$occurrencesTable}.status in ({$upcomingStatuses}) and {$occurrencesTable}.visibility in ({$publicVisibilities}) and {$occurrencesTable}.starts_at >= '{$nowUtc}' order by {$occurrencesTable}.starts_at asc, {$occurrencesTable}.created_at asc, {$occurrencesTable}.id asc limit 1)";
        $managedOccurrence = "(select {$selectExpression} from {$occurrencesTable} where {$occurrencesTable}.event_id = {$eventsTable}.id order by {$occurrencesTable}.starts_at asc, {$occurrencesTable}.created_at asc, {$occurrencesTable}.id asc limit 1)";

        return "(case when {$groupKeyExists} then {$groupedOccurrence} else {$managedOccurrence} end)";
    }

    public static function startsAtUserTimeExpression(int $offsetMinutes, ?string $eventsTable = null): string
    {
        $startsAtSql = self::column('starts_at', $eventsTable);

        /** @var PostgresConnection|SQLiteConnection $connection */
        $connection = Event::query()->getConnection();

        return match ($connection->getDriverName()) {
            'pgsql' => "to_char(({$startsAtSql}) + interval '{$offsetMinutes} minutes', 'HH24:MI')",
            'sqlite' => "strftime('%H:%M', datetime(({$startsAtSql}), '{$offsetMinutes} minutes'))",
            default => throw new InvalidArgumentException(sprintf('Unsupported database driver [%s].', $connection->getDriverName())),
        };
    }

    /**
     * @param  PostgresConnection|SQLiteConnection|MySqlConnection  $connection
     */
    private static function groupKeyExists(string $eventsTable, PostgresConnection|SQLiteConnection|MySqlConnection $connection): string
    {
        return match ($connection->getDriverName()) {
            'pgsql' => "{$eventsTable}.metadata->'public_submission_group'->>'key' is not null",
            'sqlite' => "json_extract({$eventsTable}.metadata, '$.public_submission_group.key') is not null",
            'mysql', 'mariadb' => "json_unquote(json_extract({$eventsTable}.metadata, '$.public_submission_group.key')) is not null",
            default => throw new InvalidArgumentException(sprintf('Unsupported database driver [%s].', $connection->getDriverName())),
        };
    }

    /**
     * @param  list<string>  $values
     */
    private static function sqlStringList(array $values): string
    {
        return implode(', ', array_map(
            static fn (string $value): string => "'".str_replace("'", "''", $value)."'",
            $values,
        ));
    }
}
