<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('reporter_id')->nullable()->index();
            $table->string('reporter_fingerprint', 128)->nullable()->index();
            $table->foreignUuid('handled_by')->nullable()->index();

            $table->string('entity_type')->index();
            $table->uuid('entity_id')->index();

            $table->string('category')->index();

            $table->text('description')->nullable();

            $table->string('status')->default('open')->index();
            $table->text('resolution_note')->nullable();

            $table->timestamps();

            $table->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
