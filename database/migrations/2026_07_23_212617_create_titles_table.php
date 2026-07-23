<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('titles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('category_id')->index();
            $table->string('name', 100);
            $table->string('short_form', 50)->nullable();
            $table->foreignUuid('country_id')->nullable();
            $table->string('language_code', 10)->nullable();
            $table->string('usage_position', 20);
            $table->integer('sort_order')->default(0);
            $table->text('description')->nullable();
            $table->timestampsTz();
            $table->index(['usage_position', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('titles');
    }
};
