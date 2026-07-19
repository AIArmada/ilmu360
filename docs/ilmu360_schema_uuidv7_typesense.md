# ilmu360° — DB Schema add-on proposal for Typesense (Postgres + Laravel + UUID)

> **Status (Jul 19, 2026): PROPOSAL — NOT ADOPTED.**
> The migration below (`2026_01_10_000050_add_typesense_outbox.php`) was never added to `database/migrations/`. ilmu360° currently runs Scout + Typesense on the standard model-event indexing path (Option A below). The `search:index-events` Artisan command remains the canonical reindex path.
>
> This document is retained as a design reference in case reliable outbox-based indexing becomes a requirement.

---

You **do not need** to update your DB schema just to use **Typesense**.

Typesense is an external search index. With Laravel Scout + queued indexing, you can run perfectly fine with **zero schema changes**.

That said, if you want this system to be **production-hard** (no missed indexes, resumable sync, visibility into failures), I recommend adding a small **Search Outbox** table + optional sync fields. This gives you:
- reliable “DB write → search write” delivery (outbox pattern)
- retries + error tracking
- easy backfill/rebuild when you change your Typesense schema

This file provides a **recommended add-on migration** (safe to apply on top of your existing schema).

---

## Option A (Minimal / no DB change)
- Use Laravel Scout + Typesense driver.
- On model events (created/updated/deleted), dispatch indexing jobs.
- If a job fails, it retries via queue.

✅ Simpler  
⚠️ Less observable / less resilient for long-running ops and bulk updates

---

## Option B (Recommended): Add Search Outbox + Sync Fields

### What changes?
1) Add `search_dirty` + `search_synced_at` columns to `events`.
2) Create a `search_outbox` table to persist indexing work items.

---

# Migration: Search Outbox + Event sync fields

Create a new migration file:

`database/migrations/2026_01_10_000050_add_typesense_outbox.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /**
         * 1) Track whether an event needs reindexing.
         * Makes backfill & health checks easy.
         */
        Schema::table('events', function (Blueprint $table) {
            $table->boolean('search_dirty')->default(true)->index();
            $table->timestamp('search_synced_at')->nullable()->index();

            // Optional: cheap change detection without diffing full payloads
            $table->string('search_payload_hash', 64)->nullable()->index();
        });

        /**
         * 2) Search outbox table.
         * Producers write here in the same transaction as your domain change.
         * A worker consumes rows and updates Typesense reliably.
         */
        Schema::create('search_outbox', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // What to index
            $table->string('entity_type')->index(); // e.g. 'event'
            $table->uuid('entity_id')->index();

            // What to do in Typesense
            $table->enum('action', ['upsert', 'delete'])->index();

            // Optional: store the payload you intend to send to Typesense
            $table->json('payload')->nullable();

            // Optional: payload hash so you can skip reindex when unchanged
            $table->string('payload_hash', 64)->nullable()->index();

            // Delivery state
            $table->enum('status', ['pending', 'processing', 'succeeded', 'failed'])
                ->default('pending')->index();

            // Scheduling / retries
            $table->timestamp('available_at')->nullable()->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();

            // Simple lock to avoid double-processing
            $table->timestamp('locked_at')->nullable()->index();
            $table->string('locked_by')->nullable(); // hostname/worker id
            $table->timestamp('processed_at')->nullable()->index();

            $table->timestamps();

            // Helpful composite indexes for queue scans
            $table->index(['status', 'available_at']);
            $table->index(['entity_type', 'entity_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_outbox');

        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['search_dirty', 'search_synced_at', 'search_payload_hash']);
        });
    }
};
```

---

## Implementation notes (recommended behavior)

### Producer (inside your EventService transaction)
- When event becomes `approved`, or relevant fields change:
  - set `events.search_dirty = true`
  - insert outbox row: `action=upsert`, `entity_type=event`, `entity_id=...`

- When event becomes non-indexable (deleted/private/unlisted, depending on your policy):
  - insert outbox row: `action=delete`

### Consumer worker
- Fetch `search_outbox` rows where `status=pending` and (`available_at` is null or <= now)
- Lock row, mark `processing`
- Upsert/delete into Typesense
- Mark `succeeded` + set `processed_at`
- On failure:
  - increment attempts
  - set `failed` (or back to pending with backoff)
  - store `last_error`

### Why only `events` sync fields?
Most search traffic is events. If speaker/institution updates, you typically reindex **related events** anyway.

---

## Optional: Add sync fields to speakers/institutions too
If you index `speakers` or `institutions` as separate Typesense collections:

```php
Schema::table('speakers', function (Blueprint $table) {
    $table->boolean('search_dirty')->default(true)->index();
    $table->timestamp('search_synced_at')->nullable()->index();
    $table->string('search_payload_hash', 64)->nullable()->index();
});

Schema::table('institutions', function (Blueprint $table) {
    $table->boolean('search_dirty')->default(true)->index();
    $table->timestamp('search_synced_at')->nullable()->index();
    $table->string('search_payload_hash', 64)->nullable()->index();
});
```

---

## Bottom line
- **Not required:** Typesense works fine without DB changes.
- **Recommended:** Add `search_outbox` + `events.search_dirty/search_synced_at` for reliability and visibility.

