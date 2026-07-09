<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('speakers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('searchable_name', 512)->default('')->index();
            $table->string('gender')->nullable()->default('male'); // male, female
            $table->jsonb('honorific')->nullable(); // Multiple honorifics: ["dr", "prof", "ustaz"]
            $table->jsonb('pre_nominal')->nullable(); // Multiple pre-nominals: ["tun", "datuk_seri"]
            $table->jsonb('post_nominal')->nullable(); // Multiple post-nominals: ["phd", "msc", "ma"]
            $table->string('slug')->unique();
            $table->jsonb('bio')->nullable();

            $table->jsonb('qualifications')->nullable();
            $table->boolean('is_freelance')->default(false);
            $table->string('job_title')->nullable();

            $table->string('status')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->timestampTz('rejected_at')->nullable();
            $table->timestampTz('inactive_at')->nullable();
            $table->timestampTz('last_state_change_at')->nullable();

            $table->boolean('allow_public_event_submission')->default(true)->index();
            $table->timestamp('public_submission_locked_at')->nullable()->index();
            $table->foreignUuid('public_submission_locked_by')->nullable()->index();

            $table->timestamps();

            // Main listing: WHERE status IN ('verified','pending') ORDER BY name
            $table->index(['status', 'name'], 'speakers_status_name');

            // Combined filters: WHERE gender='X' AND status='Y' ORDER BY name
            $table->index(['gender', 'status', 'name'], 'speakers_gender_status_name');

            // Sitemap generation: ORDER BY updated_at DESC
            $table->index('updated_at', 'speakers_sitemap');
        });

        Schema::create('speaker_search_terms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('speaker_id')->index();
            $table->string('term', 120)->index();
            $table->index(['speaker_id', 'term']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('speakers');
        Schema::dropIfExists('speaker_search_terms');
    }
};
