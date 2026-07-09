# Reassessment Closure: ilmu360° on AIArmada Packages

Last verified: 2026-07-09 against the live `refactor` tree (Composer requires, `app/Models` inheritance, `database/migrations`, and runtime package imports).

The original version of this document asked whether ilmu360° could be rebuilt on top of AIArmada packages. That is no longer a hypothetical planning exercise.

The current codebase already consumes local AIArmada packages through the Composer path repository at `/Users/Saiffil/Herd/commerce/packages/*`. Package installation and source availability are closed. The remaining work is cutover cleanup: thinning or deleting thick app subclasses where package contracts can own the surface, finishing dual-path domains (taxonomy, communications dispatch), and closing verification debt.

Canonical trackers (reconciled 2026-07-09):

- [`docs/aiarmada-adoption/status.md`](aiarmada-adoption/status.md) — live status + Phase 9 north star  
- [`docs/aiarmada-adoption/gap-closure-report.html`](aiarmada-adoption/gap-closure-report.html) — open-gap dashboard (native purity)  
- [`docs/aiarmada-adoption/cutover-plan.html`](aiarmada-adoption/cutover-plan.html) — Phase 9 work units  
- [`docs/aiarmada-adoption/phase-08-cutover.md`](aiarmada-adoption/phase-08-cutover.md) — Phase 8 checklist + Phase 9 handoff  
- [`docs/aiarmada-adoption/domain-mapping.md`](aiarmada-adoption/domain-mapping.md) — ownership targets  

**Policy:** remaining dual paths and BC shims are not “deferred forever.” They block further feature development until closed.

## 1. Current Adoption Truth

### 1.1 Installed directly in this app

`composer show 'aiarmada/*' --direct` reports **25 installed AIArmada packages**:

| Backend | Filament adapter |
| --- | --- |
| `addressing` | `filament-addressing` |
| `affiliates` | — |
| `authz` | `filament-authz` |
| `commerce-support` | — |
| `communications` | `filament-communications` |
| `contacting` | `filament-contacting` |
| `engagement` | `filament-engagement` |
| `events` | `filament-events` |
| `inventory` | `filament-inventory` |
| `membership` | — |
| `moderation` | — |
| `references` | — |
| `seating` | `filament-seating` |
| `signals` | `filament-signals` |
| `ticketing` | `filament-ticketing` |

Transitive commerce packages also resolve via path repos when pulled by the packages above (for example `cart`, `checkout`, `orders`, `products`). They are not root requires and are not treated as adopted product surfaces yet.

This supersedes the old “5 installed, 54 source-only” conclusion.

### 1.2 Durable evidence in the repo

- `composer.json` requires the full set of 25 packages above.
- App migrations are down to **31 files** (Laravel defaults, vendor tables, app-unique entities, shared report/saved-search tables, pivots, membership pivot bootstrap). Package tables come from package migrations.
- Package configs are published locally, including `addressing`, `contacting`, `communications`, `membership`, `moderation`, `references`, `events`, `engagement`, `signals`, `inventory`, `seating`, `ticketing`, and Filament adapter configs.
- Filament plugins registered:
  - **Admin panel:** addressing, contacting, engagement, communications, events, inventory, seating, ticketing, signals, authz.
  - **Ahli panel:** events, engagement.
- `migrate:fresh --seed` is the expected green path for schema proof (see adoption status).

### 1.3 Model ownership (live)

#### Category A — App model extends package model (15)

| App model | Package parent |
| --- | --- |
| `Event` | `AIArmada\Events\Models\Event` |
| `Registration` | `AIArmada\Events\Models\EventRegistration` |
| `Venue` | `AIArmada\Events\Models\Venue` |
| `Series` | package `EventSeries` |
| `Space` | `AIArmada\Events\Models\VenueSpace` |
| `EventKeyPerson` | `AIArmada\Events\Models\EventInvolvement` |
| `EventCheckin` | `AIArmada\Events\Models\EventAttendance` |
| `EventChangeAnnouncement` | `AIArmada\Events\Models\EventUpdate` |
| `EventSubmission` | package `EventSubmission` |
| `Reference` | `AIArmada\References\Models\Reference` |
| `MemberInvitation` | package `MembershipInvitation` |
| `MembershipApplication` | package `MembershipApplication` |
| `ModerationReview` | `AIArmada\Moderation\Models\ModerationAction` |
| `SavedSearch` | `AIArmada\CommerceSupport\Models\SavedSearch` |
| `Report` | `AIArmada\CommerceSupport\Models\Report` |

These are **active subclasses**, not empty shims. The largest still carry substantial app logic (approximate sizes today: `Event` ~2.6k lines, `Reference` ~740, `Registration` ~440, `Venue` ~210). Deleting them is not a rename; it requires moving product behavior onto package seams or accepting permanent thin app subclasses.

#### Category B — App-owned root models using package traits

| Model | Package traits / seams in use |
| --- | --- |
| `Institution` | `HasMembers`, `HasAddresses`, `HasContactMethods`, `HasSocialProfiles`, package contact/social aliases |
| `Speaker` | `HasMembers`, `HasAddresses`, `HasContactMethods`, `HasSocialProfiles`, package contact/social aliases |
| `User` | engagement actor traits (`CanFollow`, `CanBookmark`, `CanRespond`) plus package inbox/preference relations |

#### Category C — Deleted or fully replaced (examples)

| Former app surface | Resolution |
| --- | --- |
| `Contact`, `SocialMedia` | package contacting |
| Geography wrappers (`Country`, `State`, `District`, `Subdistrict`) | package addressing |
| `Following` | engagement `Follow` |
| `EventSettings` | package access policy + event columns |
| `MembershipClaim` model | `MembershipApplication` (package table) |
| Jetstream `Team` | deleted; polymorphic membership only |
| App `event_attendees` / `user_venue` / `event_reference` / `spaces` migrations | package tables |
| App notification models (`PendingNotification`, `NotificationDelivery`, `NotificationDestination`, `NotificationRule`, `NotificationSetting`) | package communications tables/models |
| `NotificationEngine`, `NotificationCenterMessage` | removed; inbox + package dispatch path + app orchestration services |

#### Category D — Intentionally app-unique

Includes `Institution`, `Speaker`, `DonationChannel`, `MediaLink`, `Inspiration`, `SlugRedirect`, `ContributionRequest`, AI usage/pricing models, Spatie `Tag`, OAuth/social account surfaces, public Livewire, MCP, and product-specific notification orchestration (FCM, WhatsApp, event notification policy services).

## 2. Assessment-By-Assessment Closure

| Original assessment area | Old conclusion | Current codebase reality | Status |
| --- | --- | --- | --- |
| Package inventory | 5 installed, most relevant packages source-only | 25 AIArmada packages installed from the local monorepo path repository | `Closed` |
| Addressing policy | “Do not adopt addressing” | `aiarmada/addressing` installed, seeded, live; country switching removed; legacy geography models deleted | `Closed` |
| Contact and social models | Package coverage only in source | `contacting` + `filament-contacting` installed; legacy `Contact` / `SocialMedia` gone | `Closed` |
| Events package feasibility | Theoretical replacement only | `events`, `seating`, `ticketing`, Filament events/inventory/seating/ticketing installed; app event models extend package models; Filament event UI is plugin-owned | `Closed (wrappers remain)` |
| Engagement feasibility | Theoretical replacement only | Public registration/bookmark/follow use package contracts (`RegistrationServiceInterface`, `EngagementManager`). Local `RegisterForEventAction`, `SaveEventAction`, `UnsaveEventAction`, `HasFollowers` deleted | `Closed` |
| References feasibility | Source-only package | `references` installed; `App\Models\Reference` is a thick package subclass with Scout, media, membership, and public UX still app-owned (no filament-references package) | `Closed (wrapper remains)` |
| Membership feasibility | Source-only package | `membership` installed and converged: `MembershipApplication` + `MemberInvitation` extend package models; package approve/reject/add/remove actions used; `HasMembers` + `MemberPermissionGate`; Jetstream teams deleted; `MembershipClaim` model deleted | `Closed (naming nits only)` |
| Communications feasibility | Source-only package | Package + Filament adapter installed. Inbox cut over to `NotificationInbox`. Preferences map to `CommunicationPreference`. App notification models/engine deleted. Resolvers bound: preference, quiet hours, consent, suppression. Digest command can use `CommunicationBatch`. Remaining: `dispatch_through_package` still defaults false; custom FCM/WhatsApp channels and event notification orchestration stay app-owned; no app `DestinationResolver` override (package default used) | `In Progress` |
| Moderation / bans / blocks | Source-only package | `moderation` installed; `ModerationReview` extends package `ModerationAction`; `Report` extends commerce-support. Package `Block` unused. App still owns report categories and contribution mutation application | `In Progress` |
| Filament adapter availability | Most relevant UIs source-only | Relevant adapters installed and registered on admin (and events/engagement on ahli). Surface migration is the remaining issue where app resources still exist | `Closed` |
| Public Livewire, MCP, donation channels | No package coverage | Still intentionally app-owned under product boundary rules | `Closed (Intentional)` |
| Source vs vendor reconciliation | Vendor/source drift blocked adoption | Composer path repositories make local package source the installed runtime dependency | `Closed` |
| Taxonomy / tags | Spatie-only | Dual path: Spatie `HasTags` still used for attach/sync and some search facets; package `EventTaxonomy` / `EventTerm` used in public filters and `SyncEventTaxonomiesAction` / migrate command; `Event` searchable array emits both tag and classification fields | `In Progress` |

## 3. Domain Reality Against The Current Codebase

| Domain | Current reality in the repo | Remaining live gap |
| --- | --- | --- |
| Geography and global discovery | Package `AddressCountry` / `AddressArea` seeded. Country switching removed. Legacy geography models/admin surfaces deleted. Scout schemas use package address fields (`country_code`, `city`, `state`, `postcode`). | Some search/filter paths still accept or emit legacy-shaped keys (`country_id`, `state_id`) as aliases during cutover. Collapse remaining alias shapes as surfaces are rebuilt. |
| Contacts and social profiles | Package-backed contact/social aliases live. Old app contact/social classes removed. | Residual compatibility accessors only where a surface still speaks the old shape. |
| Events, seating, ticketing | Package tables and models own persistence. App subclasses add media, Scout, public helpers, and product workflows. Free registration and pass flags are package-configured. Seating/inventory/ticketing packages installed for paid/capacity modes. | Keep thinning `App\Models\Event` / `Registration` / related subclasses. Wire paid commerce modes when product is ready. Public/API/MCP correctly use app subclasses today. |
| Institutions, speakers, venues, series | Venues/series/spaces are package subclasses. Institutions/speakers remain app root models with package membership/contact/address traits. | Institutions/speakers stay app-owned until a generic organization/person package is adopted (not required for exit). |
| References | Package-backed model + app subclass with heavy product logic and app Filament resources. | Thin the subclass over time; no filament-references package exists. |
| Membership | Package-owned applications, invitations, pivots, roles (`Owner` included), and actions. Filament resource lives under `MembershipApplications`. | Cosmetic leftovers only (e.g. a `startMembershipClaim` Livewire/method name). Package notifier contract may still be unbound if outbound claim mail is required through the package seam. |
| Communications | Inbox + preference storage on package models. App services orchestrate event notifications and channel selection. Custom channels: `InboxChannel`, `PushChannel`, `WhatsappChannel`. | Finish package-first dispatch (`dispatch_through_package`), adopt or delete dual-mode helpers, keep FCM/WhatsApp/digest policy as intentional app remainder. |
| Moderation and contributions | Package moderation action + commerce-support report models. Contribution requests remain app-unique. | Wire report resolution into approval workflows if required; optional package `Block` adoption if product needs bans. |
| Tags and taxonomy | Dual write/read paths coexist (Spatie tags + package classifications). | Decide: keep Spatie as intentional app taxonomy, or complete cutover to `EventTaxonomy` / `EventTerm` and stop dual indexing. |
| Public Livewire UX, MCP, donation channels | App-owned by design. | No package gap. |

## 4. What The Original Document Got Wrong Now

These conclusions are no longer true and must not drive planning:

- “Actual status: planning analysis only.”
- “None of the packages identified here (beyond the original 5) have been adopted.”
- “Do not adopt addressing.”
- “Source-only package availability is the main blocker.”
- “Filament package UIs are unavailable in the app.”
- “Membership is only partially adopted / blocked on role semantics.” (Roles and package actions are live; residual debt is naming and optional notifier wiring.)
- “App still has five Notification\* Eloquent models and NotificationEngine as the primary store.” (Those models and the engine are deleted; package tables/models own persistence.)

The blocker class has changed. Package installation and source availability are not the issue. Remaining work is dual-path elimination, thick-wrapper thinning, intentional app-boundary polish, and verification.

## 5. Remaining Live Gaps To Cutover Exit

Actionable remainder after closing the stale assumptions above:

1. **Thick package subclasses** — Keep product behavior that is ilmu360-specific on app subclasses, but stop treating `Event` / `Reference` / `Registration` deletion as blocked “legacy ownership.” They are package-first persistence with app presentation/workflow layers.
2. **Communications dispatch** — Prefer package dispatch path (`COMMS_DISPATCH_THROUGH_PACKAGE` / `DispatchMode`) end-to-end; leave only app-specific FCM, WhatsApp, and digest orchestration outside the package.
3. **Taxonomy decision** — Either complete Spatie → package taxonomy cutover, or document Spatie tags as the intentional app boundary and stop dual-path drift.
4. **Search/filter alias cleanup** — Remove remaining legacy geography filter keys once public/API clients no longer send them.
5. **Moderation product gaps** — Optional: package `Block`, report↔approval linkage.
6. **Verification debt** — Keep package-first regressions green (`RefactorTest` schema assertions, event search/show slices, membership application flows). Treat pre-existing non-SQL test assertion failures as separate from cutover schema health.
7. **Commerce modes** — Inventory/seating/ticketing packages are installed for future paid/capacity workflows; free-first product behavior is intentional until those surfaces are productized.

## 6. Closure Summary

The readiness question is answered: **AIArmada package adoption is real and already the runtime foundation of this repo.**

### Closed

- Package availability and installation uncertainty (25 direct requires)
- Addressing adoption and country-switch removal
- Contact/social package cutover
- Engagement package cutover
- Membership package convergence (models, pivots, actions, teams removal)
- Core events/references/moderation model ownership (via package parents)
- Filament adapter availability on admin (and events/engagement on ahli)
- Source-vs-vendor publishing drift as the main blocker
- App migration surface reduced to 31 files with package-owned domain tables

### Remains

- Dual-path taxonomy
- Communications dispatch completion (storage already package-backed)
- Thick app subclasses and surface rebuilds that still speak through them
- Optional moderation/commerce productization
- Ongoing verification and static-analysis hygiene

This document is a **closure audit** for the old readiness reassessment and a **current-truth snapshot** of adoption status, not a speculative feasibility study.
