<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('languageables');
        Schema::dropIfExists('languages');

        Schema::create('languages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 10)->unique();
            $table->string('name');
            $table->string('native')->nullable();
            $table->string('dir', 3)->default('ltr');
            $table->timestampsTz();
        });

        Schema::create('languageables', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('language_id');
            $table->uuidMorphs('languageable');
            $table->timestamps();

            $table->index(['language_id', 'languageable_type', 'languageable_id'], 'languageables_lookup');
        });
    }
};
