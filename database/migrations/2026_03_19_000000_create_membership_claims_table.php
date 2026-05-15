<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_claims', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('subject_type');
            $table->foreignUuid('subject_id');
            $table->foreignUuid('claimant_id')->nullable();
            $table->foreignUuid('reviewer_id')->nullable();
            $table->string('status');
            $table->string('granted_role_slug')->nullable();
            $table->text('justification');
            $table->text('reviewer_note')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id'], 'membership_claims_subject_index');
            $table->index(['claimant_id', 'status'], 'membership_claims_claimant_status_index');
            $table->index(['status', 'created_at'], 'membership_claims_status_created_at_index');
        });
    }

    public function down(): void {}
};
