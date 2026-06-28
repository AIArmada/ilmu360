# AIArmada Adoption Status

Last updated: 2026-06-28

Active phase: Phase 1 - Package Source And Dependency Alignment

## Phase Dashboard

| Phase | Owner | State | Blockers | Next action | Verification proof |
| --- | --- | --- | --- | --- | --- |
| 0 - Baseline readiness | Main agent | `Verified` | B009 recorded for later cleanup | Begin Phase 1 | `review-log.md` entry for 2026-06-28 WP-00 |
| 1 - Source and dependencies | Unassigned | `Ready` | B001, B002, B003 | Add local Composer path strategy and dependency resolve plan | Pending |
| 2 - Package readiness | Unassigned | `Not Started` | B004, B005 | Add package readiness gates and test strategy | Pending |
| 3 - Foundation adoption | Unassigned | `Not Started` | B001, B002 | Move installed AIArmada packages to local path packages | Pending |
| 4 - Identity/geography/contacts/membership | Unassigned | `Not Started` | B006, B010 | Replace app identity support domains with package models and remove country switching | Pending |
| 5 - Core domain rewrite | Unassigned | `Not Started` | B007 | Replace app event/reference/engagement/moderation domains | Pending |
| 6 - Communications | Unassigned | `Not Started` | B008 | Replace notification engine with `communications` package | Pending |
| 7 - Commerce capabilities | Unassigned | `Assessing` | Paid-ticket package chain must be resolved | Evaluate commerce packages for paid event tickets and other actual workflows | Pending |
| 8 - App rebuild and cutover | Unassigned | `Not Started` | Depends on phases 1-7 | Delete superseded app code and verify fresh app | Pending |

## Blocker Register

| ID | State | Area | Detail | Resolution path |
| --- | --- | --- | --- | --- |
| B001 | `Assessing` | Composer | App currently requires five AIArmada packages from normal Composer resolution, not local path packages. | Add path repositories for local packages and verify `composer update` resolves. |
| B002 | `Assessing` | Dependency drift | Local packages require Laravel/Illuminate `^13.15.0`; app currently requires Laravel `^13.14`. Local Filament packages require `^5.6.7`; app currently has `5.6.6` locked. | Upgrade app framework constraints or adjust package constraints generically if compatible. |
| B003 | `Blocked` | Authz | Local `authz` / `filament-authz` require `spatie/laravel-permission ^8`; current installed path is on `^7.4.1` / lock `7.4.2`. | Choose v8 upgrade and migrate app usage, or make packages compatible with both v7 and v8 generically. |
| B004 | `Assessing` | References | `aiarmada/references` requires `spatie/laravel-sluggable ^3.7`; app and support package use `^4.0`. | Update package constraint and tests for Sluggable v4 compatibility. |
| B005 | `Assessing` | Migrations | `commerce-support` materializes migration stubs into `storage/framework/cache/aiarmada-commerce-migrations`; stubs for `audits` and `webhook_calls` need UUID/package-standard review. | Make stub loading configurable/generic and verify fresh migrations. |
| B006 | `Not Started` | Geography | Current app has integer country/state/city/district/subdistrict IDs by design, but rewrite target uses package UUID addressing. | Treat old geography as removed in fresh schema; seed package addressing data. |
| B007 | `Not Started` | Table ownership | Package defaults overlap current app tables such as `events`, `venues`, `event_series`, `event_submissions`, `references`, `reports`, and `saved_searches`. | Fresh schema accepts package ownership; delete old app tables/migrations when replacing. |
| B008 | `Not Started` | Channels | Package communications does not provide app-specific FCM, WhatsApp, or digest scheduling. | Bind app-owned drivers/schedulers through package contracts. |
| B009 | `Assessing` | SoftDeletes cleanup | Broad scan finds existing app `Team` SoftDeletes usage and package docs examples mentioning soft deletes. | Remove or replace legacy app SoftDeletes during fresh-schema cutover; clean stale package docs examples when those packages are edited. |
| B010 | `Not Started` | Global discovery | Current app has country switching and preferred-country assumptions in routes, layout, search filters, docs, API contracts, and saved search behavior. | Remove country switching in Phase 4/8; make country and address areas optional filters on global search. |

## Active Phase Checklist

- [x] Create dedicated docs hub.
- [x] Record status vocabulary and phase dashboard.
- [x] Record package inventory facts from live source.
- [x] Record architecture decisions from user-approved plan.
- [x] Create agent work queue for multi-agent execution.
- [x] Run documentation verification commands.
- [x] Update `review-log.md` with verification evidence.

## Current Facts

- Local package source: `/Users/Saiffil/Herd/commerce/packages/*`
- Local packages found: 59 Composer packages.
- Package migrations found: 247.
- Package Eloquent models found: 257.
- Package route files found: 14.
- Package Filament/resource files found: 116 in the curated baseline classification.
- Package-local tests/testbench config/package.json: none found during baseline scan.
- App currently requires only these AIArmada packages: `affiliates`, `commerce-support`, `filament-authz`, `filament-signals`, `signals`.
