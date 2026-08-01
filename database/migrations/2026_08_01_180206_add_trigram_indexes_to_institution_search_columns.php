<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('CREATE INDEX IF NOT EXISTS institutions_name_trgm_idx ON institutions USING gin (name gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS institution_names_full_name_trgm_idx ON institution_names USING gin (full_name gin_trgm_ops)');
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS institutions_name_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS institution_names_full_name_trgm_idx');
    }
};
