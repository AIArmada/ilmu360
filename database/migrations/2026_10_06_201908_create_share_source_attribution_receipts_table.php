<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('share_source_attribution_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('namespace', 64)->index();
            $table->string('outcome_type', 64)->index();
            $table->string('source_transition_id', 191)->index();
            $table->string('business_key', 191)->unique();
            $table->foreignUuid('attribution_id')->nullable()->index();
            $table->string('provenance', 32)->index();
            $table->string('definition_version', 16)->index();
            $table->timestampTz('occurred_at')->index();
            $table->timestampsTz();

            $table->index(['namespace', 'outcome_type', 'source_transition_id'], 'share_receipts_source_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('share_source_attribution_receipts');
    }
};
