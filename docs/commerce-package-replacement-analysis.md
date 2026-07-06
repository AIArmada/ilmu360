# Commerce Package Replacement Analysis

> [!CAUTION]
> **STALE (last updated pre-2026-07-06).** The model adoption status below is historical and does not reflect the 5 closures completed on 2026-07-06. See [`docs/aiarmada-adoption/status.md`](aiarmada-adoption/status.md) for current truth.

> Mapping ilmu360° architecture against `~/herd/commerce/packages` (59 AIArmada packages)
> to identify replacement candidates, integration points, and gaps.

> **⚠️ ACTUAL STATUS: Planning analysis only.** None of the package adoptions described in this document (beyond the 5 already in use) have been implemented. The source code for all candidate packages exists at `~/herd/commerce/packages/` but has not been published to Packagist, added to `composer.json`, or integrated into the ilmu360 application. All `App\Models\*` (49 models) remain custom. See [§8 Actual Adoption Status](#8-actual-adoption-status) for the current truth.

> **⚠️ SOURCE VS VENDOR DISCREPANCY**: For the 5 installed packages (`affiliates`, `commerce-support`, `signals`, `filament-authz`, `filament-signals`), the installed vendor versions differ from the source at `~/herd/commerce/packages/`. See §8.3 for details.

> **⚠️ CORRECTION: `aiarmada/commerce-support`** (installed via Packagist) contains **no Eloquent models** — only foundation helpers, middleware, traits (HasOwner, HasOwnerScopeKey, FormatsMoney, ValidatesConfiguration) and a targeting engine. The Report, SavedSearch, and NotificationPreference models referenced below exist only in the package source at `~/herd/commerce/packages/commerce-support/` and have not been published.

---

## Table of Contents

1. [Summary Matrix](#1-summary-matrix)
2. [Already In Use (Installed)](#2-already-in-use)
3. [Source-Only Packages Overview](#3-source-only-packages-overview)
4. [Gap Register: What ilmus360 Needs vs What Packages Provide](#4-gap-register)
5. [Source vs Vendor Discrepancies](#5-source-vs-vendor-discrepancies)
6. [Integration Map](#6-integration-map)
7. [Recommendation (Historical)](#7-recommendation)
8. [Actual Adoption Status](#8-actual-adoption-status)
9. [Appendix: Full Package Inventory](#9-appendix-full-package-inventory)

---

## 1. Summary Matrix

### 1.1 ilmus360 Domain Coverage

| ilmus360 Domain | Source Package(s) | Coverage | Installed? |
|---|---|---|---|
| **Events core** | `events` | 85-90% match for Event, Venue, Series, Registration, Check-in, Submissions | ❌ No |
| **Institutions** | `events` (Organization model + OwnsEvents/CanOrganizeEvents traits) | ~80% (no dashboard, no donation channels) | ❌ No |
| **Speakers** | `events` (EventInvolvement + CanBeInvolvedInEvents) | ~60% (thin model, no dedicated Speaker entity) | ❌ No |
| **References** | `references` | ~85% (Reference model, hierarchy, slug generation, status workflow) | ❌ No |
| **Venues** | `events` | ~100% (Venue + VenueSpace + EventLocation) | ❌ No |
| **Series** | `events` | ~100% (EventSeries + EventSeriesItem + EventSeriesRule) | ❌ No |
| **Engagement (Follows)** | `engagement` | 90% — replaces custom followings pivot | ❌ No |
| **Engagement (Bookmarks)** | `engagement` | 90% — replaces custom event_saves pivot | ❌ No |
| **Engagement (RSVP/Going)** | `engagement` | 90% — Response model with going/interested/maybe/not_going | ❌ No |
| **Engagement (Reactions)** | `engagement` | 100% — Reaction model (like/love/celebrate) | ❌ No |
| **Engagement (Reminders)** | `engagement` | 100% — Reminder model | ❌ No |
| **Engagement (Subscriptions)** | `engagement` | 100% — Subscription model (follow-with-triggers) | ❌ No |
| **Engagement (Shares)** | `affiliates` | Already in use for share tracking | ✅ Yes |
| **Contacting** | `contacting` | 85% — replaces custom Contact + SocialMedia + verification | ❌ No |
| **Addresses & Geography** | `addressing` | 80% — replace Address; UUID geography int→UUID blocker | ❌ No |
| **Signals/Analytics** | `signals` + `filament-signals` | Already in use | ✅ Yes |
| **Auth/Authorization** | `filament-authz` | Already in use | ✅ Yes |
| **Commerce-Support** | `commerce-support` | Foundation helpers only in installed version; models in source only | ✅ Partial |
| **Notifications Engine** | `communications` | ~91% — full notification delivery engine + inbox Livewire component | ❌ No |
| **Contributions/Moderation** | `events` (EventSubmission + EventModerationWorkflow) | ~85% — submissions are polymorphic on target | ❌ No |
| **Reports** | `commerce-support` (source only, not published) | Model layer only; SubmitReportAction stays custom | ❌ No |
| **Saved Searches** | `commerce-support` (source only, not published) | Model layer only; filter normalizer stays custom | ❌ No |
| **Membership Claims** | `membership` | ~80% — MembershipApplication + MembershipInvitation + HasMembers | ❌ No |
| **Moderation/Bans** | `moderation` | ~90% — Block model + ModerationAction audit trail | ❌ No |
| **Donation Channels** | — | None | Stays custom |
| **Tags (Spatie)** | `events` (EventTaxonomy + EventTerm) | Equivalent structure; non-event entities keep Spatie Tags | ❌ Partial |
| **Media** | Spatie MediaLibrary | Standard | No change |
| **AI/MCP** | `laravel/ai` + `laravel/mcp` | Standard | No change |

### 1.2 Package Relevance Overview

Of the 59 packages in the monorepo:

| Category | Count | Packages |
|---|---|---|
| Already in use (installed) | 5 | affiliates, commerce-support, signals, filament-authz, filament-signals |
| Potentially relevant to ilmus360 | 13 | events, engagement, contacting, addressing, communications, membership, moderation, references, plus 6 filament-* UI packages |
| Commerce-specific (not relevant) | 23 | cart, checkout, orders, products, pricing, promotions, vouchers, shipping, inventory, customers, cashier, cashier-chip, chip, jnt, tax, growth, docs, affiliate-network, feedback, csuite (empty) |
| Filament UI for commerce (not relevant) | 18 | filament-cart, filament-checkout, filament-orders, filament-products, filament-pricing, filament-promotions, filament-vouchers, filament-shipping, filament-inventory, filament-customers, filament-cashier, filament-cashier-chip, filament-chip, filament-jnt, filament-tax, filament-growth, filament-docs, filament-affiliate-network |

---

## 2. Already In Use

| Package | Purpose | Integration | Notes |
|---|---|---|---|
| `aiarmada/affiliates` | Share tracking & attribution | `ShareTrackingService`, middleware, 5+ analytics services | Vendor has 10 actions; source has 19 (source ahead) |
| `aiarmada/signals` | Product telemetry/analytics | `ProductSignalsService`, 15+ tracked events, browser tracking | Vendor has 8 actions; source has 10 |
| `aiarmada/filament-signals` | Filament Signals dashboard | Admin dashboard widgets, 7 resources | Vendor has 50 files; source has 70 |
| `aiarmada/filament-authz` | Scoped permissions | `MemberPermissionGate`, panel access gates, 3 models | Vendor is AHEAD of source (56 vs 36 files) |
| `aiarmada/commerce-support` | Foundation helpers | Pulled in by affiliates + filament-authz | Vendor has 0 models, 6 traits; source has 6 models, 13 traits |

---

## 3. Source-Only Packages Overview

### 3.1 Potentially Relevant (13 packages)

| Package | What It Provides | Relevance to ilmus360 |
|---|---|---|
| `events` | 46 models, 35 actions, 23 traits, 70 migrations. Full event system: Event, Occurrence, Registration, Ticketing, Check-in, Submissions, Venue, Series, Taxonomy, ChangeLog, Notifications, Seat maps | **High** — covers Event, Venue, Series, Registration, Check-in, Submissions, Taxonomy |
| `engagement` | 11 models (Follow, Bookmark, Response, Reaction, Reminder, Subscription, Share), 15 traits, 30 domain events | **High** — replaces 3 custom pivots + adds reactions, reminders, subscriptions |
| `contacting` | ContactMethod, SocialProfile, ContactSnapshot, 10 verification actions | **High** — replaces Contact + SocialMedia + adds verification |
| `addressing` | Address, AddressCountry, AddressArea, AddressSnapshot, 6 geocoding/verification actions | **Medium** — UUID geography is a hard blocker |
| `communications` | 16 models, 30 actions, full notification engine: Communication, Batch, Thread, Delivery, Attempt, Template, Tracking, Suppression, Inbox | **High** — replaces ~80% of custom notification engine |
| `membership` | MembershipApplication, MembershipInvitation, 10 actions, HasMembers trait | **High** — replaces MembershipClaim + member invitations |
| `moderation` | Block, ModerationAction, 2 actions, HasBlocks/HasModerationActions traits | **High** — replaces ModerationReview + adds Ban/Block system |
| `references` | Reference model, GenerateReferenceSlugAction, HasReferenceParts trait, hierarchy support | **High** — replaces custom Reference model |
| `filament-events` | Admin resources for events package | **Medium** — admin UI for event management |
| `filament-engagement` | Admin resources for engagement package | **Low** — engagement has minimal admin needs |
| `filament-contacting` | Admin resources for contacting package | **Low** — admin UI for contacts/social |
| `filament-addressing` | Admin resources for addressing package | **Low** — addressing admin |
| `filament-communications` | Admin resources for communications package | **Medium** — communication log admin |

### 3.2 Commerce-Specific (Not Relevant)

These packages are designed for e-commerce and are not relevant to ilmus360's current domain:

| Package | What It Does |
|---|---|
| `cart` | Shopping cart with conditions pipeline |
| `checkout` | Unified checkout flow with steps |
| `orders` | Order management & lifecycle |
| `products` | Product catalog & PIM |
| `pricing` | Dynamic pricing engine |
| `promotions` | Discount campaigns |
| `vouchers` | Voucher & coupon system |
| `shipping` | Multi-carrier shipping |
| `inventory` | Multi-location warehouse management |
| `customers` | Customer CRM |
| `cashier` | Multi-gateway billing (Stripe + CHIP) |
| `cashier-chip` | CHIP gateway billing |
| `chip` | CHIP payment gateway |
| `jnt` | J&T Express Malaysia API |
| `tax` | Tax calculation engine |
| `growth` | Revenue experimentation engine |
| `docs` | Document generation (PDF invoices, receipts) |
| `affiliate-network` | Multi-merchant affiliate marketplace |
| `feedback` | Feedback forms, surveys, testimonials |
| `csuite` | Empty metapackage |
| Plus 18 corresponding `filament-*` packages |

These could become relevant if ilmus360 adds commerce features (paid event ticketing, merchandise store, subscriptions).

---

## 4. Gap Register

### 4.1 What ilmus360 Has That Packages Could Replace

| Custom Domain | Est. Lines | Source Package(s) | Coverage | Adopted? |
|---|---|---|---|---|
| Event (core) | ~12,000 | `events` | 85-90% | ❌ |
| Institution | ~5,000 | `events` + `membership` | ~80% | ❌ |
| Speaker | ~3,000 | `events` | ~60% | ❌ |
| Reference | ~2,000 | `references` | ~85% | ❌ |
| Notifications engine | ~3,500 | `communications` | ~91% | ❌ |
| Contributions/Moderation | ~2,500 | `events` | ~85% | ❌ |
| Contact + SocialMedia | ~600 | `contacting` | ~85% | ❌ |
| Address + geography | ~1,500 | `addressing` | ~80% (geography blocker) | ❌ |
| Follows/Bookmarks/RSVP | ~1,200 | `engagement` | 90% | ❌ |
| Membership claims | ~500 | `membership` | ~80% | ❌ |
| Moderation reviews | ~300 | `moderation` | ~90% | ❌ |
| Reports | ~2,000 | `commerce-support` (source only) | ~75% | ❌ |
| Saved searches | ~500 | `commerce-support` (source only) | ~80% | ❌ |
| **Total replaceable** | **~34,600** | — | — | ❌ |

### 4.2 What Stays Custom (No Package)

| Domain | Lines | Why |
|---|---|---|
| Donation Channels | ~500 | Islamic charity-specific (no package equivalent) |
| MCP Servers | ~2,000 | Application-specific AI tools |
| Public Livewire Pages | ~2,500 | UI layer — packages provide Filament admin, not public frontends |
| Spatie Tags (non-event) | ~500 | Spatie Tags already covers this |
| Integration glue | ~800 | Share outcome hooks, signals wiring, media bridge |
| **Total stays custom** | **~6,300** | — |

### 4.3 Source vs Vendor: What's Missing from Installed Packages

| Installed Package | Source Has That Vendor Does Not |
|---|---|
| `commerce-support` | 6 models (AuthzScope, NotificationPreference, Permission, Report, Role, SavedSearch), 7 traits (HasLanguages, HasNotificationPreferences, HasReports, HasSavedSearches, etc.), 17+ support files |
| `affiliates` | 9 actions (ApproveAffiliate, CreateAffiliate, CreateTrackingLink, TrackAffiliateVisit, etc.) |
| `signals` | 2 actions (EvaluateAlertRules, ResolveSession) |
| `filament-signals` | 20 files (resource form/table schemas, report pages, mutation guards) |
| `filament-authz` | Vendor is AHEAD — 20 more files than source (Models, Policies, Services) |

---

## 5. Source vs Vendor Discrepancies

### 5.1 Overview

The 5 installed packages in `vendor/aiarmada/` were installed as local path/development versions. They are out of sync with the current source code at `~/herd/commerce/packages/`:

| Package | Vendor Status vs Source | Direction |
|---|---|---|
| `affiliates` | Vendor has 10 actions; source has 19 | Source ahead |
| `commerce-support` | Vendor has 0 models; source has 6 | Source ahead (major) |
| `signals` | Vendor has 8 actions; source has 10 | Source ahead |
| `filament-signals` | Vendor has 50 files; source has 70 | Source ahead |
| `filament-authz` | Vendor has 56 files; source has 36 | **Vendor ahead** |

### 5.2 Impact

The `commerce-support` gap is the most significant for ilmus360. The source package has `Report`, `SavedSearch`, and `NotificationPreference` models with associated traits and enums that would directly replace custom `App\Models\Report`, `App\Models\SavedSearch`, and `App\Models\NotificationSetting`. These models exist in source but were never published to the installed version.

`filament-authz` being ahead of source means the vendor has fixes/features not yet in source. This should be reconciled if source is the canonical version.

---

## 6. Integration Map

### 6.1 Current Actual Architecture (5 Installed Packages, 49 Custom Models)

```
┌───────────────────────────────────────────────────────────────────────┐
│                        ilmu360 Application                            │
├───────────────────────────────────────────────────────────────────────┤
│                                                                         │
│  ┌───────────────────────────────────────────────────────────────┐    │
│  │            FULLY CUSTOM (~49 models, ~38,500 lines)            │    │
│  │                                                                 │    │
│  │  Event ─── Venue ─── Series ─── EventKeyPerson                 │    │
│  │  EventChangeAnnouncement ─── EventSettings                     │    │
│  │  Registration ─── EventCheckin ─── EventSubmission             │    │
│  │  ModerationReview ─── ContributionRequest                      │    │
│  │  Institution ─── Speaker ─── Reference                         │    │
│  │  MembershipClaim ─── MemberInvitation ─── Membership           │    │
│  │  Contact ─── SocialMedia ─── Address                           │    │
│  │  Followings pivot ─── EventSaves pivot ─── EventAttendees      │    │
│  │  SavedSearch ─── Report ─── DonationChannel                    │    │
│  │  Notifications (6 tables, engine, channels, digests)          │    │
│  │  Tags (Spatie Tags, custom TagType enum)                      │    │
│  │  Public Livewire Pages (Index, Show, Submit, Dashboard)       │    │
│  │  MCP Servers (admin + member, 57 tools)                       │    │
│  └───────────────────────────────────────────────────────────────┘    │
│                              │                                          │
│                              ▼                                          │
│  ┌───────────────────────────────────────────────────────────────┐    │
│  │            INSTALLED PACKAGES (5, no model replacement)         │    │
│  │                                                                 │    │
│  │  aiarmada/affiliates           share tracking (partial)        │    │
│  │  aiarmada/signals              analytics pipeline               │    │
│  │  aiarmada/filament-signals     admin analytics dashboard        │    │
│  │  aiarmada/filament-authz       scoped permissions admin UI      │    │
│  │  aiarmada/commerce-support     foundation helpers only          │    │
│  └───────────────────────────────────────────────────────────────┘    │
│                              │                                          │
│                              ▼                                          │
│  ┌───────────────────────────────────────────────────────────────┐    │
│  │            SOURCE PACKAGES (not installed, 54 packages)         │    │
│  │                                                                 │    │
│  │  ~/herd/commerce/packages/                                      │    │
│  │                                                                 │    │
│  │  Domain (31): events, engagement, contacting, addressing,       │    │
│  │    communications, membership, moderation, references, authz,   │    │
│  │    commerce-support (extended), affiliates (extended),          │    │
│  │    signals (extended), +19 commerce packages                    │    │
│  │  Filament (27): filament-events, filament-engagement,           │    │
│  │    filament-contacting, filament-addressing,                    │    │
│  │    filament-communications, filament-membership,                │    │
│  │    +21 commerce filament packages                               │    │
│  └───────────────────────────────────────────────────────────────┘    │
│                                                                         │
└───────────────────────────────────────────────────────────────────────┘
```

### 6.2 What Would Change If Source Packages Were Published and Adopted

See `docs/commerce-package-readiness-reassessment.md` for the full-rewrite analysis and `docs/commerce-package-refactor-perspective.md` for the per-package refactor plan.

---

## 7. Recommendation (Historical)

> This recommendation was written during initial analysis. None of the package adoptions beyond the original 5 were executed. See §8 for current status.

### Original Adoption Roadmap (Not Executed)

| Phase | Packages | Effort | Impact |
|---|---|---|---|
| Phase 1 | `engagement` + `contacting` | ~9 days | -2,300 lines |
| Phase 2 | `addressing` | ~5 days | -525 lines (geography stays custom) |
| Phase 3 | `events` + `filament-events` | ~40-60 days | -12,000 lines |
| Phase 4 | Integration hooks | ~5 days | -500 lines |

### Packages Built Since Original Analysis

Since the original analysis, these additional packages were built in source:

| Package | Built | What It Replaces |
|---|---|---|
| `communications` | Jun 23 | ~3,500 lines of custom notification engine (~91% coverage) |
| `moderation` | Jun 24 | ~300 lines of custom ModerationReview + Ban/Block |
| `references` | Jun 24 | ~2,000 lines of custom Reference model + hierarchy |
| `authz` | Jun 25 | Extracted from filament-authz; standalone Spatie Permission wrapper |
| `membership` | Jun 25 | ~500 lines of custom MembershipClaim + member invitations |
| `filament-communications` | Jun 23 | Admin UI for communications |

---

## 8. Actual Adoption Status

### 8.1 What's Installed (5 packages)

| Package | Version | Purpose | Status |
|---|---|---|---|
| `aiarmada/affiliates` | dev-main | Share tracking & attribution | Active via `ShareTrackingService` wrapper. Source ahead of vendor by 9 actions |
| `aiarmada/signals` | dev-main | Product telemetry/analytics | Active with browser tracking. Source ahead by 2 actions |
| `aiarmada/filament-signals` | dev-main | Admin analytics dashboard | Active in AdminPanelProvider. Source ahead by 20 files |
| `aiarmada/filament-authz` | dev-main | Scoped permissions admin UI | Active with centralApp + global_only. Vendor AHEAD of source |
| `aiarmada/commerce-support` | dev-main | Foundation helpers only | Active as dependency. Source has 6 models + 7 traits that are NOT in vendor |

### 8.2 Source Packages (54 packages, NOT Installed)

All source packages exist at `~/herd/commerce/packages/`. Key packages relevant to ilmus360:

| Package | Models | Actions | Traits | Tests | Replaces | Integration Status |
|---|---|---|---|---|---|---|
| `events` | 46 | 35 | 23 | 0 | Event, Venue, Series, Registration, Check-in, Submissions, Taxonomy, ChangeLog, Notifications | ❌ Not started |
| `engagement` | 11 | — | 15 | 0 | Follow/Bookmark/RSVP pivots + Reactions, Reminders, Subscriptions | ❌ Not started |
| `contacting` | 3 | 10 | — | 0 | Contact, SocialMedia + verification | ❌ Not started |
| `addressing` | 5 | 6 | 1 | 0 | Address + geography (UUID blocker) | ❌ Not started |
| `communications` | 16 | 30 | 2 | 0 | Custom notification engine + inbox | ❌ Not started |
| `membership` | 2 | 10 | 1 | 0 | MembershipClaim, invitations, roles | ❌ Not started |
| `moderation` | 3 | 2 | 2 | 0 | ModerationReview, Ban/Block | ❌ Not started |
| `references` | 2 | 1 | 1 | 0 | Reference model + hierarchy | ❌ Not started |
| `authz` | 0 | 0 | 0 | 0 | Extracted filament-authz base (2 migrations) | ❌ Not started |
| 19 commerce packages | varies | varies | varies | 0 | Not relevant | ❌ Not started |
| 27 filament packages | varies | varies | varies | 0 | Admin UIs for the above | ❌ Not started |

### 8.3 Source vs Vendor Reconciliation Needed

| Package | Action Required |
|---|---|
| `commerce-support` | Publish models (AuthzScope, NotificationPreference, Permission, Report, Role, SavedSearch) and traits (HasLanguages, HasNotificationPreferences, HasReports, HasSavedSearches, etc.) to vendor |
| `affiliates` | Sync 9 missing actions (ApproveAffiliate, CreateAffiliate, TrackAffiliateVisit, etc.) to vendor |
| `signals` | Sync 2 missing actions (EvaluateAlertRules, ResolveSession) to vendor |
| `filament-signals` | Sync 20 missing files (resource schemas, report page, mutation guards) to vendor |
| `filament-authz` | Backport vendor advancements (20 extra files including Models, Policies, Services) to source |

### 8.4 Current Model State

As of this writing, all 49 `App\Models\*` were fully custom — none extended or referenced aiarmada package models. *5 models have since been closed to extend packages (completed 2026-07-06): EventKeyPerson → EventInvolvement, EventCheckin → EventAttendance, EventChangeAnnouncement → EventUpdate, EventSubmission → EventSubmission(pkg), ModerationReview → ModerationAction.*

| Custom Model | Lines | Package That Would Replace | Still Custom? |
|---|---|---|---|
| Event | 1,518 | `events` | ✅ Yes |
| Contact | 45 | `contacting` | ✅ Yes |
| SocialMedia | 104 | `contacting` | ✅ Yes |
| SavedSearch | 52 | `commerce-support` (source, not published) | ✅ Yes |
| Report | 86 | `commerce-support` (source, not published) | ✅ Yes |
| MembershipClaim | 104 | `membership` | ✅ Yes |
| ModerationReview | 48 | `moderation` | ✅ Yes — now extends package model (closed 2026-07-06) |
| Reference | ~200 | `references` | ✅ Yes |
| Address | ~150 | `addressing` | ✅ Yes |
| DonationChannel | 166 | No package | ✅ Yes (no pkg) |
| Venue | ~200 | `events` | ✅ Yes |
| Series | ~100 | `events` | ✅ Yes |
| Registration | ~150 | `events` | ✅ Yes |
| EventCheckin | ~80 | `events` | ✅ Yes — now extends package model (closed 2026-07-06) |
| EventSubmission | ~150 | `events` | ✅ Yes — now extends package model (closed 2026-07-06) |
| Institution | ~400 | `events` + `membership` | ✅ Yes |
| Speaker | ~350 | `events` | ✅ Yes |
| NotificationSetting + 5 more | ~500+ | `communications` | ✅ Yes |

### 8.5 Decision Record

| Decision | Status |
|---|---|
| Adopted (keep) | affiliates, signals, filament-signals, filament-authz, commerce-support |
| Source packages built but deferred | events, engagement, contacting, addressing, membership, moderation, references, communications, filament-* UIs |
| Commerce packages (irrelevant) | cart, checkout, orders, products, pricing, promotions, vouchers, shipping, inventory, customers, cashier, cashier-chip, chip, jnt, tax, growth, docs, affiliate-network, feedback, csuite |
| Never (no package) | Donation Channels, MCP Servers, Public Livewire pages |
| Needs reconciliation | commerce-support (publish models), filament-authz (backport vendor advances), affiliates + signals + filament-signals (sync missing files) |

---

## 9. Appendix: Full Package Inventory

### 9.1 Domain Packages (32)

| # | Package | Description | Relevant? |
|---|---|---|---|
| 1 | `addressing` | Address handling with country data, area imports, polymorphic attachment | ✅ Yes (geography blocker) |
| 2 | `affiliate-network` | Multi-merchant affiliate marketplace | ❌ Commerce |
| 3 | `affiliates` | Affiliate attribution, referral tracking, commissions | ✅ Already in use |
| 4 | `authz` | Permission extraction base (2 migrations) | ⚠️ Part of filament-authz |
| 5 | `cart` | Shopping cart with conditions pipeline | ❌ Commerce |
| 6 | `cashier` | Multi-gateway billing (Stripe + CHIP) | ❌ Commerce |
| 7 | `cashier-chip` | CHIP gateway billing | ❌ Commerce |
| 8 | `checkout` | Unified checkout flow | ❌ Commerce |
| 9 | `chip` | CHIP payment gateway | ❌ Commerce |
| 10 | `commerce-support` | Foundation helpers + source-only models (Report, SavedSearch, NotificationPreference, Permission, Role, AuthzScope) | ✅ Already in use (partial — models in source only) |
| 11 | `communications` | Full notification/delivery engine (16 models, 30 actions, inbox) | ✅ Yes (critical) |
| 12 | `contacting` | Contact methods and social profiles with verification | ✅ Yes |
| 13 | `csuite` | Empty metapackage | ❌ Empty |
| 14 | `customers` | Customer CRM | ❌ Commerce |
| 15 | `docs` | Document generation (PDF invoices, receipts) | ❌ Commerce |
| 16 | `engagement` | Follow, Bookmark, Reaction, Response, Reminder, Subscription, Share | ✅ Yes |
| 17 | `events` | Full event system (46 models, 35 actions, 70 migrations) | ✅ Yes — 85-90% match |
| 18 | `feedback` | Feedback forms, surveys, testimonials | ❌ Survey ≠ moderation |
| 19 | `growth` | Revenue experimentation engine | ❌ Commerce |
| 20 | `inventory` | Multi-location warehouse management | ❌ Commerce |
| 21 | `jnt` | J&T Express Malaysia API | ❌ Commerce |
| 22 | `membership` | MembershipApplication + MembershipInvitation + HasMembers trait | ✅ Yes |
| 23 | `moderation` | Block + ModerationAction models, Ban/Block system | ✅ Yes |
| 24 | `orders` | Order management & lifecycle | ❌ Commerce |
| 25 | `pricing` | Dynamic pricing engine | ❌ Commerce |
| 26 | `products` | Product catalog & PIM | ❌ Commerce |
| 27 | `promotions` | Discount campaigns | ❌ Commerce |
| 28 | `references` | Reference entity model with hierarchy and slug generation | ✅ Yes |
| 29 | `shipping` | Multi-carrier shipping | ❌ Commerce |
| 30 | `signals` | Privacy-first behavioral analytics | ✅ Already in use |
| 31 | `tax` | Tax calculation engine | ❌ Commerce |
| 32 | `vouchers` | Voucher & coupon system | ❌ Commerce |

### 9.2 Filament Packages (27)

| # | Package | Domain Package | Relevant? |
|---|---|---|---|
| 1 | `filament-addressing` | `addressing` | ✅ Yes (if addressing adopted) |
| 2 | `filament-affiliate-network` | `affiliate-network` | ❌ |
| 3 | `filament-affiliates` | `affiliates` | ❌ Not needed |
| 4 | `filament-authz` | `authz` + `commerce-support` | ✅ Already in use (VENDOR AHEAD) |
| 5 | `filament-cart` | `cart` | ❌ |
| 6 | `filament-cashier` | `cashier` | ❌ |
| 7 | `filament-cashier-chip` | `cashier-chip` | ❌ |
| 8 | `filament-chip` | `chip` | ❌ |
| 9 | `filament-commerce-support` | `commerce-support` | ✅ Already in use |
| 10 | `filament-communications` | `communications` | ✅ Yes |
| 11 | `filament-contacting` | `contacting` | ✅ Yes |
| 12 | `filament-customers` | `customers` | ❌ |
| 13 | `filament-docs` | `docs` | ❌ |
| 14 | `filament-engagement` | `engagement` | ✅ Yes |
| 15 | `filament-events` | `events` | ✅ Yes |
| 16 | `filament-feedback` | `feedback` | ❌ |
| 17 | `filament-growth` | `growth` | ❌ |
| 18 | `filament-inventory` | `inventory` | ❌ |
| 19 | `filament-jnt` | `jnt` | ❌ |
| 20 | `filament-membership` | `membership` | ✅ Yes |
| 21 | `filament-orders` | `orders` | ❌ |
| 22 | `filament-pricing` | `pricing` | ❌ |
| 23 | `filament-products` | `products` | ❌ |
| 24 | `filament-promotions` | `promotions` | ❌ |
| 25 | `filament-shipping` | `shipping` | ❌ |
| 26 | `filament-signals` | `signals` | ✅ Already in use (SOURCE AHEAD) |
| 27 | `filament-tax` | `tax` | ❌ |
| 28 | `filament-vouchers` | `vouchers` | ❌ |

### 9.3 Summary

| Category | Count |
|---|---|
| Total packages in monorepo | 59 |
| Already in use (installed) | 5 |
| Potentially relevant (not installed) | 13 |
| Commerce-specific (not relevant) | 23 |
| Empty (csuite) | 1 |
| Filament UI for commerce (not relevant) | 17 |
