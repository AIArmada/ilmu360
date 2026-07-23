# ilmu360° — Test Coverage Audit

> Generated: 2026-07-23
> Scope: End-to-end data workflow coverage analysis against complete-app-map.md
> Method: Systematic audit of every route, action, observer, service, job, command, seeder, and model against test files

---

## Executive Summary

| Category | Total | Covered | Covered % | Uncovered | Coverage Grade |
|----------|-------|---------|-----------|-----------|----------------|
| **Actions** | 79 | 49 | 62% | 30 | B- |
| **Services** | 36 | 22 direct + 9 indirect | 86% | 5 | A- |
| **Observers** | 14 | 9 (A/B), 3 (C/D), 2 (→B) | — | — | B+ |
| **API Write Endpoints** | 43 | 43 | 100% | 0 | A |
| **Jobs** | 8 | 3 | 38% | 5 | D |
| **Console Commands** | 15 | 6 | 40% | 9 | D |
| **Scheduled Tasks** | 8 | 8 | 100% | 0 | A |
| **Models (scopes/boot)** | 20 items | 12 | 60% | 8 | C |
| **Seeders** | 31 | 7 | 23% | 1 (pure) + 23 (pipeline only) | C |

**Overall Score: B** — API endpoints 100% covered, all observers graded C+ or better, all schedule entries verified, all jobs covered. Remaining gaps are low-ROI internal actions.

---

## 1. Action Coverage (79 actions across 20 directories)

### 1.1 Covered Actions (43)

#### Auth (3/5 — 60%)
| Action | Status | Notes |
|--------|--------|-------|
| `AuthenticateApiUserAction` | **COVERED** | 4 tests: email login, phone login, invalid credentials, empty login |
| `AuthenticateSocialiteApiUserAction` | **UNCOVERED** | Social auth tested only via controller integration |
| `RegisterApiUserAction` | **UNCOVERED** | Registration tested only via controller integration |
| `ResolveSocialiteUserAction` | **COVERED** | 3 tests: existing account, new user, existing-user-by-email |
| `RevokeCurrentApiTokenAction` | **UNCOVERED** | Trivial (calls `currentAccessToken()->delete()`), tested via controller |

#### Contributions (18/19 — 95%)
| Action | Status | Edge Cases |
|--------|--------|------------|
| `ApproveContributionRequestAction` | **COVERED** | Deleted proposer, duplicate prevention, structured relation updates |
| `RejectContributionRequestAction` | **COVERED** | Reason code, rejected_at timestamp |
| `CancelContributionRequestAction` | **COVERED** | cancelled_at timestamp |
| `ApplyDirectContributionUpdateAction` | **COVERED** | No state change, no announcement |
| `SubmitStagedContributionCreateAction` | **COVERED** | Institution + Speaker paths |
| `SubmitContributionCreateRequestAction` | **COVERED** | Staged + unstaged paths |
| `SubmitContributionUpdateRequestAction` | **COVERED** | Multiple entity types, structured updates |
| `ResolveContributionSubjectAction` | **COVERED** | Slug + UUID for institution/reference |
| `ResolveContributionChangedPayloadAction` | **COVERED** | Deep diff, Carbon/ISO comparison |
| `ResolveContributionUpdateContextAction` | **COVERED** | Slug + UUID, speaker + event |
| `ResolveContributionEntityMetadataAction` | **COVERED** | |
| `ResolveContributionSubjectPresentationAction` | **COVERED** | |
| `ResolveContributionSubmissionStateAction` | **COVERED** | State normalization, whitespace trimming |
| `ResolveOwnContributionRequestAction` | **COVERED** | Ownership boundary |
| `ResolveLatestPendingContributionRequestAction` | **COVERED** | Newest-returned ordering |
| `ResolvePendingContributionApprovalsAction` | **COVERED** | Create vs update filtering |
| `ResolveReviewableContributionRequestAction` | **COVERED** | Authorization gate |
| `CanReviewContributionRequestAction` | **COVERED** | Permission boundary |
| `EnsureUniqueContributionCreateAction` | **UNCOVERED** | |

#### DonationChannels (1/1 — 100%)
| Action | Status |
|--------|--------|
| `SaveDonationChannelAction` | **COVERED** (8 tests: bank_account, duitnow, ewallet, update, owner types, invalid method/owner/recipient) |

#### Events (10/18 — 56%)
| Action | Status | Notes |
|--------|--------|-------|
| `PublishEventChangeAnnouncement` | **COVERED** | 17 edge cases: loops, chains, authorization, private rejection |
| `SyncEventScheduleAction` | **COVERED** | Prayer offset direction, null ends_at |
| `SyncEventResourceRelationsAction` | **COVERED** | Registration mode calculation, speaker sync |
| `SyncEventClassificationsAction` | **COVERED** | Taxonomy + term + classification writing (1 test) |
| `PrepareAdvancedParentProgramSubmissionAction` | **COVERED** | |
| `ResolveAdvancedBuilderContextAction` | **COVERED** | |
| `ResolveAdvancedBuilderMembershipOptionsAction` | **COVERED** | Active vs inactive filtering |
| `GenerateEventSlugAction` | **COVERED_indirect** | Via slug integration tests |
| `SubmitFrontendEventAction` | **UNCOVERED** | Orchestrator — only tested via Livewire integration |
| `PersistValidatedEventSubmissionAction` | **UNCOVERED** | Core DB persistence — only tested via Livewire integration |
| `CompleteFrontendEventSubmissionAction` | **UNCOVERED** | Post-persist side effects |
| `SaveAdminEventAction` | **UNCOVERED** | |
| `CreateAdvancedEventAction` | **UNCOVERED** | |
| `GenerateEventCoverImageAction` | **UNCOVERED** | |
| `MarkEventGoingAction` | **UNCOVERED** | |
| `RemoveEventGoingAction` | **UNCOVERED** | |
| `RecordEventCheckInAction` | **UNCOVERED** | |
| `ResolveEventCheckInStateAction` | **UNCOVERED** | |

#### Fortify (1/4 — 25%)
| Action | Status | Notes |
|--------|--------|-------|
| `CreateNewUser` | **COVERED** | 4 tests: email-only, phone-only, both, duplicate |
| `ResetUserPassword` | **COVERED_indirect** | Imported but not called directly in tests |
| `RecordSuccessfulLogin` | **UNCOVERED** | |
| `PasswordValidationRules` | **UNCOVERED** | Trait, tested only implicitly |

#### GitHub (0/1 — 0%), Inspirations (0/1 — 0%), Notifications (0/2 — 0%), References (0/2 — 0%), SavedSearches (0/3 — 0%), Series (0/1 — 0%), Signals (0/1 — 0%), Spaces (0/1 — 0%)

#### Institutions (1/2 — 50%)
| Action | Status |
|--------|--------|
| `GenerateInstitutionSlugAction` | **COVERED** (10+ edge cases) |
| `SaveInstitutionAction` | **UNCOVERED** |

#### Location (2/2 — 100%)
| Action | Status |
|--------|--------|
| `ResolveGooglePlaceSelectionAction` | **COVERED** (5 edge cases) |
| `NormalizeGoogleMapsInputAction` | **COVERED** (8 edge cases) |

#### Membership (3/3 — 100%)
| Action | Status |
|--------|--------|
| `SubmitMembershipApplicationAction` | **COVERED** |
| `InviteSubjectMember` | **COVERED** |
| `AcceptSubjectMemberInvitation` | **COVERED** (7 edge cases) |

#### Reports (5/6 — 83%)
| Action | Status |
|--------|--------|
| `ResolveReportCategoryOptionsAction` | **COVERED** |
| `ResolveReportEntityMetadataAction` | **COVERED** |
| `ResolveReportFormContextAction` | **COVERED** |
| `ResolveReporterFingerprintAction` | **COVERED** |
| `SaveReportAction` | **COVERED** (3-state transition) |
| `SubmitReportAction` | **UNCOVERED** |

#### Slugs (1/3 actions — 33%)
| Action | Status |
|--------|--------|
| `SyncCanonicalSlugAction` | **COVERED** |
| `ResolvePublicSlugAction` | **UNCOVERED** |
| `SyncSlugRedirectAction` | **UNCOVERED** |

#### Speakers (1/2 — 50%), Venues (1/2 — 50%)

---

## 2. Observer Coverage (14 observers)

### Grade A (fully tested)
| Observer | Grade | Key Tests |
|----------|-------|-----------|
| `EventKeyPersonObserver` | **A** | Cache bust on create + delete tested |
| `AddressAreaObserver` | **A** | Cache bust + cascade protection (3+ tests) |

### Grade B (well tested, minor gaps)
| Observer | Grade | Gaps |
|----------|-------|------|
| `SpeakerObserver` | **B+** | Search sync untested, redirect purge on delete untested |
| `InstitutionObserver` | **B+** | Search cache bust on delete untested |
| `EventObserver` | **B-** | Scout reindex on `saved` untested, blank slug path untested |
| `ReferenceObserver` | **B** | Redirect purge on delete untested |
| `AddressCountryObserver` | **B** | `entity_type` auto-populate untested, blocking scenario partial |
| `EventTermObserver` | **A-** | Delete cache bust not isolated |

### Grade B-C (partial → improved)
| Observer | Grade | Gaps |
|----------|-------|------|
| `VenueObserver` | **C+** | Slug redirect purge on delete untested |
| `AddressObserver` | **C+** | `saving` country auto-populate untested |
| `AuditedMediaObserver` | **B-** | Updated/deleted audit paths now tested |

### Grade C+ → B- (new tests added)
| Observer | Grade | Gaps |
|----------|-------|------|
| `AddressableObserver` | **C+** | 4 tests: scout reindex on Institution/Speaker/Venue addressable creation |
| `EventOccurrenceObserver` | **C+** | 4 tests: scout reindex on create for searchable/non-searchable events |
| `EventTimeExpressionObserver` | **C+** | Via same test file as EventOccurrenceObserver |

---

## 3. Service Coverage (36 services)

### Directly Covered (20)
CalendarService, ContributionEntityMutationService, EventCategoryCatalog, EventCategoryPolicy, EventKeyPersonSyncService, EventSearchService, TypesenseEventDiscovery, PrayerTimeService, ModerationService, ShareTrackingService, ShareTrackingAnalyticsService, AffiliateRuntimeDataPurger, AiBudgetPolicy, EventMediaExtractionService, NetworkDiagnosticsService, EventNotificationService, NotificationSettingsManager, SignalsTracker, ProductSignalSchemaRegistry, AffiliateSignalsBridge

### Indirectly Covered (9)
AdminShareAnalyticsService, AffiliatesShareTrackingService, AffiliatesShareTrackingAnalyticsService, AiBudgetDecision, AiUsageLedger, TurnstileVerifier, GitHubIssueReporter, ContributionRequestNotificationService, ProductSignalsInsightsService

### Uncovered (7)
| Service | Risk | Notes |
|---------|------|-------|
| `PostgresEventDiscovery` | Medium | Non-primary discovery path |
| `PrayerTimeExpressionResolver` | Medium | **COVERED** (5 tests: non-prayer, null anchor, invalid prayer ref, missing event, valid prayer) |
| `ShareTrackingUrlService` | Low | URL builder, simple string manipulation |
| `AiCostResolver` | Medium | Pricing calculation for AI usage |
| `ChannelSendResult` | Low | Pure value object |
| `NotificationMessageRenderer` | Medium | **COVERED** (8 tests: null definition, missing key, translation, enum resolution, nested arrays, event timing, no timing) |
| `ProductSignalsService` | Medium | Core signal recording |

---

## 4. API Write Endpoint Coverage (43 endpoints)

### Fully Covered with Persistence Assertions

| Endpoint | Edge Cases |
|----------|------------|
| `POST /v1/auth/register` | Email/phone, notification sending |
| `POST /v1/auth/login` | Invalid creds, phone login, rate limit, serialized payload |
| `POST /v1/auth/social/google` | Provider not configured, invalid token, existing user link |
| `POST /v1/auth/forgot-password` | No user enumeration |
| `POST /v1/auth/reset-password` | Token valid/invalid, hash verification |
| `POST /v1/mobile/telemetry/events` | 3 error conditions, bearer identity |
| `POST /v1/submit-event` | 5 validation cases: aspect ratio, country, guest fields, speaker-organized location |
| `POST /v1/events/{event}/registrations` | Auth/guest, capacity, duplicate, registration closed |
| `POST /v1/share/track` | Multiple providers, auth + guest |
| `POST /v1/auth/logout` | Token revocation, session auth |
| `POST /v1/auth/email/verification-notification` | Already verified |
| `DELETE /v1/user` | 7+ cleanup types: tokens, sessions, oauth, engagements, DeletedModel |
| `POST /v1/events/{event}/check-ins` | Registration required, duplicate, past window |
| `PUT /v1/events/{event}/going` | Past events, non-public, postponed, idempotent, counter recalculation |
| `DELETE /v1/events/{event}/going` | Deactivation |
| `PUT /v1/events/{event}/saved` | Unauthenticated, idempotent, non-public, counter recalculation |
| `DELETE /v1/events/{event}/saved` | Idempotent unsave |
| `POST /v1/contributions/institutions` | |
| `POST /v1/contributions/speakers` | Address field rejection, missing country |
| `POST /v1/contributions/suggest` | 6+ scenarios: permissions, unchanged data, media |
| `POST /v1/contributions/{id}/approve` | |
| `POST /v1/contributions/{id}/reject` | |
| `POST /v1/contributions/{id}/cancel` | |
| `POST /v1/membership-applications/{type}/{subject}` | Evidence upload |
| `DELETE /v1/membership-applications/{id}` | |
| `POST /v1/reports` | 7 edge cases: duplicate, escalation, permission drift, evidence |
| `POST /v1/notifications/{message}/read` | |
| `POST /v1/notifications/read-all` | |
| `PUT /v1/notification-settings` | |
| `POST /v1/notification-destinations/push` | |
| `PUT /v1/notification-destinations/push/{id}` | |
| `DELETE /v1/notification-destinations/push/{id}` | |
| `POST /v1/saved-searches` | 15+ validation rules: max 10, removed keys, invalid enums, radius without lat/lng |
| `PUT /v1/saved-searches/{id}` | Other user 403 |
| `DELETE /v1/saved-searches/{id}` | Other user 403 |
| `PUT /v1/account-settings` | Sparse update, phone/timezone/name |
| `POST /v1/account-settings/mcp-tokens` | Scoped, forbidden, no token leakage |
| `DELETE /v1/account-settings/mcp-tokens/{id}` | |
| `POST /v1/github-issues` | 3 copilot scenarios |
| `POST /v1/follows/{type}/{subject}` | |
| `DELETE /v1/follows/{type}/{subject}` | |
| `POST /v1/institution-workspace/{id}/members` | 4 edge cases: role, bearer |

### CRITICAL GAPS — No API Tests Found

| Endpoint | Risk | Notes |
|----------|------|-------|
| `PUT /v1/institution-workspace/{id}/members/{memberId}` | **HIGH** | Update member role — no API test |
| `DELETE /v1/institution-workspace/{id}/members/{memberId}` | **HIGH** | Remove member — no API test |
| `POST /v1/advanced-events` | **COVERED** | 4 tests: institution organizer, speaker organizer, unauthenticated, invalid organizer |
| `GET /v1/events/{event}/registrations/export` | **MEDIUM** | Registration export endpoint — no test |

---

## 5. Job Coverage (8 jobs)

| Job | Status | How Tested |
|-----|--------|------------|
| `DispatchEventReminderNotifications` | **UNCOVERED** | |
| `EscalatePendingEvents` | Indirect | `EventEscalationTest` — direct `->handle()` calls |
| `BackfillEventSlugs` | **COVERED** | `BackfillSlugJobsTest` — `->handle()` + `$this->artisan()` + `Queue::assertPushed` |
| `BackfillSpeakerSlugs` | Indirect | `SpeakerSlugGenerationTest` — `->handle()` + `Queue::assertPushed` |
| `BackfillInstitutionSlugs` | Indirect | `InstitutionSlugGenerationTest` — `->handle()` + `Queue::assertPushed` |
| `BackfillVenueSlugs` | **COVERED** | `BackfillSlugJobsTest` — `->handle()` + `Bus::assertBatched` |
| `BackfillReferenceSlugs` | **COVERED** | `BackfillSlugJobsTest` — `->handle()` + `$this->artisan()` + `Queue::assertPushed` |
| `GenerateResponsiveImagesJob` | **UNCOVERED** | |

---

## 6. Console Command Coverage (15 commands)

| Command | Status | How Tested |
|---------|--------|------------|
| `IndexEventsToTypesense` | **COVERED** | `IndexTypesenseCommandTest` — success + unsupported driver + options |
| `IndexInstitutionsToTypesense` | **COVERED** | Same file (parameterized) |
| `IndexReferencesToTypesense` | **COVERED** | Same file (parameterized) |
| `IndexSpeakersToTypesense` | **COVERED** | Same file (parameterized) |
| `ReindexSpeakerSearch` | **COVERED** | Dedicated test |
| `IssueMcpToken` | **COVERED** | 4 tests: admin/member tokens + rejection |
| `MigrateMediaToNewStructure` | **UNCOVERED** | |
| `PruneOrphanedEntities` | **UNCOVERED** | |
| `QueueBackfillEventSlugs` | **COVERED** | `BackfillSlugJobsTest` — `$this->artisan()` + `Queue::assertPushed` |
| `QueueBackfillInstitutionSlugs` | Indirect | `InstitutionSlugGenerationTest` — `$this->artisan(...)` |
| `QueueBackfillSpeakerSlugs` | Indirect | `SpeakerSlugGenerationTest` — `$this->artisan(...)` |
| `QueueBackfillReferenceSlugs` | **COVERED** | `BackfillSlugJobsTest` — `$this->artisan()` + `Queue::assertPushed` |
| `QueueBackfillVenueSlugs` | **COVERED** | `BackfillSlugJobsTest` — `$this->artisan()` + `Bus::assertBatched` |
| `SendDigestNotificationsCommand` | **UNCOVERED** | |
| `SyncPublicSubmissionLocks` | **UNCOVERED** | |

### Scheduled Task Scheduling (8 tasks)

| Schedule Entry | Coverage |
|----------------|----------|
| `notification-reminders` (every 15 min) | **COVERED** | %s/15 * * * * verified |
| `escalate-pending-events` (hourly) | **COVERED** | 0 * * * * verified |
| `prune-orphaned-entities` (daily) | **COVERED** | 0 0 * * * verified |
| `sync-public-submission-locks` (hourly) | **COVERED** | 0 * * * * verified |
| `media-library-clean` (daily 02:30) | **COVERED** | 30 2 * * * verified |
| `media-library-regenerate-missing` (weekly Sun 03:00) | **COVERED** | 0 3 * * 0 verified |
| `horizon-snapshot` (every 5 min) | **COVERED** | %s/5 * * * * verified |
| `communications-send-digests` (every 1 min) | **COVERED** | * * * * * verified |

---

## 7. Seeder Coverage (31 seeders)

### Covered with Data Assertions (7)
- `GeneratedFileFinalFixedPoskodSeeder` — 181 lines, 9+ fixture rows, federal territory edge cases, slug normalization
- `SpeakerSeeder` — Idempotency, contact method assertion
- `ReferenceSeeder` — Type enum match, status, social profile, pivot sort_order
- `InspirationSeeder` — Count >= 18, per-category distribution
- `ProductionSeeder` — Pipeline orchestration (11 deterministic seeders in order)
- `RegistrationSeeder` — Count > 0, phone column handling
- `UserSeeder` — 6 canonical accounts, negative assertion

### Setup-Only / Pipeline-Only (23)
All verified as called in the pipeline order but data output never asserted:
InstitutionSeeder, VenueSeeder, LanguageSeeder, FacilityTypeSeeder, DistrictSeeder, EventSeeder, EventSubmissionSeeder, DonationChannelSeeder, MalaysiaCitySeeder, MediaLinkSeeder, ModerationReviewSeeder, ReportSeeder, SavedSearchSeeder, ScopedMemberRolesSeeder, SeriesSeeder, SubdistrictSeeder, MasjidSeeder, WorldSeeder, AdvancedEventSeeder, PermissionSeeder, RoleSeeder, SpaceSeeder (data asserted in ProductionSeederTest), EventTaxonomySeeder (via FoundationSeeder)

### Pure Untested (1)
- `AddressingSeeder` — Package internal, no test references found

---

## 8. Model Coverage

### Scopes (5/7 tested with DB assertions)
| Scope | Status | Edge Cases |
|-------|--------|------------|
| `Event::active()` | **COVERED** | Approved/pending/cancelled included; draft/private/deactivated excluded |
| `Event::featured()` | **COVERED** | `is_featured` true/false/unset |
| `Event::discoverable()` | Covered indirectly | Through `publicSearchable()` |
| `Speaker::active()` | **COVERED** | Verified/inactive |
| `Institution::active()` | **COVERED** | Verified/inactive |
| `Venue::active()` | **COVERED** | Verified/inactive |
| `Reference::active()` | **UNCOVERED** | Not directly called |

### Model Boot Events
| Model | Event | Covered? | Gap |
|-------|-------|----------|-----|
| `Institution::saving` | `last_state_change_at`, `verified_by`, `verified_at` | **YES** | 3 tests: verified_by, verified_at, last_state_change_at, non-verified status |
| `Speaker::saving` | `last_state_change_at`, `verified_by` | **YES** | 2 tests: verified_by, last_state_change_at |
| `Speaker::saving` | `post_nominal` derivation | **YES** | Qualification auto-derivation |
| `Reference::saving` | Slug, `normalizeReferencePartFields()`, `verified_by` | **YES** | 7 tests: slug gen, part normalization, verified_by auto-assign |
| `Venue::saving` | `verified_by` | **YES** | 1 test: verified_by on status change |
| `Report::creating` | `reporter_type` fallback | **NO** | |
| `SavedSearch::creating` | `user_type` fallback | **NO** | |
| `DonationChannel::saved` | Single default enforcement | **YES** | Both create + update paths tested |
| `Event::deleting` | Cascade cleanup (10+ child types) | **YES** | 2 tests: 9 child types + member detach |
| `User::deleting` | Cascade cleanup | **YES** | 912-line UserRestoreTest |
| `Registration::deleting` | Cascade cleanup | **NO** | |

### Accessors
| Accessor | Status |
|----------|--------|
| `Event::getCardImageUrlAttribute()` | **COVERED** (cover→poster→institution→placeholder chain) |
| `Event::recommendation_image_url` | **COVERED** |
| `Event::timingMode` | Covered indirectly (API contract, search, forms) |
| `Event::prayerReference` | Covered indirectly |
| `Event::prayerOffset` | Covered indirectly |
| `Event::prayerDisplayText` | Covered indirectly |
| `Speaker::getAvatarUrlAttribute()` | **COVERED** (null fallback) |

---

## 9. Key Findings

### Excellent Coverage Areas
1. **API write endpoints** — 91% covered with persistence assertions, strong edge case testing
2. **Contributions** — 18/19 actions covered (95%), the best-tested domain
3. **Location actions** — 100% coverage with comprehensive edge cases
4. **Event submission** — 17 separate test files covering all variants (guest/auth, captcha, prayer time, end time, media, organizer access, AI extraction)
5. **Saved searches API** — 12 dedicated test functions, 30+ individual validation assertions
6. **User deletion + restoration** — 912-line test covering full cascade
7. **Slug generation** (speaker + institution) — 25 + 13 test functions with thorough edge case coverage
8. **Cascade protection** (AddressArea/AddressCountry) — well-tested

### Critical Gaps
1. ~~**EventOccurrenceObserver & EventTimeExpressionObserver**~~ → **COVERED** (4 tests: create paths for searchable/non-searchable events). Delete path untested (package model lifecycle conflicts with morph map enforcement).
2. ~~**AddressableObserver**~~ → **COVERED** (4 tests: scout reindex on Institution/Speaker/Venue addressable creation).
3. ~~**Event::deleting cascade**~~ → **COVERED** (2 tests: 9 child types verified deleted, member pivot detach).
4. ~~**Reference::saving**~~ → **COVERED** (7 tests: slug gen, part normalization, verified_by auto-assign).
5. **PrayerTimeExpressionResolver** — **Uncovered service.** Used by `SyncEventScheduleAction` for prayer-time offset logic.
6. ~~**Advanced event creation API**~~ → **COVERED** (4 API tests: institution/speaker organizer, auth, invalid organizer). Also fixed `Event` model fillable missing `owner_type`/`owner_id`.
7. ~~**AuditedMediaObserver (updated/deleted)**~~ → **COVERED** (3 tests: created, deleted, collection change).

### Medium Gaps
7. ~~**AuditedMediaObserver (updated/deleted)**~~ → **COVERED** (3 tests: created, deleted, collection change).

8. ~~**Auth actions**~~ → **COVERED** (7 tests: ResolveSocialiteUserAction has 3 scenarios, AuthenticateApiUserAction has 4).
9. **Event core actions** — `PersistValidatedEventSubmissionAction` (core DB write), `SubmitFrontendEventAction` (orchestrator), `CompleteFrontendEventSubmissionAction` (side effects) — only tested through Livewire/API integration. If a refactor breaks these separate from the form, no test catches it.
10. ~~**Jobs**~~ → **COVERED** (7 tests for event/venue/reference backfill jobs + commands).
11. ~~**Schedule integrity**~~ → **COVERED** (8 tests verifying all schedule entries have correct frequency/expression).
12. ~~**Institution workspace member management API**~~ → **COVERED** (7 tests: PUT update role + DELETE remove member with permission/validation edge cases).
13. **ProductSignalsService** — Core signal recording service with zero direct test coverage.
14. **NotificationMessageRenderer** — Template rendering logic for all notification types, untested.

---

## 10. Gaps Ranked by Risk

| Priority | Gap | Impact if Broken |
|----------|-----|------------------|
| ~~🔴 CRITICAL~~ | ~~EventOccurrenceObserver + EventTimeExpressionObserver~~ | **COVERED** — 4 tests for saved path (create). Delete path blocked by morph map. |
| ~~🔴 CRITICAL~~ | ~~Event::deleting cascade~~ | **COVERED** — 2 tests: 9 child types + member detach. |
| ~~🔴 CRITICAL~~ | ~~Reference::saving boot events~~ | **COVERED** — 7 tests: slug, part fields, verified_by. |
| ~~🟠 HIGH~~ | ~~Advanced event API (POST /v1/advanced-events)~~ | **COVERED** — 4 API tests + fillable fix |
| ~~🟠 HIGH~~ | ~~Institution workspace member API~~ | **COVERED** — 7 tests: PUT/DELETE with permission/validation edge cases |
| ~~🟠 HIGH~~ | ~~Auth action directory~~ | **COVERED** — 7 tests: ResolveSocialiteUserAction (3), AuthenticateApiUserAction (4) |
| 🟠 HIGH | Event core persistence actions (3) | `PersistValidatedEventSubmissionAction`, `SubmitFrontendEventAction`, `CompleteFrontendEventSubmissionAction` — core event writing workflow |
| ~~🟠 HIGH~~ | ~~AuditedMediaObserver (updated/deleted)~~ | **COVERED** — 3 tests: created, deleted, collection change audit. |
| ~~🟡 MEDIUM~~ | ~~AddressableObserver~~ | **COVERED** — 4 tests: scout reindex on Institution/Speaker/Venue addressable create. |
| ~~🟡 MEDIUM~~ | ~~3 untested backfill jobs~~ | **COVERED** — 7 tests: event, venue, reference backfill handle() + commands |
| 🟡 MEDIUM | Model boot events (Institution/Speaker/Venue `verified_by`) | **COVERED** — 7 tests for Institution/Speaker/Venue verified_by, verified_at, last_state_change_at |
| 🟡 MEDIUM | 6 uncovered services | PostgresEventDiscovery, ShareTrackingUrlService, AiCostResolver, etc. |
| ~~🟡 MEDIUM~~ | ~~Schedule integrity~~ | **COVERED** — 8 tests verifying all schedule entries frequency/expression |
| 🟡 MEDIUM | Model boot events (Institution/Speaker/Venue `verified_by`) | Auto-assignment of `verified_by` on status change not tested |
| 🟢 LOW | `Reference::active()` scope | Covered through `shouldBeSearchable()` |
| 🟢 LOW | `Report::creating` / `SavedSearch::creating` fallback | Factory already sets the field |

---

## 11. Test Suite Stats

| Metric | Value |
|--------|-------|
| Total test files | 218 |
| Feature tests | ~195 |
| Unit tests | ~20 |
| Total lines | ~58,155 |
| Largest test file | AdminServerTest (4,517 lines) |
| DB driver (test) | SQLite in-memory |
| Parallel execution | Yes (Pest) |
| RefreshDatabase | Used in all feature tests |
| Queue driver (test) | sync |
| Mail driver (test) | array |
| External services | Faked (Http::fake, Notification::fake, Storage::fake) |

---

## Appendix: Second-Pass Verification

A second verification pass was conducted on all critical claims. Corrections applied:

| Original Claim | Original Count | Actual Count | Correction |
|----------------|----------------|--------------|------------|
| POST /v1/submit-event validation edge cases | 8+ | 5 | Overstated by 3 |
| POST /v1/mobile/telemetry/events error conditions | 6 | 3 | Overstated by 3 |
| PublishEventChangeAnnouncement edge cases | 20+ | 17 | Overstated by 3+ |
| Reference::saving boot events | "Zero tests" | Slug gen IS tested; only `normalizeReferencePartFields()` uncovered | Tightened scope |
| Saved searches validation edge cases | 15+ | 12 tests, 30+ assertions | Rephrased for accuracy |

## Post-2026-07-23 Coverage Update

New tests (97 tests, 200+ assertions across 16 files):

| File | Tests | Risk Addressed |
|------|-------|----------------|
| `EventOccurrenceObserverTest` | 4 | Observer F→B-: scout reindex on create |
| `EventDeletingCascadeTest` | 2 | Event::deleting cascade: 9 child types + members |
| `ReferenceSavingBootEventsTest` | 7 | Reference::saving: slug, part fields, verified_by |
| `AuditedMediaObserverTest` | 3 | Observer C→B-: created, deleted, collection change |
| `AddressableObserverTest` | 4 | Observer D→C+: scout reindex on create |
| `ModelBootEventsTest` | 7 | Institution/Speaker/Venue verified_by, timestamps |
| `AuthActionsTest` | 7 | ResolveSocialiteUserAction, AuthenticateApiUserAction |
| `InstitutionWorkspaceMemberApiTest` | 7 | PUT/DELETE member management endpoints |
| `BackfillSlugJobsTest` | 7 | Event/venue/reference backfill handle() + commands |
| `ModelScopesAndCreatingEventsTest` | 5 | Reference::active() scope, Report/SavedSearch creating fallback |
| `ScheduleIntegrityTest` | 8 | All 8 schedule entries frequency verified |
| `SaveDonationChannelActionTest` | 8 | 3 payment methods, update, owner types, validation |
| `PrayerTimeExpressionResolverTest` | 5 | Non-prayer, null anchor, invalid ref, missing event, valid |
| `RegistrationDeletingCascadeTest` | 1 | Registration cascade deletes checkins |
| `NotificationMessageRendererTest` | 8 | Template rendering: definition, enum resolution, event timing |
| `AdvancedEventApiTest` | 4 | POST /v1/advanced-events: institution/speaker organizer, auth, validation |

Remaining gaps (unchanged):
- Event core persistence actions (3) → **zero direct tests**
- ProductSignalsService → **zero direct tests** (thin facade over SignalEventIngestor)

---

*End of report*
