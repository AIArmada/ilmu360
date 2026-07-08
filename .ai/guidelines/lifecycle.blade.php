# Lifecycle Audit (Status & Timestamp Columns)

## Core Rules
- Every model with a status or state machine must have a `status` column (string-backed enum).
- Each terminal status transition records a dedicated `timestampTz` column (e.g. `published_at`, `cancelled_at`, `archived_at`).
- Use `*_at` for actual transition times. Keep scheduled deadlines (`expires_at`, `registration_opens_at`) separate from state transitions.
- Never use `is_*` booleans for state that can be derived from status.
- Do not bury lifecycle events in JSON or booleans when the timestamp matters operationally.

## Naming
- Column: `status` (not `state`, not `is_active`, not `status_code`)
- Timestamp: `{status_name}_at` (e.g. `confirmed_at`, `refunded_at`, `completed_at`, `cancelled_at`)
- Visibility column: `visibility` with string-backed enum (not `is_public`, not `is_visible`)
- Scheduled deadlines: `{purpose}_at` (e.g. `registration_opens_at`, `check_in_closes_at`)

## Transition Integrity
- Status-to-timestamp mapping must be centralised in the transition method or supporting trait.
- When a status transitions from X to Y, the transition sets `y_at = now()`.
- Use immutable date casts (`'immutable_datetime'`) for lifecycle timestamps.
- Track the last state change: `last_state_change_at` updated on every transition.

## Migration Pattern
- Phase 1 (non-breaking): Add new `status` + `*_at` columns as nullable, backfill existing rows.
- Phase 2 (breaking): Drop old boolean columns (`is_active`, `is_public`) after confirming data is migrated.
- Phase 3 (cleanup): Make `status` NOT NULL once all rows are populated.

## Verification
- Check for boolean anti-patterns: `rg -n "is_active|is_public|is_archived|registration_required|waitlist_enabled|approval_required" packages/*/database/migrations`
- Check status columns have matching `*_at` timestamps: `rg -n "timestampTz\('.*_at'\)" packages/*/database/migrations`
- Check for `state` instead of `status`: `rg -n "\bstate\b" packages/*/src/Models`
