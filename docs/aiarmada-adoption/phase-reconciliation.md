# Phase Reconciliation Matrix

Last verified: **2026-07-10** against live `refactor` tree.

**Purpose:** Early phase files (`phase-04` … `phase-07`) still contain open checkboxes from the *assessment* era. Most of that work was executed during Phase 8. This matrix is the **no-miss inventory**: every meaningful open item from those files is either marked **Done**, rolled into **Phase 9**, or **Deferred / intentional**.

**Source of truth order:** live code → [`status.md`](status.md) → this file → individual phase markdown.

---

## Folder map (every adoption file)

| File | Role | Treat open items as |
| --- | --- | --- |
| `README.md` | Hub index | — |
| `status.md` | Live dashboard | **Authoritative backlog** |
| **`phase-reconciliation.md`** (this file) | No-miss carry-forward | **Authoritative for “is this done?”** |
| `phase-00` … `phase-03` | History (Verified) | Closed except Deferred `growth` |
| `phase-04` … `phase-07*` | Assessment-era plans | **Superseded** — banners added; open boxes are historical |
| `phase-08-cutover.md` | Phase 8 checklist + Phase 9 IDs | Active checklists for **Phase 9 only** |
| `architecture-decisions.md` | ADRs | Decisions, not task list |
| `domain-mapping.md` | Ownership targets | Reference |
| `package-inventory.md` | Catalog (baseline 2026-06-28) | **Stale install list** — use Composer + status (WP-01) |
| `paid-commerce-productization.md` | ADR-013 path | **G11** open work |
| `agent-work-queue.md` | Packet history | **Stale WP states** unless cross-checked here |
| `review-log.md` | Evidence archive | Append-only history |
| `gap-closure-report.html` | Visual gap dashboard | May lag markdown — prefer status |
| `cutover-plan.html` | Phase 9 visual plan | May lag markdown — prefer phase-08 |
| `aiarmada-360-audit.html` | Snapshot audit | Historical unless re-run |

---

## Program dashboard

| Phase | Doc file | Doc state (was) | Live state | Notes |
| --- | --- | --- | --- | --- |
| 0 Readiness | `phase-00-readiness.md` | Verified | **Verified** | Hub exists |
| 1 Composer path | `phase-01-source-dependencies.md` | Verified | **Verified** | Path repos + constraints |
| 2 Package readiness | `phase-02-package-readiness.md` | Complete | **Verified** | UUID / no FK / SoftDeletes audits |
| 3 Foundation | `phase-03-foundation.md` | Verified | **Verified** | `growth` not installed (optional skip) |
| 4 Identity / geo / contacts / membership | `phase-04-*.md` | Assessed (many open boxes) | **Superseded by Phase 8** | See matrix §4 |
| 5 Core domain | `phase-05-core-domain.md` | Assessed | **Superseded by Phase 8** | See matrix §5 |
| 6 Communications | `phase-06-communications.md` | Assessed | **Superseded by Phase 8** (+ residual Phase 9) | See matrix §6 |
| 7 Commerce | `phase-07-commerce.md`, `phase-07-commerce-capabilities.md` | Assessed / Assessing | **Superseded** — product path is ADR-013 / Phase 9 G11 | See matrix §7 |
| 8 App rebuild | `phase-08-cutover.md` | Mostly complete | **Mostly complete** | Schema ownership done |
| **9 Native purity** | `status.md` + gap/cutover HTML | In progress | **In progress** | **Only active implementation backlog** |

---

## §0–3 Residual

| Item | From | Status | Carry-forward |
| --- | --- | --- | --- |
| Adopt `growth` if telemetry needs it | Phase 3 open box | **Deferred** | Install only if product enables growth workflows |
| WP-01 package-inventory reconcile | agent-work-queue | **Open (docs)** | Refresh `package-inventory.md` to list 25 installed packages |

---

## §4 Identity / geography / contacts / membership

From `phase-04-identity-geography-contacts-membership.md` open boxes (assessment-era) — **every checkbox accounted**:

| Item | Live status | Phase 9? |
| --- | --- | --- |
| Publish `config/addressing.php` | **Done** | — |
| `HasAddresses` on Event/Institution/Speaker/Venue | **Done** | — |
| Seed countries + Malaysia areas | **Done** (`AddressingSeeder` + Malaysia areas CSV + product State seed) | — |
| Package State/City tables seeded | **Done** | — |
| Product geo FKs `state_id`/`city_id`/`admin_area_1`=district/`admin_area_2`=subdistrict | **Done** (zero legacy aliases) | P9-F **closed** |
| Replace Contact/Social with contacting | **Done** (models deleted) | — |
| filament-contacting + membership installed | **Done** | — |
| Publish contacting/membership configs | **Done** | — |
| Remove country switch / preferred country | **Done** | — |
| Search filters package-native geo | **Done** | — |
| Delete integer Country/State/City/District/Subdistrict models | **Done** | — |
| Filament geography resources → filament-addressing plugin | **Done** | — |
| Membership applications/invitations/actions + hooks | **Done** | — |
| Jetstream teams / MembershipClaim model removed | **Done** | — |
| ContactMethods / SocialProfiles traits on entities | **Done** | — |
| SocialMediaLinkResolver removed | **Done** | — |
| GeographyObserver / HasGeographyDeletionGuard removed | **Done** (not present in app) | — |
| FederalTerritoryLocation still present | **Keep intentional** | Product FT cascade policy |
| AddressesRelationManager on every resource | **Partial / intentional** | Product often uses form cascades not RMs |
| FormatAddressAction replace AddressHierarchyFormatter | **Not done** | Optional polish; not dual-path |
| SharedFormSchema → package AddressFormSchema only | **Partial intentional** | App product cascade (state→city→district) is correct law |
| ResolveGooglePlaceSelectionAction adapted | **Done enough** | Still product-owned; maps into package addresses |
| Contact/social alias traits (`HasPackage*Aliases`) | **Open** | **P9-D** |
| `HasPrimaryAddressAccessors` | **Open** | **P9-D** |

---

## §5 Core domain (events / engagement / references / moderation)

From `phase-05-core-domain.md` WP-12…15 (narrative assessment; no checkbox list):

| Item | Live status | Phase 9? |
| --- | --- | --- |
| Event models extend package Event | **Done** | P9-G thin later |
| Venue/Space/Series/Registration/Checkin/KeyPerson/Submission/Announcement | **Done** (extend package) | — |
| Engagement via EngagementManager / RegistrationServiceInterface | **Done** | — |
| Local Register/Save/HasFollowers deleted | **Done** | — |
| Reference extends package | **Done** (thick product) | P9-G |
| ModerationReview extends ModerationAction | **Done** | P9-E accessors |
| Report extends commerce-support | **Done** | — |
| Tags → EventTaxonomy/Term/Classification | **Partial** — writes largely classifications; `HasTags` + Tag Filament + AI extraction remain | **P9-A** |
| Media on app Event subclass | **Done intentional** | — |
| Free registration + pass flags | **Done** (config) | G11 matrix tests |
| Paid/mixed/seated schemas ready | **Partial** — packages installed; public paid checkout off | **G11 / ADR-013** |
| Package Block for bans | **Not adopted** | **G12 optional** |
| EventBuilder/VenueBuilder/ReferenceBuilder legacy maps | **Open** | **P9-C** |
| Filament events plugins admin+ahli | **Done** | — |
| filament-references / filament-moderation packages | **N/A** | App Filament kept intentional |

---

## §6 Communications

From `phase-06-communications.md` open boxes + Phase 8.G — **every checkbox accounted**:

| Item | Live status | Phase 9? |
| --- | --- | --- |
| Publish communications configs | **Done** | — |
| Package migrations | **Done** | — |
| Install communications + filament-communications | **Done** | — |
| DestinationResolver (email/FCM/WhatsApp) | **Package default + app channels intentional** | keep FCM/WA |
| Preference / QuietHours / Consent / Suppression resolvers | **Done** (bound) | — |
| Delivery models → CommunicationDelivery | **Done** (app delivery models deleted) | — |
| HasInbox trait on User | **Partial** — morph to NotificationInbox present; trait consistency review | **P9-B residual** |
| NotificationMessage inbox → NotificationInbox | **Done** | — |
| DispatchDueCommunications schedule | **Done** (or package schedule path) | confirm if needed |
| FilamentCommunicationsPlugin admin | **Done** | — |
| App Pending/Message/Delivery models deleted | **Done** | — |
| Keep FCM/WhatsApp/digest/Signals | **Done intentional** | — |
| Preferences → CommunicationPreference | **Done** | — |
| NotificationEngine deleted | **Done** | — |
| `auto_capture` true | **Done** | — |
| `dispatch_through_package` default **true** | **Done** | P9-B mostly closed |
| `DispatchMode` dual helper | **Deleted** | — |
| EventNotificationService / SettingsManager orchestration | **App-owned** | Document intentional |
| `communications:migrate-rules` / `migrate-settings` | **Still present** | **P9-B residual** |
| Orphan `PendingNotificationFactory` / `NotificationDeliveryFactory` | **Still present** | **P9-B residual** |

---

## §7 Commerce

From `phase-07-*.md` + `paid-commerce-productization.md` — **every open box accounted**:

| Item | Live status | Phase 9? |
| --- | --- | --- |
| List/map commerce workflows (capabilities checklist) | **Done** via ADR-013 + paid-commerce doc | — |
| Paid tickets = approved workflow | **Done** (decision) | **G11** implement |
| Donation checkout vs QR | **Decided** — QR/channel stays app-owned for now | intentional |
| Install inventory/seating/ticketing + Filament | **Done** | — |
| Cart/checkout/orders/products as root requires | **Not yet** (install when binding payment) | **G11** |
| CHIP/cashier configure + public paid checkout | **Off** (`EVENTS_PUBLIC_PAID_CHECKOUT_ENABLED=false`) | **G11** |
| Ticket types / seating admin | **Done** (plugins) | Verify CRUD in G11 |
| Free ticketed + pass path | **Config on** — prove with tests | **G11** mode matrix |
| FulfillEventOrderAction / refunds admin UI | **Not shipped** | **G11** |
| Donation QR channels | **App-owned intentional** | Not package checkout |
| Affiliates installed | **Done** (share tracking) | Monetization non-goal for first paid ship |
| Avoid install-for-install | **Done** (growth/shipping/tax deferred) | — |

---

## § agent-work-queue packet truth

Stale “Not Started / Assessed” rows in `agent-work-queue.md` must not be treated as live backlog:

| Packet | Stale label | Live truth |
| --- | --- | --- |
| WP-01 | Deferred | Still open **docs** — refresh `package-inventory.md` install list |
| WP-09 / WP-09E | In Progress / Not Started | **Verified** — integer geo models gone; product FKs hard-cut |
| WP-10 … WP-16 | Assessed | **Executed in Phase 8** (residuals only in Phase 9 IDs) |
| WP-17 | Assessed | Superseded by **ADR-013 / G11** |
| WP-18 Filament rebuild | Not Started | **Mostly done** via filament-* plugins + app resources; residual = taxonomy Tag resource (P9-A) |
| WP-19 Livewire/API/MCP | Not Started | **Mostly done** surfaces on package models; residual dual paths only |
| WP-20 Final deletion + verification | Not Started | **In Progress as Phase 9** (P9-B…P9-I) |

---

## §8 Phase 8 residual → Phase 9 map

| Phase 8 open checkbox | Phase 9 ID | Status 2026-07-10 |
| --- | --- | --- |
| Delete builder legacy maps | P9-C | **Open** |
| Taxonomy single path | P9-A | **Open** (decision ADR-011 package taxonomy) |
| Thin Event/Registration | P9-G | **Open** |
| Legacy accessors ModerationReview / EventChangeAnnouncement | P9-E | **Open** |
| Optional Block | G12 | **Deferred optional** |
| Optional report↔approval linkage | product | **Deferred** until product asks |
| dispatch_through_package false / DispatchMode | P9-B | **Mostly closed** (default true, DispatchMode gone) |
| App orchestration / channels | intentional | **Keep + document** |
| Delete migrate/parity notif commands | P9-B residual | **Open** |
| HasInbox consistency | P9-B residual | **Open** |
| Compat traits / dual taxonomy / dual dispatch | P9-A/B/D | dual dispatch mostly closed; taxonomy + traits open |
| Full suite green | P9-I | **Open** |
| PHPStan clean cutover surface | P9-I | **Open** |
| Geography hard-native | P9-F | **Closed** |

---

## §9 Active Phase 9 backlog (implementation only)

Ordered for dependency:

1. **P9-A Taxonomy** — remove dual Spatie write/index for events; Tag resource only if non-event product use  
2. **P9-C Builders** — rewrite callers; delete Event/Venue/ReferenceBuilder maps  
3. **P9-D Alias traits** — rewrite to package contact/social/address API; delete traits  
4. **P9-E Accessors** — EventChangeAnnouncement, ModerationReview, Registration  
5. **P9-B residual** — orphan factories, migrate commands, HasInbox consistency  
6. **G11 Paid commerce** — payment bind + public flag + mode matrix tests  
7. **P9-G Thin subclasses** — Event/Reference cutover glue only  
8. **P9-H UI debt** — institution dashboard legacy filter/sort  
9. **P9-I Verification** — migrate:fresh --seed, full Pest, PHPStan, Pint  
10. **G12 Block** — only if product needs bans  

### Explicitly closed (do not re-open as Phase 4–7 work)

- Package install (25 direct)  
- Integer geography models  
- Country switcher  
- Contact/Social models  
- Membership claim model → MembershipApplication  
- Notification Eloquent models + engine  
- Geography product FK hard-cut (zero `state_area_id` / `district_id` / `subdistrict_id` aliases)  
- Comms dispatch default through package + DispatchMode removal  

### Intentional app-owned (not “undone”)

Institution, Speaker, DonationChannel, Contribution UX, MCP, public Livewire, MediaLibrary collections, AI usage, FCM/WhatsApp, Malay/Islamic presentation, FederalTerritoryLocation product policy, Geography deletion guards (app integrity without DB cascades).

---

## Doc maintenance rules

When completing a Phase 9 item:

1. Update **this file** row → Closed  
2. Update **status.md** gap table  
3. Tick **phase-08** Phase 9 workstream  
4. Do **not** re-open phase-04–07 assessment checklists — mark them Superseded only  

When discovering a new dual path:

1. Add a row here under §9  
2. Add gap id in status.md  
3. Optionally gap-closure HTML  
