<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('share_signal_reader_cursors', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('reader_name', 64)->unique();
            $table->timestampTz('last_occurred_at')->nullable();
            $table->string('last_signal_event_id', 36)->nullable();
            $table->unsignedBigInteger('processed_total')->default(0);
            $table->timestampsTz();
        });

        Schema::create('share_signal_reader_skips', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('reader_name', 64)->index();
            $table->foreignUuid('signal_event_id')->index();
            $table->unique(['reader_name', 'signal_event_id']);
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('share_signal_reader_skips');
        Schema::dropIfExists('share_signal_reader_cursors');
    }
};
