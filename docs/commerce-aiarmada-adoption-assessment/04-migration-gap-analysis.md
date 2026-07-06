# 04 — Migration Gap Analysis

**Engine note:** production DB is **PostgreSQL** (not SQLite). All findings cross-checked against live `pg_tables`.

**Loader mechanism:** `app/Providers/AppServiceProvider.php:118-127` `registerPackageMigrations()` force-loads **every** `vendor/aiarmada/*/database/migrations` directory. Therefore all 22 packages' migrations run regardless of whether app code uses the package. This is the root cause of "schema installed but unused" for inventory/ticketing/seating.

**Collision handling:** app and package both attempt to create `events`, `venues`, `saved_searches`, `reports`. Only ONE of each exists in the DB — so one side is guarded by run order / conditional creation. With no published `config/events.php`, this is fragile (AIA-MIG-007).

## Migration gap table

| Gap ID | Local migration(s) | Package migration(s) | Table(s) | Ownership target | Cutover decision | Difference | Risk | Next step | Verification |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| AIA-MIG-001 | `…000014_create_series_table` | events pkg `…event_series` | `series` vs `event_series` | `PACKAGE_OWNED` | `LOCAL_MIGRATION_LEGACY_HISTORY_ONLY` | App `Series` model uses `series`; package events uses `event_series`. Two tables, same concept. | med | Decide: keep app `Series` as app-specific OR migrate to `event_series`. If keeping local, stop the package table from being conceptually duplicate. | `SELECT count(*) FROM series; SELECT count(*) FROM event_series;` confirm `event_series` empty. |
| AIA-MIG-002 | `…000024_create_registrations_table` | events pkg `…event_registrations` | `registrations` (orphan) vs `event_registrations` | `PACKAGE_OWNED` | `LOCAL_MIGRATION_LEGACY_HISTORY_ONLY` | App `Registration` extends pkg `EventRegistration` → inherits table `event_registrations`. The app's `registrations` table is **no longer read/written by the model**. | low | Confirm no raw `registrations` queries remain; drop table; keep migration as history. | `rg -n "'registrations'|\"registrations\"|DB::table\('registrations'\)" app/ routes/`; expect none. |
| AIA-MIG-003 | `2026_02_16_…_create_followings_table` | engagement pkg `engagement_follows` | `followings` vs `engagement_follows` | `PACKAGE_OWNED` (if follow feature is generic) | `NEEDS_SCHEMA_TRANSITION_PLAN` | App follow feature writes to `followings`; package `engagement_follows` exists but unused. | med | Migrate follow feature to `engagement_follows` via package action; backfill rows; drop `followings`. | confirm `HasFollowers` concern target table; row counts both. |
| AIA-MIG-004 | `…create_notifications_table` + app `notification_*` models | communications pkg `communication_*` (16) + `notification_inboxes` | parallel notification storage | `LEGACY_SHARED_REQUIRES_CUTOVER` | `NEEDS_SCHEMA_TRANSITION_PLAN` | App `NotificationMessage`/`Delivery`/`Destination`/`Rule`/`Setting`/`PendingNotification` vs package `communication_*`. Heaviest overlap. | **high** (prod notification history) | Do NOT drop yet. Design mapping; cut over delivery first, then backfill history. | audit which models write/read each; check `app/Services/Notifications/`. |
| AIA-MIG-005 | `2026_03_19_…_create_membership_claims_table` | membership pkg `membership_applications` | `membership_claims` vs `membership_applications` | `PACKAGE_OWNED` | `NEEDS_SCHEMA_TRANSITION_PLAN` | `MembershipClaim` standalone vs package `MembershipApplication`. `MemberInvitation` already cut over. | med | Map claim→application fields; migrate; switch `SubmitMembershipClaimAction` to package action. | compare columns: `\d membership_claims` vs `\d membership_applications`. |
| AIA-MIG-006 | `…create_event_attendees_table`, app `EventCheckin` model | events pkg `event_attendances`, `event_walk_ins`, `event_attendance_logs` | `event_attendees` + `event_checkins` vs pkg tables | `PACKAGE_OWNED` | `NEEDS_HUMAN_DECISION` | Check-in/attendance concept duplicated; package has richer attendance model. | med | Decide if app check-in stays (UX) or uses package attendance; map columns. | compare schemas; check `EventCheckin` writers. |
| AIA-MIG-007 | app `create_events_table`, `create_venues_table`(?), `create_saved_searches_table`, `create_reports_table` | events pkg `events`/`venues`; commerce-support pkg `reports`/`saved_searches` | `events`,`venues`,`saved_searches`,`reports` (SHARED single table) | `PACKAGE_OWNED_WITH_APP_EXTENSIONS` | `NEEDS_HUMAN_DECISION` | Single table serves both app model (extends pkg) and package model. No `config/events.php` published → package uses defaults that collide. Collision avoided only by migration order/guards. | **high** (schema stability) | Publish `config/events.php`; audit who adds columns; document ownership. Run `php artisan migrate:fresh` in a scratch DB to confirm no "table exists" error. | fresh `migrate:fresh` on throwaway PG DB; `php artisan migrate:status`. |
| AIA-MIG-008 | (none) | inventory `inventory_*`(16), ticketing `ticket_*`(7), seating `seat_*`(5) | unused package tables | `PACKAGE_OWNED` (when activated) | `LOCAL_MIGRATION_LEGACY_HISTORY_ONLY` (n/a — no local dup) | Tables exist, zero app usage. Pure schema overhead. | low | Decide roadmap: activate (paid tickets) or stop loading these migrations. If deferring, gate behind config flag. | `rg "AIArmada\\\\(Inventory|Ticketing|Seating)" app/` → none. |
| AIA-MIG-009 | (none local) | references pkg default `ref_references` | actual table is `references` | `PACKAGE_OWNED` | `PACKAGE_MIGRATION_SHOULD_BE_SOURCE_OF_TRUTH` | `config/references.php` overrides default `ref_references`→`references`; app `Reference` extends pkg model, inherits table. Working correctly. | low | Document; verify config override is intentional. | `\d references`; check `config/references.php`. |

## Table ownership summary

| Table(s) | Ownership | Notes |
| --- | --- | --- |
| `address_*`, `addresses`, `addressables`, `contact_methods`, `social_profiles` | `PACKAGE_OWNED` | clean |
| `events`, `venues`, `event_*` (most) | `PACKAGE_OWNED_WITH_APP_EXTENSIONS` | shared single table; needs config (AIA-MIG-007) |
| `event_registrations`, `membership_invitations` | `PACKAGE_OWNED` | models already inherit |
| `registrations` (app) | `LEGACY_SHARED_REQUIRES_CUTOVER` | orphaned, droppable (AIA-MIG-002) |
| `series` (app) vs `event_series` (pkg) | `LEGACY_SHARED_REQUIRES_CUTOVER` | decide (AIA-MIG-001) |
| `followings` (app) vs `engagement_follows` (pkg) | `LEGACY_SHARED_REQUIRES_CUTOVER` | (AIA-MIG-003) |
| `notification_*` (app) vs `communication_*` (pkg) | `LEGACY_SHARED_REQUIRES_CUTOVER` | heaviest (AIA-MIG-004) |
| `membership_claims` (app) vs `membership_applications` (pkg) | `LEGACY_SHARED_REQUIRES_CUTOVER` | (AIA-MIG-005) |
| `event_attendees`/`event_checkins` (app) vs `event_attendances` (pkg) | `UNCLEAR_REQUIRES_DECISION` | (AIA-MIG-006) |
| `reports`, `saved_searches` | `PACKAGE_OWNED_WITH_APP_EXTENSIONS` | commerce-support owns base; app may extend (AIA-MIG-007) |
| `inventory_*`, `ticket_*`, `seat_*` | `PACKAGE_OWNED` (dormant) | unused (AIA-MIG-008) |
| app-specific: `institutions`, `speakers`, `donation_channels`, `inspirations`, `spaces`, `teams`, `ai_*`, `contribution_requests`, `slug_redirects`, `event_change_announcements`, `event_key_people`, `event_settings`, `event_user`, `event_saves`, `media_links`, `reference_user` | `APP_OWNED` | genuine app domain |

## Migration strategy recommendations

1. **Do NOT delete old migration files.** Keep them as historical record for production `migrations` table integrity.
2. **Publish `config/events.php`** and explicitly set the 60 event table names (matching current defaults) so package behavior is pinned, not accidental.
3. **Stop adding new app migrations for package-owned tables.** New columns on `events`/`venues`/`event_registrations` go in package-owned extension migrations or package config, not app migrations.
4. **For orphaned tables** (`registrations`): create a single forward migration `drop_legacy_registrations_table` after confirming no writer; do not edit the original create migration.
5. **For parallel-storage tables** (`followings`, `membership_claims`, notification cluster): create data-migration scripts that copy rows into package tables, then drop — only after the code path is switched.
6. **Verify with:** `php artisan migrate:fresh` on a throwaway PostgreSQL DB (mirror prod engine, not SQLite) + `php artisan migrate:status` on prod-equivalent.

## Verification commands

```bash
php artisan migrate:status                # all migrations recorded
php artisan migrate:fresh --seed           # ONLY on throwaway DB; confirms no collisions
rg -n "registrations|followings|membership_claims" app/ routes/   # find residual writers
psql -c "\d event_registrations"           # confirm model-inherited table shape
```
