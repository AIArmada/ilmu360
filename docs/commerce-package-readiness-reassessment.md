# Re-assessment: ilmu360° Full Rewrite on aiarmada/packages

> Flipping the question from "what can we adopt?" to **"what if we build everything on top of packages?"**
> Then re-checking every gap against actual package source code.

> **⚠️ ACTUAL STATUS: Planning analysis only.** This document explores what a full rewrite on top of aiarmada packages would look like. **None of the packages identified here (beyond the original 5) have been adopted.** All source code exists at `~/herd/commerce/packages/` but remains unpublished and unintegrated. See [§9 Current Adoption Truth](#9-current-adoption-truth) for the actual state.

---

## 1. Complete Package Inventory

The commerce monorepo contains **59 packages** across two categories:

| Category | Count | Status |
|---|---|---|
| Domain/backend packages | 32 | 5 installed, 27 source-only |
| Filament admin UI packages | 27 | 2 installed, 25 source-only |
| **Total** | **59** | **5 installed, 54 source-only** |

### 1.1 Installed (5)

| Package | Type | Purpose |
|---|---|---|
| `affiliates` | Domain | Share tracking & attribution |
| `commerce-support` | Domain | Foundation helpers, models in source only |
| `signals` | Domain | Privacy-first behavioral analytics |
| `filament-authz` | Filament | Scoped permissions admin UI |
| `filament-signals` | Filament | Analytics dashboard |

### 1.2 Source-Only — Potentially Relevant (13)

| Package | Type | Source Path | What It Provides |
|---|---|---|---|
| `events` | Domain | `packages/events/` | 46 models, 35 actions, 23 traits, 70 migrations |
| `engagement` | Domain | `packages/engagement/` | 11 models (Follow, Bookmark, Response, Reaction, Reminder, Subscription, Share) |
| `contacting` | Domain | `packages/contacting/` | ContactMethod, SocialProfile, 10 verification actions |
| `addressing` | Domain | `packages/addressing/` | Address, AddressCountry, AddressArea, 6 geocoding/verification actions |
| `communications` | Domain | `packages/communications/` | 16 models, 30 actions — full notification engine + inbox |
| `membership` | Domain | `packages/membership/` | MembershipApplication, MembershipInvitation, HasMembers |
| `moderation` | Domain | `packages/moderation/` | Block, ModerationAction, Ban/Block system |
| `references` | Domain | `packages/references/` | Reference model, hierarchy, slug generation |
| `filament-events` | Filament | `packages/filament-events/` | Admin event management |
| `filament-engagement` | Filament | `packages/filament-engagement/` | Admin engagement management |
| `filament-contacting` | Filament | `packages/filament-contacting/` | Admin contact management |
| `filament-addressing` | Filament | `packages/filament-addressing/` | Admin address management |
| `filament-communications` | Filament | `packages/filament-communications/` | Admin communication logs |
| `filament-membership` | Filament | `packages/filament-membership/` | Admin membership management |

### 1.3 Source-Only — Commerce-Specific (41)

| Category | Packages |
|---|---|
| Core commerce | `cart`, `checkout`, `orders`, `products`, `pricing`, `promotions`, `vouchers`, `shipping`, `inventory`, `customers` |
| Payments | `cashier`, `cashier-chip`, `chip` |
| Logistics | `jnt` |
| Tax | `tax` |
| Growth | `growth` |
| Other | `docs`, `affiliate-network`, `feedback`, `csuite` (empty) |
| Filament UIs | `filament-cart`, `filament-checkout`, `filament-orders`, `filament-products`, `filament-pricing`, `filament-promotions`, `filament-vouchers`, `filament-shipping`, `filament-inventory`, `filament-customers`, `filament-cashier`, `filament-cashier-chip`, `filament-chip`, `filament-jnt`, `filament-tax`, `filament-growth`, `filament-docs`, `filament-affiliate-network`, `filament-feedback` |

---

## 2. Domain-by-Domain Coverage (Rewrite Perspective)

### 2.1 Events Core (~12,000 lines)

| Feature | Package Model | Can Cover? | Notes |
|---|---|---|---|
| Core Event (title, desc, slug, type, timezone) | `Event` | ✅ Yes | Full, 46 models in package |
| Spatie ModelStates (6 states) | `EventStatus` enum + `EventLifecyclePolicy` | ✅ Yes | Different approach, same coverage |
| Visibility (public/unlisted/private) | `EventVisibility` enum | ✅ Yes | Same |
| Delivery mode (in_person/virtual/hybrid) | `EventFormat` enum | ✅ Yes | Same |
| Event structure (standalone/parent/child) | `EventAttribute` | ✅ Yes | Via EAV |
| Prayer-relative timing | `EventTimeExpression` | ✅ Yes | `anchor_type=prayer`, `anchor_code=maghrib` |
| Gender restriction | `EventAttribute` | ✅ Yes | `attribute_key="gender_restriction"` |
| Age group | `EventAudience` | ✅ Yes | `audience_type="age_group"` |
| Muslim only flag | `EventAttribute` | ✅ Yes | `attribute_key="is_muslim_only"` |
| Event type (enum collection) | `EventAttribute` | ✅ Yes | `attribute_key="event_type"` |
| Timing mode (fixed/prayer-relative) | `EventTimeExpression.time_mode` | ✅ Yes | `time_mode="fixed"` or `"prayer_relative"` |
| Islamic key roles (Imam, Khatib, Bilal) | `EventRole` | ✅ Yes | Seedable, code-based catalog |
| Spatie Tags | `EventTaxonomy` + `EventTerm` | ✅ Yes | Hierarchical taxonomies |
| Spatie MediaLibrary | `EventMedia` | ⚠️ Bridge needed | Package uses file_id/url, not MediaLibrary |
| Multi-occurrence | `EventOccurrence` | ✅ Upgrade | Per-occurrence dates, status, capacity |
| Sessions/agenda | `EventSession` | ✅ Upgrade | Per-occurrence session scheduling |
| Ticket types/pricing | `EventTicketType` | ✅ New | Not in ilmus360 (free events only) |
| Seat maps | `EventSeatMap` + Section + Seat | ✅ New | Reserved seating |
| Pass issuance | `EventPass` | ✅ New | QR codes, barcodes |
| Recurrence rules | `EventRecurrenceRule` | ✅ New | RRULE support |
| Prayer-time time expressions | `EventTimeExpression` | ✅ New | Time anchors relative to prayers |
| Itineraries | `EventItinerary` + Items | ✅ New | Day-by-day schedules |

**Verdict: ALL Islamic-domain features CAN be modeled via EventAttribute, EventAudience, EventTimeExpression, EventRole, and EventTaxonomy.** The package's EAV + audience + time-expression + taxonomy architecture provides equivalent coverage through composition.

### 2.2 Institutions (~5,000 lines)

| Feature | Package Model | Can Cover? |
|---|---|---|
| Basic org (name, slug, bio, status, visibility) | `Organization` (events package) | ✅ Yes |
| Email/phone/social contacts | `HasContactMethods` + `HasSocialProfiles` | ✅ Yes |
| Own events | `OwnsEvents` + `CanOrganizeEvents` | ✅ Yes |
| Be involved in events | `HasEventInvolvements` + `CanBeInvolvedInEvents` | ✅ Yes |
| Media/logo | `InteractsWithMedia` | ✅ Yes |
| Languages | `HasLanguages` trait (commerce-support source) | ✅ Yes |
| Membership/claims | `aiarmada/membership` | ✅ Yes |
| Member invitations | `MembershipInvitation` | ✅ Yes |
| Donation channels | None | ❌ Custom |
| Dashboard (Livewire) | None | ❌ Custom |

**Verdict: ~80% coverage.** Missing: donation channels, public dashboard UI.

### 2.3 Speakers (~3,000 lines)

| Feature | Package Model | Can Cover? |
|---|---|---|
| Speaker as entity | No dedicated model | ❌ No standalone model |
| Speaker involvement in events | `EventInvolvement` (polymorphic) | ✅ Yes |
| Speaker roles | `EventRole` + `EventInvolvement.role_code` | ✅ Yes |
| Claims workflow | `aiarmada/membership` | ✅ Yes |
| Bio, contact, social, media | `HasContactMethods`, `HasSocialProfiles`, `InteractsWithMedia` | ✅ Yes |
| Listing/detail UI | None (Livewire) | ❌ Custom |

**Verdict: ~60% coverage.** No dedicated Speaker model. A thin custom model (~100 lines) implementing `CanBeInvolvedInEvents` + `HasContactMethods` + `HasSocialProfiles` + `InteractsWithMedia` + `HasMembers` would be needed.

### 2.4 References (~2,000 lines)

| Feature | Package Model | Can Cover? |
|---|---|---|
| Reference entity | `Reference` (aiarmada/references) | ✅ Yes |
| Event→Reference linking | `EventReference` (events package) | ✅ Yes |
| Hierarchy (parent/child) | `HasReferenceParts` trait + `parent_id` | ✅ Yes |
| Slug generation | `GenerateReferenceSlugAction` | ✅ Yes |
| Status workflow | `ReferenceStatus` enum (draft, verified, rejected) | ✅ Yes |
| Claims workflow | `aiarmada/membership` | ✅ Yes |
| Search/listing/detail | None (Livewire/API) | ❌ Custom UI |

**Verdict: ~85% coverage.** Remaining gap: public UI (~300 lines).

### 2.5 Notifications Engine (~3,500 lines)

| Feature | communications Package | Can Cover? |
|---|---|---|
| Communication record | `Communication` (direction, category, priority, status) | ✅ Yes |
| Batching | `CommunicationBatch` | ✅ Yes |
| Threading/conversations | `CommunicationThread` | ✅ Yes |
| Multi-channel content | `CommunicationContent` | ✅ Yes |
| Recipient tracking | `CommunicationRecipient` (snapshot, role, locale, timezone) | ✅ Yes |
| Delivery per channel | `CommunicationDelivery` (queued→sending→sent→delivered→...→failed) | ✅ Yes |
| Delivery attempts | `CommunicationAttempt` (count, max, provider, response) | ✅ Yes |
| Provider events | `RecordProviderEventAction`, `ApplyProviderEventAction` | ✅ Yes |
| Suppression | `CommunicationSuppression` (reason, expiry, scope) | ✅ Yes |
| Tracking tokens | `CommunicationTrackingToken` | ✅ Yes |
| Templates | `CommunicationTemplate` + `CommunicationTemplateVersion` | ✅ Yes |
| Preferences | `CommunicationPreference` (channel, category, digest, quiet hours) | ✅ Yes |
| Eligibility (suppression + prefs + quiet hours + rate limits) | `ResolveCommunicationEligibilityAction` | ✅ Yes |
| Laravel Notification integration | `DispatchManagedNotificationAction` | ✅ Yes |
| Attachments | `CommunicationAttachment` | ✅ Yes |
| Pruning | `PruneCommunicationDataAction` | ✅ Yes |
| In-app inbox | `NotificationInbox` + `HasInbox` + `InboxIndex` Livewire component | ✅ Yes |
| FCM push channel | None | ❌ Custom channel impl |
| WhatsApp channel | None | ❌ Custom channel impl |
| Digest scheduling job | Preferences have digest_frequency, no scheduling job | ⚠️ Custom needed |

**Verdict: ~91% coverage.** Only provider channel implementations (FCM, WhatsApp) and digest aggregation scheduling remain custom.

### 2.6 Contributions / Moderation (~2,500 lines)

| Feature | Package Model | Can Cover? |
|---|---|---|
| Event submissions (CRUD) | `EventSubmission` (polymorphic submitter + target, state machine) | ✅ Yes |
| Moderation actions | `EventModerationAction` (actionable polymorphic, reason, reversal) | ✅ Yes |
| Moderation audit trail | `EventSubmissionLog` | ✅ Yes |
| Approval workflow | `EventApprovalRequest` (approvable polymorphic, assigned_to, status) | ✅ Yes |
| Submission attachments | `EventSubmissionAttachment` | ✅ Yes |
| Moderation workflow contract | `EventModerationWorkflow` + `DefaultEventModerationWorkflow` | ✅ Yes |
| Non-event submissions | `EventSubmission.target` is polymorphic — all entity types | ✅ Yes |
| ContributionEntityMutationService | None | ❌ Custom |

**Verdict: ~85% coverage.** `EventSubmission` is polymorphic on both `submitter` and `target`, handling ALL entity types. Main remaining custom code is the mutation service for applying accepted data to entities.

### 2.7 Bans / Blocks (~300 lines)

| Feature | moderation Package | Can Cover? |
|---|---|---|
| Block user (any entity) | `Block` model (morphTo `blockable`) | ✅ Yes |
| Entity-level block | `Block.blockable` is polymorphic | ✅ Yes |
| Block lifecycle | `BlockStatus` enum (active/expired/lifted) + `expires_at`/`lifted_at` | ✅ Yes |
| Block reasons | `BlockReason` enum (spam, abuse, harassment, etc.) | ✅ Yes |
| Block/unblock action | `BlockEntityAction` | ✅ Yes |
| Moderation audit trail | `ModerationAction` model + `HasModerationActions` trait | ✅ Yes |

**Verdict: ~90% coverage.**

### 2.8 Tags on Non-Event Entities (~500 lines)

| Feature | Package Model | Can Cover? |
|---|---|---|
| Taxonomy system | `EventTaxonomy` + `EventTerm` + `EventClassification` | ✅ Yes — classifiable morph is generic |
| HasEventClassifications trait | Can be used on any model (not just events) | ✅ Yes |

**Verdict: ~90% coverage.** The only issue is the dependency on the `events` package for non-event entity tagging.

### 2.9 Donation Channels (~500 lines), MCP Servers (~2,000 lines), Public Livewire UI (~2,500 lines)

No package coverage. **Stay 100% custom.**

---

## 3. Comprehensive Package Comparison Table

### 3.1 ilmus360 Domain vs Package Coverage

| ilmus360 Domain | Custom Lines | Package | Lines Replaced | Coverage | Remaining Custom |
|---|---|---|---|---|---|
| Events core | ~12,000 | `events` | ~10,000 | 83% | ~2,000 (Livewire pages, donation channels, MCP, integration) |
| Institutions | ~5,000 | `events` + `membership` | ~4,000 | 80% | ~1,000 (dashboard, donation channels) |
| Speakers | ~3,000 | `events` | ~2,600 | 87% | ~400 (thin model + UI) |
| References | ~2,000 | `references` | ~1,700 | 85% | ~300 (public UI) |
| Notifications | ~3,500 | `communications` | ~3,200 | 91% | ~300 (FCM/WhatsApp channels, digest scheduler) |
| Contributions/Moderation | ~2,500 | `events` | ~1,500 | 60% | ~1,000 (mutation service) |
| Engagement (follow/bookmark/rsvp) | ~1,200 | `engagement` | ~1,000 | 83% | ~200 (integration hooks) |
| Contact + SocialMedia | ~600 | `contacting` | ~500 | 83% | ~100 (URL normalization porting) |
| Address + geography | ~1,500 | `addressing` | ~800 | 53% | ~700 (geography stays, geocoding wiring) |
| Membership claims | ~500 | `membership` | ~400 | 80% | ~100 (UI) |
| Moderation reviews | ~300 | `moderation` | ~250 | 83% | ~50 (admin UI) |
| Reports | ~2,000 | `commerce-support` | ~1,500 | 75% | ~500 (SubmitReportAction, triage) |
| Saved searches | ~500 | `commerce-support` | ~400 | 80% | ~100 (filter normalizer) |
| Membership (cross-cutting) | ~2,500 | `membership` | ~2,000 | 80% | ~500 (management UI) |
| Bans/Blocks | ~300 | `moderation` | ~250 | 83% | ~50 (admin UI) |
| Tags (non-event) | ~500 | `events` | ~400 | 80% | ~100 (bridge) |
| **Total** | **~37,400** | — | **~29,600** | **79%** | **~7,800** |

### 3.2 Remaining Custom Code Breakdown After Full Rewrite

| Custom Domain | Lines | Why |
|---|---|---|
| Public Livewire pages (index, show, submit, dashboard) | ~2,500 | Packages provide Filament admin only |
| MCP servers (admin + member) | ~2,000 | App-specific AI tools |
| ContributionEntityMutationService | ~1,000 | CRUD/mutation for all 5 entities |
| Membership management UI | ~500 | Cross-cutting member lists, invite modals |
| Donation Channels | ~500 | Islamic charity-specific |
| FCM + WhatsApp push channel implementations | ~400 | Provider channel drivers |
| Speaker thin model + UI | ~400 | No dedicated Speaker entity in packages |
| Digest scheduling | ~300 | Notification-center concern |
| Reference entity UI | ~300 | Package provides model + hierarchy |
| Integration glue (signals hooks, share outcomes) | ~300 | App-specific wiring |
| Data migration scripts | ~200 | One-time ETL |
| SavedSearch filter normalizer | ~200 | Domain-specific (geography/prayer/tag filters) |
| Filament adapters (moderation, references) | ~200 | Packages lack admin CRUD UIs |
| Ban/block management UI | ~50 | Package provides model + actions |
| Notification inbox integration | ~50 | Package provides component |
| **Total** | **~8,900** | — |

### 3.3 Custom Code Stays Comparison

| Scenario | Custom Lines | Reduction |
|---|---|---|
| Current (no packages beyond 5) | ~38,500 | — |
| Adopt 13 relevant packages | ~8,900 | ~77% |
| Commerce packages (41) | ~38,500 | 0% (not relevant) |
| **Best case** | **~8,900** | **~77%** |

---

## 4. New Packages Discovered Since Original Analysis

The original analysis was done before several packages existed. These additional packages address gaps:

| Package | Created | Addresses Gap | Original Coverage |
|---|---|---|---|
| `communications` | Jun 23 | Notifications engine | Was "no coverage" → now ~91% |
| `moderation` | Jun 24 | Ban/Block system | Was "no coverage" → now ~90% |
| `references` | Jun 24 | Reference entity model | Was "no coverage" → now ~85% |
| `membership` | Jun 25 | Membership claims, invitations | Was "stays custom" → now ~80% |
| `authz` | Jun 25 | Permission extraction | Was in filament-authz → now standalone |
| `filament-communications` | Jun 23 | Notification admin UI | Was "no UI" → now provided |
| `filament-membership` | Jun 25 | Membership admin UI | Was "no UI" → now provided |

### Packages Added Chronologically

| Date | Packages |
|---|---|
| Jun 12 | Most initial packages (events, engagement, contacting, cart, checkout, etc.) |
| Jun 12-13 | `addressing`, `filament-addressing`, `filament-commerce-support` |
| Jun 13 | `filament-feedback`, `chip`, `jnt`, `cashier-chip`, `orders`, `customers`, `affiliates`, `affiliate-network`, `filament-contacting`, `contacting` |
| Jun 23 | `communications`, `filament-communications` |
| Jun 24 | `moderation`, `references` |
| Jun 25 | `authz`, `events`, `filament-authz`, `membership` |
| Jun 27 | `tax`, `feedback`, `shipping` |
| Jun 28 | `cashier` |

---

## 5. Source vs Vendor Reconciliation Required

### 5.1 Packages Needing Sync (Source → Vendor)

| Package | Missing in Vendor | Impact |
|---|---|---|
| `commerce-support` | 6 models (AuthzScope, NotificationPreference, Permission, Report, Role, SavedSearch), 7 traits (HasLanguages, HasNotificationPreferences, HasReports, HasSavedSearches, HasCommerceTranslations, HasWebhookLifecycle), 17+ support files | **High** — models would directly replace custom Report, SavedSearch, notification preferences |
| `affiliates` | 9 actions (ApproveAffiliate, CreateAffiliate, TrackAffiliateVisit, etc.) | Low — ilmus360 uses ShareTrackingService wrapper, not raw actions |
| `signals` | 2 actions (EvaluateAlertRules, ResolveSession) | Low — not affecting current signal usage |
| `filament-signals` | 20 files (resource schemas, report pages, mutation guards) | Medium — admin UI enhancements not available |

### 5.2 Package Needing Sync (Vendor → Source)

| Package | Extra in Vendor | Impact |
|---|---|---|
| `filament-authz` | 20 extra files: Models (AuthzScope, Permission, Role), Policies, Services (EntityDiscoveryService, ImpersonateManager, PermissionKeyBuilder, WildcardPermissionResolver), Tables Actions (ImpersonateTableAction), UserResource expansion | **High** — source is stale; vendor has significant enhancements that should be backported |

---

## 6. Feature Comparison: What ilmus360 Would Gain

| Feature | Current ilmus360 | After Full Rewrite | Delta |
|---|---|---|---|
| Event CRUD | ✅ Custom | ✅ Package | Same |
| Prayer timing | ✅ Custom | ✅ EventTimeExpression | Same (different storage) |
| Gender/age/type | ✅ Custom | ✅ EventAttribute | Same (different storage) |
| Multi-occurrence | ❌ Manual child events | ✅ Native EventOccurrence | **New** |
| Ticketing/pricing | ❌ | ✅ Optional EventTicketType | **New** |
| Seating | ❌ | ✅ Optional EventSeatMap | **New** |
| Registrations | ✅ Basic | ✅ Full (participants, items, answers) | **Major upgrade** |
| Check-in | ✅ Basic | ✅ Full (audit logs, session scoped) | **Major upgrade** |
| RSVP types | ✅ Going only | ✅ Going/interested/maybe/not_going | **Upgrade** |
| Bookmark collections | ❌ | ✅ | **New** |
| Reactions | ❌ | ✅ (like/love/celebrate) | **New** |
| Subscriptions | ❌ | ✅ (follow-with-triggers) | **New** |
| Reminders | ❌ | ✅ | **New** |
| Contact verification | ❌ | ✅ (9 actions) | **New** |
| Address geocoding | ❌ | ✅ (4 actions) | **New** |
| Severity/escalation | ❌ | ✅ (Report model) | **New** |
| Saved search polymorphic | ❌ | ✅ | **New** |
| In-app notification inbox | ❌ | ✅ (Livewire component) | **New** |
| Domain events | ❌ | ✅ 28 event types + 30 engagement events | **New** |
| Ban/Block system | ❌ | ✅ (polymorphic Block model) | **New** |
| Reference hierarchy | ❌ | ✅ (jilid/juz/surah parts) | **New** |

---

## 7. Estimated Effort (Full Rewrite)

| Phase | Packages | Effort | Risk | Custom Lines Removed |
|---|---|---|---|---|
| 1 — Quick wins | engagement + contacting + membership | 3-4 weeks | Low | ~2,300 |
| 2 — Notifications | communications + filament-communications | 3-4 weeks | Medium | ~3,200 |
| 3 — Core events | events (registrations, check-in, change management) | 3-4 weeks | Medium-High | ~3,000 |
| 4 — Events full | events (core model, lifecycle, venue, series, taxonomy) | 6-8 weeks | High | ~7,000 |
| 5 — Remaining | moderation + references + commerce-support models | 2-3 weeks | Low | ~2,150 |
| 6 — Addressing | addressing (if geography blocker resolved) | 4-6 weeks | Very High | ~800 |
| **Total** | **All relevant** | **21-29 weeks** | **High** | **~29,600** |

**Not included**: UI rewrite (Filament resources, Livewire components, API controllers). Add another 4-8 weeks.

---

## 8. Key Architectural Decisions in a Rewrite

### 8.1 Core Event Model

Use `AIArmada\Events\Models\Event` with:
| Islamic Feature | Mapping |
|---|---|
| Prayer timing | `EventTimeExpression` |
| Gender restriction | `EventAttribute("gender_restriction")` |
| Age group | `EventAudience("age_group", "children")` |
| Muslim only | `EventAttribute("is_muslim_only")` |
| Event type | `EventAttribute("event_type")` |
| Key people | `EventInvolvement` + seeded `EventRole` |
| Tags/classification | `EventTaxonomy` for Domain/Discipline/Source/Issue |

### 8.2 Institution = Extended `Organization`

`Organization` model + `aiarmada/membership` + custom `HasDonationChannels` trait + custom dashboard.

### 8.3 Speaker = Thin Custom Model

~100-line model implementing `CanBeInvolvedInEvents` + `HasContactMethods` + `HasSocialProfiles` + `InteractsWithMedia` + `HasOwner`.

### 8.4 Notifications = `communications` Package

Replace 6-table custom engine with 16-model package. Keep only FCM/WhatsApp channel implementations + digest scheduler.

### 8.5 Policy: Don't Adopt Addressing

Geography int→UUID migration is still disproportionate. Geocoding actions useful but can add without replacing geography.

---

## 9. Current Adoption Truth

### 9.1 Actually Installed (5 packages)

| Package | Purpose |
|---|---|
| `aiarmada/affiliates` | Share tracking & attribution |
| `aiarmada/signals` | Product telemetry/analytics |
| `aiarmada/filament-signals` | Admin analytics dashboard |
| `aiarmada/filament-authz` | Scoped permissions admin UI |
| `aiarmada/commerce-support` | Foundation helpers (HasOwner, middleware, targeting — **no Eloquent models in installed version**) |

### 9.2 Built in Source, NOT Adopted (13 packages relevant to ilmus360)

All source exists at `~/herd/commerce/packages/` with full models, migrations, actions, events, contracts. None published or integrated.

| Package | What It Would Replace | Source Completeness |
|---|---|---|
| `events` | Event, Venue, Series, Registration, Checkin, Submission, Moderation, Taxonomy | 46 models, 35 actions, 70 migrations |
| `engagement` | Follow, Bookmark, RSVP pivots + Reactions, Reminders, Subscriptions | 11 models, 15 traits, 30 events |
| `contacting` | Contact + SocialMedia models, verification | 3 models, 10 actions |
| `addressing` | Address + geography system | 5 models, 6 actions |
| `communications` | Full notification engine + in-app inbox | 16 models, 30 actions |
| `membership` | MembershipClaim, invitations, roles | 2 models, 10 actions |
| `moderation` | ModerationReview, Ban/Block system | 3 models, 2 actions |
| `references` | Reference model + hierarchy | 2 models, 1 action |
| `filament-events` | Admin UI for events | Full |
| `filament-engagement` | Admin UI for engagement | Full |
| `filament-contacting` | Admin UI for contacts | Full |
| `filament-addressing` | Admin UI for addresses | Full |
| `filament-communications` | Admin UI for communication logs | Full |
| `filament-membership` | Admin UI for membership | Full |

### 9.3 Decision Record

| Decision | Status |
|---|---|
| **Adopted** | affiliates, signals, filament-signals, filament-authz, commerce-support (foundation only) |
| **Deferred indefinitely** | events, engagement, contacting, addressing, membership, moderation, references, communications, filament-* UIs |
| **Never (no package)** | Donation Channels, MCP Servers, Public Livewire pages |
| **Needs reconciliation** | commerce-support (publish source models to vendor), filament-authz (backport vendor to source), affiliates + signals + filament-signals (sync missing files) |
