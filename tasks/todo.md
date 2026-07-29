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

# Reactive primary toggles in Person form

## Plan
- [x] Inspect the Person form and existing repeater state patterns.
- [x] Implement live mutual exclusivity for contact and social repeaters.
- [x] Verify syntax, static analysis, and focused tests where available.

## Review

- Filament v5's `Toggle::fixIndistinctState()` now handles live sibling exclusivity
  for both relationship-bound repeaters. It uses the parent Repeater and absolute
  state paths, so UUID item keys are supported without custom `../` traversal.
- Verification: PHP syntax passed, Pint passed, PHPStan passed with no errors,
  and 3 focused Pest tests passed (5 assertions).

# Audit: Person media path architecture

- [x] Refresh the codebase graph and map Person/Speaker/media-related symbols.
- [x] Inspect Spatie media configuration, path generation, model collections, and upload callers.
- [x] Trace the Speaker-to-Person path cutover and existing media migration command.
- [x] Validate the local storage tree and targeted media regression coverage.

## Review

`Person` is now the `HasMedia` owner and the enforced morph alias is `person`, so
new media resolves under `persons/{uuid-shard}/{person-uuid}/{collection}/`.
The old custom path was `speakers/...`; existing files therefore require an
explicit storage migration. The current `media:migrate-structure` command only
assumes legacy `{media_id}/{file_name}` paths, so it does not by itself detect
or move already-customized `speakers/...` paths. The local storage tree contains
both prefixes. The codebase graph refresh completed in degraded mode after its
integrity check removed the index; the audit used direct source inspection as
the fallback. The focused Pest command was started but did not produce a
completion result in the current test environment and was stopped.
# Fix Person edit page timeout

## Plan
- [x] Map the timeout report to the Person edit form query paths.
- [x] Add a focused regression test for the excessive-query pattern.
- [x] Implement the root-cause fix.
- [x] Run focused tests, formatting, static analysis, and diff checks.

## Review

The Person edit timeout was caused by repeated address-profile country lookups
while Filament evaluated location field labels and visibility/options closures.
SharedFormSchema now caches the resolved hierarchy per country for the request,
and a regression test verifies that repeated label construction performs one
country lookup instead of three.

Verification: focused Person tests passed (3 assertions), the performance
regression passed, Pint passed, and PHPStan passed for the changed schema.

# Speaker social links visual refinement

- [x] Replace plain social links with branded icon tiles.
- [x] Add platform-aware icon treatment, handles, hover states, and keyboard focus states.
- [x] Verify the Wadi Annuar page and focused speaker tests.

## Review

The speaker sidebar now presents official social profiles as compact branded tiles using the bundled SVG assets, platform colors, profile handles, external-link affordances, and responsive two-column behavior on smaller widths. Verification: 5 focused tests passed (20 assertions), Pint passed, PHPStan passed, live HTML includes the Facebook, Instagram, and YouTube icon links, and `git diff --check` passed.

# Shared share-button icon refinement

- [x] Remove nested visual containers from share icons.
- [x] Enlarge the bundled brand SVGs and preserve accessible focus states.
- [x] Verify supported public share surfaces.

Verification: public share modal coverage passed (25 assertions), tracked-share UI coverage passed (24 assertions), and `git diff --check` passed.

# Profile review action icons

- [x] Add edit and flag icons to the profile update/report actions.
- [x] Preserve destinations, labels, hover motion, and keyboard focus states.

Verification: 5 focused speaker tests passed (20 assertions), Pint passed, PHPStan passed, and the live speaker page still renders both actions.

# YouTube social profile URL canonicalization

- [x] Trace YouTube URL construction through the contacting package, form schemas, save normalizer, API, and public profile rendering.
- [x] Change YouTube profile links from `/@handle` to `/handle`.
- [x] Update package fixtures and form hints, and add regression coverage for handle, current URL, public page, and admin API paths.
- [x] Complete final formatting, static analysis, and diff verification.

## Review

The contacting package now configures YouTube with `www.youtube.com/`. Form hints
no longer imply that every platform requires an at-sign.

Verification: focused normalization tests passed (9 tests / 29 assertions),
speaker-page tests passed (7 tests / 20 assertions), the admin API regression
passed (1 test / 19 assertions), the contribution workflow regression passed
(1 test / 6 assertions), Pint passed, PHPStan passed with no errors, and both
repositories passed `git diff --check`. The live Wadi Annuar record now resolves
YouTube to `https://www.youtube.com/UstazWadiAnnuarOfficial`.

# Refine speaker profile information layout

## Plan

- [x] Inspect the speaker view and identify the hero, biodata, summary, contact, and social blocks.
- [x] Keep contact and social media in their existing sidebar sections.
- [x] Add contained scrolling for long biodata in its original section.
- [x] Verify the corrected speaker view layout and final diff.

## Review

The speaker profile keeps contact and social media in the existing sidebar,
while the original biodata section now uses a contained scroll region for long
content. The Ringkasan Profil card continues to organize speaker location and
future/past event counts.
Verification: Blade cache, focused speaker coverage (5 tests / 21 assertions),
and `git diff --check` passed.
