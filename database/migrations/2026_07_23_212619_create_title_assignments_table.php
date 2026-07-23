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
        Schema::create('title_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('titleable_type', 255);
            $table->uuid('titleable_id');
            $table->foreignUuid('title_id')->index();
            $table->foreignUuid('issuer_id')->nullable();
            $table->date('date_awarded')->nullable();
            $table->date('date_expired')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestampsTz();
            $table->index(['titleable_type', 'titleable_id'], 'title_assignments_titleable_index');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('title_assignments');
    }
};
