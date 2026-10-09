<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_cap_counters', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('cap_key', 191);
            $table->string('window_key', 64);
            $table->string('family_key', 64)->index();
            $table->string('scope', 64)->index();
            $table->unsignedInteger('limit_value');
            $table->unsignedInteger('consumed');
            $table->timestampsTz();

            $table->unique(['cap_key', 'window_key'], 'reward_caps_key_window_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_cap_counters');
    }
};
