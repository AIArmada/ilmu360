<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_change_announcements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->index();
            $table->foreignUuid('replacement_event_id')->nullable()->index();
            $table->foreignUuid('actor_id')->nullable()->index();
            $table->string('type')->index();
            $table->string('status')->index();
            $table->string('severity')->index();
            $table->text('public_message')->nullable();
            $table->text('internal_note')->nullable();
            $table->jsonb('changed_fields')->nullable();
            $table->jsonb('before_snapshot')->nullable();
            $table->jsonb('after_snapshot')->nullable();
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamp('retracted_at')->nullable()->index();
            $table->timestamps();

            $table->index(['event_id', 'status', 'published_at'], 'event_change_announcements_event_status_published');
            $table->index(['replacement_event_id', 'status'], 'event_change_announcements_replacement_status');
        });
    }
};
