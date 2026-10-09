<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_adjustments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('award_id')->nullable()->index();
            $table->foreignUuid('appeal_id')->nullable()->index();
            $table->foreignUuid('linked_adjustment_id')->nullable()->index();
            $table->string('kind', 16)->index();
            $table->string('status', 16)->index();
            $table->string('operation_key', 191)->unique();
            $table->json('amount_snapshot')->nullable();
            $table->string('actor_type', 64);
            $table->string('actor_id', 64)->nullable();
            $table->string('actor_role', 32);
            $table->string('reason', 191);
            $table->json('evidence')->nullable();
            $table->string('resolved_by_type', 64)->nullable();
            $table->string('resolved_by_id', 64)->nullable();
            $table->string('resolve_reason', 191)->nullable();
            $table->timestampTz('opened_at');
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();

            $table->index(['award_id', 'status'], 'reward_adjustments_award_status_idx');
            $table->index(['appeal_id', 'status'], 'reward_adjustments_appeal_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_adjustments');
    }
};
