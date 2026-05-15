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
        Schema::create('social_media', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuidMorphs('socialable'); // polymorphic relation
            $table->string('platform')->index(); // e.g. facebook, twitter, instagram
            $table->string('url')->nullable();
            $table->string('username')->nullable(); // optional handle
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('social_media');
    }
};
