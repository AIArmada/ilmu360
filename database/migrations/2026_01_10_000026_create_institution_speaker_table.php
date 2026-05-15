<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('institution_speaker', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('institution_id')->index();
            $table->foreignUuid('speaker_id')->index();

            $table->string('position')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->date('joined_at')->nullable();

            $table->unique(['institution_id', 'speaker_id']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('institution_speaker');
    }
};
