<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_source_component_claims', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('source_business_key', 191)->index();
            $table->string('component', 16)->index();
            $table->string('beneficiary_role', 32)->index();
            $table->string('family_key', 64)->index();
            $table->foreignUuid('program_id')->index();
            $table->foreignUuid('award_id')->index();
            $table->json('compatible_snapshot');
            $table->timestampTz('claimed_at');
            $table->timestampsTz();

            $table->unique(
                ['source_business_key', 'component', 'beneficiary_role', 'family_key'],
                'reward_claims_source_component_role_family_unique'
            );
            $table->index(
                ['source_business_key', 'component', 'beneficiary_role'],
                'reward_claims_source_component_role_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_source_component_claims');
    }
};
