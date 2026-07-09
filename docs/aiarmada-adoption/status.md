# AIArmada Adoption Status

Last verified: **2026-07-09** against live `refactor` tree  
(Composer direct requires, `app/Models` inheritance, `database/migrations`, package imports, dual-path greps).

> **Source of truth hierarchy**
> 1. Live code + Composer
> 2. This file (`status.md`)
> 3. [`phase-08-cutover.md`](phase-08-cutover.md) — phase checklist
> 4. [`gap-closure-report.html`](gap-closure-report.html) — open-gap dashboard (native purity program)
> 5. [`cutover-plan.html`](cutover-plan.html) — work-unit execution board
> 6. [`../commerce-package-readiness-reassessment.md`](../commerce-package-readiness-reassessment.md) — readiness closure audit

## North Star (product decision)

**Close every remaining gap.** Use AIArmada packages **natively**. Do **not** keep backward-compatibility shims, dual paths, legacy column mappers, or historical naming “just because it used to work.”

- Accept total rewrites of surfaces that still speak old shapes.
- Custom code is allowed only when it is **intentional product design** (ilmu360-specific UX, MCP, donations, Islamic presentation), never as residue of an unfinished cutover.
- Exit criterion for further feature development: **no dual systems, no legacy alias layers, green verification, package contracts as the default path.**

## Phase Dashboard

| Phase | State | Summary |
| --- | --- | --- |
| 0 – 7 (foundation through commerce install) | `Verified` | Packages installed, foundation cutovers landed |
| Phase 02 package readiness hardening | `Verified` | Route/UUID/constraint/SoftDeletes audits clean |
| Phase 8 app rebuild & schema cutover | `Mostly complete` | Schema/package ownership largely done; dual-path purity not done |
| **Phase 9 native purity & gap closure** | **`In Progress`** | Kill BC/hacks; single-path package-native runtime |

Phases 0–8 delivered package installation, geography/contacts/membership/events schema ownership, and thick subclasses on package parents. They did **not** finish native purity. That is Phase 9.

## Current Facts

| Fact | Value |
| --- | --- |
| Local package source | `/Users/Saiffil/Herd/commerce/packages/*` (Composer path repo) |
| AIArmada packages installed (direct) | **25** |
| Laravel | **v13.19.0** |
| App migration files | **31** (from 73) |
| Package-owned domain tables | Via package migrations (events, addressing, communications, membership, …) |
| SoftDeletes / DB cascades in app package path | None intended; packages audited clean |
| `migrate:fresh --seed` | Expected green path for schema |
| Homepage / core schema tests | Load / `RefactorTest` schema assertions present |

### Installed packages (25)

**Backend:** addressing, affiliates, authz, commerce-support, communications, contacting, engagement, events, inventory, membership, moderation, references, seating, signals, ticketing  

**Filament:** filament-addressing, filament-authz, filament-communications, filament-contacting, filament-engagement, filament-events, filament-inventory, filament-seating, filament-signals, filament-ticketing  

**Admin plugins registered:** addressing, contacting, engagement, communications, events, inventory, seating, ticketing, signals, authz  
**Ahli plugins registered:** events, engagement  

### Migration inventory (31)

| Category | Count | Notes |
| --- | ---: | --- |
| Laravel defaults | 3 | users, cache, jobs |
| Standard vendor | 8 | media, audits, tags, activity_log, deleted_models, socialite, settings, personal_access_tokens |
| Passport OAuth | 5 | oauth_* |
| App-unique entities | 9 | institutions, speakers, donation_channels, media_links, inspirations, slug_redirects, contribution_requests, ai_usage_logs, ai_model_pricings |
| Shared with package models | 2 | reports, saved_searches (app migration + package model parent) |
| App pivots | 3 | institution_speaker, languageables, institution_space |
| Membership pivot bootstrap | 1 | uniform_membership_pivots |

There is **no** remaining app migration that owns the five deleted notification Eloquent models. Notification persistence is package-owned (`notification_inboxes`, preference/delivery tables from communications package).

## Model Ownership Register

### Category A — Extends package model (15)

| App model | Package parent | Notes |
| --- | --- | --- |
| `Event` | `Events\Event` | ~2.6k lines — thick product layer |
| `Registration` | `Events\EventRegistration` | ~440 lines; legacy defaults remain |
| `Venue` | `Events\Venue` | + builder column mapping |
| `Series` | `Events\EventSeries` | |
| `Space` | `Events\VenueSpace` | |
| `EventKeyPerson` | `Events\EventInvolvement` | |
| `EventCheckin` | `Events\EventAttendance` | |
| `EventChangeAnnouncement` | `Events\EventUpdate` | **legacy accessors** (`type`/`status`/`public_message`) |
| `EventSubmission` | `Events\EventSubmission` | |
| `Reference` | `References\Reference` | ~740 lines thick |
| `MemberInvitation` | `Membership\MembershipInvitation` | hashed tokens |
| `MembershipApplication` | `Membership\MembershipApplication` | |
| `ModerationReview` | `Moderation\ModerationAction` | **legacy-friendly accessors** |
| `SavedSearch` | `CommerceSupport\SavedSearch` | |
| `Report` | `CommerceSupport\Report` | |

### Category B — App root models + package traits

| Model | Package seams |
| --- | --- |
| `Institution` | `HasMembers`, `HasAddresses`, `HasContactMethods`, `HasSocialProfiles` + alias traits |
| `Speaker` | same as Institution |
| `User` | `CanFollow`, `CanBookmark`, `CanRespond`; morph relations to package inbox/preferences |

### Category C — Deleted / fully replaced (closed)

`Contact`, `SocialMedia`, geography wrappers (`Country`/`State`/`District`/`Subdistrict`), `Following`, `EventSettings`, `MembershipClaim` model, Jetstream `Team`, app notification Eloquent models (`PendingNotification`, `NotificationDelivery`, `NotificationDestination`, `NotificationRule`, `NotificationSetting`), `NotificationEngine`, `NotificationCenterMessage`, app tables `event_attendees` / `user_venue` / `event_reference` / app `spaces` migration.

### Category D — Intentional app-unique (keep by design)

`Institution`, `Speaker`, `DonationChannel`, `MediaLink`, `Inspiration`, `SlugRedirect`, `ContributionRequest`, AI usage/pricing, `User`/auth/OAuth surfaces, public Livewire composition, MCP tools/prompts, Spatie MediaLibrary collection definitions, product Signals naming, FCM/WhatsApp channel adapters (until a generic package owns them).

**Taxonomy decision required:** Spatie `Tag` is either intentional app taxonomy **or** must fully cut over to package `EventTaxonomy`/`EventTerm`. Dual write is **not** allowed at exit.

## Closures Completed (schema ownership)

| App model | Package target | Status |
| --- | --- | --- |
| EventKeyPerson | EventInvolvement | Closed |
| EventCheckin | EventAttendance | Closed |
| EventChangeAnnouncement | EventUpdate | Closed (accessors still legacy-shaped) |
| EventSubmission | package EventSubmission | Closed |
| ModerationReview | ModerationAction | Closed (accessors still legacy-shaped) |
| SavedSearch | CommerceSupport\SavedSearch | Closed |
| Report | CommerceSupport\Report | Closed |
| Space | VenueSpace | Closed |
| MembershipClaim | MembershipApplication | Closed (cosmetic `startMembershipClaim` name only) |
| Membership invitations/actions | package actions + `MemberRole::Owner` | Closed |
| Engagement save/follow/register | `EngagementManager` / `RegistrationServiceInterface` | Closed |
| Communications inbox storage | `NotificationInbox` + `CommunicationPreference` | Closed |
| Membership hooks/notifier | `AppMembershipHook`, `AppMembershipApplicationNotifier` bound | Closed |

## What Is Still Open (must close before “ready to develop”)

See full detail in [`gap-closure-report.html`](gap-closure-report.html). Summary:

| # | Gap | Class | Why it blocks native purity |
| ---: | --- | --- | --- |
| G1 | Communications dual dispatch (`DispatchMode`, `dispatch_through_package` default **false**) | Dual path | Package not the default send path |
| G2 | Spatie Tags remaining on admin Filament Tag resource only | Dual path (closing) | Event writes use package classifications (submit, admin, contributions); catalogs serve EventTerm |
| G3 | `EventBuilder` / `VenueBuilder` / `ReferenceBuilder` legacy column maps | Hack / shim | Call sites still speak old column names |
| G4 | `HasPackageContactAliases` / `HasPackageSocialAliases` / `HasPrimaryAddressAccessors` | Compat layer | Surfaces not package-native |
| G5 | Legacy accessors on EventChangeAnnouncement / ModerationReview / Registration defaults | Compat layer | Hides package field model |
| G6 | Geography hard cut | **Closed on API** (ADR-012) | Product: state cascade-only; `admin_area_1`=district, `admin_area_2`=subdistrict; area_3 empty |
| G7 | Thick `Event` / `Reference` / `Registration` subclasses | Structural debt | Hard to reason; mix product + cutover glue |
| G8 | Institution dashboard “legacy” table filter/sort state | Local hack | Non-package UI debt |
| G9 | Migration/parity commands for old notification dual store | Dead tooling | Encourages dual-system thinking |
| G10 | Verification debt (non-SQL assertion failures in some suites) | Quality | Blocks confident further development |
| G11 | Paid commerce productization | Active product (ADR-013) | Modes catalog + config flags landed; public checkout still flagged off until payment bound |
| G12 | Package `Block` unused | Optional product | Only if product needs bans |

**Non-goals for Phase 9:** inventing more BC “deprecation windows.” Prefer hard cuts with test/doc updates in the same PR.

## Removed App Infrastructure (already done)

Jetstream teams; `event_attendees` ownership; `event_settings`; `user_venue`; `event_reference`; app `spaces` migration; 4 `_add_` migrations moved into packages; membership role catalog (~700 lines); 15 custom membership actions; `MembershipClaim` model; app notification models + engine.

## Verification Snapshot (last recorded)

| Check | Result (as of last status capture; re-run before claiming exit) |
| --- | --- |
| `migrate:fresh --seed` | 0 errors (last run) |
| Homepage | Loads |
| RefactorTest schema | Present / was 5/5 |
| Broader suite | Partial greens; some pre-existing assertion mismatches, 0 SQL errors in sampled slices |

Phase 9 exit requires a **fresh** full suite pass + PHPStan level 6 on touched paths + Pint, not historical partial greens.

## Active Next Actions

1. **Decisions recorded (2026-07-09):** ADR-011 package taxonomy, ADR-012 geography hard cut, ADR-013 paid commerce productize.
2. **In flight:** finish taxonomy dual-path removal (admin/API/contribution still may use tag catalogs); complete paid checkout binding when payment provider ready.
3. Flip communications to package dispatch; delete dual-mode helpers once green.
4. Rewrite callers off builders’ legacy maps; delete maps.
5. Thin Event/Reference only after product seams are explicit (media, Scout presentation, Islamic helpers).
6. Continue Phase 9 units in [`gap-closure-report.html`](gap-closure-report.html) / [`cutover-plan.html`](cutover-plan.html).

## Blocker Register

| ID | State | Detail |
| --- | --- | --- |
| B001–B010 | `Resolved` / `Verified` | Prior phase blockers closed |
| B011 | `Open` | Dual-path purity incomplete (comms, taxonomy, builders, aliases) |
| B012 | `Open` | Feature development freeze until Phase 9 exit criteria met |

No external package-install blockers remain.
