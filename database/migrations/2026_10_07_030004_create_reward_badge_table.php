<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_badge_grants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('recipient_type', 64)->index();
            $table->foreignUuid('recipient_id')->index();
            $table->string('family_key', 64)->index();
            $table->string('badge_key', 64)->index();
            $table->unsignedInteger('seq');
            $table->foreignUuid('program_id')->nullable()->index();
            $table->foreignUuid('award_id')->nullable()->index();
            $table->string('status', 16)->index();
            $table->string('granted_by_type', 64);
            $table->string('granted_by_id', 64)->nullable();
            $table->string('grant_reason', 191);
            $table->json('evidence')->nullable();
            $table->timestampTz('granted_at');
            $table->string('revoked_by_type', 64)->nullable();
            $table->string('revoked_by_id', 64)->nullable();
            $table->string('revoke_reason', 191)->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();

            $table->unique(
                ['recipient_type', 'recipient_id', 'family_key', 'badge_key', 'seq'],
                'reward_badges_recipient_family_badge_seq_unique'
            );
            $table->index(
                ['recipient_type', 'recipient_id', 'family_key', 'badge_key', 'status'],
                'reward_badges_recipient_badge_status_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_badge_grants');
    }
};
