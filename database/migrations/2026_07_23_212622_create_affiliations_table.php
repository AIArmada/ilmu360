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
        Schema::create('affiliations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('affiliatable_type', 255);
            $table->uuid('affiliatable_id');
            $table->foreignUuid('institution_id')->nullable()->index();
            $table->string('affiliation_type', 50);
            $table->date('joined_at')->nullable();
            $table->date('left_at')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestampsTz();
            $table->index(['affiliatable_type', 'affiliatable_id'], 'affiliations_affiliatable_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('affiliations');
    }
};
