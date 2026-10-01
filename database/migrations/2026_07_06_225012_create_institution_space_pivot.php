<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('institution_space', function (Blueprint $table) {
            $table->foreignUuid('institution_id')->index();
            $table->foreignUuid('space_id')->index();
            $table->unsignedInteger('capacity')->nullable();
            $table->timestamps();

            $table->primary(['institution_id', 'space_id']);
        });

        Schema::create('institution_venue', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->index();
            $table->foreignUuid('venue_id')->index();
            $table->string('role');
            $table->boolean('is_primary')->default(false);
            $table->timestampsTz();

            $table->unique(['institution_id', 'venue_id'], 'institution_venue_institution_venue_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('institution_venue');
        Schema::dropIfExists('institution_space');
    }
};
