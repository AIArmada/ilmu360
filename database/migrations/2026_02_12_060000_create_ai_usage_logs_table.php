<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_usage_logs')) {
            return;
        }

        Schema::create('ai_usage_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('invocation_id')->index();
            $table->string('operation')->index();
            $table->string('provider')->nullable()->index();
            $table->string('model')->nullable()->index();
            $table->unsignedBigInteger('input_tokens')->nullable();
            $table->unsignedBigInteger('output_tokens')->nullable();
            $table->unsignedBigInteger('cache_write_input_tokens')->nullable();
            $table->unsignedBigInteger('cache_read_input_tokens')->nullable();
            $table->unsignedBigInteger('reasoning_tokens')->nullable();
            $table->unsignedBigInteger('total_tokens')->nullable();
            $table->decimal('cost_usd', 14, 8)->nullable();
            $table->string('currency', 3)->default('USD');
            $table->foreignUuid('user_id')->nullable()->index();
            $table->jsonb('meta')->nullable();
            $table->timestamps();

            $table->index(['provider', 'model'], 'ai_usage_logs_provider_model_index');
            $table->index(['operation', 'created_at'], 'ai_usage_logs_operation_created_at_index');
        });
    }
};
