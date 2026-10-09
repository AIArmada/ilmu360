<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_milestone_cycles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('recipient_type', 64)->index();
            $table->foreignUuid('recipient_id')->index();
            $table->string('family_key', 64)->index();
            $table->foreignUuid('program_id')->nullable()->index();
            $table->string('milestone_key', 64)->index();
            $table->timestampTz('window_start');
            $table->unsignedInteger('window_days');
            $table->unsignedInteger('target_count');
            $table->unsignedInteger('current_count');
            $table->json('reward_snapshot');
            $table->string('status', 16)->index();
            $table->foreignUuid('award_id')->nullable()->index();
            $table->timestampTz('achieved_at')->nullable();
            $table->timestampTz('invalidated_at')->nullable();
            $table->timestampsTz();

            $table->unique(
                ['recipient_type', 'recipient_id', 'family_key', 'milestone_key', 'window_start'],
                'reward_cycles_recipient_family_milestone_window_unique'
            );
        });

        Schema::create('reward_milestone_contributions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('cycle_id')->index();
            $table->string('source_business_key', 191)->index();
            $table->string('contributor_type', 64);
            $table->string('contributor_id', 64);
            $table->string('contributor_role', 32);
            $table->string('outcome', 64);
            $table->json('source_snapshot');
            $table->timestampTz('occurred_at');
            $table->timestampTz('contributed_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->string('revoke_reason', 191)->nullable();
            $table->timestampsTz();

            $table->unique(['cycle_id', 'source_business_key'], 'reward_contributions_cycle_source_unique');
            $table->index(['cycle_id', 'revoked_at'], 'reward_contributions_cycle_revoked_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_milestone_contributions');
        Schema::dropIfExists('reward_milestone_cycles');
    }
};
