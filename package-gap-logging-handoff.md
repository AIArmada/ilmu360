# Handoff: Resolution-gap logging + admin matching + provider promotion

## Suggested skills

Before writing code, read this repo's `AGENTS.md` and each package's `CONTEXT.md`, then invoke what's available: a PHP/Laravel standards skill and a testing skill (this repo's equivalents of spatie-laravel-php / testing-best-practices). Package conventions listed under "Verify before building" override generic advice where they conflict.

---

# Task (two workstreams, same repo)

## Background (the problem we are facing)

The ilmu360 app has a Google Places picker (app-side) that resolves Google address components against this ecosystem's addressing provider hierarchies. It is fully provider-driven: the Google-component mapping derives from hierarchy shape, output roles/types/levels come from the loaded `CountryAddressProfile`, and name matching is alias-aware via `address_area_names` plus a small app-side per-country prefix-strip table.

There are 37 country providers and growing toward 100s. The remaining failure mode is **naming gaps**: the name an external source returns does not match the official provider area name or any alias. Real examples: Google `Jakarta Pusat` vs official `Kota Jakarta Pusat`; Google `Kuala Lumpur` vs official `Wilayah Persekutuan Kuala Lumpur`; English exonyms vs local official names (`West Java` vs `Jawa Barat`).

Today these gaps are **silent**: resolution degrades to a partial match or text fallback with no record, so provider authors discover gaps by accident. This task builds the complete loop:

1. **Surface** — log every attempted-but-unmatched value with country + role (this task: mechanism).
2. **Match** — a package admin reviews gaps in the Filament adapter and matches each to the correct area, creating an alias immediately with no reseed and no deploy (this task).
3. **Promote** — periodically export admin-matched aliases back into providers' `areaNames()` so fresh installs and other projects seed complete data (this task: export tooling; the paste itself stays human).

Explicit non-goal: gaps must never **auto-fill**. A failed match means the system does not know *which* area the string maps to, so an automatic alias would manufacture authoritative-looking wrong data. Human-in-the-loop at match time and at promotion time.

## Why the package layer (decision already taken)

- The feedback loop is package-closed: gap → alias → re-seed, all in these packages.
- Producers exist on both sides of the app/package line (app Google picker today; OneMap invalids and import failures are package-side code that cannot log to an app table cleanly).
- The addressing package already has failure-data vocabulary (`Import*FailureData` / `ResultData`); gap logging is its persistent, aggregated sibling.
- The Filament adapter package exists precisely for this UI (`AIArmada\FilamentAddressing`, already registered by consuming apps); per its CONTEXT.md it must stay UI-only with all domain logic in core actions.

## Workstream A — `aiarmada/addressing` (domain: table, actions, commands)

Follow this package's conventions (verified): `AddressingTableResolver` keys, uuid PKs, `foreignUuid` columns, **no FK constraints or cascades**, `json_column_type` config for JSON columns, `ResultData` DTOs with created/updated/skipped/failures, `execute()` entry points, `DB::transaction` writes, `ValidationException` with `__()` messages for operator input errors, `mb_*` string functions, `final` classes, `declare(strict_types=1)`, `LOWER()`-based name matching. Mirror `ImportPostalCodesAction`, `SyncAddressAreaAssignmentsAction`, and `SaveAddressAreaAction` where they overlap. Tests go in monorepo `tests/src/Addressing/...` (Pest); docs update in the same pass (`docs/03-configuration.md` tables entry, `docs/04-usage.md` gap/match/promote section).

1. **Migration + model.** Table `address_resolution_gaps` via a new `resolution_gaps` key in `AddressingTableResolver::defaults()` (check migration numbering/naming of the existing `2001_01_01_*` series and continue it). Model follows existing model conventions (`AddressingTableResolver::resolve()`, `HasUuids`); register in `addressing.models` / `ModelResolver` only if the pattern requires it for non-overridable models — check first, do not invent seams. Columns:
   - `source` (free string: `google-picker`, `onemap`, `import` — NOT an enum), `country_code`, `role` (assignment role, or `state` for state-level misses), `value` (raw attempted string), `normalized` (comparison form), `reason` (`unmatched` | `ambiguous`, free string), `hits`, `first_seen_at` / `last_seen_at`, `context` (nullable JSON: producer sample such as place id / attempted names), `status` (`open` | `matched` | `ignored`), `matched_area_id` (nullable uuid, no constraint), `matched_by` (nullable string: actor identifier — the package is auth-agnostic), `matched_at`, timestamps.
   - Index `(source, country_code, role, normalized)` unique for the upsert key; plain indexes for report filters. Global reference data: no owner traits (like areas/postcodes, unlike addresses/snapshots).
2. **`LogAddressResolutionGapAction`.** Upserts on (`source`, `country_code`, `role`, `normalized`): insert with `hits = 1` + first/last seen, or increment `hits`, touch `last_seen_at`, refresh `context` sample. **Normalization is package-owned and minimal** (lowercase + collapse whitespace) — no prefix stripping or app-specific rules; Google-specific translation stays in integrations per the usage docs. Recurrence of a `matched` key reopens it to `open` (regression signal: alias broken, area deactivated). `ignored` stays terminal.
3. **`MatchGapToAreaAction`.** Attaches an unmatched gap to an area. Guards (all server-side — never trust UI scoping): gap must be `open` with reason `unmatched` (`ambiguous` gaps need data cleanup, not another alias — refuse with a clear message); area's `country_code` must equal the gap's; area type/level must satisfy the role's profile definition via `CountryAddressProfileResolver` (mirror `SyncAddressAreaAssignmentsAction`'s match logic); **ambiguity guard**: refuse when the value already resolves to a *different* area in the same scope, checking primary names AND aliases with `LOWER()` comparison (functional-index convention — see troubleshooting docs). On success, inside a transaction: create the alias (`source = 'manual'` per the manual-vs-provider coexistence doctrine so reseeds preserve it, `name_type = 'common'`, `is_preferred = false`) and mark the gap `matched` with area/by/at. `state`-role gaps are rejected by this action: states have no alias table; their remedy is the integration's prefix rules or a provider rename — surface that message.
4. **`IgnoreResolutionGapAction`.** Marks a gap `ignored` (junk values). Terminal unless manually reopened; keep a reopen path minimal (direct status update or a tiny action — follow the simplest existing pattern).
5. **Report command** `addressing:resolution-gaps` with `--country=`, `--days=30` (default recency window), `--reason=`, `--status=`; top gaps by hits; plus a "matched but not yet in providers" backlog count (see promotion). No auto-prune in v1: fixed gaps fall off the recency window; reappearance means regression.
6. **Promotion export command** `addressing:export-gap-aliases --country=ID` (name adjustable to command conventions). Emits copy-paste-ready PHP in the exact `areaNames()` shape, grouped per provider file, sorted by `source_id` then name. Candidate set = aliases joined through `matched` gaps (evidence-backed), with guards: skip aliases whose area is NOT shipped by that provider (verify `source_id` exists in the provider's bundled `AddressAreaSource::areas()` — a manually created area has nowhere to land; warn loudly); skip values already declared in the provider's `areaNames()` (case-insensitive). Add `--prune`: after a reseed has landed the provider rows, delete `manual` aliases exactly duplicated by a provider-sourced (area, name) pair. Never auto-edit provider files — the paste is the human quality gate.
7. **Tests** (`tests/src/Addressing/...`): log insert/dedupe/hits; reopen-on-recurrence; match success + each guard rejection; ignore; report filters + backlog count; export guards (unshipped-area skip, already-declared skip, deterministic output) + prune safety; table-name override respected.

## Workstream B — `aiarmada/filament-addressing` (UI adapter, no domain logic)

Follow this package's conventions (verified): `Resource` + `Tables/*Table::make` + `Pages/` + `Rules/` validation objects; config sections Navigation → Tables → Features → Resources; `resources.<key>` entries with `enabled`/`read_only`/`model`; feature flags gating header actions; delegate all writes to core actions (see `PostalCodeImporter::beforeSave` calling `ImportPostalCodesAction` and surfacing failures). Tests in `tests/src/FilamentAddressing/...`; docs updated in the same pass (overview resource list + configuration entries).

1. **`ResolutionGap` resource** (global data — no owner scoping): columns for value, country badge, role badge, reason, status, hits, last seen, matched area link; filters for country / role / reason / status reusing the `AddressingFilterOptions` shape; default sort by hits or last seen. No create/edit pages (rows are system-created); view page optional — keep minimal per existing read-mostly precedent.
2. **Match table action**: area select reusing the `Schemas/AddressFormSchema` provider-driven search shape — scoped to the gap's country + the role's profile type/level definition, `LikeSearch`, showing type/level/parent path so the admin can identify correctly; display the gap's sample `context` (place name/coords) in the action form for evidence. Disabled (with explanation) for `ambiguous` and `state` gaps. Calls `MatchGapToAreaAction`; surfaces its `ValidationException` messages. When no suitable area exists the admin learns it is a *coverage* gap (area missing — remedy is data import/area creation via the existing areas resource, then match); link to area creation from the empty state if trivial.
3. **Ignore action** (+ optional bulk ignore) calling the core ignore action.
4. **Config**: `resources.resolution_gaps` entry, navigation icon + sort after postcodes, feature flag for the match/ignore actions (default on, mirroring how mutating header actions are gated). Respect `read_only` by hiding mutating actions.
5. **Tests**: resource registration/model/navigation, read-only hides actions, match/ignore delegate and surface core failures (mock or live-action — follow the lightest existing pattern).

## Out of scope (do NOT do)

- Producer wiring of any kind (app picker calls the log action separately with source `google-picker`; OneMap-invalid and import-failure producers are follow-ups).
- Auto-alias creation, auto-editing provider files, notifications, pruning of gap rows, ambiguous-gap remediation UI (view-only), state-gap matching (view-only by design).
- New extension seams, model overrides, or config patterns beyond what each package already establishes.

## First step: verify before building

Dig into both packages and confirm or adjust the proposal against actual conventions: table-resolver + migration series, model/`ModelResolver` patterns, action + `Data/` patterns, command registration and naming, Filament resource/table/action/rule patterns, test layout and tooling, Pint/PHPStan configs, docs structure. If any proposed detail conflicts with package conventions, follow the package and note the deviation in the final summary.

## Acceptance criteria

- Migrations run clean on fresh and existing databases; table name overridable via config; docs updated in both packages in the same pass.
- Log dedupes (repeat bumps `hits`, no duplicate); matched-key recurrence reopens; match/ignore enforce every guard with clear messages; manual aliases survive `SeedCountryGeographiesAction` reseeds (add a test proving source-scoped preservation).
- Report and export commands honor all filters; export skips unshipped/duplicate aliases deterministically; prune only removes exact provider-duplicated manual aliases.
- Filament resource lists/filters; match/ignore work end-to-end against core actions with core failures surfaced; hidden when disabled/read-only.
- New behavior covered by monorepo Pest suites for both packages (run per-package, plus the touched suites); Pint/PHPStan per repo config clean on touched files.
- Demo: log sample gaps → match one via the action → show report + export output → reseed a provider → show the alias survived → remove demo rows.
