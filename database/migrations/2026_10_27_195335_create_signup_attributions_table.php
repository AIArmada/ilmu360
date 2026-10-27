<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('signup_attributions')) {
            return;
        }

        Schema::create('signup_attributions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique();
            $table->foreignUuid('referrer_user_id')->nullable();
            $table->string('source_type', 32);
            $table->string('status', 16);
            $table->foreignUuid('affiliate_id')->nullable();
            $table->foreignUuid('affiliate_link_id')->nullable();
            $table->foreignUuid('affiliate_attribution_id')->nullable();
            $table->foreignUuid('referral_invite_id')->nullable();
            $table->string('invitee_contact_hash', 64)->nullable();
            $table->timestampTz('signed_up_at');
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampsTz();

            $table->index(['referrer_user_id', 'status']);
            $table->index(['affiliate_id', 'source_type', 'referrer_user_id'], 'signup_attributions_guest_lookup');
        });
    }
};
