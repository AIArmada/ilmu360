<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('institution_user', function (Blueprint $table) {
            $table->uuid('institution_id')->index();
            $table->uuid('user_id')->index();
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->primary(['institution_id', 'user_id']);

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('institution_user');
    }
};
