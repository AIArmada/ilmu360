# AIArmada Adoption Status

Last verified: **2026-07-10** against live `refactor` tree  
(Composer requires, `app/Models`, migrations, dual-path greps, full multi-file phase reconciliation).

**Docs refresh complete:** every file under `docs/aiarmada-adoption/` is mapped in [`phase-reconciliation.md`](phase-reconciliation.md) folder map. Phase-04…07 have **Superseded** banners. Agent queue packet states realigned.

> **Source of truth hierarchy**
> 1. Live code + Composer  
> 2. This file (`status.md`)  
> 3. [`phase-reconciliation.md`](phase-reconciliation.md) — **no-miss matrix** (every phase-04…07 checkbox + agent WPs)  
> 4. [`phase-08-cutover.md`](phase-08-cutover.md) — Phase 8 checklist + Phase 9 workstreams  
> 5. [`gap-closure-report.html`](gap-closure-report.html) / [`cutover-plan.html`](cutover-plan.html) — visual only; may lag  
> 6. [`../commerce-package-readiness-reassessment.md`](../commerce-package-readiness-reassessment.md)  
> 7. Historical phase-00…07 + `agent-work-queue.md` — **not live backlog** without the reconciliation matrix  

## North Star

**Close remaining dual paths and cutover shims.** Use AIArmada packages natively. No BC layers “for old clients.” Custom code only when intentional product design.

**Feature development freeze (B012)** until Phase 9 exit criteria pass.

## Phase Dashboard

| Phase | State | Summary |
| --- | --- | --- |
| 0 – 3 | `Verified` | Hub, Composer path, package readiness, foundation |
| 4 – 7 assessment docs | `Superseded` | Work absorbed by Phase 8; residuals tracked in Phase 9 only — see [`phase-reconciliation.md`](phase-reconciliation.md) |
| 8 App rebuild & schema | `Mostly complete` | Schema/package ownership landed |
| **9 Native purity & productization** | **`In Progress`** | Taxonomy dual path, builders, alias traits, accessors, verification, paid checkout |

## Current Facts

| Fact | Value |
| --- | --- |
| Package source | `/Users/Saiffil/Herd/commerce/packages/*` (path repo) |
| Direct `aiarmada/*` requires | **25** |
| Laravel | **v13** |
| App migration files | **31** |
| SoftDeletes / DB cascades (policy) | Forbidden; packages audited clean |
| Comms `dispatch_through_package` | **Default `true`** (`COMMS_DISPATCH_THROUGH_PACKAGE`) |
| `DispatchMode` dual helper | **Removed** |
| Geography aliases (`state_area_id`, `district_id`, `subdistrict_id`) | **Rejected** (product + tests); package accessors removed |
| Public paid checkout | **Off** (`EVENTS_PUBLIC_PAID_CHECKOUT_ENABLED=false`) until payment bound |

### Installed packages (25)

**Backend:** addressing, affiliates, authz, commerce-support, communications, contacting, engagement, events, inventory, membership, moderation, references, seating, signals, ticketing  

**Filament:** filament-addressing, filament-authz, filament-communications, filament-contacting, filament-engagement, filament-events, filament-inventory, filament-seating, filament-signals, filament-ticketing  

**Admin plugins:** addressing, contacting, engagement, communications, events, inventory, seating, ticketing, signals, authz  
**Ahli plugins:** events, engagement  

### Migration inventory (31)

Laravel defaults (3) · vendor (8) · Passport (5) · app-unique entities (9) · shared report/saved_search (2) · pivots (3) · membership pivot bootstrap (1). Domain tables for events/addressing/comms/membership come from **package** migrations.

## Model Ownership Register

### Category A — Extends package model (15)

| App model | Package parent | Notes |
| --- | --- | --- |
| `Event` | `Events\Event` | Thick (~2.6k); still `HasTags` |
| `Registration` | `Events\EventRegistration` | Thick; legacy defaults residual |
| `Venue` | `Events\Venue` | + alias traits |
| `Series` | `Events\EventSeries` | |
| `Space` | `Events\VenueSpace` | |
| `EventKeyPerson` | `Events\EventInvolvement` | |
| `EventCheckin` | `Events\EventAttendance` | |
| `EventChangeAnnouncement` | `Events\EventUpdate` | Legacy accessors remain |
| `EventSubmission` | `Events\EventSubmission` | |
| `Reference` | `References\Reference` | Thick product |
| `MemberInvitation` | `Membership\MembershipInvitation` | |
| `MembershipApplication` | `Membership\MembershipApplication` | |
| `ModerationReview` | `Moderation\ModerationAction` | Legacy-friendly accessors |
| `SavedSearch` | `CommerceSupport\SavedSearch` | |
| `Report` | `CommerceSupport\Report` | |

### Category B — App roots + package traits

| Model | Seams |
| --- | --- |
| `Institution`, `Speaker` | `HasMembers`, `HasAddresses`, contacting traits + **alias traits (P9-D)** |
| `User` | Engagement actor traits; inbox/preference morphs |

### Category C — Deleted / replaced (closed)

App geography wrappers · Contact/SocialMedia · Following · EventSettings · MembershipClaim model · Jetstream Team · app notification Eloquent models + NotificationEngine · app event_attendees / user_venue / event_reference migrations.

### Category D — Intentional app-unique

Institution, Speaker, DonationChannel, MediaLink, Inspiration, SlugRedirect, ContributionRequest, AI usage/pricing, public Livewire, MCP, MediaLibrary collections, FCM/WhatsApp adapters, Malay/Islamic presentation.

## Gap register (Phase 9)

| # | Gap | State 2026-07-10 | Phase 9 ID |
| ---: | --- | --- | --- |
| G1 | Comms dual dispatch | **Mostly closed** — default through package; `DispatchMode` gone; destinations package-native | P9-B residual |
| G2 | Taxonomy dual path (Spatie Tags + classifications) | **Closed for event write path** — EventTerm forms/AI/seeder; Tag Filament remains non-event admin catalog only | P9-A done |
| G3 | Builder legacy column maps | **Closed** — DirectColumnMaps gone; factory uses `default_venue_id`/`delivery_mode` | P9-C done |
| G4 | Contact/social/address alias traits | **Closed** | P9-D done |
| G5 | Legacy accessors (announcement/moderation/reference parent) | **Closed** | P9-E done |
| G6 | Geography hard cut | **Closed** — product native FKs; zero alias footprint | P9-F done |
| G7 | Thick Event subclass | **Closed** — package-backed projections single-source; product metadata intentional; `primaryOccurrence()` | P9-G done |
| G8 | Institution dashboard legacy UI helpers | **Closed** — renamed to dashboard filter/sort sync; membership pivot uses package `role` | P9-H done |
| G9 | Dead notif dual-store tooling | **Closed 2026-07-10** — orphan factories + migrate commands deleted; User uses package `HasInbox`; destinations use `recipient_*` + `metadata` | P9-B done |
| G10 | Verification debt | **Open** — full suite/PHPStan not claimed | **P9-I** |
| G11 | Paid commerce productization | **In progress** — packages in; public checkout flag off | **ADR-013** |
| G12 | Package `Block` unused | **Deferred optional** | product decision |

## Active next actions

1. **P9-I** — `migrate:fresh --seed`, full Pest, PHPStan, Pint (claim exit only after green)  
2. **G11** — bind payment + public paid checkout when product ready  
3. **Optional** — retire Filament Tag admin if product confirms Tags unused  

## Blocker register

| ID | State | Detail |
| --- | --- | --- |
| B001–B010 | Resolved | Prior phase blockers |
| B011 | **Open** | Dual-path purity incomplete (taxonomy primary; builders/aliases/accessors) |
| B012 | **Open** | Feature freeze until Phase 9 exit |

No external package-install blockers.

## Verification (claim only after fresh run)

```bash
php artisan migrate:fresh --seed
vendor/bin/pest --parallel --compact
vendor/bin/phpstan analyse --ansi
vendor/bin/pint --dirty --format agent
```

Geography zero-legacy spot check:

```bash
rg -n "state_area_id|\bdistrict_id\b|\bsubdistrict_id\b" app tests database resources --glob '!**/storage/**' --glob '!tests/errors.md'
# Expect only reject-guards / negative assertions
```
