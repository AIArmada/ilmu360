<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_model_pricings')) {
            return;
        }

        Schema::create('ai_model_pricings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('provider')->default('*')->index();
            $table->string('model_pattern')->default('*')->index();
            $table->string('operation')->default('*')->index();
            $table->string('tier')->nullable()->index();
            $table->string('currency', 3)->default('USD');
            $table->decimal('input_per_million', 14, 8)->nullable();
            $table->decimal('output_per_million', 14, 8)->nullable();
            $table->decimal('cache_write_input_per_million', 14, 8)->nullable();
            $table->decimal('cache_read_input_per_million', 14, 8)->nullable();
            $table->decimal('reasoning_per_million', 14, 8)->nullable();
            $table->decimal('per_request', 14, 8)->nullable();
            $table->decimal('per_image', 14, 8)->nullable();
            $table->decimal('per_audio_second', 14, 8)->nullable();
            $table->string('status')->default('active')->index();
            $table->timestampTz('last_state_change_at')->nullable();
            $table->unsignedInteger('priority')->default(100)->index();
            $table->timestamp('starts_at')->nullable()->index();
            $table->timestamp('ends_at')->nullable()->index();
            $table->text('notes')->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestamps();

            $table->index(['provider', 'operation', 'tier'], 'ai_model_pricings_lookup_index');
            $table->index(['status', 'priority'], 'ai_model_pricings_status_priority_index');
        });
    }
};
