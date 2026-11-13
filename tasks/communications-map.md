# Communications ownership map

> **Status: C1, C2a, and C2b implemented; C3–C6 remain planned.** This map records the current app/package boundary and implementation sequence. C1 changed scheduling only. C2a moved app notification policy and preference writes into Communications. C2b moved push destination operations and account lifecycle handling; package tables remain unclaimed.

## 1. Ownership boundary

`aiarmada/communications` is the canonical persistence and delivery package. The app `Features/Communications` module exists with `owns: {}`. It owns ilmu360° notification policy, preference actions, push destination operations, account lifecycle handling, and app schedules. It will take on the remaining custom channel adapters and recipient-facing notification-center behavior. It will not claim package tables in `feature.json`; those remain package-owned resources. Business notification producers remain with their domain feature and call the communications boundary.

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
| `notification_inboxes` | `InboxChannel` delegates writes to package `DispatchInboxNotificationAction`; `MarkNotificationMessageReadAction` and `MarkAllNotificationMessagesReadAction` mutate read state through package `NotificationInboxService`; lifecycle contributor snapshots/deletes/restores inbox rows | Communications inbox writer. Preserve signals and first-transition semantics. |
| `communications` / `communication_deliveries` / `communication_attempts` | Package `CommunicationManager` and recorder actions are used by app notification producers and custom channels. `GuardedDeliverySend` locks delivery/communication rows and creates/completes attempts through package actions. `ShareGratitudeDigestNotification::failed()` checks delivery state and reports eligible failures through the package recorder. Rewards digest plan/dispatch/cancel actions and `ResolveDigestMailAmbiguityAction` also read or update package communication/delivery/attempt rows directly. | Package remains canonical. Move app-owned orchestration behind named Communications actions/APIs; retain reward slot coordination in Rewards. No direct cross-feature model mutation after the eventual Rewards cutover. |
| `communication_batches` | The retired `SendDigestNotificationsCommand` selected due rows and set `started_at`/`completed_at` without processing related communications or deliveries. No app caller of `CreateCommunicationBatchAction` or app batch producer was found. Vendor Filament exposes batch list/view pages. | C1 removed the stub. Keep the package table and admin resource canonical. Do not represent a completed batch until an actual batch workflow exists. |

`AccountCommunications` remains a temporary root adapter for package-backed base-preference, active-destination, and inbox reads. C2a removed its locale/timezone and base-preference write methods; C2b removed its push-destination query and removal methods. C3 removes its inbox query/count reads, and C4 moves the remaining policy reads behind Communications. Do not expose its builders or package models from `Features/Communications/Api`.

The recipient read surfaces are:

- `User::preferredLocale()` and `preferredTimezone()` use the Communications API; `routeNotificationForPush()` returns immutable push-destination snapshots. Custom routing also exists for `InboxChannel` and `DigestMailChannel`.
- Identity account settings use the Communications feature API directly; the obsolete Shared settings wrapper was removed in C2b.
- API settings/catalog endpoints (`NotificationSettingsController`) and push destination endpoints (`NotificationDestinationController`).
- API inbox endpoints (`NotificationMessageController`), `NotificationBell`, dashboard `NotificationsIndex`, and the `UserDashboard` unread count.
- Locale selection updates the base preference through the Communications API from `LocaleController`.

## 4. App policy and delivery integration

App policy now lives in `Features\Communications\Api` values/catalog, `NotificationSettingsService`, and the `App\Support\Communications` adapters. Preserve the following behavior unless a later slice has explicit evidence and a product decision to change it:

- Default channels are `in_app` and `email`; `in_app` is always present. Family defaults are daily for followed content and saved-search matches, instant for event updates/reminders, registration/check-in and submissions, and weekly for sharing. Sharing starts disabled; family/trigger rules are separately persisted.
- `AccountNotificationPreferences::resolvePolicy` checks both family and trigger enablement and returns the family cadence, selected channels, quiet hours, locale, timezone, and global digest delivery time/week day. Digest time (`08:00`) and weekly day (Monday) are global config, not per-user preferences.
- `AppPreferenceResolver` maps package channels/families into app values and currently checks channel selection plus family enablement; it does not consult trigger-level rules or cadence. Do not assume package eligibility and `resolvePolicy` are equivalent.
- `AppConsentResolver` treats an active destination as channel consent. `AppSuppressionResolver` currently reports no suppression. `AppQuietHoursResolver` resolves the account preference timezone. Do not conflate communication-channel consent with participant-data consent.
- `SaveNotificationPreferencesAction` performs multiple preference writes and email-destination synchronization without one encompassing transaction. Preserve the existing API's partial-update merge and failure behavior until an explicit atomicity slice is planned.
- The notification timezone fallback is currently `Asia/Kuala_Lumpur`; account preferences may override it. Preserve this output during ownership-only moves and review the default separately against the app's timezone policy.

App package-contract adapters currently include `AppRecipientSnapshotResolver`, `AppConsentResolver`, `AppPreferenceResolver`, `AppQuietHoursResolver`, `AppSuppressionResolver`, `ProbeSafeDestinationResolver`, and `ListSafePayloadRedactor`. Delivery-specific integrations include `EnumMapper`, `GuardedDeliverySend`, `InboxChannel`, `DigestMailChannel`, `PushChannel`, and `NotificationMessageRenderer`. The root `AppServiceProvider` binds these contracts; move registrations with implementations and verify there is one binding and one event-listener registration after each cutover.

`DigestPolicy`/`DigestPolicySnapshot` and `DigestMessages` are existing Shared seams used by Rewards. Preserve their current call timing and payload semantics while improving weak boundaries in later slices; `DigestMessages` currently returns a Laravel `Notification`, so it is not an immutable feature value. `AccountNotificationSettings` was removed in C2b after Identity moved directly to the Communications API.

## 5. Producers and responsibility

Communications owns delivery policy, destination resolution, channel integration, and inbox behavior. It does not own domain event decisions or message facts. Producers stay with their owning feature and use the communications API:

| Producer area | Current examples |
|---|---|
| Events / Registration | `EventNotificationService`, event status transition notifications, event and occurrence submission notifications, and `DispatchEventReminderNotifications` / `EscalatePendingEvents` |
| Contributions / Directory | `ContributionRequestNotificationService` and contribution workflow notifications |
| Feedback / Reports | Feature-owned notification classes and actions |
| Identity / Auth | Welcome, email verification, and password reset notifications |
| Membership | Member invitation notification |
| Rewards | Gratitude digest planning and `ShareGratitudeDigestNotification`; the `reward_digest_slots` lifecycle remains Rewards-owned |
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
| Admin / MCP | `FilamentCommunicationsPlugin` remains vendor-registered in `AdminPanelProvider` and registers vendor Communication, Delivery, Thread, Template, Preference, Suppression, and Batch resources. The app `AdminResourceMutationService` allowlist does not include these vendor resources. No dedicated app Communications MCP tool was found; verify generic read exposure during C6. |
| Account lifecycle | The Communications provider registers `CommunicationsAccountLifecycleContributor` through Identity's `AccountLifecycleContributor` composition. It retains scoped preference rules while detaching base settings, destinations, and inbox rows. Preserve snapshot keys, ordering, and quiet restore behavior. |

Read marking records `notification.read` only on an unread-to-read transition and `notification.read_all` only when the action updates at least one row. Inbox queries exclude archived messages; unread counts also exclude archived rows by default. Keep these semantics and the existing Signal payloads.

## 7. Rewards coupling and delivery invariants

Rewards' `DispatchDueRewardDigestsAction` and `CancelRewardDigestAction` currently coordinate a Rewards-owned slot with package Communication/Delivery rows. `ResolveDigestMailAmbiguityAction` and its command remain root today. Any future Communications API must preserve:

- the existing row-lock order, transaction boundaries, after-commit behavior, and deadlock/retry behavior;
- delivery cancellation vs send races and the mail external-I/O boundary;
- an unresolved started mail attempt as UNKNOWN, never an automatic resend or guessed failure;
- reconciliation and payload-fingerprint rules, slot status transitions, and the command's evidence/role checks;
- Rewards' ownership of `reward_digest_slots` and its existing public feature API.

Current known hazards: the mail send path locks delivery then communication, while reward cancellation/reconciliation can lock communication then deliveries; a race can deadlock. The scheduled generic batch stub can falsely complete a batch. Laravel's `withoutOverlapping()` uses a default 1,440-minute mutex; a crashed scheduler run can suppress later runs for up to a day. Current settings do not expose an urgent-override or fallback-channel control; if either is introduced later, its runtime behavior must be implemented before it is presented as a user setting. These are explicit follow-up work items, not reasons to silently change policy during the ownership move.

## 8. Planned slices

### C1 — own the real scheduled dispatcher (complete)

Added the Communications feature module with `owns: {}` and scheduled package command `communications:dispatch-due` every minute in UTC with non-overlap protection. Removed the app-only `SendDigestNotificationsCommand` and its obsolete schedule. Kept the vendor `communication_batches` table and admin pages; the package command processes due Communication aggregates, not batches. Schedule and command coverage passed (2 tests, 8 assertions); architecture scan passed (0 violations across 18 features). All other schedules remain registered.

### C2a — preference policy and locale boundary (complete)

Moved notification policy/catalog values and `NotificationSettingsManager` into Communications behind a scalar account API. Moved preference defaults, preference saves, profile-timezone sync, email-destination sync, and locale writes into named Communications actions. Updated `User` locale/timezone reads, settings API, event/digest policy consumers, and `LocaleController`; kept the Shared `AccountNotificationSettings` seam as the Identity-facing adapter. Preserved response keys, partial-update merging, email-verification behavior, and timezone fallbacks. Package tables remain unclaimed. Commit `9a596d84` was self-reviewed at the user's direction after Muse Ember 1.0 `max` was rejected with `model does not exist or you lack access`; no unavailable-model agreement is claimed. Focused Pest passed 88 tests / 1,215 assertions, Identity reverse-relation seams passed 3 tests / 27 assertions, architecture scan passed with 0 violations across 18 features, and `git diff --check` was clean.

The Shared settings wrapper described in C2a was removed in C2b after Identity moved to the feature API.

### C2b — push destinations and account lifecycle (complete)

Moved push register/update/delete/removal and destination resolution behind the Communications API and named actions. `User::routeNotificationForPush()` now returns immutable destination snapshots. Moved `CommunicationsAccountLifecycleContributor` into the feature and registered it from the feature provider. Removed `AccountNotificationSettings`, its root adapter, and the root push-destination query/removal helpers after cutting all callers over. Preserved encrypted-address matching, account scoping, lifecycle snapshot keys/order, scoped family/trigger rule retention, quiet saves, and package table ownership. Commit `51352bc8` was self-reviewed at the user's direction after Muse Ember 1.0 `max` was rejected with `model does not exist or you lack access`; no unavailable-model agreement is claimed. Focused Pest passed 14 tests / 201 assertions; architecture scan passed with 0 violations across 18 features; `graphify update .` and `git diff --check` completed successfully.

### C3 — inbox and notification-center cutover

Move inbox query/read policy behind a Communications API returning immutable message/count snapshots. Cut over API, Livewire, dashboard, and read actions atomically. Preserve route names, pagination, Signal semantics, archived filtering, locale/timezone formatting, and package inbox writes.

### C4 — delivery adapter and policy registration

Move app-specific package-contract bindings, channel adapters, notification rendering, and delivery fencing into Communications. Keep domain notification intent and facts in source features. Verify native capture/listener uniqueness, push provider failure signaling, and all queue/after-commit behavior.

### C5 — Rewards integration boundary

Replace Rewards' direct package Communication/Delivery/Attempt model mutation with explicit scalar Communications commands/snapshots. Keep digest slot operations in Rewards and preserve transaction/lock/retry/ambiguity semantics. Resolve the lock-order hazard with race evidence before removing old writers.

### C6 — final transport/admin/MCP and boundary audit

Move remaining owned routes/schedules/commands and any app-owned MCP/admin composition, remove stale root wrappers, scan direct package model writes/callers, compare route and listener registration, and run the final verification gate.

## 9. Verification and migration constraints

- Before each move, search direct package-model writes, raw table writes, route registrations, listeners, account lifecycle contributors, queue dispatches, and schedules.
- Preserve stored morph identities, API route names/middleware/order, translation keys, actor/recipient scoping, queue and after-commit semantics, and existing Signals.
- Add focused Pest coverage for behavior changes, run the affected parallel Pest tests once per meaningful revision, and run the architecture boundary scanner. Do not run PHPStan, Rector, or Pint during development; reserve them for the final migration pass.
- Do not edit sibling `commerce`, package source, dependencies, or production data as part of this app migration.
