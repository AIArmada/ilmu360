<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_enrollments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('program_id')->index();
            $table->string('member_type', 64)->index();
            $table->foreignUuid('member_id')->index();
            $table->string('status', 16)->index();
            $table->foreignUuid('current_acceptance_id')->nullable()->index();
            $table->timestampTz('enrolled_at');
            $table->timestampTz('left_at')->nullable();
            $table->timestampsTz();

            $table->unique(['program_id', 'member_type', 'member_id'], 'reward_enrollments_identity_unique');
            $table->index(['program_id', 'status'], 'reward_enrollments_program_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_enrollments');
    }
};
