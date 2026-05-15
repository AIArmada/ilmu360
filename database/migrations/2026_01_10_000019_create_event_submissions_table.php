<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_submissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->index();
            $table->foreignUuid('submitted_by')->nullable()->index();
            $table->string('submitter_name')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

        });

        Schema::create('contribution_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->string('subject_type');
            $table->string('entity_type')->nullable();
            $table->uuid('entity_id')->nullable();
            $table->foreignUuid('proposer_id')->nullable()->index();
            $table->foreignUuid('reviewer_id')->nullable()->index();
            $table->string('status')->default('pending')->index();
            $table->string('reason_code')->nullable();
            $table->text('proposer_note')->nullable();
            $table->text('reviewer_note')->nullable();
            $table->jsonb('proposed_data');
            $table->jsonb('original_data')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['entity_type', 'entity_id'], 'contribution_requests_entity_idx');
            $table->index(['subject_type', 'type', 'status'], 'contribution_requests_subject_type_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contribution_requests');
        Schema::dropIfExists('event_submissions');
    }
};
