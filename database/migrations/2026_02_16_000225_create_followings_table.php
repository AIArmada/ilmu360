<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('followings', function (Blueprint $table) {
            $table->foreignUuid('user_id');
            $table->uuidMorphs('followable');
            $table->timestamps();

            $table->primary(['user_id', 'followable_id', 'followable_type']);

            $table->index(['followable_type', 'followable_id', 'user_id'], 'followings_followable_user');
        });
    }
};
