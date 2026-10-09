<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('share_guest_claims', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('guest_affiliate_id')->unique();
            $table->foreignUuid('claimant_user_id')->index();
            $table->string('capability_verifier', 64);
            $table->string('status', 16)->default('active')->index();
            $table->timestampTz('claimed_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('share_guest_claims');
    }
};
