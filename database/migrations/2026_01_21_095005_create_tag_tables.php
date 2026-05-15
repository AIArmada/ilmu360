<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->jsonb('name');
            $table->jsonb('slug');
            $table->string('type')->nullable();
            $table->string('status')->default('verified');
            $table->integer('order_column')->nullable();

            $table->timestamps();

            $table->index(['type', 'status', 'order_column'], 'tags_type_status_order');
        });

        Schema::create('taggables', function (Blueprint $table) {
            $table->foreignUuid('tag_id')->index();

            $table->uuidMorphs('taggable');

            $table->unique(['tag_id', 'taggable_id', 'taggable_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taggables');
        Schema::dropIfExists('tags');
    }
};
