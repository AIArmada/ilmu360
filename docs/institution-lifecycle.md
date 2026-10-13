# Institution lifecycle

Authoritative contract for institution states, source provenance, deletion
protection, place doctrine, and facilities. Constraints live in code (PHP
enums plus model and action validation); the database carries no check
constraints.

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

Each terminal transition records its dedicated `timestampTz` column
(`verified_at`, `rejected_at`, `inactive_at`) plus `last_state_change_at`.
`inactive_at` is the dedicated retirement timestamp: stale selection and the
`institutions:flag-stale-inactive` command read it exclusively and never fall
back to `published_at`, `updated_at`, or `last_state_change_at`.

## Feed import removed

The national masjid-directory feed importer (`MalaysiaPoskodMasjidSeeder`
plus the `InstitutionData` graph writer) was removed, along with its CSVs
and the `SEED_MASJID_DIRECTORY` gate. Institutions are created and updated
only through the admin and public save paths. Institutions the feed created
keep their source identity, curated slugs, and statuses (below); nothing
reseeds or refreshes them.

## Manual review

- `Rejected` rows leave that state only through explicit manual review.
- `institutions:flag-stale-inactive --days=180` (default) is a dry run that
  reports still-`Inactive` rows with `inactive_at` at or before the UTC
  threshold. `--apply` records `stale_inactive_flagged_at` on exactly those
  rows. The command never changes status, never deletes, never notifies, and
  is idempotent: already-flagged rows are left alone.
- Clearing an import exclusion likewise requires explicit manual review.

## Immutable source identity

Source-backed institutions carry `source`, `external_ref`, and `imported_at`:

- Written on create only. At the model layer the provenance keys are
  guarded on save, so a later save cannot rewrite the stored identity —
  including attaching `source`/`external_ref` to a manually created row.
- `imported_at` records the first successful create and is never updated.
- Identity bytes are exact: the exclusion lookup preserves
  `source`/`external_ref` without trimming or case rewrites.
- Slugs on source-backed rows are curated values protected from regeneration.

## Persistent no-recreate exclusion

Deleting a source-backed institution synchronously records an
`InstitutionImportExclusion` keyed by the exact `(source, external_ref)`
bytes, with a nullable audit-only `institution_id` (no foreign key) and the
deletion time.
The record is independent of `deleted_models` snapshots, so snapshot
pruning never re-opens the identity. No importer currently reads
exclusions; they are retained as the durable no-recreate ledger a future
importer must check before recreating any source identity. Related venues
and spaces are never deleted with the institution.

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
