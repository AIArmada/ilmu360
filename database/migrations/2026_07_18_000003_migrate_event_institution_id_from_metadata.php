<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $eventsTable = (string) config('events.database.tables.events', 'events');
        $driver = DB::connection()->getDriverName();

        match ($driver) {
            'pgsql' => $this->migratePostgres($eventsTable),
            'mysql', 'mariadb' => $this->migrateMysql($eventsTable),
            default => $this->migrateSqlite($eventsTable),
        };
    }

    private function migratePostgres(string $eventsTable): void
    {
        DB::statement(
            "UPDATE {$eventsTable} SET institution_id = NULLIF(metadata->>'institution_id', '')::uuid "
            ."WHERE jsonb_exists(metadata::jsonb, 'institution_id')",
        );
        DB::statement(
            "UPDATE {$eventsTable} SET metadata = metadata - 'institution_id' "
            ."WHERE jsonb_exists(metadata::jsonb, 'institution_id')",
        );
    }

    private function migrateMysql(string $eventsTable): void
    {
        DB::statement(
            "UPDATE {$eventsTable} SET institution_id = NULLIF(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.\"institution_id\"')), '') "
            ."WHERE JSON_CONTAINS_PATH(metadata, 'one', '$.\"institution_id\"')",
        );
        DB::statement(
            "UPDATE {$eventsTable} SET metadata = JSON_REMOVE(metadata, '$.\"institution_id\"') "
            ."WHERE JSON_CONTAINS_PATH(metadata, 'one', '$.\"institution_id\"')",
        );
    }

    private function migrateSqlite(string $eventsTable): void
    {
        DB::statement(
            "UPDATE {$eventsTable} SET institution_id = json_extract(metadata, '$.\"institution_id\"') "
            ."WHERE json_type(metadata, '$.\"institution_id\"') IS NOT NULL",
        );
        DB::statement(
            "UPDATE {$eventsTable} SET metadata = json_remove(metadata, '$.\"institution_id\"') "
            ."WHERE json_type(metadata, '$.\"institution_id\"') IS NOT NULL",
        );
    }
};
