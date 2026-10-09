<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_appeals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('award_id')->index();
            $table->unsignedInteger('seq');
            $table->string('status', 16)->index();
            $table->string('opened_by_type', 64);
            $table->string('opened_by_id', 64);
            $table->string('opened_by_role', 32);
            $table->string('reason', 191);
            $table->json('evidence')->nullable();
            $table->timestampTz('window_ends_at');
            $table->timestampTz('opened_at');
            $table->timestampTz('in_review_at')->nullable();
            $table->timestampTz('upheld_at')->nullable();
            $table->timestampTz('overturned_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['award_id', 'seq'], 'reward_appeals_award_seq_unique');
            $table->index(['award_id', 'status'], 'reward_appeals_award_status_idx');
        });

        Schema::create('reward_appeal_decisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('appeal_id')->index();
            $table->unsignedInteger('seq');
            $table->string('from_status', 16);
            $table->string('to_status', 16);
            $table->string('actor_type', 64);
            $table->string('actor_id', 64)->nullable();
            $table->string('actor_role', 32);
            $table->string('reason', 191);
            $table->json('evidence')->nullable();
            $table->timestampTz('decided_at');
            $table->timestampsTz();

            $table->unique(['appeal_id', 'seq'], 'reward_appeal_decisions_appeal_seq_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_appeal_decisions');
        Schema::dropIfExists('reward_appeals');
    }
};
