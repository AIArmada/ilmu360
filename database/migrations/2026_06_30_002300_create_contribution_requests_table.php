<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('contribution_requests')) {
            return;
        }

        Schema::create('contribution_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type')->nullable();
            $table->string('subject_type')->nullable();
            $table->string('entity_type')->nullable();
            $table->uuid('entity_id')->nullable();
            $table->foreignUuid('proposer_id')->nullable();
            $table->foreignUuid('reviewer_id')->nullable();
            $table->string('status');
            $table->string('reason_code')->nullable();
            $table->text('proposer_note')->nullable();
            $table->text('reviewer_note')->nullable();
            $table->json('proposed_data')->nullable();
            $table->json('original_data')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['entity_type', 'entity_id', 'status'], 'contribution_requests_entity_status_idx');
            $table->index(['proposer_id', 'status'], 'contribution_requests_proposer_status_idx');
        });
    }
};
