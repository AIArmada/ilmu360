<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('user_id')->nullable()->index();
            $table->foreignUuid('institution_id')->nullable();
            $table->foreignUuid('submitter_id')->nullable()->index();
            $table->foreignUuid('venue_id')->nullable();
            $table->foreignUuid('space_id')->nullable()->index();
            $table->foreignUuid('parent_event_id')->nullable()->index();
            // Changes for EventType Refactor
            $table->foreignUuid('organizer_id')->nullable();
            $table->string('organizer_type')->nullable(); // Standard polymorphic columns if you want explicit control, but nullableUuidMorphs handles 'organizer_type' and 'organizer_id' usually. Wait, looking at current code it uses nullableUuidMorphs('organizer').
            // Correcting based on previous file content:
            // $table->nullableUuidMorphs('organizer'); was there.

            $table->jsonb('event_type')->default('[]');

            $table->string('title');
            $table->string('slug')->unique();
            $table->string('event_structure')->default('standalone')->index();
            $table->jsonb('description')->nullable();

            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->string('timezone')->default('Asia/Kuala_Lumpur');

            $table->string('timing_mode')->default('absolute');
            $table->string('prayer_reference')->nullable();
            $table->string('prayer_offset')->nullable();
            $table->string('prayer_display_text')->nullable();

            $table->string('gender')->default('all');
            $table->jsonb('age_group')->nullable();
            $table->boolean('children_allowed')->default(true);

            $table->string('event_format')->default('physical');
            $table->boolean('is_muslim_only')->default(false); // Added based on recent changes

            $table->string('visibility')->default('public');

            $table->string('status')->nullable();

            $table->string('schedule_kind')->default('single')->index();
            $table->string('schedule_state')->default('active')->index();

            $table->string('live_url')->nullable();
            $table->string('event_url')->nullable();
            $table->string('recording_url')->nullable();

            $table->unsignedInteger('views_count')->default(0);
            $table->unsignedInteger('saves_count')->default(0);
            $table->unsignedInteger('registrations_count')->default(0);
            $table->unsignedInteger('going_count')->default(0);

            $table->timestamp('published_at')->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->boolean('is_priority')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            // Optimized composite indexes for common query patterns
            // Main listing query: WHERE status='approved' AND visibility='public' AND starts_at >= NOW()
            $table->index(['status', 'visibility', 'starts_at'], 'events_status_visibility_starts_at');

            // Filter queries with starts_at sorting
            // event_type is jsonb — composite index not applicable
            $table->index(['institution_id', 'starts_at'], 'events_institution_starts_at');
            $table->index(['venue_id', 'starts_at'], 'events_venue_starts_at');
            $table->index(['gender', 'starts_at'], 'events_gender_starts_at');

            // Event format filtering (physical/online/hybrid)
            $table->index(['status', 'visibility', 'event_format', 'starts_at'], 'events_format_filter');
            $table->index(['event_format', 'starts_at'], 'events_format_upcoming');

            // Sitemap generation: WHERE status='approved' AND visibility='public' ORDER BY updated_at DESC
            $table->index(['status', 'visibility', 'updated_at'], 'events_sitemap');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
