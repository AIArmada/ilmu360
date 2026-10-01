<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('institutions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('type')->nullable();
            $table->string('name');
            $table->string('slug')->unique();

            $table->text('description')->nullable();
            $table->boolean('has_friday_prayer_permission')->default(false);

            $table->string('source')->nullable();
            $table->string('external_ref')->nullable();
            $table->timestampTz('imported_at')->nullable();
            $table->jsonb('facilities')->nullable();

            $table->string('status')->default('pending');
            $table->timestampTz('verified_at')->nullable();
            $table->foreignUuid('verified_by')->nullable()->index();
            $table->timestampTz('rejected_at')->nullable();
            $table->timestampTz('inactive_at')->nullable();
            $table->timestampTz('stale_inactive_flagged_at')->nullable();
            $table->timestampTz('last_state_change_at')->nullable();

            $table->boolean('allow_public_event_submission')->default(true)->index();
            $table->timestamp('public_submission_locked_at')->nullable()->index();
            $table->foreignUuid('public_submission_locked_by')->nullable()->index();

            $table->timestamps();

            // Main listing: WHERE status IN ('verified','pending') ORDER BY name
            $table->index(['status', 'name'], 'institutions_status_name');

            // Combined filters: WHERE type='X' AND status='Y' ORDER BY name
            $table->index(['type', 'status', 'name'], 'institutions_type_status_name');

            // Sitemap generation: ORDER BY updated_at DESC
            $table->index('updated_at', 'institutions_sitemap');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
            DB::statement('CREATE INDEX IF NOT EXISTS institutions_name_trgm_idx ON institutions USING gin (name gin_trgm_ops)');
        }

        if (in_array(Schema::getConnection()->getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('CREATE UNIQUE INDEX institutions_source_external_ref_unique ON institutions (source, external_ref) WHERE source IS NOT NULL AND external_ref IS NOT NULL');
        }

        Schema::create('institution_import_exclusions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('source');
            $table->string('external_ref');
            $table->foreignUuid('institution_id')->nullable()->index();
            $table->timestampTz('deleted_at')->nullable();
            $table->timestampsTz();

            $table->unique(['source', 'external_ref'], 'institution_import_exclusions_source_ref_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('institution_import_exclusions');
        Schema::dropIfExists('institutions');
    }
};
