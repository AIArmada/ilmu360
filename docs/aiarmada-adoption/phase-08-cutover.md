# Phase 8 — App Rebuild And Cutover

State: **`Mostly complete`** (schema + ownership).  
Native purity / dual-path removal: **Phase 9** — see [`status.md`](status.md), [`phase-reconciliation.md`](phase-reconciliation.md), and [`gap-closure-report.html`](gap-closure-report.html).

Last verified: **2026-07-10**.

## Objective (Phase 8)

Rebuild app surfaces on package-owned domains, delete superseded app code, prove fresh-schema app works.

## Objective (Phase 9 — successor, required)

Remove every backward-compat shim, dual path, and cutover hack so AIArmada packages are used **natively**. Custom code only when intentional by product design. Application must be stable, performant, and ready for further development.

---

## Package Installation (complete)

Direct requires: **25** `aiarmada/*` packages via path repository.  
Configs published. Admin + ahli plugins registered (ahli: events + engagement only).

Full list: see [`status.md`](status.md).

---

## Sub-Phase Checklist

### 8.A — Package Installation

- [x] Require core + Filament packages
- [x] Publish configs
- [x] Autoload / optimize clear

### 8.B — Migration Conflict Analysis

- [x] Identify table overlaps
- [x] Plan ownership
- [x] App migrations reduced **73 → 31**

### 8.C — Geography & Contacts

- [x] Remove country switching (routes, shell selectors, preferred-country defaults)
- [x] Delete app geography models/enums/traits
- [x] Seed addressing countries + areas
- [x] Replace Contact/SocialMedia with package contacting
- [x] Scout schemas use package address fields (`country_code`, `city`, `state`, `postcode`)
- [x] **Phase 9 geography:** package-native only — `state_id`/`city_id` + `admin_area_1_id` (district) / `admin_area_2_id` (subdistrict); no `district_id`/`subdistrict_id`/`state_area_id` aliases

### 8.D — Events Domain

- [x] No app event table migrations (package owns)
- [x] App models extend package: Event, Venue, Registration, EventCheckin, Series, Space, EventKeyPerson, EventChangeAnnouncement, EventSubmission
- [x] `config/events.php` wired
- [x] Event media collections (cover/poster/gallery) on app subclass
- [x] `filament-events` on admin + ahli
- [x] Free registration path + pass flags configured
- [x] Submission workflow on package `EventSubmission`
- [x] Runtime builders bridge package columns (`EventBuilder`, `VenueBuilder`, `ReferenceBuilder`) — **temporary**
- [ ] **Phase 9 P9-C:** delete builder legacy maps after callers rewritten
- [ ] **Phase 9 P9-A:** taxonomy single path (see 8.D.T)
- [ ] **Phase 9 P9-G:** thin Event/Registration; package-native field names (app subclass OK only for intentional product)

#### 8.D.T — Taxonomy

- [x] Package tables exist; `SyncEventTaxonomiesAction` / `SyncEventClassificationsAction` exist
- [x] Public filters / catalogs largely use `EventTaxonomy` / `EventTerm`
- [x] ADR-011: package classifications are the product taxonomy (not dual write forever)
- [x] Event submit/admin/contribution **write path** largely uses classifications
- [ ] **Open dual path residual:** `Event` still uses Spatie `HasTags`; Filament Tag resource; AI media extraction still touches `Tag`
- [ ] **Exit:** one write path + one index path; no dual attach for the same concept

### 8.E — Engagement & Membership

- [x] Engagement via `EngagementManager` + `RegistrationServiceInterface`
- [x] Deleted local Register/Save/Unsave actions + `HasFollowers`
- [x] `MembershipApplication` extends package model
- [x] `MemberInvitation` extends package invitation (hashed tokens)
- [x] Package approve/reject/cancel + add/remove member actions used
- [x] `AppMembershipHook` bound
- [x] `AppMembershipApplicationNotifier` bound
- [x] Jetstream teams deleted
- [x] Filament resource under `MembershipApplications`
- [x] Cosmetic only: `startMembershipClaim` Livewire method name
- [x] `filament-engagement` on admin + ahli

### 8.F — References & Moderation

- [x] `Reference` extends package model (thick intentional product layer + residual cutover glue)
- [x] App Filament references (no filament-references package)
- [x] `ModerationReview` extends `ModerationAction`
- [x] `Report` extends commerce-support Report
- [ ] **Phase 9:** remove legacy-friendly accessors on ModerationReview / EventChangeAnnouncement
- [ ] Optional: package `Block` if product needs bans
- [ ] Optional: report ↔ event approval linkage if product requires it

### 8.G — Communications

- [x] Package + `filament-communications` (admin)
- [x] Inbox: `InboxChannel` → `NotificationInbox`; Livewire/API read package model
- [x] Preferences: `User::notificationSetting` → `CommunicationPreference`
- [x] App notification Eloquent models **deleted**
- [x] `NotificationEngine` / `NotificationCenterMessage` **deleted**
- [x] Resolvers bound: Preference, QuietHours, Consent, Suppression
- [x] Content/recipient snapshot resolvers present
- [x] Digest command can use `CommunicationBatch`
- [x] `auto_capture` default **true**
- [x] **`dispatch_through_package` default true**; dual `DispatchMode` **removed** (2026-07)
- [x] App orchestration may remain intentional: `EventNotificationService`, `NotificationSettingsManager`, Push/WhatsApp (document as intentional channels)
- [ ] **P9-B residual:** delete orphan `PendingNotificationFactory` / `NotificationDeliveryFactory`; retire unused `communications:migrate-*` if safe
- [ ] Prefer package `HasInbox` (or documented intentional morph relation) consistently

### 8.H — Final Cleanup (Phase 8 original)

- [x] Superseded geography/contact/membership/notification models deleted
- [x] Superseded event settings / following / claim model deleted
- [x] Geography product hard-cut (P9-F closed)
- [ ] **Phase 9:** delete compat traits, builder maps, taxonomy dual residual
- [ ] Full suite green (not claimed)
- [ ] PHPStan clean on cutover surface (baseline still has pre-existing noise)

---

## Corrected Facts (supersede older phase-08 prose)

| Claim that was stale | Truth 2026-07-09 |
| --- | --- |
| PendingNotification / NotificationDelivery / engine still exist | **Deleted** |
| Notification models still in app/Models | **No** |
| MembershipClaim still in 38 files / 12 broken tests | **Mostly gone**; cosmetic Livewire name only |
| MembershipApplicationNotifier unbound | **Bound** (`AppMembershipApplicationNotifier`) |
| Phase 8 “Complete” | **Schema mostly complete; purity incomplete** |
| Category C “app notification models still exist” | **False** — see status.md |

---

## Phase 9 Workstreams (exit = develop-ready)

Ordered for dependency and blast radius. Full task board: [`cutover-plan.html`](cutover-plan.html) / [`gap-closure-report.html`](gap-closure-report.html).

| ID | Workstream | State 2026-07-10 | Exit proof |
| --- | --- | --- | --- |
| P9-A | **Taxonomy single path** | Open residual | One write + one index path; no dual Tags/classifications for events |
| P9-B | **Comms package dispatch** | Mostly closed | Default through package + DispatchMode gone; residual factories/commands |
| P9-C | **Kill legacy builders** | Open | Callers use package columns; builder maps deleted |
| P9-D | **Kill alias traits** | Open | Package contact/social/address API only |
| P9-E | **Kill legacy accessors** | Open | Announcement / moderation / registration package fields only |
| P9-F | **Hard-native geography** | **Closed** | Product FKs only; zero `state_area_id`/`district_id`/`subdistrict_id` |
| P9-G | **Thin thick subclasses** | Open | Intentional product only on Event/Reference |
| P9-H | **UI debt** | Open | Institution dashboard legacy helpers removed |
| P9-I | **Verification** | Open | Full seed + Pest + PHPStan + Pint |
| G11 | **Paid commerce (ADR-013)** | In progress | Public checkout when payment bound; mode matrix tests |
| G12 | **Package Block** | Deferred optional | Only if product needs bans |

### Intentional custom (allowed after Phase 9)

- Institution / Speaker as app entities
- Donation channels, contribution UX, inspirations, AI usage
- Public Livewire page composition + Malay/Islamic copy
- MCP tools and prompts
- Spatie MediaLibrary collection/conversion policy
- FCM / WhatsApp channel adapters (document as intentional)
- Thin app subclasses of package models when they only add product behavior

### Forbidden after Phase 9

- Dual systems (two stores for the same concept)
- Silent alias maps inside builders/forms/search core
- “Temporary” BC for old admin or API clients without a dated hard cut
- Keeping dead migrate/parity tooling for deleted dual stores
- Calling unfinished cutover “deferred forever”

---

## Verification Commands

```bash
php artisan migrate:fresh --seed
vendor/bin/pest --parallel --compact
vendor/bin/phpstan analyse --ansi
vendor/bin/pint --dirty --format agent
npm run build
php artisan route:list --except-vendor
```

Runtime smoke:

- Filament admin + ahli boot
- Public discovery without country switch
- Event show: register / bookmark / follow via package contracts
- Membership application approve/reject
- Notification inbox read/mark-read
- MCP admin/member tools against package-backed models

## Exit Criteria (Phase 8 + 9)

- [x] Package install + schema ownership
- [x] Geography country-switch removal
- [x] Membership package convergence
- [x] Engagement package contracts
- [x] Inbox package storage
- [x] Communications package dispatch default (`true`; `DispatchMode` removed) — P9-B residual only for factories/commands
- [ ] No dual-path domains (taxonomy + builders + aliases remain)
- [ ] No legacy builder/alias layers
- [ ] Taxonomy decision **implemented** (ADR-011 decided; residual HasTags dual path = P9-A)
- [ ] Verification green enough to unfreeze feature development
- [ ] `status.md` Phase 9 marked `Verified`

## Stop And Re-plan Triggers

- Package requires ilmu360-specific behavior inside package source without a generic seam
- Fresh migrate/seed cannot complete
- Public/MCP contracts change without tests + docs
- New dual path introduced “temporarily”
