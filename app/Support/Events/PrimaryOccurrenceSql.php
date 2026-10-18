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

        $occurrencesTable = config('events.database.tables.event_occurrences', 'event_occurrences');
        $eventsTable ??= (new Event)->getTable();

        /** @var PostgresConnection|SQLiteConnection|MySqlConnection $connection */
        $connection = Event::query()->getConnection();

        $groupKeyExists = match ($connection->getDriverName()) {
            'pgsql' => "{$eventsTable}.metadata->'public_submission_group'->>'key' is not null",
            'sqlite' => "json_extract({$eventsTable}.metadata, '$.public_submission_group.key') is not null",
            'mysql', 'mariadb' => "json_unquote(json_extract({$eventsTable}.metadata, '$.public_submission_group.key')) is not null",
            default => throw new InvalidArgumentException(sprintf('Unsupported database driver [%s].', $connection->getDriverName())),
        };
        $upcomingStatuses = self::sqlStringList(Event::UPCOMING_SCHEDULE_STATUSES);
        $publicVisibilities = self::sqlStringList(Event::PUBLIC_SCHEDULE_VISIBILITIES);
        $nowUtc = now()->utc()->format('Y-m-d H:i:s');
        $groupedOccurrence = "(select {$occurrencesTable}.{$column} from {$occurrencesTable} where {$occurrencesTable}.event_id = {$eventsTable}.id and {$occurrencesTable}.status in ({$upcomingStatuses}) and {$occurrencesTable}.visibility in ({$publicVisibilities}) and {$occurrencesTable}.starts_at >= '{$nowUtc}' order by {$occurrencesTable}.starts_at asc, {$occurrencesTable}.created_at asc, {$occurrencesTable}.id asc limit 1)";
        $managedOccurrence = "(select {$occurrencesTable}.{$column} from {$occurrencesTable} where {$occurrencesTable}.event_id = {$eventsTable}.id order by {$occurrencesTable}.starts_at asc, {$occurrencesTable}.created_at asc, {$occurrencesTable}.id asc limit 1)";

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
