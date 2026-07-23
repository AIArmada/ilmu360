<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('institution_members', function (Blueprint $table) {
            $table->foreignUuid('institution_id')->index();
            $table->foreignUuid('user_id')->index();
            $table->string('role')->default('viewer');
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->primary(['institution_id', 'user_id']);
            $table->index('role');
        });

        Schema::create('person_members', function (Blueprint $table) {
            $table->foreignUuid('person_id')->index();
            $table->foreignUuid('user_id')->index();
            $table->string('role')->default('viewer');
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->primary(['person_id', 'user_id']);
            $table->index('role');
        });

        Schema::create('reference_members', function (Blueprint $table) {
            $table->foreignUuid('reference_id')->index();
            $table->foreignUuid('user_id')->index();
            $table->string('role')->default('viewer');
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->primary(['reference_id', 'user_id']);
            $table->index('role');
        });

        Schema::create('event_members', function (Blueprint $table) {
            $table->foreignUuid('event_id')->index();
            $table->foreignUuid('user_id')->index();
            $table->string('role')->default('viewer');
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->primary(['event_id', 'user_id']);
            $table->index('role');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('institution_members');
        Schema::dropIfExists('person_members');
        Schema::dropIfExists('reference_members');
        Schema::dropIfExists('event_members');
    }
};
