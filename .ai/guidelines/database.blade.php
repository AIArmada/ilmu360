# Database Guidelines

- **Primary keys**: `uuid('id')->primary()`. **Foreign keys**: `foreignUuid('col')` only — UUIDs end-to-end, no integer geography FKs.
- **Geography**: package addressing storage only — `country_id`, `state_id`, `city_id` plus role-based `address_area_assignments` (see `.ai/guidelines/addressing.blade.php`).
- **Never** DB-level constraints or cascades: no `->constrained()`, no `->cascadeOnDelete()`, no FK constraints; enforce integrity in application logic (models/actions/services).
- **Migrations**: keep safe/idempotent; no `down()` required.
- **No SoftDeletes**: never use Laravel's `SoftDeletes` trait or `$table->softDeletes()`. This application uses `spatie/laravel-deleted-models` (`KeepsDeletedModels` trait) instead, which stores a full copy of the deleted model in a separate `deleted_models` table.

## Verification

- Ensure no constraints/cascades slipped in: `rg -n -- "constrained\(|cascadeOnDelete\(" packages/*/database`
- Ensure no SoftDeletes slipped in: `rg -n -- "softDeletes\(\)|SoftDeletes" database/ app/Models/`
