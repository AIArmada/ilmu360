<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('reference_user')) {
            return;
        }

        Schema::create('reference_user', function (Blueprint $table): void {
            $table->uuid('reference_id')->index();
            $table->uuid('user_id')->index();
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->primary(['reference_id', 'user_id']);
        });
    }
};
