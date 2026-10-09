<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('share_outcome_corrections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('conversion_id')->index();
            $table->string('business_key', 191)->unique();
            $table->string('reason', 64)->index();
            $table->string('actor_type')->nullable();
            $table->uuid('actor_id')->nullable()->index();
            $table->timestampTz('occurred_at')->index();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('share_outcome_corrections');
    }
};
