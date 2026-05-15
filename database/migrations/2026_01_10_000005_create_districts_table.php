<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('districts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id');
            $table->foreignId('state_id');
            $table->string('name');
            $table->string('country_code', 3)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('districts');
    }
};
