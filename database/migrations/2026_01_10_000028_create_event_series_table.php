<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_series', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->index();
            $table->foreignUuid('series_id')->index();
            $table->unsignedSmallInteger('order_column')->default(0)->index();
            $table->timestamps();

            $table->unique(['event_id', 'series_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_series');
    }
};
