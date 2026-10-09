<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('share_effect_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('effect_key', 191)->unique();
            $table->string('source_type', 64)->index();
            $table->string('source_id', 191)->index();
            $table->string('effect_type', 64)->index();
            $table->string('status', 16)->default('pending')->index();
            $table->json('payload')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->uuid('lease_token')->nullable()->index();
            $table->string('leased_by', 64)->nullable();
            $table->timestampTz('lease_expires_at')->nullable()->index();
            $table->timestampTz('succeeded_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('share_effect_receipts');
    }
};
