<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuidMorphs('mediable');
            $table->string('type')->index();
            $table->string('provider')->nullable()->index();
            $table->string('url');
            $table->boolean('is_primary')->default(false)->index();
            $table->timestamps();

            $table->index(['mediable_type', 'mediable_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_links');
    }
};
