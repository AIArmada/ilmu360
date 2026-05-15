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
        Schema::create('addresses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuidMorphs('addressable');

            $table->string('type')->default('main')->index(); // e.g. main, billing, shipping

            $table->string('line1')->nullable();
            $table->string('line2')->nullable();
            $table->string('postcode', 16)->nullable();

            $table->foreignId('country_id')->nullable()->index();
            $table->foreignId('state_id')->nullable()->index();
            $table->foreignId('district_id')->nullable()->index();
            $table->foreignId('subdistrict_id')->nullable();
            $table->foreignId('city_id')->nullable()->index();

            $table->decimal('lat', 10, 7)->nullable()->index();
            $table->decimal('lng', 10, 7)->nullable()->index();

            $table->string('google_maps_url')->nullable();
            $table->string('google_place_id')->nullable()->index();
            $table->string('waze_url')->nullable();

            $table->timestamps();

            // Composite indexes for common queries
            $table->index(['addressable_type', 'addressable_id'], 'addresses_morphs_index');
            $table->index(['country_id', 'state_id'], 'addresses_country_state_index');
            $table->index(['state_id', 'city_id'], 'addresses_state_city_index');
            $table->index('postcode', 'addresses_postcode_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('addresses');
    }
};
