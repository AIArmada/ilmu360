<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_venue', function (Blueprint $table) {
            $table->foreignUuid('user_id')->index();
            $table->foreignUuid('venue_id')->index();
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->primary(['user_id', 'venue_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_venue');
    }
};
