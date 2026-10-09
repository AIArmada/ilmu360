<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('share_browser_selections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('browser_key', 160)->unique();
            $table->foreignUuid('current_attribution_id')->nullable()->index();
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('share_browser_selections');
    }
};
