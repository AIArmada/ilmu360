<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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

            $table->string('status')->nullable();
            $table->boolean('is_active')->default(true);

            $table->boolean('allow_public_event_submission')->default(true)->index();
            $table->timestamp('public_submission_locked_at')->nullable()->index();
            $table->foreignUuid('public_submission_locked_by')->nullable()->index();

            $table->timestamps();

            // Optimized composite indexes for common query patterns
            // Main listing: WHERE status='verified' AND is_active=true ORDER BY name
            $table->index(['status', 'is_active', 'name'], 'institutions_status_active_name');

            // Type filtering: WHERE type='masjid' AND is_active=true ORDER BY name
            $table->index(['type', 'is_active', 'name'], 'institutions_type_active_name');

            // Combined filters: WHERE type='X' AND status='Y' AND is_active=true ORDER BY name
            $table->index(['type', 'status', 'is_active', 'name'], 'institutions_type_status_active');

            // Sitemap generation: ORDER BY updated_at DESC
            $table->index('updated_at', 'institutions_sitemap');
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('institutions');
    }
};
