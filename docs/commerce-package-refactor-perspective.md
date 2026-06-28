# Full Refactor Perspective: Building ilmu360° ON Commerce Packages

> Previous analysis assessed "can these packages be adopted into existing ilmu360?"
> This document flips the question: **"What if ilmu360 were rewritten to use these packages natively?"**

> **⚠️ ACTUAL STATUS: Exploration only.** This document explores a hypothetical full rewrite on top of aiarmada packages. **None of the package adoptions described here have been implemented.** All 49 `App\Models\*` remain fully custom. See §11 for current adoption truth.

---

## 1. The Core Idea

Instead of asking "how do we fit these packages into our existing architecture," ask: **"what if the packages are the foundation and we build ilmu360's domain customization on top?"**

The packages are designed for exactly this — they have:
- **Contracts** for every major workflow (36 in events, 15 in engagement)
- **Config-driven feature flags** (events, engagement, addressing all have config)
- **Model class overrides** (engagement `config('engagement.models.follow')`)
- **Null implementations** for optional features
- **Runtime integration detection** via `class_exists()`

This means a full-refactor ilmu360 could:
1. Use package models as the **base entities** (Event, Follow, Bookmark, Address, etc.)
2. Extend via **traits, custom contracts, and EventAttribute**
3. Override **service bindings** for custom business logic
4. Build **domain customization on top** as a thin layer

---

## 2. All 59 Packages Overview

### 2.1 Installed (5)

| Package | Type | Purpose |
|---|---|---|
| `affiliates` | Domain | Share tracking & attribution |
| `commerce-support` | Domain | Foundation helpers (models in source only) |
| `signals` | Domain | Analytics pipeline |
| `filament-authz` | Filament | Scoped permissions UI |
| `filament-signals` | Filament | Admin analytics dashboard |

### 2.2 Potentially Relevant — Source Only (13)

| Package | Type | What It Replaces |
|---|---|---|
| `events` | Domain | Event, Venue, Series, Registration, Check-in, Submissions, Taxonomy (46 models, 35 actions, 70 migrations) |
| `engagement` | Domain | Follow/Bookmark/RSVP pivots + Reactions, Reminders, Subscriptions (11 models, 15 traits, 30 events) |
| `contacting` | Domain | Contact + SocialMedia models + verification (3 models, 10 actions) |
| `addressing` | Domain | Address + geography system (5 models, 6 actions) |
| `communications` | Domain | Full notification engine + in-app inbox (16 models, 30 actions) |
| `membership` | Domain | MembershipClaim + invitations + roles (2 models, 10 actions) |
| `moderation` | Domain | ModerationReview + Ban/Block system (3 models, 2 actions) |
| `references` | Domain | Reference model + hierarchy (2 models, 1 action) |
| `filament-events` | Filament | Admin event management |
| `filament-engagement` | Filament | Admin engagement management |
| `filament-contacting` | Filament | Admin contact management |
| `filament-addressing` | Filament | Admin address management |
| `filament-communications` | Filament | Admin communication logs |
| `filament-membership` | Filament | Admin membership management |

### 2.3 Commerce-Specific — Not Relevant (41)

| Category | Packages |
|---|---|
| Core commerce | `cart`, `checkout`, `orders`, `products`, `pricing`, `promotions`, `vouchers`, `shipping`, `inventory`, `customers` |
| Payments | `cashier`, `cashier-chip`, `chip` |
| Logistics | `jnt` |
| Tax | `tax` |
| Growth | `growth` |
| Other | `docs`, `affiliate-network`, `feedback`, `csuite` (empty) |
| Filament UIs | `filament-cart`, `filament-cart`, `filament-checkout`, `filament-orders`, `filament-products`, `filament-pricing`, `filament-promotions`, `filament-vouchers`, `filament-shipping`, `filament-inventory`, `filament-customers`, `filament-cashier`, `filament-cashier-chip`, `filament-chip`, `filament-jnt`, `filament-tax`, `filament-growth`, `filament-docs`, `filament-affiliate-network`, `filament-feedback` |

---

## 3. Proposed Architecture

```
┌────────────────────────────────────────────────────────────────────┐
│                    ilmu360 Custom Layer                              │
│                                                                      │
│  Presentation Layer:                                                 │
│  ├─ Livewire components (home, search, event detail, dashboard)      │
│  ├─ Filament admin/ahli panels                                      │
│  ├─ Blade views                                                     │
│  └─ MCP servers (admin + member)                                    │
│                                                                      │
│  API Layer:                                                          │
│  ├─ API controllers (wrap package services)                         │
│  ├─ Form requests + validation                                      │
│  └─ Scout/Typesense search (custom EventSearchEngine)                │
│                                                                      │
│  Domain Customization Layer:                                         │
│  ├─ Custom contracts (ilmu360EventLifecycleWorkflow)                │
│  ├─ Custom resolvers (ilmu360SearchPayloadResolver)                 │
│  ├─ EventAttribute-based custom fields (prayer, gender, age)        │
│  ├─ Custom traits (HasIslamicFields, HasPrayerTiming)               │
│  └─ Spatie Tags + MediaLibrary integration                          │
│                                                                      │
│  Custom Domains (no package):                                        │
│  ├─ ContributionEntityMutationService                               │
│  ├─ DonationChannels                                                │
│  ├─ MCP Servers                                                     │
│  └─ Public Livewire Pages                                           │
│                                                                      │
│  Adapter/Bridge Layer:                                               │
│  ├─ Convert package enums ↔ custom enums                            │
│  ├─ Map package events → ProductSignals                             │
│  └─ Authorization bridge (package ownership → MemberPermissionGate) │
├────────────────────────────────────────────────────────────────────┤
│          Package Foundation (installed + could adopt)                │
│                                                                      │
│  events:      Event, Occurrence, Registration, Attendance, etc.     │
│  engagement:  Follow, Bookmark, Response, Reaction, Share, Remind   │
│  contacting:  ContactMethod (phone, email, social, all unified)     │
│  addressing:  Address + Country + Area hierarchy                    │
│  communications: Communication, Batch, Inbox, Template, Delivery    │
│  membership:  Application, Invitation, HasMembers                   │
│  moderation:  Block, ModerationAction                               │
│  references:  Reference, HasReferenceParts                          │
│  cs:          Owner, Reports, SavedSearches, NotifPreferences       │
│  affiliates:  Share tracking (already in use)                       │
│  signals:     Analytics/telemetry (already in use)                  │
├────────────────────────────────────────────────────────────────────┤
│                     Laravel + Filament                                │
└────────────────────────────────────────────────────────────────────┘
```

---

## 4. Package-by-Package Refactor Plan

### 4.1 Events Package — Full Adoption

| Current (ilmus360 Custom) | Refactored (Package-Based) |
|---|---|
| `App\Models\Event` (1,518 lines) | `AIArmada\Events\Models\Event` |
| Spatie ModelStates (6 states) | `EventStatus` enum (12 states) + `EventLifecyclePolicy` |
| Direct FK to Institution, Venue, Speaker | Polymorphic morphs via `EventInvolvement`, `EventLocation` |
| Self-referential `parent_event_id` | `EventOccurrence` model (per-time occurrence) |
| EventKeyPerson with Islamic roles | `EventInvolvement` + `EventRole` (seeded) |
| EventChangeAnnouncement | EventUpdate + EventChangeLog + DispatchEventChangeChainAction |
| EventSubmission + ModerationReview | EventSubmission + EventModerationAction |
| Custom Registration model | EventRegistration → EventRegistrationItem → EventRegistrationParticipant |
| Custom EventCheckin model | EventAttendance + EventAttendanceLog |
| Manual counter management | EngagementCounterService |
| Spatie Tags | EventTaxonomy + EventTerm (or keep as add-on) |
| Spatie MediaLibrary | EventMedia (bridge needed) |
| No pricing/ticketing | EventTicketType + EventPass (optional) |
| No seating | EventSeatMap (optional) |

**Customization points:**

| ilmus360 Feature | How It Stays Custom |
|---|---|
| Prayer-relative timing | `EventTimeExpression` with anchor_type=prayer |
| Gender/age/audience | `EventAttribute` + `EventAudience` |
| Islamic key person roles | Seeded `EventRole` values |
| Malay-optimized search | Custom `EventSearchEngine` wrapping Scout/Typesense |
| Moderation workflow | Custom `EventModerationWorkflow` implementation |
| Spatie MediaLibrary | Custom bridge layer |

### 4.2 Engagement Package — Full Adoption

| Current | Refactored |
|---|---|
| `followings` pivot | `Follow` model (status lifecycle, notification level) |
| `event_saves` pivot | `Bookmark` model (collections, status lifecycle) |
| `event_attendees` pivot | `Response` model (going/interested/maybe/not_going) |
| HasFollowers trait | Followable + Follower traits |
| Custom controllers | Thin wrappers around EngagementManager |

**Gained:** Reactions, Reminders, Subscriptions, 30 domain events, collections, engagement counters.

### 4.3 Contacting Package — Full Adoption

| Current | Refactored |
|---|---|
| Contact + SocialMedia (separate) | ContactMethod (unified, 16 types) |
| SocialMediaLinkResolver | Ported to observer on ContactMethod::saving |
| No verification | 9 verification actions |

**Gained:** Contact verification, unified model, 6 additional contact types.

### 4.4 Addressing Package — Full Adoption (Hardest)

| Current | Refactored |
|---|---|
| Address (int geography FKs) | Address (UUID geography FKs) |
| Nnjeim World (5 tables, int PKs) | Package geography (UUID PKs) |
| HasAddress (morphOne) | HasAddresses (morphMany + primary) |
| Manual lat/lng | 4 geocoding actions |

**The Geography Problem:** Requires replacing all geography FK columns (int→uuid), reseeding all geography data, rewriting all geography queries. Estimated: 4-6 weeks.

### 4.5 Communications Package — Critical Adoption

| Current | Refactored |
|---|---|
| 6 custom notification tables | 16 package models |
| Custom engine + channels | 30 actions + contracts |
| Custom Livewire inbox | `InboxIndex` Livewire component |

**Gained:** Full notification lifecycle, suppression, tracking, templates, batching, eligibility resolution, inbox component.

**Remaining:** FCM push channel, WhatsApp channel, digest scheduling job.

### 4.6 Membership Package — Adoption

| Current | Refactored |
|---|---|
| MembershipClaim | MembershipApplication + MembershipInvitation |
| Custom member management | HasMembers trait + 10 actions |
| Custom roles | MemberRole enum + Spatie role integration |

### 4.7 Moderation Package — Adoption

| Current | Refactored |
|---|---|
| ModerationReview | Block + ModerationAction models |
| Custom ban logic | HasBlocks/HasModerationActions traits |
| No block status lifecycle | BlockStatus enum + expiration |

### 4.8 References Package — Adoption

| Current | Refactored |
|---|---|
| Custom Reference model | Reference + GenerateReferenceSlugAction |
| Custom hierarchy | HasReferenceParts trait + ReferencePartType enum |

---

## 5. What Stays Custom (No Package)

| Domain | Lines | Key Files |
|---|---|---|
| Public Livewire pages | ~2,500 | Event Index, Show, Submit, Dashboard |
| MCP servers | ~2,000 | Admin + member, 57 tools |
| ContributionEntityMutationService | ~1,000 | CRUD/mutation for 5 entity types |
| Membership management UI | ~500 | Cross-cutting member lists, invite modals |
| Donation Channels | ~500 | DonationChannel model, media, API |
| FCM + WhatsApp push channels | ~400 | Provider channel implementations |
| Speaker thin model + UI | ~400 | No dedicated Speaker entity in packages |
| Digest scheduling | ~300 | Notification-center concern |
| Reference entity UI | ~300 | Search/listing/detail pages |
| Integration glue | ~300 | Signals hooks, share outcomes |
| SavedSearch filter normalizer | ~200 | Domain-specific (geography/prayer/tag filters) |
| Ban/block management UI | ~50 | Admin page |
| **Total** | **~8,450** | — |

---

## 6. Feature Comparison: Before vs After

| Capability | Current ilmus360 | After Refactor | Delta |
|---|---|---|---|
| Event CRUD | ✅ Custom | ✅ Package | Same |
| Prayer timing | ✅ Custom | ✅ EventTimeExpression | Same |
| Gender/age/type | ✅ Custom | ✅ EventAttribute | Same |
| Islamic key roles | ✅ Custom | ✅ EventRole seeding | Same |
| Spatie Tags | ✅ Custom | ✅ Via bridge | Same |
| MediaLibrary | ✅ Custom | ✅ Via EventMedia bridge | Same |
| Moderation workflow | ✅ Custom | ✅ Contract-driven | Better |
| Registrations | ✅ Basic | ✅ Full (participants, items, answers) | **Upgrade** |
| Check-in | ✅ Basic | ✅ Full (audit logs, session scoped) | **Upgrade** |
| Multi-occurrence | ❌ | ✅ Native | **New** |
| Ticketing/pricing | ❌ | ✅ Optional | **New** |
| Seating | ❌ | ✅ Optional | **New** |
| Pass issuance | ❌ | ✅ Optional | **New** |
| Change management | ✅ Custom | ✅ Full (ChangeLog + Update + NotificationBatch) | Better |
| Domain events | ❌ | ✅ 28 events + 30 engagement events | **New** |
| RSVP types | ✅ Going only | ✅ Going/interested/maybe/not_going | **Upgrade** |
| Bookmark collections | ❌ | ✅ | **New** |
| Reactions | ❌ | ✅ | **New** |
| Subscriptions | ❌ | ✅ | **New** |
| Reminders | ❌ | ✅ | **New** |
| Contact verification | ❌ | ✅ (9 actions) | **New** |
| Address geocoding | ❌ | ✅ (4 actions) | **New** |
| In-app notification inbox | ❌ | ✅ (Livewire component) | **New** |
| Severity/escalation | ❌ | ✅ | **New** |
| Ban/Block system | ❌ | ✅ | **New** |
| Reference hierarchy | ❌ | ✅ | **New** |
| Template versioning | ❌ | ✅ | **New** |
| Communication suppression | ❌ | ✅ | **New** |
| Tracking tokens | ❌ | ✅ | **New** |
| Membership roles | ✅ Custom | ✅ Spatie-integrated | Better |
| Notifications engine | ✅ 6 tables | ✅ 16 models + 30 actions | **Major upgrade** |
| Search | ✅ Scout/Typesense | ✅ Custom EventSearchEngine | Same |
| Donation channels | ✅ | ✅ Still custom | Same |
| MCP servers | ✅ | ✅ Still custom | Same |

---

## 7. Estimated Effort (Complete)

| Phase | Packages | Effort | Risk | Lines Replaced |
|---|---|---|---|---|
| **1 — Quick wins** | engagement + membership | 3-4 weeks | Low | ~1,700 |
| **2 — Contacts** | contacting | 2 weeks | Medium | ~600 |
| **3 — Models** | commerce-support (publish source models) | 1 week | Low | ~2,500 |
| **4 — Notifications** | communications | 3-4 weeks | Medium | ~3,200 |
| **5 — Events (partial)** | events registrations + check-in | 3-4 weeks | Medium-High | ~2,000 |
| **6 — Events (full)** | events core model + lifecycle + venue + series | 6-8 weeks | High | ~8,000 |
| **7 — Remaining** | moderation + references | 1-2 weeks | Low | ~2,300 |
| **8 — Addressing** | addressing (if geography resolved) | 4-6 weeks | Very High | ~800 |
| **Total** | All relevant | **23-31 weeks** | **High** | **~20,100** |

**Plus:** UI rewrite (Filament resources, Livewire components, API controllers): 4-8 weeks

---

## 8. Risk Assessment

### Breaking Changes

| Change | Impact | Mitigation |
|---|---|---|
| Event model class change | High — all code referencing `App\Models\Event` | Facade/adapter during migration |
| Status system (Spatie ModelStates → Enum) | High — state machine rewrite | Wrap package enum in state-like adapter |
| Relationship model change (FKs → morphs) | Medium — query changes | ORM abstraction |
| Geography ID type (int → UUID) | Very High — every query, filter, API response | Bulk migration + query audit |
| Table name changes | Low — config-driven | Keep same names via config |

### Non-Breaking (Additive)

| Change | Notes |
|---|---|
| New fields (severity, meta, is_active) | Add columns, existing code unaffected |
| New models (EventOccurrence, EventSession) | Coexist during migration |
| New services (geocoding, verification) | Opt-in |
| New events | Listeners are opt-in |

---

## 9. Adoption Timeline (Realistic)

```
Month 1-2:    engagement + membership + contacting
              ├─ Engagement models + traits (3 weeks)
              ├─ Membership backend (1 week)
              ├─ Contacting package (2 weeks)
              ├─ commerce-support model publication (1 week)
              └─ Update API controllers (3 weeks)

Month 2-4:    communications + events registrations
              ├─ Communications package (4 weeks)
              ├─ Events registration + check-in (4 weeks)
              ├─ Notification channel implementations (2 weeks)
              └─ API + UI updates (4 weeks)

Month 4-7:    events core + moderation + references
              ├─ Core Event model migration (8 weeks)
              ├─ Moderation package (1 week)
              ├─ References package (1 week)
              └─ Testing + bug fixes (6 weeks)

Month 7-8:    addressing (if geography resolved)
              ├─ Geography data migration (4 weeks)
              └─ Query rewrite + testing (2 weeks)

After:        Polish, remove old code, performance tuning
```

---

## 10. Source vs Vendor Reconciliation Needed

Before any adoption can proceed, the installed packages need to be synced with source:

| Package | Action |
|---|---|
| `commerce-support` | **Publish** 6 models + 7 traits from source to vendor. Models: AuthzScope, NotificationPreference, Permission, Report, Role, SavedSearch. Traits: HasLanguages, HasNotificationPreferences, HasReports, HasSavedSearches, HasCommerceTranslations, HasWebhookLifecycle |
| `filament-authz` | **Backport** vendor's 20 extra files to source (Models, Policies, Services, Actions) |
| `affiliates` | **Sync** 9 missing actions from source to vendor |
| `signals` | **Sync** 2 missing actions from source to vendor |
| `filament-signals` | **Sync** 20 missing schema/table files from source to vendor |

---

## 11. Current Adoption Truth

### 11.1 Installed (5 packages)

| Package | Purpose |
|---|---|
| `aiarmada/affiliates` | Share tracking & attribution |
| `aiarmada/signals` | Product telemetry/analytics |
| `aiarmada/filament-signals` | Admin analytics dashboard |
| `aiarmada/filament-authz` | Scoped permissions admin UI |
| `aiarmada/commerce-support` | Foundation helpers (no models in installed version) |

### 11.2 Source-Only (54 packages)

All source exists at `~/herd/commerce/packages/` but has not been published to Packagist, added to `composer.json`, or integrated.

### 11.3 Current Architecture

All 49 `App\Models\*` remain fully custom. The application architecture is unchanged from before this analysis was written.

**Key takeaway:** The 13 relevant packages could replace ~79% of custom code (29,600 of ~37,400 lines) but adoption was deferred. The packages remain available for future adoption. The 41 commerce packages are not relevant to ilmus360's domain.
