<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_key_people', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('event_id')->index();
            $table->uuid('speaker_id')->nullable()->index();
            $table->string('role')->index();
            $table->string('name')->nullable();
            $table->unsignedSmallInteger('order_column')->default(0);
            $table->boolean('is_public')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['event_id', 'order_column']);
            $table->index(['event_id', 'role'], 'event_key_people_event_role');
            $table->index(['event_id', 'speaker_id'], 'event_key_people_event_speaker');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_key_people');
    }
};
