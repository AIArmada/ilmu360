<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_enrollment_acceptances', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('enrollment_id')->index();
            $table->unsignedInteger('seq');
            $table->foreignUuid('policy_version_id')->index();
            $table->string('material_terms_hash', 64);
            $table->string('terms_label', 191);
            $table->json('accepted_snapshot');
            $table->timestampTz('accepted_at');
            $table->string('accepted_via', 32)->nullable();
            $table->timestampsTz();

            $table->unique(['enrollment_id', 'seq'], 'reward_acceptances_enrollment_seq_unique');
            $table->index(['enrollment_id', 'accepted_at'], 'reward_acceptances_enrollment_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_enrollment_acceptances');
    }
};
