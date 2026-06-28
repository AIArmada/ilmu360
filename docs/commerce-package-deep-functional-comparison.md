# Commerce Package Deep Functional Comparison

> Detailed model-by-model, field-by-field, and workflow-by-workflow comparison
> of ilmu360° custom code vs the 59 packages at `~/herd/commerce/packages/*`.

> **⚠️ ACTUAL STATUS: Purely analytical.** This document compares ilmu360's custom code against the aiarmada package source. **None of the package adoptions recommended here have been implemented.** All `App\Models\*` remain fully custom. See [§11 Current Adoption Truth](#11-current-adoption-truth) for the actual state.

> **⚠️ CORRECTION: `aiarmada/commerce-support`** (installed via Packagist) contains **no Eloquent models** — only foundation helpers, middleware, and traits. The Report, SavedSearch, NotificationPreference, Permission, and Role models discussed in §§7-9 exist only in the package source at `~/herd/commerce/packages/commerce-support/` and have not been published. The custom `App\Models\Report`, `App\Models\SavedSearch`, etc. remain the live implementations.

---

## Table of Contents

1. [Events Core Domain](#1-events-core-domain)
2. [Engagement Domain](#2-engagement-domain)
3. [Contacting Domain](#3-contacting-domain)
4. [Addressing Domain](#4-addressing-domain)
5. [Communications (Notifications) Domain](#5-communications-domain)
6. [Membership Domain](#6-membership-domain)
7. [Moderation Domain](#7-moderation-domain)
8. [References Domain](#8-references-domain)
9. [Commerce-Support Models](#9-commerce-support-models)
10. [Package Interdependencies & Source vs Vendor Status](#10-package-interdependencies)
11. [Current Adoption Truth](#11-current-adoption-truth)

---

## 1. Events Core Domain

### 1.1 Structural Comparison: Models

| Aspect | ilmus360 (custom) | `aiarmada/events` Package (source) |
|---|---|---|
| **Lines of code** | Event.php: 1,518 | 46 models in package |
| **PK strategy** | UUID (`HasUuids`) | UUID (`UsesEventUuid`) |
| **Key traits** | `HasStates`, `HasTags`, `InteractsWithMedia`, `HasLanguages`, `Searchable` | `HasOwner`, `HasEventLifecycleActions`, `AcceptsEventSubmissions`, `OwnsEvents` |
| **Fillable fields** | 40 fields (many Islamic-domain specific) | Different field set — uses EAV for custom fields |
| **Status management** | `spatie/laravel-model-states` — 6 states, 8 transitions | `EventStatus` enum (12 values) + `EventLifecyclePolicy` |
| **Total models** | ~20 (Event, EventKeyPerson, EventSubmission, ModerationReview, EventChangeAnnouncement, Registration, EventCheckin, EventSettings, EventSeries pivot, etc.) | **46 models** (Event, EventOccurrence, EventSession, EventRegistration, EventTicketType, EventPass, EventAttendance, EventChangeLog, EventSubmission, EventModerationAction, Venue, VenueSpace, Organization, EventSeries, EventSeriesItem, EventSeriesRule, EventRecurrenceRule, EventTimeExpression, EventTaxonomy, EventTerm, EventClassification, EventRole, EventInvolvement, EventUpdate, EventLink, EventMedia, EventMaterial, EventReference, EventLanguage, EventAudience, EventSearchDocument, EventReport, EventApprovalRequest, EventNotificationBatch, EventItinerary, EventSeatMap, EventSeatSection, EventSeat, EventTemplate, EventVerification, EventChangeLog, EventAvailabilityBlock, EventManagementAssignment, EventAttribute, EventAccessPolicy, etc.) |

### 1.2 Field-Level: Islamic-Domain Features

| ilmus360 Field | Package Equivalent | Mapping |
|---|---|---|
| `event_structure` (standalone/parent/child) | `EventAttribute("event_structure")` | EAV |
| `gender` (EventGenderRestriction) | `EventAttribute("gender_restriction")` | EAV |
| `age_group` (AsEnumCollection of EventAgeGroup) | `EventAudience("age_group", "children")` | Audience model |
| `children_allowed` (boolean) | `EventAttribute("children_allowed")` | EAV |
| `event_type` (AsEnumCollection of EventType) | `EventAttribute("event_type")` | EAV |
| `timing_mode` (TimingMode enum) | `EventTimeExpression.time_mode` | Time expression |
| `prayer_reference` / `prayer_offset` | `EventTimeExpression(anchor_type="prayer", anchor_code="maghrib", offset_minutes=0)` | Time expression |
| `is_muslim_only` (boolean) | `EventAttribute("is_muslim_only")` | EAV |
| `schedule_kind` / `schedule_state` | `EventOccurrence.status` | Occurrence model |

**All ilmus360-specific Islamic features can be modeled via EAV + Audience + TimeExpression + Taxonomy.**

### 1.3 Key Coverage Gaps (Package Has, ilmus360 Doesn't)

| Feature | Package Model | Benefit |
|---|---|---|
| Multi-occurrence | `EventOccurrence` | Per-occurrence dates, status, capacity |
| Session scheduling | `EventSession` | Per-occurrence agenda |
| Ticket types/pricing | `EventTicketType` + Component | Paid event support |
| Seat maps | `EventSeatMap` → Section → Seat | Reserved seating |
| Pass issuance | `EventPass` (QR, barcode) | Attendee credentials |
| Recurrence rules | `EventRecurrenceRule` | RRULE, prayer-time anchors |
| Prayer-time time expressions | `EventTimeExpression` | Time anchors relative to prayers |
| Itineraries | `EventItinerary` + Items | Day-by-day schedules |
| Domain events | 28 events (Published, Cancelled, Postponed, etc.) | Webhook/signal integration |
| Spatie Data DTOs | 17 Data classes | Typed API contracts |
| Contracts/interfaces | 36 contracts | Testable service boundaries |

### 1.4 Key Coverage Gaps (ilmus360 Has, Package Doesn't)

| ilmus360 Feature | Gap |
|---|---|
| Spatie ModelStates | Package uses enum + policy, not state machine |
| Spatie MediaLibrary (cover/poster/gallery) | Package uses `EventMedia` with file_id/url — bridge needed |
| Scout/Typesense search | Package has `EventSearchDocument` but different approach |
| Islamic key roles (Imam, Khatib, Bilal) | Can seed `EventRole` with these values |

---

## 2. Engagement Domain

### 2.1 Structural Comparison

| Aspect | ilmus360 Custom | `aiarmada/engagement` Package |
|---|---|---|
| **Follow model** | `followings` pivot (user_id, followable_type, followable_id) via `MorphToMany` | `Follow` model (follower morph, followable morph, status lifecycle: active/muted/unfollowed/blocked) |
| **Bookmark model** | `event_saves` pivot (user_id, event_id) via `BelongsToMany` | `Bookmark` model (bookmarker morph, bookmarkable morph, collections) + `BookmarkCollection` |
| **RSVP/Going model** | `event_attendees` pivot (user_id, event_id) via `BelongsToMany` | `Response` model (responder morph, respondable morph, response_type free-text: going/interested/maybe/not_going) |
| **Additional models** | None | `Reaction` (like/love/celebrate), `Reminder`, `Subscription`, `Share`, `EngagementCounter` |
| **Follow trait** | `HasFollowers` trait | `Followable` trait + `Follower` trait |
| **Bookmark trait** | None (inline on Event) | Bookmark behavior through engagement traits |
| **Events** | None | 30 domain events (FollowCreated, BookmarkCreated, ResponseCreated, etc.) |
| **Contracts** | None | `EngagementManager`, `EngagementStateResolver`, `EngagementPolicyResolver`, `EngagementCounterService` |

### 2.2 Coverage Comparison

| Capability | ilmus360 | engagement Package |
|---|---|---|
| Follow/unfollow entities | ✅ (3 pivots) | ✅ (11 models, 15 traits) |
| Bookmark/save events | ✅ (event-specific pivot) | ✅ (generic Bookmark + collections) |
| RSVP (going/interested/maybe/not_going) | ✅ (going only) | ✅ (all 4 response types) |
| Reactions (like/love/celebrate) | ❌ | ✅ |
| Reminders | ❌ | ✅ |
| Subscriptions (follow-with-triggers) | ❌ | ✅ |
| Share outcomes | ✅ (via affiliates) | ✅ (Share model) |
| Domain events (30 types) | ❌ | ✅ |
| Engagement policies | ❌ | ✅ (contract) |
| Polymorphic user | ❌ (user_id only) | ✅ (user_type + user_id) |

---

## 3. Contacting Domain

### 3.1 Structural Comparison

| Aspect | ilmus360 | `aiarmada/contacting` Package |
|---|---|---|
| **Models** | `Contact` + `SocialMedia` (separate) | `ContactMethod` + `SocialProfile` + `ContactSnapshot` |
| **Type system** | `ContactCategory` + `ContactType` enums | `ContactMethodType` enum (16 values: phone, email, whatsapp, telegram, twitter, instagram, facebook, youtube, tiktok, linkedin, github, website, url, sms, fax, other) |
| **Traits** | None (inline relationships) | `HasContactMethods` + `HasSocialProfiles` |
| **Verification** | None | `is_verified` + `verified_at` + 9 actions (SendEmailVerification, SendMobileVerification, VerifyContactMethod, etc.) |
| **Normalization** | `SocialMediaLinkResolver` | None (would need porting) |
| **Data objects** | None | `ContactMethodData`, `EmailData`, `MobileData`, `SocialData` |

### 3.2 Coverage Comparison

| Capability | ilmus360 | contacting Package |
|---|---|---|
| Phone/email/website contacts | ✅ | ✅ (more types) |
| Social media profiles | ✅ (separate model) | ✅ (same model, 16 types) |
| Contact verification | ❌ | ✅ (9 actions) |
| Primary contact flag | ❌ (order_column only) | ✅ `is_primary` |
| Webhook contact sync | ❌ | ✅ |
| URL normalization | ✅ (`SocialMediaLinkResolver`) | ❌ (would port to observer) |

---

## 4. Addressing Domain

### 4.1 Structural Comparison

| Aspect | ilmus360 | `aiarmada/addressing` Package |
|---|---|---|
| **Address model** | `Address` (int geography FKs) | `Address` (UUID geography FKs) + `AddressSnapshot` |
| **Geography models** | `Country`, `State`, `District`, `Subdistrict`, `City` (int PKs, Nnjeim World) | `AddressCountry` (UUID PK) + `AddressArea` (UUID PK, polymorphic parent) |
| **Address trait** | `HasAddress` (morphOne, single) | `HasAddresses` (morphMany, multiple + primary) |
| **Map links** | Stored on model | Generated via accessors |
| **Geocoding** | Manual lat/lng | 4 actions: GeocodeAddress, ResolveAddressCoordinates, ReverseGeocode, ValidateAddressCoordinates |
| **Verification** | None | `is_verified` + `VerifyAddressAction` |
| **CLI commands** | None | 3 commands: addresses:geocode, addresses:verify, addresses:import-csv |
| **Data objects** | None | 6 Data classes |

### 4.2 Hard Blocker: Geography ID Types

```
ilmus360:  country_id = 132 (int, from Nnjeim World)
Package:  country_id = UUID

ilmus360:  state_id = 5 (int, from Nnjeim World)
Package:  area_id = UUID (morph to AddressArea)
```

This requires either replacing the entire geography system (5 tables, all existing data, all query filters, all API contracts) or writing an adapter layer. Estimated: 4-6 weeks.

---

## 5. Communications Domain

### 5.1 Structural Comparison

| Aspect | ilmus360 (Custom) | `aiarmada/communications` Package |
|---|---|---|
| **Models** | 6 tables: notifications, notification_settings, notification_rules, notification_destinations, notification_messages, notification_deliveries | **16 models**: Communication, CommunicationBatch, CommunicationThread, CommunicationContent, CommunicationRecipient, CommunicationDelivery, CommunicationAttempt, CommunicationEvent, CommunicationTrackingToken, CommunicationPreference, CommunicationTemplate, CommunicationTemplateVersion, CommunicationSuppression, CommunicationAttachment, CommunicationReference, NotificationInbox |
| **Actions** | Custom engine logic spread across services | **30 actions**: DispatchManagedNotification, CreateCommunication, CancelCommunication, RetryCommunication, PlanDeliveries, RecordSending/Sent/Failure, ResolveEligibility, LiftSuppression, TrackInteraction, PruneCommunicationData, etc. |
| **Enums** | Custom string-based statuses | **10 enums**: Direction, Category, Priority, Status, DeliveryStatus, SuppressionReason, RecipientRole, TemplateStatus, ThreadStatus, NotificationFamily, NotificationPriority, NotificationTrigger |
| **In-app inbox** | Custom Livewire + API | `NotificationInbox` model + `HasInbox` trait + `InboxIndex` Livewire component |
| **Contracts** | None | ContentRenderer, DestinationResolver, RecipientSnapshotResolver, etc. |
| **Digests** | Custom `DispatchNotificationDigests` job | Preferences have `digest_frequency` enum but no scheduling job |
| **Provider channels** | FCM + WhatsApp + Email | No provider channel implementations |

### 5.2 Coverage Comparison

| Capability | ilmus360 | communications Package |
|---|---|---|
| Communication record | ✅ (notifications table) | ✅ (Communication model, richer) |
| Batching | ❌ | ✅ (CommunicationBatch) |
| Threading/conversations | ❌ | ✅ (CommunicationThread) |
| Multi-channel content | ❌ (one template per notification) | ✅ (CommunicationContent per channel) |
| Delivery tracking | ✅ (notification_messages + deliveries) | ✅ (CommunicationDelivery + Attempt, richer) |
| Provider events/webhooks | ❌ | ✅ (CommunicationEvent, RecordProviderEventAction) |
| Suppression lists | ❌ | ✅ (CommunicationSuppression) |
| Tracking tokens | ❌ | ✅ (CommunicationTrackingToken) |
| Template management | ❌ | ✅ (CommunicationTemplate + Version) |
| Preferences | ✅ (notification_settings) | ✅ (CommunicationPreference, richer) |
| Eligibility resolution | ❌ (manual checks) | ✅ (ResolveCommunicationEligibilityAction) |
| In-app inbox | ✅ (custom Livewire) | ✅ (NotificationInbox + InboxIndex component) |
| FCM push | ✅ | ❌ |
| WhatsApp channel | ✅ | ❌ |
| Digest scheduling | ✅ | ❌ (has enum only) |
| Laravel Notification bridge | ❌ | ✅ (DispatchManagedNotificationAction) |

**Verdict: ~91% coverage.** Only FCM/WhatsApp channel implementations and digest scheduling job remain custom.

---

## 6. Membership Domain

### 6.1 Structural Comparison

| Aspect | ilmus360 (Custom) | `aiarmada/membership` Package |
|---|---|---|
| **Application model** | `MembershipClaim` (subject polymorphic, applicant FK, status string) | `MembershipApplication` (subject polymorphic, applicant morph, status enum, justification, meta) |
| **Invitation model** | `MemberInvitation` | `MembershipInvitation` (subject polymorphic, email, token, role, expires_at, accepted_at, revoked_at) |
| **Member model** | `Membership` | `HasMembers` trait → `belongsToMany` with pivot `role`+`joined_at` |
| **Actions** | Custom controllers | 10 actions: ApplyForMembership, ApproveMembershipApplication, RejectMembershipApplication, CancelMembershipApplication, InviteMember, AcceptInvitation, RevokeInvitation, AddMember, RemoveMember, ChangeMemberRole |
| **Member roles** | Custom string-based | `MemberRole` enum (Admin, Editor, Viewer) + Spatie role integration |
| **Contracts** | None | `MembershipApplicationNotifier`, `MembershipHook` |
| **Events** | None | 6 events: ApplicationSubmitted/Approved/Rejected/Cancelled, InvitationSent/Accepted |
| **Config** | None | `membership.php` — table names, pivot suffix, role mapping, team-scoped flag |
| **Commands** | None | `membership:sync-roles`, `authz:make-pivot` |

### 6.2 Coverage Comparison

| Capability | ilmus360 | membership Package |
|---|---|---|
| Membership application | ✅ | ✅ (richer: status enum, justification, meta) |
| Membership invitation | ✅ | ✅ (token, expiry, role, lifecycle) |
| Member roles | ✅ (custom) | ✅ (enum + Spatie integration) |
| Approve/reject applications | ✅ (custom controller) | ✅ (3 actions) |
| Invite/accept/revoke | ✅ (custom) | ✅ (3 actions) |
| Add/remove/change roles | ✅ (custom) | ✅ (3 actions) |
| Spatie role integration | ❌ | ✅ (team-scoped) |
| Notifier contracts | ❌ | ✅ (MembershipApplicationNotifier) |
| Hook contracts | ❌ | ✅ (MembershipHook for side effects) |
| Config-driven table names | ❌ | ✅ |
| CLI command for role sync | ❌ | ✅ |

---

## 7. Moderation Domain

### 7.1 Structural Comparison

| Aspect | ilmus360 (Custom) | `aiarmada/moderation` Package |
|---|---|---|
| **Block model** | None (Follow model has embedded block status) | `Block` model (blockable polymorphic, blocked_by, BlockStatus enum, expires_at, lifted_at, BlockReason enum) |
| **Moderation action model** | `ModerationReview` (event_id, moderator_id, decision, note, reason_code) | `ModerationAction` (actionable polymorphic, action_type, reason, metadata, reversal support) |
| **Traits** | None | `HasBlocks` (isBlocked(), activeBlocks(), block(), unblock()), `HasModerationActions` (morphMany, log action) |
| **Actions** | Custom | `BlockEntityAction`, `UnblockEntityAction` |
| **Block status** | Custom string | `BlockStatus` enum (active/expired/lifted) |
| **Block reasons** | Custom string | `BlockReason` enum (spam, abuse, harassment, violence, impersonation, misinformation, etc.) |

### 7.2 Coverage Comparison

| Capability | ilmus360 | moderation Package |
|---|---|---|
| Block/unblock entities | ⚠️ Partial (in Follow model) | ✅ (Dedicated Block model, polymorphic) |
| Block status lifecycle | ❌ | ✅ (active/expired/lifted + timestamps) |
| Block reasons | ❌ | ✅ (9 reasons in enum) |
| Block expiration | ❌ | ✅ (expires_at) |
| Moderation audit trail | ✅ (ModerationReview) | ✅ (ModerationAction, richer: reversible, metadata) |
| Trait for block checks | ❌ | ✅ (HasBlocks: isBlocked(), activeBlocks()) |
| Ban management UI | ❌ | ❌ (custom ~50 lines) |

---

## 8. References Domain

### 8.1 Structural Comparison

| Aspect | ilmus360 (Custom) | `aiarmada/references` Package |
|---|---|---|
| **Reference model** | Custom `Reference` | `Reference` (title, slug, author, publisher, year, isbn, url, status, parent_id hierarchy) |
| **Enums** | Custom string-based | `ReferenceType` (quran, hadith, book, article, fatwa, thesis, etc.), `ReferenceStatus` (draft, verified, rejected), `ReferencePartType` (jilid, juz, surah, chapter, verse, page) |
| **Traits** | None | `HasReferenceParts` (ref_part_type + ref_part_value hierarchy) |
| **Actions** | Custom | `GenerateReferenceSlugAction` (uses spatie/laravel-sluggable) |
| **Event→Reference linking** | Custom `event_reference` pivot | `EventReference` (in events package, polymorphic) |

### 8.2 Coverage Comparison

| Capability | ilmus360 | references Package |
|---|---|---|
| Reference entity (title, author, publisher, year) | ✅ | ✅ |
| Hierarchy (parent/child, jilid/juz/surah) | ✅ (custom) | ✅ (ReferencePartType enum + HasReferenceParts trait) |
| Slug generation | ✅ (custom) | ✅ (GenerateReferenceSlugAction) |
| Reference type | ✅ (custom) | ✅ (ReferenceType enum, 10+ types) |
| Status workflow | ✅ (custom) | ✅ (ReferenceStatus enum) |
| Event linking | ✅ (custom pivot) | ✅ (EventReference in events pkg) |
| Search/listing/detail UI | ✅ (custom) | ❌ (custom ~300 lines) |
| Claims workflow | ✅ (custom) | ✅ (via membership pkg) |

---

## 9. Commerce-Support Models

### 9.1 What's Installed vs What's in Source

| Component | Installed (vendor) | Source (`~/herd/commerce/packages/`) |
|---|---|---|
| **Models** | **None** | AuthzScope, NotificationPreference, Permission, Report, Role, SavedSearch |
| **Traits** | HasOwner, HasOwnerScopeKey, FormatsMoney, HasPaymentStatus, ValidatesConfiguration | All above + HasCommerceTranslations, HasLanguages, HasNotificationPreferences, HasReports, HasSavedSearches, HasWebhookLifecycle |
| **Actions** | 8 (health check, targeting, owner) | 8 (same) |
| **Enums** | None | NotificationCadence, NotificationChannel, ReportSeverity, ReportStatus |
| **Support files** | Middleware, targeting engine, health check | All above + AuditableModelRegistry, HealthCheckRegistry, LoggableModelRegistry, OwnerBatchRunner, OwnerContextTeamResolver, PaymentStatusNormalizer, ReadOnlyListRecords, ReadOnlyViewRecord |

### 9.2 Report Model Comparison

| Aspect | `App\Models\Report` | Source `Report` Model |
|---|---|---|
| **PK** | UUID | UUID |
| **Polymorphic entity** | `entity_type`/`entity_id` | `reportable_type`/`reportable_id` |
| **Reporter** | `reporter_id` (FK to users) | `reporter_type`/`reporter_id` (polymorphic) |
| **Handler** | `handled_by` (FK to users) | `reviewed_by_type`/`reviewed_by_id` (polymorphic) |
| **Fingerprint** | `reporter_fingerprint` (indexed) | Not present |
| **Category** | `category` (string) | `category` (string) |
| **Status** | `status` (string, default 'open') | `status` enum (ReportStatus: open/under_review/resolved/rejected/archived) |
| **Severity** | ❌ | ✅ `severity` enum (low/medium/high/critical) |
| **Escalated at** | ❌ | ✅ `escalated_at` |
| **Media** | ✅ Spatie MediaLibrary | ❌ |
| **Trait** | None | ✅ `HasReports` trait |

### 9.3 SavedSearch Model Comparison

| Aspect | `App\Models\SavedSearch` | Source `SavedSearch` Model |
|---|---|---|
| **PK** | UUID | UUID |
| **User** | `user_id` (FK) | `user_type`/`user_id` (polymorphic) |
| **Name** | `name` | `name` |
| **Query** | `query` (string) | `query` JSON |
| **Filters** | `filters` JSONB | `filters` JSON |
| **Searchable** | Not applicable | `searchable_type`/`searchable_id` polymorphic |
| **Geography** | `radius_km`, `lat`, `lng` | Not present |
| **Notify** | `notify` (string, default 'daily') | Not present |
| **Meta** | Not present | `meta` JSON |
| **Active** | Not present | `is_active` |
| **Trait** | None | `HasSavedSearches` trait |
| **Filter normalizer** | 455 lines, 25+ filter keys | Not present |

---

## 10. Package Interdependencies & Source vs Vendor Status

### 10.1 Package Dependency Graph

```
commerce-support (foundation)
  ├── HasOwner, middleware, targeting engine
  ├── Source-only: Report, SavedSearch, NotificationPreference, Permission, Role
  │
  ├── affiliates (installed, source ahead by 9 actions)
  ├── signals (installed, source ahead by 2 actions)
  │     └── filament-signals (installed, source ahead by 20 files)
  ├── authz
  │     └── filament-authz (installed, VENDOR ahead by 20 files)
  │
  ├── events (source only)
  │     ├── contacting (source only)
  │     ├── engagement (source only)
  │     └── filament-events (source only)
  │
  ├── engagement (source only)
  │     └── filament-engagement (source only)
  │
  ├── contacting (source only)
  │     └── filament-contacting (source only)
  │
  ├── addressing (source only)
  │     └── filament-addressing (source only)
  │
  ├── communications (source only)
  │     └── filament-communications (source only)
  │
  ├── membership (source only)
  │     └── filament-membership (source only)
  │
  ├── moderation (source only)
  │
  └── references (source only)
```

### 10.2 Source vs Vendor Status for Installed Packages

| Package | Installed Version | Source Version | Difference |
|---|---|---|---|
| `affiliates` | 10 actions, 28 models | 19 actions, 28 models | Source: +9 actions |
| `commerce-support` | 0 models, 6 traits, 114 files | 6 models, 13 traits, 137 files | Source: +6 models, +7 traits, +23 files |
| `signals` | 8 actions, 12 models, 75 files | 10 actions, 12 models, 87 files | Source: +2 actions, +12 files |
| `filament-authz` | 3 models, 56 files | 0 models, 36 files | **Vendor ahead**: +3 models, +20 files |
| `filament-signals` | 7 resources, 50 files | 7 resources with schemas, 70 files | Source: +20 schema files |

---

## 11. Current Adoption Truth

### 11.1 Installed (5 packages)

| Package | Purpose | Status |
|---|---|---|
| `aiarmada/affiliates` | Share tracking & attribution | Active, source ahead |
| `aiarmada/signals` | Analytics pipeline | Active, source ahead |
| `aiarmada/filament-signals` | Admin analytics dashboard | Active, source ahead |
| `aiarmada/filament-authz` | Scoped permissions UI | Active, **vendor ahead** |
| `aiarmada/commerce-support` | Foundation helpers only | Active, **source has 6 models not in vendor** |

### 11.2 Source-Only (54 packages)

All packages at `~/herd/commerce/packages/` have full source code but are not published to Packagist, added to `composer.json`, or integrated into ilmu360.

**Relevant packages (13):** events, engagement, contacting, addressing, communications, membership, moderation, references, filament-events, filament-engagement, filament-contacting, filament-addressing, filament-communications, filament-membership

**Commerce packages (41):** cart, checkout, orders, products, pricing, promotions, vouchers, shipping, inventory, customers, cashier, cashier-chip, chip, jnt, tax, growth, docs, affiliate-network, feedback, csuite, and their 19 filament-* UIs

### 11.3 Current Custom Models (All 49 Still Custom)

| Model | Status | Package That Would Replace |
|---|---|---|
| Event | ✅ Custom | events |
| Contact | ✅ Custom | contacting |
| SocialMedia | ✅ Custom | contacting |
| SavedSearch | ✅ Custom | commerce-support (source, not published) |
| Report | ✅ Custom | commerce-support (source, not published) |
| MembershipClaim | ✅ Custom | membership |
| ModerationReview | ✅ Custom | moderation |
| Reference | ✅ Custom | references |
| Address | ✅ Custom | addressing |
| Venue | ✅ Custom | events |
| Series | ✅ Custom | events |
| Registration | ✅ Custom | events |
| EventCheckin | ✅ Custom | events |
| EventSubmission | ✅ Custom | events |
| Institution | ✅ Custom | events + membership |
| Speaker | ✅ Custom | events |
| DonationChannel | ✅ Custom | No package |
| NotificationSetting + 5 more | ✅ Custom | communications |
| + 31 others | ✅ Custom | Various |

### 11.4 Reconciliation Needed

| Package | Action |
|---|---|
| `commerce-support` | Publish 6 models + 7 traits from source to vendor |
| `affiliates` | Sync 9 missing actions from source to vendor |
| `signals` | Sync 2 missing actions from source to vendor |
| `filament-signals` | Sync 20 missing files from source to vendor |
| `filament-authz` | Backport vendor's 20 extra files to source |
