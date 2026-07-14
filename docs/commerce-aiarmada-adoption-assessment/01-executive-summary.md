# 01 — Executive Summary

> ## ⚠️ OWNER DIRECTIVE — NO BACKWARD COMPATIBILITY
> All cutovers are **hard**. Local code is deleted in the same change that introduces
> the package replacement — no deprecated aliases, shims, facades, wrappers, or
> dual-write/dual-read periods. One-time data migration is permitted; lingering
> compatibility code is not. Any `TEMPORARY_*`/`staged` wording in the detailed docs
> is to be read as "full cutover + removal in a single change, behind a test."

## Headline

ilmu360 has adopted the **foundational + identity/events layer** of the `aiarmada/*` package suite at a **shallow-but-real** level: packages are installed, their migrations run, their config is published (mostly), six Filament plugins are registered, and five app models inherit package models. However, **runtime business logic for the "in-progress" domains still lives entirely in the app** — app code imports **zero** package `Actions` or `Services` for events, engagement, inventory, communications, moderation, ticketing, and seating. The adoption is therefore best described as **"schema + models + plugins adopted; flows not yet cut over."**

The original audit hypothesis (heavy local duplication of cart/order/voucher/chip commerce flows) is **rejected with high confidence**: ilmu360 contains **no local commerce code whatsoever** and intentionally does not require the commerce packages. The real gaps are **parallel-storage duplicates** in the events/membership/engagement/communications/moderation domains, where app-owned tables coexist with package-owned tables for the same concept.

## Adoption levels

| Package | Adoption Level (0–5) | Cutover Maturity (0–5) | One-line reality |
| --- | --- | --- | --- |
| `affiliates` | 4 | 4 | Active via `ShareTrackingService` + analytics services; genuinely used |
| `signals` + `filament-signals` | 5 | 5 | Fully adopted; tracking, pages, analytics all package-backed |
| `authz` + `filament-authz` | 4 | 4 | `config/authz.php` published; UserResource extends package; gates active |
| `commerce-support` | 3 | 3 | Foundation only; `Permission`/`Role` models pointed at package; helpers |
| `addressing` + `filament-addressing` | 5 | 5 | Fully adopted; legacy geography models deleted; seeded; resolver live |
| `contacting` + `filament-contacting` | 5 | 5 | Fully adopted; `Contact`/`SocialMedia` deleted; traits + aliases used |
| `events` | 3 | 2 | Models inherited (Event/Venue/Registration) + ~60 tables installed, but **zero package actions/services called**; app flows still local |
| `filament-events` | 1 | 0 | Required in composer but plugin **NOT registered**; unused |
| `engagement` + `filament-engagement` | 2 | 2 | Plugin registered + tables installed; app still uses local `followings` table; no package action imports |
| `membership` | 2 | 2 | `MemberInvitation` extends package model; `MembershipClaim` + claim flow still local; `membership_applications` table parallel to `membership_claims` |
| `references` | 3 | 3 | `Reference` extends package model + builder bridge; local Resource/UI remains |
| `inventory` | 1 | 0 | Installed (16 tables) + config published; **zero app usage** |
| `ticketing` | 1 | 0 | Installed (tables exist); **zero app usage** |
| `seating` | 1 | 0 | Installed (tables exist); **zero app usage** (no filament-seating registered) |
| `communications` + `filament-communications` | 2 | 1 | Plugin registered + 16 tables; app still runs own `notification_*` models/tables; no package action imports |
| `moderation` | 1 | 1 | Installed + config; app runs own `ModerationReview` + queue; no package usage |

Adoption scale: 0 none · 1 installed · 2 configured/partial · 3 some main use · 4 main flow uses package · 5 fully adopted+tested.
Cutover maturity: 0 local only · 1 package exists/local dominates · 2 mixed · 3 wrappers · 4 package direct · 5 source of truth.

## What is genuinely fully adopted (level 5)

- **addressing + filament-addressing** — legacy `Country/State/District/Subdistrict` models deleted; country resolution and State/AddressArea bridging now live in the addressing package, with app observers and seeders consuming them.
- **contacting + filament-contacting** — `Contact`/`SocialMedia` removed; `HasContactMethods`/`HasSocialProfiles` traits + `HasPackageContactAliases`/`HasPackageSocialAliases` accessors in use.
- **signals + filament-signals** — tracking, `ProductSignalsService`, signal pages extend package pages.

## What is partially adopted (the real work zone)

- **events** — model inheritance done (Event/Venue/Registration → package), schema installed, but **all event business logic still runs through `app/Actions/Events/*`** (`SaveAdminEventAction`, `SubmitFrontendEventAction`, `RegisterForEventAction`, `CreateAdvancedParentProgramAction`, `SyncEventResourceRelationsAction`). No package action is invoked. This is the single largest cutover opportunity.
- **engagement** — plugin + tables present, but the app's "follow" feature writes to local `followings`, not package `engagement_follows`.
- **membership** — invitation model cut over; claim/application workflow not.
- **references** — model + builder cut over; admin Resource + local UI not.
- **communications** — heaviest parallel storage: app's `notification_*` cluster (5 models) vs package `communication_*` (16 tables).

## What is installed but not meaningfully used (level 1)

- **inventory, ticketing, seating** — all three: tables exist in DB, configs (partly) published, but **zero `AIArmada\Inventory|Ticketing|Seating` imports anywhere in `app/`**. These are schema-only installations. They become real only when paid-ticketing commerce is built.
- **filament-events** — required but plugin not registered.

## What remains duplicated / removable

See `09-deletion-candidates.md`. Highest-value removals: orphaned `registrations` table (AIA-DELETE-002), parallel `series`/`event_series` (AIA-MIG-001), parallel `followings`/`engagement_follows` (AIA-MIG-003), the local `Notification*` cluster once communications is truly adopted (AIA-MODEL-012), and the local events actions once flows delegate to package actions (AIA-ACTION-001).

## What should depend directly on packages

Event create/submit/register flows should call `AIArmada\Events\Actions\*` instead of `app/Actions/Events/*` — and those local action classes are **deleted** in the same change (consumers call the package directly). Engagement follow/unfollow should call `AIArmada\Engagement\Actions\*`, with `HasFollowers` + the `followings` table removed in the same change. No compatibility wrappers.

## What genuinely needs to stay local (app-specific)

- `DonationChannel`, `Inspiration`, `ContributionRequest`, `EventChangeAnnouncement`, `EventKeyPerson`, `Space`, `SlugRedirect`, `SavedSearch` (app-specific), `AiModelPricing`/`AiUsageLog`, `Team`/`TeamInvitation`, `SocialAccount`, `PassportUser`. No package equivalent and genuine app need.
- App-specific Filament: `Ahli` panel, `ModerationQueue` page, `ProductSignals`/`ShareAnalytics` dashboards (these extend package pages — correct), local event admin Resources (until filament-events is registered).

## Highest-risk gaps

1. **AIA-MIG-007** — `events`/`venues`/`saved_searches`/`reports` tables are shared between app + package migrations with no published `config/events.php`; collision is currently resolved only by migration run-order/guards. Fragile.
2. **AIA-ACTION-001** — events flows bypass all package actions; biggest behavioral divergence risk. Cutover is hard: local action deleted in the same change.
3. **AIA-MODEL-012 / AIA-ACTION-004** — communications parallel storage (app notifications vs package communications). Hard cutover = one-time row migration into `communication_*`, then `Notification*` models + `notification_*` tables removed in the same change. Touches production notification history.
4. **AIA-FILAMENT-002** — `filament-events` required but unused; ambiguous intent.
5. **AIA-CONFIG-003** — blanket `loadMigrationsFrom(vendor/aiarmada/*)` masks intentional opt-in and pulls unused inventory/ticketing/seating schema.

## Recommended move-forward strategy (hard cutovers, no compatibility layers)

1. **Stop the bleed:** publish `config/events.php` and pin table ownership; decide the 4 ambiguous shared tables.
2. **Pilot one hard cutover:** switch event register to call `AIArmada\Events\Actions\*` directly behind a test, **and delete `app/Actions/Events/RegisterForEventAction.php` in the same change** (update all consumers). Prove the pattern.
3. **Drop dead parallel tables** that have no writer: `registrations` (one step), then `series`→`event_series` (data move + drop source table in one change).
4. **Activate or uninstall** inventory/ticketing/seating based on the commerce roadmap decision.
5. **Hard-cut communications and membership in single changes:** for each, migrate data once → switch consumers to the package → delete local models/tables. No dual-write phase.

> The order still front-loads low-risk items to build test coverage before the high-risk communications slice, but each step is now a **complete** cutover, not a staged transition.
