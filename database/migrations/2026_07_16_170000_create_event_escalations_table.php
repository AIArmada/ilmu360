<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('event_escalations')) {
            return;
        }

        Schema::create('event_escalations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->index();
            $table->string('type');
            $table->string('decision_key')->unique();
            $table->text('reason')->nullable();
            $table->timestampTz('dispatched_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['event_id', 'type'], 'event_escalations_event_type_index');
        });
    }
};
