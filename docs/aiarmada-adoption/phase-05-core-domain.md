# Phase 5 - Core Domain Rewrite

State: `Assessed`

## Objective

Replace event, venue, series, registration, check-in, submission, reference, taxonomy, engagement, and moderation persistence with package-owned domains.

The target event domain must support more than the current free-only app model: free walk-ins, optional RSVP, free ticketed events, paid tickets, mixed free/paid ticket types, passes, capacity, waitlists, seating, and check-in.

## Target Packages

| Package | Status | Filament Adapter | Notes |
|---|---|---|---|
| `events` | Exists, installed | `filament-events` exists | Full event ecosystem |
| `engagement` | Exists, installed | `filament-engagement` exists | 8 engagement types |
| `references` | Exists, installed | `filament-references` **does not exist** | Keep app Filament resources |
| `moderation` | Exists, installed | `filament-moderation` **does not exist** | Keep app Filament resources |
| `feedback` | **Does not exist** | N/A | Reports remain app-owned |

## Package Assessment

### WP-12 — Events (71 models, 70 migrations)

**Package provides a complete event ecosystem:**

| Domain Area | Package Model(s) | Coverage |
|---|---|---|
| Core event | `Event`, `EventOccurrence`, `EventSession` | Full: status state machine, visibility, delivery mode (physical/online/hybrid) |
| Venues | `Venue`, `VenueSpace`, `VenueFacility`, `EventLocation` | Rich: hierarchical venues, spaces with capacity, facilities, polymorphic location linking |
| Series | `EventSeries`, `EventSeriesItem`, `EventSeriesRule`, `EventRecurrenceRule` | Full: static/dynamic series, recurrence with RRULE support |
| Ticketing | `EventTicketType`, `EventTicketTypeComponent`, `EventTicketTypeProduct`, `EventPass` | **Comprehensive**: pricing modes (`Free`/`Paid`/`Mixed`), sales windows, inventory, bundle products, seating |
| Registration | `EventRegistration`, `EventRegistrationParticipant`, `EventRegistrationItem`, `EventRegistrationAnswer` | **Full state machine** (11 states), bundle registration, order integration |
| Check-in | `EventAttendance`, `EventAttendanceLog`, `EventWalkIn`, `EventHeadcountLog` | Full: per-participant check-in, walk-ins, headcount logging |
| Seating | `EventSeatMap`, `EventSeatSection`, `EventSeat`, `EventSeatHold`, `EventSeatAllocation` | Full: seat maps, sections, holds during cart, permanent allocations |
| Taxonomies | `EventTaxonomy`, `EventTerm`, `EventClassification` | Full: hierarchical vocabularies, classification on event/occurrence/session |
| Submissions | `EventSubmission`, `EventSubmissionLog`, `EventSubmissionAttachment`, `EventApprovalRequest` | **State machine** (7 states), polymorphic submissions |
| Change mgmt | `EventChangeLog`, `EventUpdate`, `EventNotificationBatch` | Full change tracking + public updates + notification delivery |
| People & roles | `EventInvolvement`, `EventRole`, `EventManagementAssignment` | Full: involvements (speaker/organizer/host), management assignments |
| Content | `EventMaterial`, `EventReference`, `EventLink`, `EventMedia`, `EventLanguage` | Full: materials, references, links, media, languages |
| Audiences | `EventAudience`, `EventAudienceProfile`, `EventEligibilityRule` | Full: audience typing, eligibility rules |
| Scheduling | `EventTimeExpression` | Supports prayer-based scheduling |
| Itineraries | `EventItinerary`, `EventItineraryItem` | Full: multi-session itineraries |

**Filament integration:**
- 9 Filament resources (Event, Occurrence, Session, Venue, Registration, Participant, TicketType, Attendance, ChangeLog)
- 5 Pages (CheckInConsole, NotificationCenter, ApprovalQueue, EventPublicPreview, SeatMapManager)
- 1 Widget (EventStatsWidget)

**Key differences from app:**
- App uses **Spatie Tags** (`Tag` with `TagType` enum); package uses its own **taxonomy system** (`EventTaxonomy`/`EventTerm`/`EventClassification`). The app's tag system is richer with status/moderation, so mapping is needed.
- Package has its own Venue model — app's Venue would be replaced.
- Package has its own EventSubmission — app's EventSubmission would be replaced, but the app's submission has additional fields (contact info, contribution data) that need to map to `submission_data` JSON.
- Package has NO built-in `HasMedia` or media collections on Event model — app's Event has rich media (cover, poster, gallery, conversions). Package would need to add media via trait or app extension.
- Package Event model uses `owner_type/owner_id` polymorphic ownership — app's Event directly belongs to User/Institution. This is a different ownership model.

### WP-13 — Engagement (10 models, 14 traits, 30 events)

**Package provides 8 engagement types:**

| Type | Model(s) | Polymorphism | Status Enum | Key Traits |
|---|---|---|---|---|
| Follow | `Follow` | `follower`/`followable` | Active/Muted/Unfollowed/Blocked | `CanFollow`, `HasFollowers` |
| Bookmark | `Bookmark`, `BookmarkCollection` | `bookmarker`/`bookmarkable` | Active/Removed/Archived | `CanBookmark`, `HasBookmarks` |
| Reaction | `Reaction` | `reactor`/`reactable` | Active/Removed | `CanReact`, `HasReactions` |
| RSVP | `Response` | `responder`/`respondable` | Active/Changed/Cancelled/Expired | `CanRespond`, `HasResponses` |
| Reminder | `Reminder` | `remindable`/`recipient` | Pending/Scheduled/Sent/Cancelled/Failed/Expired | `CanSetReminders`, `HasReminders` |
| Share | `Share` | `sharer`/`shareable` | Created/Shared/Revoked/Expired/Failed | `CanShare`, `HasShares` |
| Subscribe | `Subscription` | `subscriber`/`subscribable` | Active/Muted/Unsubscribed/Expired | `CanSubscribe`, `HasSubscriptions` |
| Counter | `EngagementCounter` | `subject` | N/A (integer counter) | N/A |

**Filament integration:**
- 7 Filament resources (Follow, Bookmark, BookmarkCollection, Reaction, Response, Reminder, Subscription)
- 8 pre-built Actions (Follow/Unfollow/Bookmark/React/Respond/SetReminder/Subscribe)
- 6 RelationManagers (Followers, Bookmarks, Reactions, Reminders, Responses, Subscriptions)
- 1 Widget (EngagementOverviewWidget)

**Key differences from app:**
- App has custom `Going`/`Interested`/`Save`/`Follow`/`Share` behavior spread across Livewire components and controllers — would cleanly replace with engagement traits
- App's saved search system uses a separate `SavedSearch` model (not an engagement type) — this would remain app-owned
- App's share tracking integrates with `affiliates` package for attribution — package's `Share` model has `metadata` JSON for this

### WP-14 — References (1 model)

**Package provides:**
- `Reference` model (UUID PK, `ReferenceType` enum: Book/Article/Thesis/Fatwa/Video/Audio/Website/Other)
- `ReferenceStatus` enum (Draft/Published/Archived)
- `ReferencePartType` enum (Jilid/Juz/Surah/Bab/Bahagian/Halaman — Islamic-specific)
- `HasReferenceParts` trait for structured part manipulation
- `GenerateReferenceSlugAction`
- Uses `spatie/laravel-sluggable`
- No Filament resources — keep app's current ReferenceResource

**Key differences from app:**
- App's `Reference` model is very similar — would be a clean replacement
- App adds media (cover image with thumb conversion), topic/issue tags, and `EventRelation` pivot — these are app extensions on top
- App's reference has language/category fields — map to package's existing fields or metadata JSON

### WP-15 — Moderation (2 models)

**Package provides:**
- `Block` model (polymorphic `blockable`, expiry, status machine)
- `ModerationAction` model (polymorphic `actionable`, type: Warn/Mute/Suspend/Ban/Approve/Reject)
- `BlockReason` enum (Spam/AbusiveContent/Harassment/Impersonation/CopyrightViolation/PolicyViolation/Other)
- `HasBlocks` and `HasModerationActions` traits
- No Filament resources — keep app's current moderation UI

**Key differences from app:**
- App has a more extensive **Report** system (`Report` model with evidence file uploads, status workflow) — the `moderation` package does NOT cover reports/feedback. Reports remain app-owned (or move to a future `feedback` package).
- App's `ModerationReview` model is similar to `ModerationAction` but includes media evidence, reviewer assignment, resolution log
- App's moderation is tightly coupled to event submissions (auto-approve tags on event approval)
- App has speaker/institution block flows that would use `Block` model

## Work Plan

### WP-12 — Events (High effort)

Major replacement of app's event domain with package models. 71 package models replace:
- `Event`, `EventSubmission`, `Series` (app models) → package equivalents
- `Venue`, `Space` (app models) → `Venue`, `VenueSpace`
- `Registration`, `RegistrationParticipant` → `EventRegistration`, `EventRegistrationParticipant`
- `Tag` system (Spatie Tags) → `EventTaxonomy`/`EventTerm`/`EventClassification`
- Custom engagement → `engagement` package (WP-13)

**Key decisions:**
1. **Tags → Taxonomies**: Map current `TagType` enum (Domain/Discipline/Source/Issue) to `EventTaxonomy` codes. Tag status (pending/verified) maps to `EventTerm.is_active`. This is a significant behavioral change — the current app's tag moderation auto-verifies on event approval.
2. **Media**: Package Event has no media collections. App Event has cover/poster/gallery with conversions. Add media trait to package Event or extend via app.
3. **Ownership**: Package uses polymorphic `owner_type/owner_id`. App's Event belongs to User via `creator_id` and Institution via `institution_id`. Need to map.
4. **Key people**: App has `EventKeyPerson` model. Package has `EventInvolvement` with roles. Map app's PersonType enum to package roles.
5. **Pricing migration**: App is free-only. Fresh schema must support free/paid/mixed from the start. No data migration — new schema only.
6. **Filament**: Replace app's `EventResource`, `VenueResource`, `SeriesResource`, `RegistrationResource` with package's 9 Filament resources. Keep app's custom public Livewire pages.

### WP-13 — Engagement (Medium effort)

Replace app's custom engagement code with package traits.

**Changes:**
- Add `CanFollow`/`HasFollowers` to User/Event/Institution/Speaker
- Add `CanRespond`/`HasResponses` for RSVP/Going flows
- Add `CanBookmark`/`HasBookmarks` for Saved events
- Add `CanShare`/`HasShares` for share tracking (wire into affiliates)
- Add `CanSetReminders`/`HasReminders` for event reminders
- Register `FilamentEngagementPlugin` on admin panels
- Remove custom `app/Services/ShareTracking/*`, `app/Livewire/Partials/GoingButton.php`, etc.

**App-owned engagement that stays:**
- `SavedSearch` model (not an engagement type)
- Share attribution analytics (`AffiliateSignalsBridge`)
- Share URL generation (keep app's custom flow)

### WP-14 — References (Low effort)

**Changes:**
- Replace `App\Models\Reference` with `AIArmada\References\Models\Reference` (very similar schema)
- Keep app's media collections (cover, thumb conversion) as app extension
- Keep app's `EventRelation` pivot (reference-to-event linking)
- Keep app's Filament `ReferenceResource` (no package Filament available)
- Keep app's topic/issue tagging via package taxonomies or metadata JSON

### WP-15 — Moderation (Medium effort)

**Changes:**
- Replace `App\Models\Block` with package `Block` model + `HasBlocks` trait on User/Speaker
- Replace `App\Models\ModerationReview` with package `ModerationAction` model + `HasModerationActions` trait
- Keep `App\Models\Report` as app-owned (evidence files, submissions — no package equivalent)
- Wire `ModerationAction` into event submission approval flow
- Keep app's Filament `ModerationQueue` page and report resources

## Verification

```bash
vendor/bin/pest --parallel --compact --filter=Event
vendor/bin/pest --parallel --compact --filter=Registration
vendor/bin/pest --parallel --compact --filter=Ticket
vendor/bin/pest --parallel --compact --filter=WalkIn
vendor/bin/pest --parallel --compact --filter=Engagement
vendor/bin/pest --parallel --compact --filter=Reference
vendor/bin/pest --parallel --compact --filter=Moderation
vendor/bin/phpstan analyse --ansi
```

## Exit Criteria

- Package models own core event/reference/engagement/moderation data.
- Event workflows cover free walk-ins, free registration/tickets, paid tickets, mixed ticket types, passes, capacity, and check-in.
- Public and admin workflows pass against package-backed models.
- No legacy app domain models remain for replaced areas.
- Tags are replaced by package taxonomies or app maintained as a separate taxonomy.
- Reports remain app-owned (no `feedback` package exists).

## Stop And Re-plan Triggers

- Package event model needs ilmu360-only fields instead of generic attributes/seams.
- The app begins rebuilding a free-only event abstraction instead of exposing package participation/ticketing primitives.
- Public search/API/MCP contracts cannot be rebuilt without compatibility shims.
- Donation or Islamic presentation logic starts leaking into packages.
- Spatie Tags replacement with package taxonomies loses tag status/moderation behavior.
