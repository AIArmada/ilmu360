<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venues', function (Blueprint $table): void {
            if (! Schema::hasColumn('venues', 'verified_at')) {
                $table->timestampTz('verified_at')->nullable();
            }
            if (! Schema::hasColumn('venues', 'rejected_at')) {
                $table->timestampTz('rejected_at')->nullable();
            }
            if (! Schema::hasColumn('venues', 'last_state_change_at')) {
                $table->timestampTz('last_state_change_at')->nullable();
            }
            if (! Schema::hasColumn('venues', 'verified_by')) {
                $table->foreignUuid('verified_by')->nullable()->index();
            }
        });

        Schema::table('references', function (Blueprint $table): void {
            if (! Schema::hasColumn('references', 'verified_at')) {
                $table->timestampTz('verified_at')->nullable();
            }
            if (! Schema::hasColumn('references', 'rejected_at')) {
                $table->timestampTz('rejected_at')->nullable();
            }
            if (! Schema::hasColumn('references', 'last_state_change_at')) {
                $table->timestampTz('last_state_change_at')->nullable();
            }
            if (! Schema::hasColumn('references', 'verified_by')) {
                $table->foreignUuid('verified_by')->nullable()->index();
            }
        });

        Schema::table('affiliations', function (Blueprint $table): void {
            if (! Schema::hasColumn('affiliations', 'position')) {
                $table->string('position', 50)->nullable()->after('affiliation_type');
            }
        });

        // Raw inserts (e.g. MorphToMany::attach()) bypass the HasUuids trait,
        // so the DB needs defaults for NOT NULL columns they don't provide.
        DB::statement('ALTER TABLE affiliations ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("ALTER TABLE affiliations ALTER COLUMN affiliation_type SET DEFAULT 'member'");

        Schema::table('persons', function (Blueprint $table) {
            if (! Schema::hasColumn('persons', 'searchable_name')) {
                $table->string('searchable_name', 512)->nullable()->index();
            }
            if (! Schema::hasColumn('persons', 'verified_at')) {
                $table->timestampTz('verified_at')->nullable();
            }
            if (! Schema::hasColumn('persons', 'verified_by')) {
                $table->foreignUuid('verified_by')->nullable();
            }
            if (! Schema::hasColumn('persons', 'rejected_at')) {
                $table->timestampTz('rejected_at')->nullable();
            }
            if (! Schema::hasColumn('persons', 'last_state_change_at')) {
                $table->timestampTz('last_state_change_at')->nullable();
            }
            if (! Schema::hasColumn('persons', 'allow_public_event_submission')) {
                $table->boolean('allow_public_event_submission')->default(true);
            }
            if (! Schema::hasColumn('persons', 'public_submission_locked_at')) {
                $table->timestampTz('public_submission_locked_at')->nullable();
            }
            if (! Schema::hasColumn('persons', 'public_submission_locked_by')) {
                $table->foreignUuid('public_submission_locked_by')->nullable();
            }

            $table->index(['status', 'name'], 'persons_status_name');
            $table->index(['gender', 'status', 'name'], 'persons_gender_status_name');
            $table->index('updated_at', 'persons_sitemap');
        });
    }
};
