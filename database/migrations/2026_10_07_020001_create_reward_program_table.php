<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_programs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('family_key', 64)->unique();
            $table->string('title', 191);
            $table->string('mode', 32)->index();
            $table->string('owner_type', 64)->index();
            $table->foreignUuid('owner_id')->nullable()->index();
            $table->string('purpose', 191);
            $table->string('status', 16)->index();
            $table->foreignUuid('current_version_id')->nullable()->index();
            $table->timestampTz('ready_at')->nullable();
            $table->timestampTz('active_at')->nullable();
            $table->timestampTz('paused_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampsTz();

            $table->index(['owner_type', 'owner_id'], 'reward_programs_owner_idx');
            $table->index(['status', 'mode'], 'reward_programs_status_mode_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_programs');
    }
};
