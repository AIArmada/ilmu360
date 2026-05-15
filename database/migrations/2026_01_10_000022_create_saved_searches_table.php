<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_searches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->index();

            $table->string('name');
            $table->string('query')->nullable();
            $table->jsonb('filters')->nullable();

            $table->unsignedSmallInteger('radius_km')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();

            $table->string('notify')->default('daily')->index();

            $table->timestamps();

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_searches');
    }
};
