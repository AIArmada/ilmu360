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
            $table->timestamps();

            $table->primary(['institution_id', 'space_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('institution_space');
    }
};
