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
        Schema::create('event_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->unique();

            $table->string('registration_mode')->default('event')->index();
            $table->boolean('registration_required')->default(true);
            $table->unsignedInteger('capacity')->nullable();
            $table->timestamp('registration_opens_at')->nullable();
            $table->timestamp('registration_closes_at')->nullable();

            // Future extensibility
            $table->boolean('requires_approval')->default(false);
            $table->boolean('allow_waitlist')->default(false);
            $table->unsignedInteger('max_per_user')->nullable();

            $table->timestamps();

            $table->index(['event_id', 'registration_required']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_settings');
    }
};
