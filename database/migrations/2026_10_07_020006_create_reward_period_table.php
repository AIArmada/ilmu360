<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_enrollment_periods', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('enrollment_id')->index();
            $table->unsignedInteger('seq');
            $table->timestampTz('joined_at');
            $table->timestampTz('left_at')->nullable();
            $table->foreignUuid('join_acceptance_id')->index();
            $table->string('leave_reason', 191)->nullable();
            $table->timestampsTz();

            $table->unique(['enrollment_id', 'seq'], 'reward_periods_enrollment_seq_unique');
            $table->index(['enrollment_id', 'joined_at'], 'reward_periods_enrollment_joined_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_enrollment_periods');
    }
};
