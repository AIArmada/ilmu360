<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_benefit_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('program_id')->index();
            $table->string('benefit_key', 64);
            $table->string('title', 191);
            $table->json('conditions');
            $table->unsignedBigInteger('cost_points')->nullable();
            $table->string('cost_unit', 16)->nullable();
            $table->boolean('direct_entitlement');
            $table->unsignedBigInteger('capacity');
            $table->json('fulfillment_config')->nullable();
            $table->string('status', 16)->index();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('retired_at')->nullable();
            $table->timestampsTz();
            $table->unique(['program_id', 'benefit_key']);
        });

        Schema::create('reward_benefit_stock_movements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('movement_key', 191)->unique();
            $table->uuid('item_id')->index();
            $table->string('kind', 16)->index();
            $table->uuid('grant_id')->nullable()->index();
            $table->unsignedBigInteger('quantity');
            $table->string('actor_type', 32);
            $table->string('actor_id', 191)->nullable();
            $table->string('actor_role', 16);
            $table->timestampTz('decided_at');
            $table->timestampsTz();
            $table->index(['item_id', 'kind']);
        });

        Schema::create('reward_benefit_grants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('grant_key', 191)->unique();
            $table->uuid('item_id')->index();
            $table->string('recipient_type', 191);
            $table->string('recipient_id', 191);
            $table->uuid('award_id')->nullable()->index();
            $table->string('reservation_key', 191)->nullable()->index();
            $table->unsignedBigInteger('quantity');
            $table->string('status', 16)->index();
            $table->string('fulfillment_adapter', 16);
            $table->string('external_ref', 191)->nullable();
            $table->json('evidence')->nullable();
            $table->string('actor_type', 32);
            $table->string('actor_id', 191)->nullable();
            $table->string('actor_role', 16);
            $table->timestampTz('reserved_at');
            $table->timestampTz('issued_at')->nullable();
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('expired_at')->nullable();
            $table->timestampsTz();
            $table->index(['recipient_type', 'recipient_id', 'status']);
        });

        Schema::create('reward_recoveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('recovery_key', 191)->unique();
            $table->uuid('award_id')->index();
            $table->uuid('payout_request_id')->nullable()->index();
            $table->uuid('grant_id')->nullable()->index();
            $table->string('kind', 16)->index();
            $table->string('status', 16)->index();
            $table->unsignedBigInteger('amount_minor')->nullable();
            $table->char('currency', 3)->nullable();
            $table->unsignedBigInteger('points')->nullable();
            $table->string('unit', 16)->nullable();
            $table->json('evidence')->nullable();
            $table->string('actor_type', 32);
            $table->string('actor_id', 191)->nullable();
            $table->string('actor_role', 16);
            $table->timestampTz('opened_at');
            $table->timestampTz('settled_at')->nullable();
            $table->timestampTz('written_off_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_recoveries');
        Schema::dropIfExists('reward_benefit_grants');
        Schema::dropIfExists('reward_benefit_stock_movements');
        Schema::dropIfExists('reward_benefit_items');
    }
};
