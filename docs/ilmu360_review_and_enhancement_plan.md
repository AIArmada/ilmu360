# ilmu360° Full Code Audit (Literal Codebase Audit)


> **Superseded (geography):** Integer geo IDs and `district_id`/`subdistrict_id` are obsolete. Canonical addressing is package UUID `state_id`/`city_id`/`admin_area_1_id`/`admin_area_2_id`. See `.ai/guidelines/addressing.blade.php`.

Updated: February 12, 2026

> Audit scope note: this document records a specific audit pass completed in February 2026.
> For current runtime truth, use `docs/ilmu360_technical_documentation.md`,
> `docs/ilmu360_mobile_api_reference.md`,
> `docs/ilmu360_api_mcp_filament_crud_comparison.md`, and the verification tests.

## 0. Implementation Progress (Current Pass)
Completed in code:

1. Report dedupe/escalation identity hardening:
   - Added `reporter_fingerprint` support and updated report dedupe/escalation logic to use fingerprint identity.
2. Address accessor correctness:
   - Fixed `address_line1` accessor to read `line1` (not `address1`).
3. Saved search geography validation alignment:
   - Updated `filters.state_id` and `filters.district_id` validation to numeric IDs.
4. Registration capacity race hardening:
   - Registration create path now uses transaction + row lock and live registration row counting.
5. Save/interest race hardening:
   - Converted save/interest write paths to atomic insert-or-ignore flow, deterministic `409` conflicts, and count reconciliation from source rows.
6. Registration export scalability:
   - CSV export now streams using cursor-based query instead of eager-loading all registrations.
7. Export audit robustness:
   - Added explicit UUID assignment for audit inserts to match UUID PK schema in `audits`.
8. Submit-event public access alignment:
   - Explicitly kept public submit-event route unthrottled based on product decision (guests can submit freely), and aligned route test coverage to this behavior.
9. Moderation service dead code cleanup:
   - Removed unused notification methods with unresolved references from `ModerationService`.
10. Typesense parity with DB filtering:
    - Added `is_active:=true` to Typesense filter parts.
11. Seeder dispatcher safety:
    - Wrapped all `unsetEventDispatcher()` seeders with `try/finally` restore.
12. Seeder idempotency and portability:
    - `SpeakerSeeder` contact writes are now idempotent (`updateOrCreate`) with deterministic phone value.
    - `EventSeeder` now uses driver-aware LIKE operator instead of hard-coded `ILIKE`.
13. Dead placeholder job removal:
    - Removed `AdjustTrustScores` placeholder job and verified no remaining references.
14. Geography strategy decision completed:
    - Decided and documented intentional integer-ID exception for geography tables (`countries`, `states`, `cities`, `districts`, `subdistricts`) and references (`country_id`, `state_id`, `city_id`, `district_id`, `subdistrict_id`) in `.ai/guidelines/database.blade.php`.
15. Geography filter completeness implemented:
    - End-to-end `state -> district -> subdistrict` support added across frontend filters, API filters, saved-search validation/state, search service (DB + Typesense), and searchable payload/schema.
16. Static-analysis baseline reduction (module pass):
    - Completed module pass for `User.php`, `Institution.php`, `Speaker.php`, `Venue.php`, plus shared model concerns (`HasAddress`, `HasContacts`, `HasDonationChannels`, `HasLanguages`, `HasSocialMedia`).
    - Added missing generic relation PHPDoc, corrected typed scope signatures, hardened model-return typing, and cleaned media conversion chains for static-analysis compatibility.
    - Regenerated baseline to remove stale entries introduced by resolved findings.
17. Event-centric static-analysis and reliability pass:
    - Extended typed relation/collection generic coverage across `Event`, event-facing Livewire pages, search/calendar/observer/controller layers, and geography models.
    - Added/updated `SocialAccountFactory` for missing factory coverage referenced by model generics.
    - Fixed final remaining PHPStan runtime issue in `EventSearchTest` expectation chaining.
18. Tooling gate completion:
    - `phpstan`, `pint --test`, `rector --dry-run`, and full `pest --parallel --compact` all pass on current working tree.
19. Static-analysis scope policy correction:
    - Reverted converted tests back to Pest style.
    - Updated PHPStan scope to analyze application/runtime code (`app`, `bootstrap`, `config`, `database`, `routes`) and exclude `tests/*`.
    - Regenerated baseline to remove stale test-related suppressions.
20. Deep baseline compression pass (app/runtime only):
    - Fixed broad app/runtime typing and static-analysis issues across Filament resources/forms, API/auth controllers, Livewire pages, model relations, notifications, services, state classes, factories, migrations, and seeders.
    - Removed stale factories (`AuditLogFactory`, `EventTypeFactory`) that referenced non-existent models.
    - Introduced typed custom select component (`App\Forms\Components\Select`) with concrete `closeOnSelect()` and `quickAdd()` passthrough to remove macro-only static-analysis blind spots.
    - Regenerated baseline to zero.
21. Speaker quick-create UX/data completeness:
    - Enhanced submit-event speaker create-option flow with rich biography (`jsonb` + rich editor), main profile image upload, single affiliated institution selection, and institution-specific position capture on `institution_speaker` pivot.

## 0.1 Resolution Status (Audit Findings)
1. Findings 1-4 (`P1`): fixed.
2. Findings 5-13 (`P2`): fixed (finding 11 resolved by explicit unthrottled public-submit product policy + aligned tests/docs).
3. Finding 14 (`P3` mixed UUID/integer geography strategy): fixed by explicit architecture decision and documentation (integer geography exception).
4. Finding 15 (`P3` placeholder job): fixed.

## 0.2 Verification Snapshot (Current Pass)
1. Hygiene sweeps:
   - `debug_calls_found=0`
   - `db_constraints_found=0` (`constrained`, `cascadeOnDelete`)
   - `softdeletes_found=0` (`SoftDeletes`, `softDeletes()`)
   - `route_duplicates=0` (method + URI)
2. Full quality gates (current working tree):
   - `vendor/bin/phpstan analyse --ansi`: `No errors`.
   - `vendor/bin/pint --test`: `PASS (412 files)`.
   - `XDEBUG_MODE=off vendor/bin/rector process --dry-run`: `OK (no changes)`.
   - `vendor/bin/pest --parallel --compact`: `328 passed (970 assertions)`.
3. Baseline reduction status:
   - `phpstan-baseline.neon` total suppressed errors reduced from `959` to `0` (`-959`) after excluding tests from static-analysis scope and completing module-by-module fixes.
   - Current baseline entries (`message:`): `0`.

## 1. Audit Method
This audit was done as a literal codebase audit, not just static-analysis follow-through:

1. Full syntax sweep across all tracked PHP files (`501` files): no syntax/parse errors.
2. Heuristic code scans for:
   - debug leftovers (`dd`, `dump`, `var_dump`, etc.),
   - migration rule drift (`constrained`, `cascadeOnDelete`, `SoftDeletes`),
   - route method+URI duplication.
3. Full style compliance check (`pint --test`) to detect code hygiene drift.
4. Manual review of core app layers:
   - API controllers,
   - public controllers,
   - event/moderation services and transitions,
   - model concerns and accessors,
   - key migrations and seeders,
   - scheduled jobs and routing.

## 2. Findings (Ordered by Severity, Historical Baseline)
These findings are the original audit baseline. Current resolution status is tracked in section `0.1` above.

### P1 - Functional correctness risks
1. Guest report dedupe and escalation logic are inconsistent and can suppress valid reporting.
- File: `app/Http/Controllers/Api/ReportController.php:53`
- File: `app/Http/Controllers/Api/ReportController.php:127`
- Issue:
  - duplicate check groups all anonymous users under `reporter_id = null` (global guest collision),
  - escalation uses `distinct('reporter_id')->count()` which excludes/null-collapses guest identity.
- Impact:
  - one guest can block all other guests for 24h on same entity,
  - anonymous reports may not contribute correctly to escalation threshold.
- Recommendation:
  - include stable guest identity (`ip_hash`, fingerprint, or token-based reporter key),
  - count unique reporter key, not only nullable `reporter_id`.

2. Address line accessor is broken due wrong property name.
- File: `app/Models/Concerns/HasAddress.php:17`
- Issue: uses `address1` but address field is `line1`.
- Impact:
  - derived address output is empty in consumers (calendar export, JSON-LD, UI helpers).
- Recommendation:
  - change to `line1`, add regression tests for `address_line1` accessor.

3. Saved-search API validates `state_id` and `district_id` as UUID, but schema uses integer IDs.
- File: `app/Http/Controllers/Api/SavedSearchController.php:42`
- File: `database/migrations/2026_01_10_000005_create_districts_table.php:12`
- Issue:
  - validation contract mismatches actual DB key type for geography tables.
- Impact:
  - valid state/district filters can be rejected at API layer.
- Recommendation:
  - update validation to integer-compatible constraints for geography IDs.

4. Event registration flow has non-atomic capacity enforcement.
- File: `app/Http/Controllers/Public/EventsController.php:64`
- File: `app/Http/Controllers/Public/EventsController.php:99`
- File: `app/Http/Controllers/Public/EventsController.php:109`
- Issue:
  - capacity check, insert, and counter increment are separate operations without transaction/locking.
- Impact:
  - concurrent requests can overbook capacity and skew `registrations_count`.
- Recommendation:
  - wrap capacity check + create + counter update in transaction with row lock or derive counts from registrations table.

### P2 - Reliability, maintainability, and best-practice drift
5. Unreachable conditional branch in registration controller.
- File: `app/Http/Controllers/Public/EventsController.php:47`
- Issue:
  - `rejected` branch is unreachable because prior guard allows only `approved|pending`.
- Impact:
  - dead branch hides real behavior intent and confuses maintenance.
- Recommendation:
  - remove unreachable branch or reorder guards to preserve intended message path.

6. Event save endpoints are race-prone around duplicate insertion.
- File: `app/Http/Controllers/Api/EventSaveController.php:69`
- File: `app/Http/Controllers/Api/EventSaveController.php:84`
- Issue:
  - check-then-insert pattern is non-atomic on composite PK tables.
- Impact:
  - concurrent calls can throw DB exceptions instead of returning deterministic `409`.
- Recommendation:
  - use atomic insert-or-ignore path plus explicit conflict response handling.

7. Registration CSV export loads all registrations into memory before streaming.
- File: `app/Http/Controllers/Api/RegistrationExportController.php:32`
- Issue:
  - `->get()` on full dataset before stream callback.
- Impact:
  - large exports can spike memory and response latency.
- Recommendation:
  - stream with cursor/chunked query in callback.

8. Seeder event-dispatcher disabling is not exception-safe.
- File: `database/seeders/EventSeeder.php:21`
- File: `database/seeders/SpeakerSeeder.php:21`
- File: `database/seeders/SeriesSeeder.php:19`
- Issue:
  - `unsetEventDispatcher()` + `setEventDispatcher()` without `try/finally`.
- Impact:
  - exception during seeding can leave dispatcher disabled in process.
- Recommendation:
  - wrap restore in `finally` consistently across all affected seeders.

9. Seeder idempotency drift remains in speaker seeding contacts.
- File: `database/seeders/SpeakerSeeder.php:66`
- File: `database/seeders/SpeakerSeeder.php:112`
- Issue:
  - contacts are always inserted for real speakers on each run.
- Impact:
  - duplicate contact rows across repeated seed runs.
- Recommendation:
  - `insertOrIgnore`, upsert, or `firstOrCreate` by `contactable_id + category + value`.

10. Event seeder uses DB-specific operator directly.
- File: `database/seeders/EventSeeder.php:150`
- File: `database/seeders/EventSeeder.php:154`
- File: `database/seeders/EventSeeder.php:158`
- Issue:
  - direct `ILIKE` usage is PostgreSQL-specific.
- Impact:
  - seeder portability is reduced for non-PG environments.
- Recommendation:
  - gate by driver or use case-insensitive portable pattern abstraction.

11. Public submit-event route is labeled as rate-limited but has no throttle middleware.
- File: `routes/web.php:28`
- File: `routes/web.php:29`
- Issue:
  - comment says rate-limited; route lacks `throttle:event-submission`.
- Impact:
  - spam surface is larger than documented.
- Recommendation:
  - apply named limiter or update documentation to match actual behavior.

12. ModerationService contains dead notification methods with unresolved class references.
- File: `app/Services/ModerationService.php:142`
- File: `app/Services/ModerationService.php:168`
- File: `app/Services/ModerationService.php:192`
- Issue:
  - methods are unused and reference notification/review classes not imported in this namespace.
- Impact:
  - dead code and potential fatal if invoked later.
- Recommendation:
  - remove dead methods or fully wire and import classes with tests.

13. Typesense search filter logic omits explicit `is_active` constraint.
- File: `app/Services/EventSearchService.php:180`
- Issue:
  - Typesense filter enforces `status` and `visibility`, but not `is_active`.
- Impact:
  - inconsistent results vs DB fallback if inactive records remain indexed.
- Recommendation:
  - add `is_active:=true` in Typesense filters for parity.

### P3 - Architecture and hygiene
14. Migration strategy drifts from internal UUID-only convention in geography/infra tables.
- File: `database/migrations/2026_01_10_000005_create_districts_table.php:12`
- File: `database/migrations/2026_02_02_000952_create_subdistricts_table.php:15`
- Issue:
  - uses integer PK/foreignId in parts of schema while main domain is UUID-first.
- Impact:
  - mixed ID semantics and validation complexity at API/form boundaries.
- Recommendation:
  - document intentional exception for world/geography tables or align with app-wide ID strategy.

15. Dead placeholder job remains in codebase.
- File: `app/Jobs/AdjustTrustScores.php:15`
- Issue:
  - job intentionally does nothing.
- Impact:
  - maintenance noise and unclear roadmap signal.
- Recommendation:
  - remove until feature is implemented or mark behind feature-flag contract.

## 3. Dead Code and Hygiene Summary
1. Previously identified dead/unreachable code paths have been removed in this pass (registration unreachable branch, placeholder job, unused moderation notification methods).
2. `pint --test` currently reports `0` style issues (`PASS` across `413` files).
3. No debug artifact calls (`dd`, `dump`, `var_dump`, etc.) detected in application code paths.

## 4. Laravel Best-Practice Summary
1. Good:
   - route method+URI uniqueness currently clean,
   - no DB-level FK constraint helpers (`constrained`, `cascadeOnDelete`) detected,
   - no `SoftDeletes` trait usage in app models/migrations.
2. Strengthened in this pass:
   - transactional integrity for registration/save/interest write paths,
   - validation contract alignment for saved-search geography IDs,
   - exception-safe lifecycle cleanup in dispatcher-disabled seeders,
   - dead code pruning in service/job layer.
3. Architecture policy finalized:
   - geography tables are an intentional integer-ID exception, documented and enforced in guidelines.

## 5. Immediate Action Plan (Recommended Sequence)
1. Completed in this pass:
   - all P1 fixes,
   - all P2 fixes,
   - P3 placeholder-job cleanup,
   - P3 geography strategy decision + implementation alignment.
2. Operational follow-up (non-blocking):
   - reindex Typesense after schema/payload changes to ensure `subdistrict_id` and `is_active` facets are populated.
