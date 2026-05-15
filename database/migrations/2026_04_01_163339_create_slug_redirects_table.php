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
        Schema::create('slug_redirects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuidMorphs('redirectable');
            $table->string('source_slug');
            $table->string('source_path', 512)->unique();
            $table->string('destination_slug');
            $table->string('destination_path', 512);
            $table->timestamp('first_visited_at')->nullable();
            $table->timestamp('last_redirected_at')->nullable();
            $table->unsignedInteger('redirect_count')->default(0);
            $table->timestamps();

            $table->index(['redirectable_type', 'redirectable_id'], 'slug_redirects_redirectable_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('slug_redirects');
    }
};
