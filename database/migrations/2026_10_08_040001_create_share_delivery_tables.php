<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('share_cohort_projections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('business_key', 191)->unique();
            $table->string('outcome', 64)->index();
            $table->string('cohort_week', 10)->index();
            $table->string('stage', 32)->index();
            $table->timestampTz('occurred_at');
            $table->timestampTz('projected_at');
            $table->timestampsTz();
            $table->index(['cohort_week', 'stage']);
        });

        Schema::create('reward_digest_slots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('recipient_type', 191);
            $table->string('recipient_id', 191);
            $table->string('local_day', 10);
            $table->string('plan_key', 191)->unique();
            $table->string('status', 16)->index();
            $table->json('categories');
            $table->json('payload');
            $table->string('timezone', 64);
            $table->timestampTz('due_at');
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('communication_id', 191)->nullable();
            $table->timestampsTz();
            $table->unique(['recipient_type', 'recipient_id', 'local_day']);
            $table->index(['status', 'due_at']);
        });

        Schema::create('community_releases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('release_key', 191)->unique();
            $table->string('period_start', 10);
            $table->string('period_end', 10);
            $table->json('cells');
            $table->unsignedBigInteger('sharer_count');
            $table->unsignedBigInteger('participant_count');
            $table->string('published_by_type', 32);
            $table->string('published_by_id', 191)->nullable();
            $table->string('published_by_role', 16);
            $table->timestampTz('published_at');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_releases');
        Schema::dropIfExists('reward_digest_slots');
        Schema::dropIfExists('share_cohort_projections');
    }
};
