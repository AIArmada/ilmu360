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
        Schema::create('event_reference', function (Blueprint $table) {
            $table->foreignUuid('event_id');
            $table->foreignUuid('reference_id');
            $table->unsignedInteger('order_column')->nullable();
            $table->primary(['event_id', 'reference_id']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_reference');
    }
};
