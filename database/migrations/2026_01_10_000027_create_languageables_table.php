<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('languageables')) {
            return;
        }

        Schema::create('languageables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('language_id');
            $table->uuidMorphs('languageable');
            $table->timestamps();

            // Composite index for polymorphic relationship lookups
            $table->index(['language_id', 'languageable_type', 'languageable_id'], 'languageables_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('languageables');
    }
};
