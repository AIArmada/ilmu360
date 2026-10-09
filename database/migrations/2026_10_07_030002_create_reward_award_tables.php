<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_awards', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('program_id')->index();
            $table->foreignUuid('policy_version_id')->nullable()->index();
            $table->foreignUuid('enrollment_id')->nullable()->index();
            $table->foreignUuid('acceptance_id')->nullable()->index();
            $table->string('recipient_type', 64)->index();
            $table->foreignUuid('recipient_id')->index();
            $table->string('beneficiary_role', 32)->index();
            $table->string('source_business_key', 191)->index();
            $table->string('family_key', 64)->index();
            $table->string('component', 16)->index();
            $table->string('status', 16)->index();
            $table->json('amount_snapshot');
            $table->json('policy_snapshot');
            $table->json('consent_snapshot');
            $table->json('source_snapshot');
            $table->unsignedInteger('obligation_epoch')->default(0);
            $table->unsignedBigInteger('obligated_minor')->default(0);
            $table->timestampTz('held_at')->nullable();
            $table->timestampTz('pending_review_at')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('rejected_at')->nullable();
            $table->timestampTz('fulfilled_at')->nullable();
            $table->timestampTz('reversed_at')->nullable();
            $table->timestampTz('corrected_at')->nullable();
            $table->timestampTz('acknowledged_at')->nullable();
            $table->timestampsTz();

            $table->unique(
                ['recipient_type', 'recipient_id', 'source_business_key', 'family_key', 'component'],
                'reward_awards_canonical_unique'
            );
            $table->index(['program_id', 'status'], 'reward_awards_program_status_idx');
            $table->index(['family_key', 'status'], 'reward_awards_family_status_idx');
        });

        Schema::create('reward_award_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('award_id')->index();
            $table->unsignedInteger('seq');
            $table->string('from_status', 16);
            $table->string('to_status', 16);
            $table->string('actor_type', 64);
            $table->string('actor_id', 64)->nullable();
            $table->string('actor_role', 32);
            $table->string('reason', 191);
            $table->json('evidence')->nullable();
            $table->string('source_operation_id', 191)->nullable()->index();
            $table->timestampTz('decided_at');
            $table->timestampsTz();

            $table->unique(['award_id', 'seq'], 'reward_award_entries_award_seq_unique');
            $table->unique(['award_id', 'source_operation_id'], 'reward_award_entries_award_operation_unique');
            $table->index(['award_id', 'decided_at'], 'reward_award_entries_award_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_award_entries');
        Schema::dropIfExists('reward_awards');
    }
};
