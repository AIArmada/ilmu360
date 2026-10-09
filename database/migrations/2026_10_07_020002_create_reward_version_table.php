<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_program_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('program_id')->index();
            $table->unsignedInteger('version');
            $table->timestampTz('effective_from');
            $table->string('material_terms_hash', 64)->index();
            $table->json('rules');
            $table->json('terms');
            $table->json('compatible_with');
            $table->string('published_by_type', 64)->nullable();
            $table->foreignUuid('published_by_id')->nullable()->index();
            $table->timestampTz('published_at')->index();
            $table->timestampsTz();

            $table->unique(['program_id', 'version'], 'reward_versions_program_version_unique');
            $table->unique(['program_id', 'effective_from'], 'reward_versions_program_effective_unique');
            $table->index(['program_id', 'effective_from'], 'reward_versions_program_effective_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_program_versions');
    }
};
