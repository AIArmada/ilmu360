<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('persons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('family_name', 100)->nullable();
            $table->string('gender')->nullable()->default('male');
            $table->date('date_of_birth')->nullable();
            $table->foreignUuid('nationality_country_id')->nullable();
            $table->string('searchable_name', 512)->default('')->index();
            $table->string('slug')->unique();
            $table->jsonb('bio')->nullable();

            $table->string('status')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->foreignUuid('verified_by')->nullable()->index();
            $table->timestampTz('rejected_at')->nullable();
            $table->timestampTz('inactive_at')->nullable();
            $table->timestampTz('last_state_change_at')->nullable();

            $table->boolean('allow_public_event_submission')->default(true)->index();
            $table->timestamp('public_submission_locked_at')->nullable()->index();
            $table->foreignUuid('public_submission_locked_by')->nullable()->index();

            $table->timestamps();

            $table->index(['status', 'name'], 'persons_status_name');
            $table->index(['gender', 'status', 'name'], 'persons_gender_status_name');
            $table->index('updated_at', 'persons_sitemap');
        });

        Schema::create('person_search_terms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('person_id')->index();
            $table->string('term', 120)->index();
            $table->index(['person_id', 'term']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('person_search_terms');
        Schema::dropIfExists('persons');
    }
};
