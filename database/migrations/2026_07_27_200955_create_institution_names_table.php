<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('institution_names', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->index();
            $table->string('name_type', 50);
            $table->string('full_name');
            $table->string('language_code', 10);
            $table->boolean('is_primary')->default(false);
            $table->timestampsTz();
            $table->index(['institution_id', 'name_type']);
        });
    }
};
