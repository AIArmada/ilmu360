# Institution lifecycle

Authoritative contract for institution states, feed provenance, reseeds,
deletion protection, place doctrine, and facilities. Constraints live in code
(PHP enums plus DTO validation); the database carries no check constraints.

## Statuses and transitions

`App\Enums\InstitutionStatus`: `Pending`, `Verified`, `Rejected`, `Inactive`
(backed by `pending`, `verified`, `rejected`, `inactive`; serialized values
are unchanged). Donation channels carry the parallel
`App\Enums\DonationChannelStatus` vocabulary so donation policy never couples
to institution policy.

| From → To | Meaning |
|---|---|
| `Pending` → `Verified` | Approved; publicly listed. |
| `Pending` → `Rejected` | Refused. Terminal except through explicit manual review. |
| `Verified` ⇄ `Inactive` | Retirement and reinstatement. |
| Any → hard delete | Spam or duplicates only, via explicit review. Never a routine path. |

Feed imports create `Verified` rows directly with `verified_at` set; feed
refreshes of `Pending` rows stay `Pending` with their status timestamps
retained.

Each terminal transition records its dedicated `timestampTz` column
(`verified_at`, `rejected_at`, `inactive_at`) plus `last_state_change_at`.
`inactive_at` is the dedicated retirement timestamp: stale selection and the
`institutions:flag-stale-inactive` command read it exclusively and never fall
back to `published_at`, `updated_at`, or `last_state_change_at`.

## Reseed matrix

The feed importer (`MalaysiaPoskodMasjidSeeder`) matches rows by
`(source, external_ref)` only, never by slug or name.

| Row state | Reseed behavior |
|---|---|
| New identity | Created as `Verified` with `imported_at`, `verified_at`, and `last_state_change_at` set. |
| `Pending` | Refreshed from the feed (name, slug, address) but stays `Pending`; status timestamps, `imported_at`, and any manually written description are retained — the feed has no description column and an omitted description preserves. |
| `Verified` | Completely untouched, including graph and timestamps. A manually renamed slug stays attached; no duplicate is created. |
| `Rejected` | Completely untouched. Never re-verified by a reseed. |
| `Inactive` | Completely untouched. Never re-verified by a reseed. |
| Excluded identity | Skipped. Never recreated; the importer never clears an exclusion. |

A feed slug that collides with another identity fails the import loudly
instead of stealing or adopting the slug. Renaming a `Pending` slug and
reseeding restores the feed slug on the same row (same ID, no duplicate).

## Manual review

- `Rejected` rows leave that state only through explicit manual review.
- `institutions:flag-stale-inactive --days=180` (default) is a dry run that
  reports still-`Inactive` rows with `inactive_at` at or before the UTC
  threshold. `--apply` records `stale_inactive_flagged_at` on exactly those
  rows. The command never changes status, never deletes, never notifies, and
  is idempotent: already-flagged rows are left alone.
- Clearing an import exclusion likewise requires explicit manual review; no
  importer path clears one.

## Immutable source identity

Source-backed institutions carry `source`, `external_ref`, and `imported_at`:

- Written on create only. Through the graph writer
  (`App\Data\InstitutionData` plus `ImportInstitutionGraphAction`), a
  supplied provenance value that differs from the stored identity fails
  loudly with a validation error instead of rewriting identity — including
  attaching `source`/`external_ref` to a manually created row — while
  omitting both stays permitted for reviewed edits; at the model layer the
  provenance keys are additionally guarded on save.
- `imported_at` records the first successful create and is never updated.
- Identity bytes are exact: the DTO, importer, and exclusion lookup preserve
  `source`/`external_ref` without trimming or case rewrites.
- Slugs on source-backed rows are curated values protected from regeneration.

## Canonical feed

`database/seeders/masjid_feed_v1.csv` is the only feed. Every row carries its
own `source`/`external_ref`; `nama_display` and `slug` are opaque curated
bytes written byte-identical (no trimming, case rewrites, or fallbacks), and
all rows import regardless of `curation_status`, which is upstream information
distinct from app moderation. Only address `line1` keeps the historical
`normalizeAddressLine()` behavior.

State resolution consumes the canonical MY `State.code` exactly; the supplied
`state_code` must resolve. District, subdistrict, and locality resolve
through the canonical country address profile and hierarchy resolver —
exact names matched case-insensitively under the selected parent roles, over
active and temporally valid links — and are optional: blank is valid, and
unresolved non-blank values are reported while their feed text is retained in
the address `feed_geography` metadata — never fuzzily matched, invented, or
randomly assigned. Latitude/longitude are range-validated and must both be
present or both be blank.

The whole feed is preflighted (header, width, required identity fields, max
lengths, type, canonical state codes, coordinates and their pair shape,
in-feed duplicates) before any write, so a malformed feed fails loudly with
zero partial writes. Each row then imports in its own transaction holding a
row lock across the identity lookup, a post-lock exclusion recheck, the
moderation guard, and the write: whole-graph atomicity, no ownership
membership. Lifecycle hooks stay enabled throughout. Omitted graph parts
preserve, explicit `[]` clears, explicit values replace; an omitted address
preserves while explicit null detaches the primary link without deleting the
shared Address row. Description follows the same Optional shape: omitted
preserves on update (null on create), explicit null clears, a string writes.

Graph writes allow at most one primary per names list (an explicit
selection wins wherever it sits; the first name becomes primary only when
none was selected), at most one primary per package contact group (type +
purpose) and social group (platform + purpose), and unique space and
language ids, so conflicting data is rejected instead of silently
arbitrated away. Facility flags must be strict booleans in a code map at
the DTO boundary.

## Persistent no-recreate exclusion

Deleting a source-backed institution synchronously records an
`InstitutionImportExclusion` keyed by the exact `(source, external_ref)`
bytes, with a nullable audit-only `institution_id` (no foreign key) and the
deletion time.
The importer checks exclusions before the identity lookup and rechecks
after acquiring the row lock in the same per-row transaction, so an
identity deleted concurrently is skipped, never recreated; the record is
independent of `deleted_models` snapshots, so snapshot pruning never
re-opens the identity. The graph writer independently refuses to create an
excluded identity, so direct validated calls cannot bypass deletion
protection, while explicit updates of an existing live row stay supported.
Related venues and spaces are never deleted with the institution.

## Institution as location vs organizer

An event's organizer and its physical location are separate concerns.
`Event.institution_id` is an explicit institution place selection (the
`institution()` relation); the organizer is a separate `event_involvements`
row with `role_code = organizer` (`primaryOrganizerInvolvement`), and
organizer links alone never supply place.

Place resolution (`resolvedLocationAddress()` / `resolvedLocationName()`) is
venue-first: a selected `default_venue_id` wins, then the primary package
place (`primaryLocation` venue), then the selected institution's address.
When an explicit venue is selected but has no address, resolution returns
null — a missing explicit venue address never borrows the
institution/organizer address.

## Institution–venue bridge

`institution_venue` links institutions to reusable venues with `role` in
`operated|preferred`, `is_primary`, and timestamps, related from both sides.
`operated` means the institution runs the venue; `preferred` marks a favored
off-site venue. Off-site events keep their venue address while retaining the
masjid link instead of duplicating the masjid as a forked venue row.

## Facilities

Institutions carry own facilities as a nullable JSONB flag map, administered
through checkboxes. The effective facilities shown for an institution merge
its own flags with the linked venue/space facilities from the events package;
explicit own flags override inherited ones, and public/available flags on the
linked records are respected. Invalid facility keys are rejected.

## Clean schema cutover

This design cut over in the original migrations with no backward
compatibility: no legacy aliases, fields, branches, or values, no backfills,
no reconciliation, and no old-timestamp fallbacks. All writers assume the new
schema.

## Deferred items

- `country_code`/`state_id` denormalization on institutions is deferred: Scout
  already indexes them, so revisit only if listings slow down.
- Filament form adoption of `App\Data\InstitutionData` is phase 2. The DTO
  is the common import write boundary now; admin forms keep their current
  save paths until that phase.
