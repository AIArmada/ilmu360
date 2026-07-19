<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tags')) {
            return;
        }

        Schema::create('tags', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type')->nullable()->index();
            $table->addColumn('jsonb', 'name');
            $table->addColumn('jsonb', 'slug')->nullable();
            $table->addColumn('jsonb', 'description')->nullable();
            $table->unsignedInteger('order_column')->nullable();
            $table->timestampsTz();
        });

        Schema::create('taggables', function (Blueprint $table): void {
            $table->foreignUuid('tag_id');
            $table->foreignUuid('taggable_id');
            $table->string('taggable_type');
            $table->unique(['tag_id', 'taggable_id', 'taggable_type']);
            $table->index(['taggable_id', 'taggable_type']);
        });
    }
};
