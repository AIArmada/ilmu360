<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('reporter_type', 255)->default('user');
            $table->foreignUuid('reporter_id')->nullable()->index();
            $table->string('reporter_fingerprint', 128)->nullable()->index();

            $table->string('entity_type')->index();
            $table->uuid('entity_id')->index();
            $table->index(['entity_type', 'entity_id']);

            $table->string('report_type')->index();
            $table->text('message')->nullable();
            $table->string('title', 255)->nullable();

            $table->string('severity', 255)->default('medium');
            $table->string('status')->default('open')->index();
            $table->text('resolution')->nullable();
            $table->text('internal_notes')->nullable();
            $table->jsonb('metadata')->nullable();

            $table->foreignUuid('handled_by')->nullable()->index();
            $table->string('reviewed_by_type', 255)->nullable();
            $table->uuid('reviewed_by_id')->nullable();

            $table->timestampTz('reported_at')->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampTz('rejected_at')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
