<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_version_publications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('program_id')->index();
            $table->foreignUuid('version_id')->index();
            $table->foreignUuid('previous_version_id')->nullable()->index();
            $table->unsignedInteger('version');
            $table->timestampTz('effective_from');
            $table->string('reason', 191)->nullable();
            $table->string('published_by_type', 64)->nullable();
            $table->foreignUuid('published_by_id')->nullable()->index();
            $table->timestampTz('published_at');
            $table->timestampsTz();

            $table->unique(['program_id', 'version_id'], 'reward_publications_program_version_unique');
            $table->index(['program_id', 'published_at'], 'reward_publications_program_published_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_version_publications');
    }
};
