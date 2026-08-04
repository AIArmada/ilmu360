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

        return "(select {$occurrencesTable}.{$column} from {$occurrencesTable} where {$occurrencesTable}.event_id = {$eventsTable}.id order by {$occurrencesTable}.starts_at asc, {$occurrencesTable}.created_at asc, {$occurrencesTable}.id asc limit 1)";
    }

    public static function startsAtUserTimeExpression(int $offsetMinutes, ?string $eventsTable = null): string
    {
        $startsAtSql = self::column('starts_at', $eventsTable);

        /** @var MySqlConnection|PostgresConnection|SQLiteConnection $connection */
        $connection = Event::query()->getConnection();

        return match ($connection->getDriverName()) {
            'pgsql' => "to_char(({$startsAtSql}) + interval '{$offsetMinutes} minutes', 'HH24:MI')",
            'mysql', 'mariadb' => "DATE_FORMAT(DATE_ADD(({$startsAtSql}), INTERVAL {$offsetMinutes} MINUTE), '%H:%i')",
            default => "strftime('%H:%M', datetime(({$startsAtSql}), '{$offsetMinutes} minutes'))",
        };
    }
}
