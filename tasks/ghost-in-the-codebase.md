# Ghost in the Codebase: Verified Follow-ups

This document records hypotheses that were checked against the application,
the installed packages, and the package source. It is a prioritised backlog,
not a list of code that is safe to delete.

## Verified findings

### 1. Sluggable package is not imported by `app/`

`spatie/laravel-sluggable` is installed, but the application uses its own
slug actions for events, speakers, institutions, venues, and references.
Those actions also regenerate dependent event slugs and maintain path-based
redirects. Sluggable can replace the per-model collision algorithm, but it
does not provide an equivalent migration for the current URL contract.

**Decision:** keep the current slug orchestration. Consider a model-by-model
prototype only if the canonical URL and redirect behavior remain identical.

### 2. Event institution identity is now a native indexed column

`institution_id` has since been cut over to a nullable indexed UUID column by
the current migration sequence. Existing metadata values are migrated and
removed; event reads, writes, filters, and search payloads use the native
column. Other package-owned identity fields remain separate concerns.

**Decision:** keep the native-column migration and do not add a stale JSON
expression index.

### 3. Activitylog is active through a dependency

`aiarmada/commerce-support` uses `spatie/laravel-activitylog` through its
`LogsCommerceActivity` trait and service provider. The application also uses
OwenIt auditing for a separate audit surface.

**Decision:** do not remove Activitylog.

### 4. `EventPayloadData` is an output adapter

`EventPayloadData::fromModel()` builds the public event payload and overrides
`transform()` to return that stable array. This intentionally uses the Data
package as a boundary adapter; it is not evidence that the API should be
rewritten into typed constructor properties.

**Decision:** add contract tests before considering any serializer refactor.

### 5. Event lifecycle logic has separate phases

`Event::booted()` synchronizes package-backed records during save. The
after-commit `EventObserver` handles cache invalidation, search, slug, and
redirect side effects.

**Decision:** retain the split and document it with regression tests.

### 6. Cache keys are distributed by domain

Public listing, directory-version, search, and model caches use separate
classes and key families. No missed invalidation was demonstrated.

**Decision:** remove only the redundant `rememberPayload()` delegate; retain
primitive-payload and model-rehydration helpers.

### 7. `is_featured` is an EAV-backed product flag

The flag is stored in `event_attributes` and synchronized through the event
model. The lifecycle rule against boolean state does not automatically apply
to this curation flag.

**Decision:** add a reusable `featured()` query scope; do not change storage.

### 8. Scout commands are thin wrappers

The four search-index commands all delegate to `scout:import` while adding
driver validation, options, and operator-facing messages.

**Decision:** consolidate shared command implementation while keeping the
four public command names.

## Implementation backlog

1. Inline the default event search cache call and remove only
   `SafeModelCache::rememberPayload()`.
2. Add event observer, cache, slug, search, and `EventPayloadData` contract
   coverage.
3. Add the `Event::featured()` scope and use it in the inventory widget.
4. Consolidate Scout wrapper internals without changing command contracts.
5. Upgrade `laravel/mcp` from `^0.8` to `^0.9` and run the full MCP suite.

## Explicitly rejected as blanket refactors

- Replacing the slug and redirect system wholesale.
- Moving package-owned event metadata into physical foreign-key columns.
- Removing `spatie/laravel-activitylog`.
- Rewriting `EventPayloadData` into a broad typed-DTO hierarchy.
- Moving `is_featured` into a new lifecycle/status model.
- Replacing all cache keys with cache tags; the default database cache driver
  does not support tags.
