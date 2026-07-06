# AIA-MODEL-001/002/003 — Revised Architecture Plan

## Root cause

The app stores ~32 fields in `events.metadata` JSON and uses a 486-line `EventBuilder`
to transparently rewrite queries. The package has **purpose-built normalized models** for
nearly every concept, plus an `EventMetadataSyncService` designed to project those rows
back into metadata. The app **inverts the intended data flow** — writing metadata
directly, bypassing all normalized models. The only exception is `EventInvolvement`
(organizer), which already works correctly.

## The principle

Every virtual column maps to a **first-class package model** that already exists.
The right architecture is: write through the normalized model → let the sync service
project into metadata → reads keep working. No new columns on the events table needed.

---

## Tier 0 — Already correct (no change)

| Field | Storage | Notes |
|-------|---------|-------|
| `starts_at`, `ends_at` | `event_occurrences` table | Already synced via model `saved` hook. Accessor reads from primary occurrence. |
| `venue_id` | `events.default_venue_id` | Real column. Dual-write to metadata can stop once `EventBuilder` is gone. |
| `event_format` | `events.delivery_mode` | Real column. Dual-write can stop. |
| Organizer | `event_involvements` (role=organizer) | Already normalized. `setPrimaryOrganizer()` works correctly. |
| Registration mode | `events.registration_mode` | Real column. |
| Status / visibility / timezone / slug / title / description / summary | Real columns | Already on package table. |

---

## Tier 1 — Rename to package column (stop dual-writing)

| App name | Package column | What changes |
|----------|---------------|-------------|
| `user_id` | `created_by_id` | Set `created_by_type = User` morph. Accessor returns `created_by_id`. Stop writing to metadata. |
| `venue_id` | `default_venue_id` | Already dual-written. Stop metadata mirror. Rename in ~20 query sites. |
| `event_format` | `delivery_mode` | Already dual-written. Stop metadata mirror. Rename in ~15 sites. |

**Builder impact**: Remove `DirectColumnMap` entries. No more aliasing.
**App impact**: Rename in actions, data objects, controllers, search service, Livewire, Filament.

---

## Tier 2 — Use package normalized models (source of truth → metadata projection)

### 2a. EventLink (URLs)

| App metadata field | EventLink row |
|--------------------|---------------|
| `live_url` | `link_type = 'streaming'`, `url = ...` |
| `event_url` | `link_type = 'external'`, `url = ...` |
| `recording_url` | `link_type = 'recording'`, `url = ...` |

**Migration**: In `SaveAdminEventAction` / `SubmitFrontendEventAction`, replace
`$event->live_url = $url` with `EventLink::updateOrCreate(['event_id', 'link_type' => 'streaming'], ['url' => $url])`.

**Read**: `$event->links()->where('link_type', 'streaming')->value('url')` or an accessor
that caches the lookup. The `EventMetadataSyncService` can project these into metadata
if configured (or we add a sync hook).

**Builder impact**: Remove `live_url`, `event_url`, `recording_url` from metadata list.
**Consumers**: `EventSearchService` filters on `has_event_url`/`has_live_url` — would
become `whereHas('links', fn($q) => $q->where('link_type', 'external'))`.

### 2b. EventTimeExpression (prayer-relative timing)

| App metadata fields | EventTimeExpression row |
|---------------------|----------------------|
| `timing_mode` | `time_mode` ('absolute' / 'anchor_relative') |
| `prayer_reference` | `anchor_type = 'prayer'`, `anchor_code = 'fajr'` etc. |
| `prayer_offset` | `offset_minutes` |
| `prayer_display_text` | `display_label` |
| (computed starts_at) | `resolver_class` = PrayerTimeResolver::class |

**Migration**: The app's `EventObserver::calculatePrayerRelativeTime()` already computes
the resolved time. Moving to `EventTimeExpression` formalizes this with a pluggable
resolver. The package syncs time expressions into `metadata._time_expressions`.

**Builder impact**: Remove `timing_mode`, `prayer_reference`, `prayer_offset`, `prayer_display_text`.
**Consumers**: `EventSearchService` prayer-time filters become
`whereHas('timeExpressions', fn($q) => $q->where('anchor_code', $prayer))`.

### 2c. EventAudience + EventAudienceProfile (demographics)

| App metadata field | Package model |
|--------------------|--------------|
| `gender` | `EventAudience` (audience_type='gender', value='men_only') |
| `age_group` | `EventAudience` (audience_type='age_group', value=['youth','adults']) |
| `is_muslim_only` | `EventAudience` (audience_type='religion', value='muslim_only') |
| `children_allowed` | `EventAudienceProfile.is_child_friendly` |

**Migration**: In actions, replace `$event->gender = $value` with
`EventAudience::updateOrCreate(['event_id', 'audience_type' => 'gender'], ['value' => $value])`.

The `EventMetadataSyncService` projects audiences into `metadata._audiences` as
`{gender: ['men_only'], age_group: ['youth']}`. Accessors read from this structure.

**Builder impact**: Remove `gender`, `age_group`, `children_allowed`, `is_muslim_only`.
**Consumers**: `EventSearchService` filters become `whereHas('audiences', ...)`.

### 2d. EventClassification (event types)

| App metadata field | Package model |
|--------------------|--------------|
| `event_type` (array) | `EventClassification` rows linked to `EventTerm` via `EventTaxonomy` |

**Migration**: Seed an `EventTaxonomy` with code `event_type`, seed terms from
`App\Enums\EventType` cases. Write classifications instead of metadata.

The sync service projects into search facets.

**Builder impact**: Remove `event_type` from metadata + JSON-array handling.
**Consumers**: `EventSearchService` type filter becomes `whereHas('classifications', ...)`.

### 2e. Engagement counters

| App metadata field | Package model |
|--------------------|--------------|
| `saves_count` | `EngagementCounter` (subject=Event, type='bookmark') |
| `going_count` | `EngagementCounter` (subject=Event, type='response', key='going') |
| `registrations_count` | **Remove** — dead weight, always computed via `withCount('registrations')` |
| `views_count` | **Remove** — dead weight, never written |

**Migration**: Replace manual recount logic in `EventSaveController` / `MarkEventGoingAction`
with `EngagementCounterService::recalculate($event, 'bookmark')`. Or simply compute on read
via `withCount` since the package maintains counters.

**Builder impact**: Remove all 4 counters from metadata list.
**Consumers**: Data objects read via `$event->saves_count` accessor → queries counter or
`withCount`.

### 2f. EventSeries (parent/child programs)

| App metadata field | Package model |
|--------------------|--------------|
| `parent_event_id` | `EventSeries` + `EventSeriesItem` |
| `event_structure` | Derived from series membership (standalone = no series, parent = owns series) |

**Migration**: When creating a parent program, create an `EventSeries` and add child events
as `EventSeriesItem` rows. `event_structure` becomes a computed accessor:
`$event->series()->exists() ? 'parent_program' : ($event->seriesItems()->exists() ? 'child_event' : 'standalone')`.

**Builder impact**: Remove `parent_event_id`, `event_structure`.
**Consumers**: `EventSearchService` parent-program exclusion becomes
`whereDoesntHave('seriesItems')`. `shouldBeSearchable()` checks series membership.

### 2g. EventInvolvement (institution/submitter)

| App metadata field | Package model |
|--------------------|--------------|
| `institution_id` | `EventInvolvement` (role=organizer, involveable=Institution) — **already done for organizer** |
| `submitter_id` | `EventSubmission.submitter` morph |

**Migration**: `institution_id` is already partially migrated — the organizer flows through
`EventInvolvement`. The metadata `institution_id` is redundant. For `submitter_id`, the
package's `EventSubmission` model tracks this properly.

**Builder impact**: Remove `institution_id`, `submitter_id`.

### 2h. EventLocation (venue space)

| App metadata field | Package model |
|--------------------|--------------|
| `space_id` | `EventLocation.venue_space_id` |

**Migration**: Create/update `EventLocation` row with `venue_space_id` instead of metadata.

**Builder impact**: Remove `space_id`.

### 2i. Remaining flags (EventAttribute or real columns)

| App metadata field | Recommendation |
|--------------------|---------------|
| `is_active` | Real column on events table (used in too many queries for JSON) |
| `is_featured` | `EventAttribute` (key='is_featured') or real column |
| `is_priority` | `EventAttribute` (key='is_priority') or real column |
| `escalated_at` | `EventAttribute` (key='escalated_at') or real column |
| `schedule_kind` | `EventOccurrence` lifecycle (already partially mapped) |
| `schedule_state` | `EventOccurrence.status` state machine |

For `is_active`, `is_featured`, `is_priority`, `escalated_at`: these are simple
scalar flags used in admin filters. They could stay as `EventAttribute` rows (synced
to metadata by the package) OR become real columns if query performance matters.
**Recommendation**: Start with `EventAttribute`, promote to real columns only if
profiling shows JSON extraction is a bottleneck.

---

## Tier 3 — Remove the builders entirely

After Tiers 1-2, every virtual column is either:
- A real package column (Tier 0/1)
- Backed by a normalized model that syncs to metadata (Tier 2)
- Removed as dead weight (counters)

The `EventBuilder` (486 lines), `VenueBuilder` (184 lines), and `ReferenceBuilder`
(212 lines) can be **deleted**. Queries that filtered on virtual columns now use:
- `whereHas('links', ...)` for URLs
- `whereHas('audiences', ...)` for demographics
- `whereHas('classifications', ...)` for types
- `whereHas('timeExpressions', ...)` for prayer timing
- `where('default_venue_id', ...)` for venue
- `where('delivery_mode', ...)` for format
- `where('is_active', true)` for real column (if promoted)

The raw `metadata->` SQL extractors in `EventSearchService` and `SearchController`
are removed — replaced by proper joins/subqueries on normalized tables.

---

## Venue thinning (same pattern, smaller scope)

| Virtual field | Package equivalent |
|---------------|-------------------|
| `description` | Keep in metadata (no package column) or add `EventAttribute`-style |
| `facilities` | `VenueFacility` rows (typed via `FacilityType`) |
| `is_active` | `Venue.status` → `VenueStatus::Active` (already an enum) |
| `type` → `venue_type` | Already real column |

**Builder**: Deleted after `is_active` → `status` migration.

## Reference thinning (smallest scope)

| Virtual field | Package equivalent |
|---------------|-------------------|
| `part_type`, `part_number`, `part_label` | `reference_parts` JSON column (already exists) |
| `is_canonical`, `is_active` | Keep in metadata or use `status` (Published/Archived) |
| `parent_reference_id` → `parent_id` | Already real column |
| `publication_year` → `year` | Already real column |
| `reference_url` → `url` | Already real column |

**Builder**: Deleted after column renames.

---

## Implementation path

### Phase 1: Foundation (package-side)
1. Enable `EventMetadataSyncService` (already configured, just needs source rows)
2. Seed `EventTaxonomy` + `EventTerm` for event types from `App\Enums\EventType`
3. Seed `EventRole` rows from `App\Enums\EventKeyPersonRole` + `organizer`
4. Write a `PrayerTimeExpressionResolver` implementing the package's resolver contract

### Phase 2: Write-path migration (per field group)
For each Tier 2 group (2a through 2i):
1. Update `SaveAdminEventAction` + `SubmitFrontendEventAction` to write normalized rows
2. Update `Event::setAttribute` override to dispatch to normalized model instead of metadata
3. Update `Event::getAttribute` to read from normalized model (or from metadata projected by sync service)
4. Update `EventSearchService` query filters
5. Update Data Objects, controllers, Livewire, Filament

### Phase 3: Remove compatibility layer
1. Delete `EventBuilder`, `VenueBuilder`, `ReferenceBuilder`
2. Remove `setAttribute`/`getAttribute` overrides
3. Remove raw `metadata->` SQL extractors from search service/controller
4. Drop dead counters (`registrations_count`, `views_count`) from metadata

### Phase 4: Scout integration
1. Index normalized fields in Typesense (more fields available now)
2. Remove `requiresDatabaseFiltering()` fallbacks for fields now in the index
3. Consider using package's `EventSearchDocument` + `search.payload_resolver` config

---

## What NOT to do

- **Don't add real columns to the events table for fields the package already models
  through normalized tables** (EventLink, EventAttribute, EventAudience, etc.). That
  would duplicate the package's design.
- **Don't keep the builders** — they're a compatibility shim that should not survive.
- **Don't write to metadata directly** — the package's data flow is
  normalized → sync → metadata. The app should follow this flow.
- **Don't promote Islamic-specific fields to the package** (prayer_* as real columns).
  Use `EventTimeExpression` with a pluggable resolver — that's the generic extension point.
