<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('app_feedback')) {
            return;
        }

        Schema::create('app_feedback', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('reporter_id');
            $table->uuid('submission_key');
            $table->char('payload_hash', 64);

            $table->string('category', 32);
            $table->string('title', 180);
            $table->text('description');
            $table->string('page_url', 2048)->nullable();
            $table->json('diagnostics')->nullable();

            $table->string('status', 24)->default('new');
            $table->foreignUuid('handled_by')->nullable();
            $table->text('internal_notes')->nullable();
            $table->text('resolution_note')->nullable();

            $table->timestampTz('triaged_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampTz('declined_at')->nullable();
            $table->timestampTz('last_state_change_at');

            $table->string('github_escalation_status', 24)->default('not_requested');
            $table->uuid('github_escalation_token')->nullable();
            $table->timestampTz('github_requested_at')->nullable();
            $table->timestampTz('github_created_at')->nullable();
            $table->timestampTz('github_unknown_at')->nullable();
            $table->unsignedBigInteger('github_issue_number')->nullable();
            $table->string('github_issue_url', 2048)->nullable();

            $table->timestampsTz();

            $table->unique(['reporter_id', 'submission_key']);
            $table->index(['reporter_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_feedback');
    }
};
