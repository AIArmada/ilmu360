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
        Schema::create('person_names', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('person_id')->index();
            $table->string('name_type', 50);
            $table->string('full_name', 255);
            $table->string('language_code', 10);
            $table->boolean('is_primary')->default(false);
            $table->timestampsTz();
            $table->index(['person_id', 'name_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('person_names');
    }
};
