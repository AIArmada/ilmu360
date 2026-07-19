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
            $table->string('nickname')->nullable();
            $table->string('slug')->unique();

            $table->text('description')->nullable();

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

            // Main listing: WHERE status IN ('verified','pending') ORDER BY name
            $table->index(['status', 'name'], 'institutions_status_name');

            // Combined filters: WHERE type='X' AND status='Y' ORDER BY name
            $table->index(['type', 'status', 'name'], 'institutions_type_status_name');

            // Sitemap generation: ORDER BY updated_at DESC
            $table->index('updated_at', 'institutions_sitemap');
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('institutions');
    }
};
