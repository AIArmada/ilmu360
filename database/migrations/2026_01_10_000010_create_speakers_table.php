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
            $table->boolean('is_active')->default(true);

            $table->boolean('allow_public_event_submission')->default(true)->index();
            $table->timestamp('public_submission_locked_at')->nullable()->index();
            $table->foreignUuid('public_submission_locked_by')->nullable()->index();

            $table->timestamps();

            // Optimized composite indexes for common query patterns
            // Main listing: WHERE status='verified' AND is_active=true ORDER BY name
            $table->index(['status', 'is_active', 'name'], 'speakers_status_active_name');

            // Gender filtering: WHERE gender='male' AND is_active=true ORDER BY name
            $table->index(['gender', 'is_active', 'name'], 'speakers_gender_active_name');

            // Combined filters: WHERE gender='X' AND status='Y' AND is_active=true ORDER BY name
            $table->index(['gender', 'status', 'is_active', 'name'], 'speakers_gender_status_active');

            // Sitemap generation: ORDER BY updated_at DESC
            $table->index('updated_at', 'speakers_sitemap');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('speakers');
    }
};
