<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_point_operations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('kind', 16)->index();
            $table->string('operation_key', 191);
            $table->string('recipient_type', 64)->index();
            $table->foreignUuid('recipient_id')->index();
            $table->string('family_key', 64)->index();
            $table->string('unit', 16)->index();
            $table->unsignedBigInteger('points');
            $table->string('reservation_key', 191)->nullable()->index();
            $table->string('linked_aggregate_key', 191)->nullable()->index();
            $table->timestampsTz();

            $table->unique(
                ['kind', 'operation_key'],
                'reward_point_operations_kind_key_unique'
            );
            $table->index(
                ['recipient_type', 'recipient_id', 'family_key', 'unit'],
                'reward_point_operations_recipient_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_point_operations');
    }
};
