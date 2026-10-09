<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_point_lots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('recipient_type', 64)->index();
            $table->foreignUuid('recipient_id')->index();
            $table->string('family_key', 64)->index();
            $table->foreignUuid('program_id')->nullable()->index();
            $table->foreignUuid('award_id')->nullable()->index();
            $table->unsignedInteger('seq');
            $table->string('unit', 16)->index();
            $table->unsignedBigInteger('credited_points');
            $table->string('source_operation_key', 191)->unique();
            $table->timestampTz('available_at');
            $table->timestampTz('expires_at')->nullable()->index();
            $table->string('status', 16)->index();
            $table->timestampTz('credited_at');
            $table->timestampTz('depleted_at')->nullable();
            $table->timestampTz('expired_at')->nullable();
            $table->timestampsTz();

            $table->index(
                ['recipient_type', 'recipient_id', 'family_key', 'unit', 'status'],
                'reward_lots_recipient_family_unit_status_idx'
            );
            $table->index(
                ['recipient_type', 'recipient_id', 'family_key', 'unit', 'expires_at'],
                'reward_lots_recipient_expiry_idx'
            );
        });

        Schema::create('reward_point_movements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('lot_id')->index();
            $table->string('kind', 16)->index();
            $table->bigInteger('delta');
            $table->string('operation_key', 191)->unique();
            $table->string('aggregate_operation_key', 191)->index();
            $table->foreignUuid('linked_movement_id')->nullable()->index();
            $table->string('reservation_key', 191)->nullable()->index();
            $table->foreignUuid('award_id')->nullable()->index();
            $table->string('actor_type', 64);
            $table->string('actor_id', 64)->nullable();
            $table->string('reason', 191);
            $table->timestampTz('occurred_at');
            $table->timestampsTz();

            $table->index(['lot_id', 'kind'], 'reward_movements_lot_kind_idx');
            $table->index(['aggregate_operation_key', 'lot_id'], 'reward_movements_aggregate_lot_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_point_movements');
        Schema::dropIfExists('reward_point_lots');
    }
};
