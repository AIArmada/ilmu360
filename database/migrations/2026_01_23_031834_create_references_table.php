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
        Schema::create('references', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('author')->nullable();
            $table->string('type')->default('book')->index(); // e.g. book, article, video, etc.
            $table->string('publication_year')->nullable();
            $table->string('publisher')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_canonical')->default(false)->index(); // Generic/Official reference
            $table->string('status')->default('verified')->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('reference_user', function (Blueprint $table): void {
            $table->foreignUuid('reference_id')->index();
            $table->foreignUuid('user_id')->index();
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->primary(['reference_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reference_user');
        Schema::dropIfExists('references');
    }
};
