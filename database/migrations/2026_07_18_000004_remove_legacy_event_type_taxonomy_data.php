<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $taxonomiesTable = config('events.database.tables.event_taxonomies', 'event_taxonomies');
        $termsTable = config('events.database.tables.event_terms', 'event_terms');
        $classificationsTable = config('events.database.tables.event_classifications', 'event_classifications');

        $legacyTaxonomyIds = DB::table($taxonomiesTable)
            ->where('code', 'event_type')
            ->pluck('id');

        if ($legacyTaxonomyIds->isNotEmpty()) {
            DB::table($classificationsTable)
                ->whereIn('event_taxonomy_id', $legacyTaxonomyIds)
                ->delete();

            DB::table($termsTable)
                ->whereIn('event_taxonomy_id', $legacyTaxonomyIds)
                ->delete();

            DB::table($taxonomiesTable)
                ->whereIn('id', $legacyTaxonomyIds)
                ->delete();
        }

        // The old seeder could delete the taxonomy before its terms because
        // the package intentionally has no database-level foreign-key cascade.
        $legacyTermIds = DB::table($termsTable)
            ->whereNotNull('metadata->group')
            ->pluck('id');

        if ($legacyTermIds->isNotEmpty()) {
            DB::table($classificationsTable)
                ->whereIn('event_term_id', $legacyTermIds)
                ->delete();

            DB::table($termsTable)
                ->whereIn('id', $legacyTermIds)
                ->delete();
        }
    }
};
