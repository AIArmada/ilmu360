# A06 — Location and key-person corrections (superseded by first-class metadata hard cut)

This historical checklist is retained for traceability only. Its former metadata,
alias, and compatibility recommendations are not implementation guidance; the
canonical package relations and columns described in
`tasks/first-class-metadata-hard-cut-plan.md` supersede them.

## Location Tasks
- [x] 1. Event.institution() - already uses institution_id column ✓
- [x] 2. Event.venue() - already uses default_venue_id column ✓
- [x] 3. Store Space in EventLocation.venue_space_id (already done) + fix location_role → 'primary'
- [x] 4. Use location_role = primary consistently
- [x] 5. Replace Event.space() belongsTo with query through primary EventLocation
- [x] 6. Validate Space belongs to Institution (already done in SaveAdminEventAction) ✓
- [x] 7. Validate institution/venue mutual exclusion (already done in SaveAdminEventAction) ✓
- [x] 8. Update serializers to show institution+space OR venue+venueSpace

## Key-Person Tasks
- [x] 1. Update EventKeyPersonSyncService: use display_name consistently (already done ✓)
- [x] 2. Delete HasEventInvolvementRole trait
- [x] 3. Update EventKeyPerson model: replace metadata name with display_name
- [x] 4. Replace metadata->name search with display_name in EventSearchService + EventController
- [x] 5. Eager-load involveable/speaker

---

# Metadata hard-cut cross-repository audit

## Plan
- [x] Refresh codebase-memory indexes for `commerce` and `ilmu360`.
- [x] Audit `commerce` package contracts, migrations, models, tests, docs, and CI for removed metadata/legacy paths.
- [x] Audit `ilmu360` callers, migrations, serializers, tests, docs, and CI for removed metadata/legacy paths.
- [x] Delegate an independent Terra Extra High review and reconcile findings.
- [x] Implement fixes without compatibility aliases, shims, dual keys, or legacy fallbacks.
- [ ] Run focused tests, PHPStan/Pint, full quality workflows, and monitor GitHub Actions with `gh`.
- [ ] Repeat audit until both repositories are clean.

## Review

The cross-repository audit is in progress; local focused gates are clean after
the final regression batch. Commerce is green at commit `81da2f3f1c137f4442b0425c8d603a04843dd93b`
with Monorepo Split `29645941528`, CI `29645941526`, and style `29645941523`.
The app Composer lock/vendor tree is refreshed to events split SHA
`f10ce8de7b9180b2f529cf4d8560f10577165dc5`; the app refactor branch still needs
the final commit and Quality workflow confirmation.

### Final local regression batch

- [x] Refresh vendor packages before app verification.
- [x] Fix all failures from Quality workflow `29647038093` in one batch.
- [x] Re-run the affected app tests: Admin API (85 passed / 1,160 assertions) plus the remaining CI-failure files (293 passed / 2,337 assertions before the final five; the final five now pass).
- [x] Run Pint, Rector dry-run, PHPStan level 6, and `git diff --check`.
- [x] Confirm app production/test scans have no forbidden geography, metadata, or Event alias references.
# Person location country + speaker seeder

- [x] Locate the person edit location schema and speaker seeder.
- [x] Make country required in the location tab and ensure the saved path validates it.
- [x] Make speaker seeding assign Malaysia to every speaker.
- [x] Add or update focused tests and run formatting/static checks relevant to the change.

## Review

The Location tab now shows Country first and marks it required. `PersonSeeder`
assigns a primary Malaysia address when creating each seeded speaker; it does
not backfill existing records. The person edit page now hydrates and persists
the address state. Focused seeder tests pass.

# Non-production city seed reduction

- [x] Keep production city seeding unchanged.
- [x] Seed all Malaysian cities and a deterministic 10% sample for other countries outside production.
- [x] Verify the addressing seeder and production seeder behavior.

# Fix person institution attach selector

## Plan
- [x] Reproduce the Filament inverse-relationship failure from the reported stack trace.
- [x] Add a regression assertion for the explicit institution/person inverse relation.
- [x] Configure the relation manager and verify focused tests plus static checks.

## Review

Filament inferred `Person`'s inverse relation as `people`, but the application
model exposes the canonical inverse as `Institution::persons()`. The person
institution relation manager now declares `inverseRelationship = 'persons'`,
and the regression test passes.

Verification: focused Pest tests passed (2 tests / 10 assertions), Pint passed,
PHPStan passed for the changed PHP files, and `git diff --check` passed.

# Remove legacy person education tab

## Plan
- [x] Remove the obsolete Pendidikan tab from the person edit schema.
- [x] Add a regression assertion that the tab is absent while credentials remain in the profile flow.
- [x] Run focused tests, formatting, and static analysis.

## Review

The legacy Pendidikan tab was removed from the person edit form. The existing
Titles & Credentials section remains in Profil as the supported workflow.

Verification: focused Pest test passed (1 test / 2 assertions), Pint passed,
PHPStan passed for the changed PHP files, and `git diff --check` passed.
