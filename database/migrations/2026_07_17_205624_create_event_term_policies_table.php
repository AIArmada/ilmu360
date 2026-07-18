<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_term_policies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_term_id');
            $table->string('policy_code', 64);
            $table->boolean('is_enabled')->default(true);
            $table->timestampsTz();
            $table->unique(['event_term_id', 'policy_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_term_policies');
    }
};
