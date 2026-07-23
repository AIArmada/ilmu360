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
        Schema::create('credential_definitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 200);
            $table->string('short_form', 50)->nullable();
            $table->string('field', 100)->nullable();
            $table->string('credential_type', 50);
            $table->string('language_code', 10)->nullable();
            $table->timestampsTz();
            $table->index('credential_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('credential_definitions');
    }
};
