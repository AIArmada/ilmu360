# Lifecycle Audit (Status & Timestamp Columns)

## Core Rules
- Every model with a status/state machine has a `status` column (string-backed enum); never use `is_*` booleans for derivable state, and never bury operational lifecycle events in JSON or booleans.
- Each terminal status transition records a dedicated `timestampTz` column (`published_at`, `cancelled_at`, `archived_at`); keep scheduled deadlines (`expires_at`, `registration_opens_at`) separate from transition times.

## Naming
- Status column: `status` (not `state`/`status_code`/`is_active`); timestamps: `{status_name}_at` (`confirmed_at`, `refunded_at`, …); visibility: `visibility` enum (not `is_public`/`is_visible`); deadlines: `{purpose}_at` (`registration_opens_at`, `check_in_closes_at`).

## Transition Integrity
- Centralise status→timestamp mapping in the transition method/trait: on X→Y set `y_at = now()` plus `last_state_change_at = now()`; cast lifecycle timestamps as `'immutable_datetime'`.

## Migration Pattern
- Phase 1: add nullable `status` + `*_at`, backfill. Phase 2: drop old booleans (`is_active`, `is_public`). Phase 3: make `status` NOT NULL.

## Verification
- Boolean anti-patterns: `rg -n "is_active|is_public|is_archived|registration_required|waitlist_enabled|approval_required" packages/*/database/migrations`
- `*_at` coverage: `rg -n "timestampTz\('.*_at'\)" packages/*/database/migrations`
- `state` vs `status`: `rg -n "\bstate\b" packages/*/src/Models`
