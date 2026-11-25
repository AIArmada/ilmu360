<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX IF NOT EXISTS events_title_trgm_idx ON events USING gin (lower(title) gin_trgm_ops)');
            DB::statement('CREATE INDEX IF NOT EXISTS persons_searchable_name_trgm_idx ON persons USING gin (lower(searchable_name) gin_trgm_ops)');
            DB::statement('CREATE INDEX IF NOT EXISTS person_search_terms_term_trgm_idx ON person_search_terms USING gin (lower(term) gin_trgm_ops)');
            DB::statement('CREATE INDEX IF NOT EXISTS references_title_trgm_idx ON "references" USING gin (lower(title) gin_trgm_ops)');
            DB::statement('CREATE INDEX IF NOT EXISTS venues_name_trgm_idx ON venues USING gin (lower(name) gin_trgm_ops)');
        }

        $involvementsTable = (string) config('events.database.tables.event_involvements', 'event_involvements');
        $timeExpressionsTable = (string) config('events.database.tables.event_time_expressions', 'event_time_expressions');

        $this->addIndexIfMissing($involvementsTable, ['updated_at'], 'event_involvements_updated_at_idx');
        $this->addIndexIfMissing($timeExpressionsTable, ['event_id', 'anchor_type'], 'event_time_expressions_event_anchor_type_idx');
        $this->addIndexIfMissing($timeExpressionsTable, ['event_occurrence_id', 'anchor_type'], 'event_time_expressions_occ_anchor_type_idx');
    }

    /**
     * @param  list<string>  $columns
     */
    private function addIndexIfMissing(string $table, array $columns, string $indexName): void
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['name'] ?? null) === $indexName || ($index['columns'] ?? null) === $columns) {
                return;
            }
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $indexName): void {
            $blueprint->index($columns, $indexName);
        });
    }
};
