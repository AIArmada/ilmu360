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
        Schema::create('title_issuers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('country_id')->nullable();
            $table->foreignUuid('institution_id')->nullable()->index();
            $table->string('issuer_name', 255);
            $table->string('issuer_type', 50);
            $table->timestampsTz();
            $table->index('issuer_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('title_issuers');
    }
};
