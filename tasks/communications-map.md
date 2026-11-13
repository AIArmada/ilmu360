# Communications ownership map

> **Status: C1–C6 implemented.** This map records the current app/package boundary and implementation sequence. C1 removed the fake batch-completion stub and scheduled the package dispatcher. C2a moved app notification policy and preference writes into Communications. C2b moved push destination operations and account lifecycle handling. C3 moved inbox queries, snapshots, and read actions. C4 moved channel adapters, message rendering, delivery fencing, and transport registration into Communications. C5 moved Rewards transport operations behind the Communications API. C6 moved the remaining app-owned HTTP, Livewire, route, view, and notification dispatch surfaces into Communications and closed the generic admin read exposure. Package tables remain unclaimed.

## 1. Ownership boundary

`aiarmada/communications` is the canonical persistence and delivery package. The app `Features/Communications` module exists with `owns: {}`. It owns ilmu360° notification policy, preference actions, push destination operations, account lifecycle handling, inbox queries/read actions, app schedules, channel adapters, rendering, delivery fencing, transport registration, HTTP/Livewire entrypoints, and route/view composition. It does not claim package tables in `feature.json`; those remain package-owned resources. Business notification producers remain with their domain feature and call the Communications API.

`aiarmada/filament-communications` provides the vendor admin UI. `AdminPanelProvider` registers `FilamentCommunicationsPlugin`; Communications migration must preserve this registration and must not add a second app resource for the same package models.

## 2. Canonical persistence

The installed `aiarmada/communications` package owns these 17 tables and migrations:

| Group | Package tables |
|---|---|
| Batches and delivery aggregates | `communication_batches`, `communication_threads`, `communications`, `communication_recipients`, `communication_contents`, `communication_deliveries`, `communication_attempts`, `communication_events` |
| Templates | `communication_templates`, `communication_template_versions` |
| Recipient policy and destinations | `communication_preferences`, `communication_suppressions`, `notification_destinations` |
| Supporting records | `communication_attachments`, `communication_references`, `communication_tracking_tokens`, `notification_inboxes` |

The package migrations and models define these schemas. App-level direct model/query writes currently exist and are listed below; they are not a reason to claim the vendor tables in the app feature catalog. App-owned `reward_digest_slots` belongs to Rewards. Its `communication_id` correlates a reward digest slot to a package Communication; Rewards must not become an owner of Communications records.

## 3. Current app writers and read surfaces

| Data / operation | Current app writers or entrypoints | Migration owner / hold |
|---|---|---|
| Account `communication_preferences` | Communications `EnsureNotificationPreferencesAction`, `SaveNotificationPreferencesAction`, `SyncProfileNotificationSettingsAction`, `SyncSystemEmailDestinationAction`, and `UpdateNotificationLocaleAction` create/update base and family/trigger rules; `CommunicationsAccountLifecycleContributor` still snapshots/deletes/restores the base row | Communications policy writer. Preserve package schema and current account lifecycle behavior. Scoped family/trigger rows are intentionally retained on account detach and are not part of the lifecycle snapshot. |
| `notification_destinations` | Communications `SyncSystemEmailDestinationAction` syncs the verified account email; `PushDestinationManagement` handles push registration, update, removal, delivery resolution, and API deletion; `CommunicationsAccountLifecycleContributor` snapshots/deletes/restores destinations | Communications destination writer. Preserve encrypted-address PHP matching and account scoping. |
| `notification_inboxes` | `InboxChannel` delegates writes to package `DispatchInboxNotificationAction`; `NotificationInboxManagementService` returns immutable account-scoped snapshots and delegates reads to feature Actions backed by package `NotificationInboxService`; `CommunicationsAccountLifecycleContributor` snapshots/deletes/restores inbox rows | Communications inbox writer. Preserve signals and first-transition semantics. |
| `communications` / `communication_deliveries` / `communication_attempts` | Communications channel adapters use the package `CommunicationManager`, recorder actions, and models. `GuardedDeliverySend` locks delivery/communication rows and creates/completes attempts through package actions. `DeliveryFencingService` handles failed and ambiguous external outcomes. Rewards now calls `ManagedNotificationDelivery` and consumes immutable snapshots; package model access remains inside Communications. | Package remains canonical and unclaimed by app features. Rewards owns only `reward_digest_slots`; Communications owns package aggregate creation/adoption, delivery inspection/cancellation, and ambiguity settlement. |
| `communication_batches` | The retired `SendDigestNotificationsCommand` selected due rows and set `started_at`/`completed_at` without processing related communications or deliveries. No app caller of `CreateCommunicationBatchAction` or app batch producer was found. Vendor Filament exposes batch list/view pages. | C1 removed the stub. Keep the package table and admin resource canonical. Do not represent a completed batch until an actual batch workflow exists. |

The former `AccountCommunications` root adapter was deleted after its callers moved to the Communications API. No builder or package model is exposed from `Features/Communications/Api`.

The recipient read surfaces are:

- `User::preferredLocale()` and `preferredTimezone()` use the Communications API; `routeNotificationForPush()` returns immutable push-destination snapshots. Custom routing also exists for `InboxChannel` and `DigestMailChannel`.
- Identity account settings use the Communications feature API directly; the obsolete Shared settings wrapper was removed in C2b.
- API settings/catalog endpoints (`NotificationSettingsController`) and push destination endpoints (`NotificationDestinationController`).
- API inbox endpoints (`NotificationMessageController`), `NotificationBell`, and dashboard `NotificationsIndex`.
- Locale selection updates the base preference through the Communications API from `LocaleController`.

## 4. App policy and delivery integration

App policy now lives in `Features\Communications\Api` values/catalog, `NotificationSettingsService`, and the `Features\Communications\Support\Integration` adapters. Preserve the following behavior unless a later slice has explicit evidence and a product decision to change it:

- Default channels are `in_app` and `email`; `in_app` is always present. Family defaults are daily for followed content and saved-search matches, instant for event updates/reminders, registration/check-in and submissions, and weekly for sharing. Sharing starts disabled; family/trigger rules are separately persisted.
- `AccountNotificationPreferences::resolvePolicy` checks both family and trigger enablement and returns the family cadence, selected channels, quiet hours, locale, timezone, and global digest delivery time/week day. Digest time (`08:00`) and weekly day (Monday) are global config, not per-user preferences.
- `AppPreferenceResolver` maps package channels/families into app values and currently checks channel selection plus family enablement; it does not consult trigger-level rules or cadence. Do not assume package eligibility and `resolvePolicy` are equivalent.
- `AppConsentResolver` treats an active destination as channel consent. Suppression uses the package's permissive resolver default, with no app suppression adapter. `AppQuietHoursResolver` resolves the account preference timezone. Do not conflate communication-channel consent with participant-data consent.
- `SaveNotificationPreferencesAction` performs multiple preference writes and email-destination synchronization without one encompassing transaction. Preserve the existing API's partial-update merge and failure behavior until an explicit atomicity slice is planned.
- The notification timezone fallback is currently `Asia/Kuala_Lumpur`; account preferences may override it. Preserve this output during ownership-only moves and review the default separately against the app's timezone policy.

App package-contract overrides live in `Features\Communications\Support\Integration` and include `AppRecipientSnapshotResolver`, `AppConsentResolver`, `AppPreferenceResolver`, `AppQuietHoursResolver`, `ProbeSafeDestinationResolver`, and `ListSafePayloadRedactor`. Suppression and rate limiting retain the package's `PermissiveEligibilityResolver` defaults; there is no app suppression adapter. Delivery integrations include API `EnumMapper`, `GuardedDeliverySend`, entrypoint channels `InboxChannel` / `DigestMailChannel` / `PushChannel`, `NotificationMessageRenderer`, and `DeliveryFencingService`. `Features/Communications/FeatureServiceProvider` binds these contracts and registers the `communications.inbox`, `communications.digest-mail`, and `communications.push` Laravel notification drivers. Keep one binding, one driver registration, and one package event-listener registration.

Rewards resolves cadence and channels through `AccountNotificationPreferences`, builds an immutable `ManagedNotificationMessage`, and dispatches through `ManagedNotificationDelivery`. The Rewards-specific notification, ambiguity action/API/DTO, and Shared digest policy/snapshot have been removed. `AccountNotificationSettings` was removed in C2b after Identity moved directly to the Communications API.

## 5. Producers and responsibility

Communications owns delivery policy, destination resolution, channel integration, and inbox behavior. It does not own domain event decisions or message facts. Producers stay with their owning feature and use the communications API:

| Producer area | Current examples |
|---|---|
| Events / Registration | `EventNotificationService`, event status transition notifications, event and occurrence submission notifications, and `DispatchEventReminderNotifications` / `EscalatePendingEvents` |
| Contributions / Directory | `ContributionRequestNotificationService` and contribution workflow notifications |
| Feedback / Reports | Feature-owned notification classes and actions |
| Identity / Auth | Welcome, email verification, and password reset notifications |
| Membership | Member invitation notification |
| Rewards | Gratitude digest planning and slot lifecycle; notification aggregate and delivery operations cross `ManagedNotificationDelivery` |
| Discovery / Saved Searches | Saved-search match facts are read through `SavedSearchReader`; `EventNotificationService` currently renders and dispatches them from root. Settle its feature owner with Discovery/Saved Searches when moving this producer. |

Some app producers call `CommunicationManager::notify`; others call Laravel `Notification::send` directly and rely on package listeners/native capture. Preserve the exact channel selection, queueing, locale rendering, deduplication, and transaction behavior when converting those producers. Feature notification classes stay with the feature that owns their source facts.

## 6. Routes, schedules, admin, and lifecycle

| Surface | Current registration / behavior |
|---|---|
| Web inbox | `/notifications` named `notifications`, `NotificationsIndex` Livewire page |
| API inbox | `api.notifications.index`, `.read`, `.read-all` |
| API preferences | `api.notification-settings.catalog`, `.show`, `.update` |
| API push destinations | `api.notification-destinations.push.store`, `.update`, `.destroy` |
| Account preferences | Identity account settings consumes the Communications API directly; locale selection also updates the base communication preference |
| Event reminders | `DispatchEventReminderNotifications` every 15 minutes in UTC, without overlap. The job asks `EventNotificationService` to determine due reminders; keep schedule timezone and event-local due calculations distinct. |
| Rewards digest | `rewards:dispatch-gratitude-digests` hourly in UTC, without overlap; Rewards owns planning/slot locks and its slot schedule. |
| Generic scheduled communications | The Communications feature schedules package command `communications:dispatch-due` every minute in UTC with non-overlap protection. It scans due `Communication` records, queues eligible deliveries, and dispatches delivery jobs after commit. |
| Retired digest stub | C1 removed app command `communications:send-digests`, which only marked due `CommunicationBatch` rows started and completed. It never dispatched deliveries and was not the package dispatch path. |
| Admin / MCP | `FilamentCommunicationsPlugin` remains vendor-registered in `AdminPanelProvider` and registers vendor Communication, Delivery, Thread, Template, Preference, Suppression, and Batch resources. The app `AdminResourceMutationService` allowlist does not include these vendor resources. Generic Admin API serialization removes private Communications attributes from direct and related records and uses static model titles. No dedicated app Communications MCP tool exists; generic MCP reads use the same serializer. |
| Account lifecycle | The Communications provider registers `CommunicationsAccountLifecycleContributor` through Identity's `AccountLifecycleContributor` composition. It retains scoped preference rules while detaching base settings, destinations, and inbox rows. Preserve snapshot keys, ordering, and quiet restore behavior. |

Read marking records `notification.read` only on an unread-to-read transition and `notification.read_all` only when the action updates at least one row. Inbox queries exclude archived messages; unread counts also exclude archived rows by default. Keep these semantics and the existing Signal payloads.

## 7. Rewards coupling and delivery invariants

Rewards' `DispatchDueRewardDigestsAction` and `CancelRewardDigestAction` coordinate the Rewards-owned slot through `ManagedNotificationDelivery`. The root ambiguity command verifies the operator and calls the Communications API. C5 preserves:

- the existing row-lock order, transaction boundaries, after-commit behavior, and deadlock/retry behavior;
- delivery cancellation vs send races and the mail external-I/O boundary;
- an unresolved started mail attempt as UNKNOWN, never an automatic resend or guessed failure;
- reconciliation and payload-fingerprint rules, slot status transitions, and the command's evidence/role checks;
- Rewards' ownership of `reward_digest_slots` and its existing public feature API.

Current known hazards: Laravel's `withoutOverlapping()` uses a default 1,440-minute mutex; a crashed scheduler run can suppress later runs for up to a day. Current settings do not expose an urgent-override or fallback-channel control; if either is introduced later, its runtime behavior must be implemented before it is presented as a user setting. C1 removed the batch-completion stub, and C5 aligned aggregate operations with the sender's delivery-first lock order; both are covered by focused tests.

## 8. Planned slices

### C1 — own the real scheduled dispatcher (complete)

Added the Communications feature module with `owns: {}` and scheduled package command `communications:dispatch-due` every minute in UTC with non-overlap protection. Removed the app-only `SendDigestNotificationsCommand` and its obsolete schedule. Kept the vendor `communication_batches` table and admin pages; the package command processes due Communication aggregates, not batches. Schedule and command coverage passed (2 tests, 8 assertions); architecture scan passed (0 violations across 18 features). All other schedules remain registered.

### C2a — preference policy and locale boundary (complete)

Moved notification policy/catalog values and `NotificationSettingsManager` into Communications behind a scalar account API. Moved preference defaults, preference saves, profile-timezone sync, email-destination sync, and locale writes into named Communications actions. Updated `User` locale/timezone reads, settings API, event/digest policy consumers, and `LocaleController`; kept the Shared `AccountNotificationSettings` seam as the Identity-facing adapter. Preserved response keys, partial-update merging, email-verification behavior, and timezone fallbacks. Package tables remain unclaimed. Commit `9a596d84` was self-reviewed at the user's direction after Muse Ember 1.0 `max` was rejected with `model does not exist or you lack access`; no unavailable-model agreement is claimed. Focused Pest passed 88 tests / 1,215 assertions, Identity reverse-relation seams passed 3 tests / 27 assertions, architecture scan passed with 0 violations across 18 features, and `git diff --check` was clean.

The Shared settings wrapper described in C2a was removed in C2b after Identity moved to the feature API.

### C2b — push destinations and account lifecycle (complete)

Moved push register/update/delete/removal and destination resolution behind the Communications API and named actions. `User::routeNotificationForPush()` now returns immutable destination snapshots. Moved `CommunicationsAccountLifecycleContributor` into the feature and registered it from the feature provider. Removed `AccountNotificationSettings`, its root adapter, and the root push-destination query/removal helpers after cutting all callers over. Preserved encrypted-address matching, account scoping, lifecycle snapshot keys/order, scoped family/trigger rule retention, quiet saves, and package table ownership. Commit `51352bc8` was self-reviewed at the user's direction after Muse Ember 1.0 `max` was rejected with `model does not exist or you lack access`; no unavailable-model agreement is claimed. Focused Pest passed 14 tests / 201 assertions; architecture scan passed with 0 violations across 18 features; `graphify update .` and `git diff --check` completed successfully.

### C3 — inbox and notification-center cutover (complete)

Commit `2eb54050` moved inbox reads behind `NotificationInboxManagement`, which returns immutable page/message snapshots and scalar counts. API, Livewire bell/index, and read actions now use the feature API; the two root read Actions and inbox methods on `AccountCommunications` were removed. Deleted unused `UserDashboard` inbox getters after confirming there was no view consumer. Preserved routes, response fields, pagination, archived filtering, account scope, locale/timezone formatting, and first-transition Signal properties. Focused Pest passed 93 tests / 504 assertions; `graphify update .` and `git diff --check` completed successfully. The architecture scanner reported two Shared→Directory references in the separate, unstaged `SubmissionContextResolver` workspace edit; the C3 files are not implicated.

### C4 — delivery adapter and policy registration (complete)

Commit `a38d8975` moved custom package-contract bindings, channel adapters, notification rendering, and delivery fencing into Communications while keeping notification intent with Rewards and other source features. Registered the current scalar transport IDs (`communications.inbox`, `communications.digest-mail`, `communications.push`), removed old channel class paths and the Rewards-only Shared message factory, and routed Rewards ambiguity resolution through scalar APIs. Failure fencing now checks communication/delivery cancellation tombstones, terminal timestamps, and ambiguous attempts; both Sent and Failed external resolutions reject missing or incomplete frozen fences. The map and feature-provider docs now describe current ownership. Focused C4 Pest passed 82 tests / 740 assertions; after strict-fence follow-up, Delivery and ambiguity-resolution Pest passed 15 tests / 425 assertions. Architecture scan passed with 0 violations across 18 features; `git diff --check` passed. Muse Ember remained unavailable; self-review and independent spec/standards reviews found no remaining scoped findings.

### C5 — Rewards integration boundary (complete)

Commit `b2759310` moved package aggregate creation/adoption, delivery inspection/cancellation, and ambiguity settlement behind the immutable `ManagedNotificationDelivery` API. Rewards retains digest planning, slot state, content facts, and policy decisions; it has no direct Communications `Communication`, `CommunicationDelivery`, or `CommunicationAttempt` model imports. Removed the Rewards-specific notification, ambiguity action/API/DTO, and Shared digest policy/snapshot. No schema change, compatibility path, legacy API, or backfill was added.

The lock order is Rewards slot → deliveries sorted by ID → communication → attempts; channel sends keep delivery → communication → attempt. The Rewards slot transaction still encloses the API call and slot update, queued work remains after-commit, and failure recording retains its three deadlock retries at the aggregate transaction boundary.

Added deterministic isolated PostgreSQL barriers for sender-vs-cancel, late-send-after-cancel, sender-vs-reconcile, and operator-resolution-vs-reconcile. Focused Pest passed 49 tests / 995 assertions, including the race suite; the architecture scanner reported 0 violations across 18 features and `git diff --check` passed. Muse Ember remained unavailable; Codex self-review and two independent read-only reviews completed, with their retry-semantics and stale-documentation findings fixed and verified. No unavailable-model agreement is claimed.

### C6 — final transport/admin/MCP and boundary audit (complete)

Moved notification HTTP controllers, request/response data, Livewire bell and inbox page, their Blade views, mail view, API and web route registrations, and the app `InAppNotification` transport into Communications. Removed the old root controllers, DTOs, Livewire classes, notification class, views, and route declarations with no compatibility aliases. Added `NotificationDispatch` so domain producers submit immutable payloads and only the Communications implementation resolves accounts and calls the package `CommunicationManager`.

Moved package enum normalization and inbox-only family/priority presentation out of the public API namespace. Enum mappings are exhaustive; sharing notifications now store and read back as `DigestWeekly`.

Closed generic admin/MCP read exposure centrally: private content, identity, destination, provider, failure, metadata, and storage attributes are removed from Communications resources and nested related records; their generic title is a static model label. Vendor Filament resources stay registered, and no Communications write resource was added to the generic mutation allowlist.

No schema change, backfill, compatibility alias, legacy path, or superseded root implementation remains. Muse Ember was unavailable through both configured review paths; Codex self-review proceeded at the user's direction, and no unavailable-model agreement is claimed. Focused serial Pest passed 103 tests / 577 assertions; the architecture scan reported 0 violations across 18 features; API/web routes and middleware, scheduler registration, and package listener registration were checked; `git diff --check` passed.

## 9. Verification and migration constraints

- Before each move, search direct package-model writes, raw table writes, route registrations, listeners, account lifecycle contributors, queue dispatches, and schedules.
- Preserve stored morph identities, API route names/middleware/order, translation keys, actor/recipient scoping, queue and after-commit semantics, and existing Signals.
- Add focused Pest coverage for behavior changes, run the affected parallel Pest tests once per meaningful revision, and run the architecture boundary scanner. Do not run PHPStan, Rector, or Pint during development; reserve them for the final migration pass.
- Do not edit sibling `commerce`, package source, dependencies, or production data as part of this app migration.
