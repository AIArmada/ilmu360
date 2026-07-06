# AIA-COMMS — Communications Cutover Plan (Revised)

> Revised after code review. Previous version had an impossible step 1
> (table swap on a `DatabaseNotification` subclass), missing sub-items for
> push destinations and enum alignment, and a backfill ordering that
> violated the hard-cutover directive.

## Status

- ✅ `auto_capture` enabled by default (AIA-COMMS-001)
- ✅ AIA-TEST-006: verified auto_capture works
- ✅ Enum mapper (AIA-COMMS-007)
- ✅ Inbox write path — `InboxChannel`, `NotificationCenterMessage` routes InApp to channel (AIA-COMMS-002a)
- ✅ Inbox reads — all 8 consumers switched to `NotificationInbox` (AIA-COMMS-002b)
- ✅ Backfill + cleanup — `notifications` dropped, `NotificationMessage` deleted (AIA-COMMS-002c)
- ✅ All 4 package resolvers implemented and container-bound: `QuietHoursResolver`, `PreferenceResolver`, `ConsentResolver`, `SuppressionResolver`
- ⏳ Pipeline cutover (AIA-COMMS-003) — scoped. `auto_capture` observes all notifications. `DispatchManagedNotificationAction` available for per-notification-type migration. Resolver stubs are no longer Null — they check real `NotificationSetting`/`NotificationRule`/`NotificationDestination` data. Next: migrate one notification family at a time (e.g., EventReminders via `DispatchManagedNotificationAction`).

## Critical traps

### 1. Name collision
| Table | Model | Purpose |
|-------|-------|---------|
| `notifications` | `NotificationMessage` (extends `DatabaseNotification`) | **Inbox** — what users see in-app |
| `notification_messages` | `PendingNotification` | **Staging queue** — pre-dispatch / digest |

Every reference to "notification_messages" must be double-checked against this table. They are different tables with confusingly similar names.

### 2. Push destinations have no package equivalent
`notification_destinations` stores FCM device tokens and WhatsApp numbers. The communications package stores addresses per-delivery (encrypted on `communication_deliveries`) — there is no device registration table. This table stays local (AIA-COMMS-006).

### 3. NotificationCenterMessage uses Laravel's native `database` channel
In-app notifications are written to `notifications` by Laravel's built-in `DatabaseChannel` (not custom code). Replacing this requires a custom channel or bypassing Laravel's notification system for in-app delivery.

### 4. Enum divergence
The package ships its own `NotificationFamily`, `NotificationPriority`, `NotificationTrigger` enums under `AIArmada\Communications\Enums\`. These have DIFFERENT values/names than the app's local `App\Enums\Notification*` enums. Every model switch needs enum mapping (AIA-COMMS-007).

---

## Sub-items

### AIA-COMMS-002a: Create inbox channel replacement
**Goal**: Replace Laravel's `DatabaseChannel` for in-app delivery with a custom channel that writes to `notification_inboxes`.

**Why a custom channel, not a model swap**: `NotificationMessage extends DatabaseNotification` which hardcodes `$table = 'notifications'` and expects `type`/`notifiable_type`/`notifiable_id` columns. The target `notification_inboxes` table has `recipient_type`/`recipient_id`/`communication_id`/`title`/`body` — fundamentally different schema. Can't repoint the model.

**Steps**:
1. Create `app\Notifications\Channels\InboxChannel.php` — a custom Laravel notification channel that calls `DispatchInboxNotificationAction` (or `NotificationInboxService::create()` directly)
2. Map app enums → package enums inside the channel
3. Update `NotificationCenterMessage::via()` to return `InboxChannel::class` instead of `'database'` for the in-app path
4. Update `NotificationEngine::queueChannelNotification()` to handle the new channel
5. Write test: send notification → verify row in `notification_inboxes`
6. Backfill: copy `notifications` rows → `notification_inboxes` with column mapping
7. Drop: remove `notifications` table, delete `NotificationMessage` model + factory

**Files changed**: `NotificationCenterMessage.php`, `NotificationEngine.php`, new `InboxChannel.php`
**Files removed**: `NotificationMessage.php`, `NotificationMessageFactory.php`
**Risk**: Medium-High — `NotificationEngine` is the central orchestrator

### AIA-COMMS-002b: Switch inbox reads to notification_inboxes
**Goal**: Update all inbox consumers to read from `notification_inboxes` instead of `notifications`.

**Consumers to update**:
- `app/Livewire/Pages/Dashboard/NotificationsIndex.php` — main inbox page
- `app/Livewire/Pages/Dashboard/UserDashboard.php` — recent notifications widget
- `app/Http/Controllers/Api/NotificationMessageController.php` — inbox API
- `app/Actions/Notifications/MarkNotificationMessageReadAction.php`
- `app/Actions/Notifications/MarkAllNotificationMessagesReadAction.php`
- `app/Models/User.php` — `notificationMessages()` relationship → `notificationInbox()` morphMany to `notification_inboxes`
- `app/Data/Api/Notification/NotificationMessageData.php` — API DTO
- User lifecycle: delete/restore snapshots reference notification tables

**Pattern**: Each consumer switches from `NotificationMessage` queries to `NotificationInbox` queries (using `NotificationInbox::forRecipient()` or equivalent). The `visibleInInbox()` scope maps to `WHERE archived_at IS NULL`. The `unread` concept maps to `WHERE read_at IS NULL`.

**Risk**: Medium — OwnerContext scoping on `NotificationInbox` (HasOwner trait). Needs `OwnerContext::setForRequest(null)` in Livewire/API boot or wrapping.

### AIA-COMMS-003: Replace PendingNotification + delivery pipeline
**Goal**: Replace the custom notification engine with package managed mode.

**This is the largest and riskiest sub-item.**

**Local components**:
- `NotificationEngine.php` (~250 lines) — orchestrator with quiet hours, fallback chains, cadence management, digest support
- `PendingNotification` model → `notification_messages` table (staging queue)
- `NotificationDelivery` model → `notification_deliveries` table (delivery log)
- `NotificationDeliveryLogger.php` — logs to `notification_deliveries`
- `app/Notifications/Channels/PushChannel.php` — FCM HTTP API
- `app/Notifications/Channels/WhatsappChannel.php` — Meta Cloud API
- `EventNotificationService.php` (983 lines) — 14+ event triggers
- `ContributionRequestNotificationService.php` — 2 directory triggers
- `NotificationCatalog.php` — 22 triggers with channel/cadence/priority metadata
- `NotificationSettingsManager.php` — resolves per-user policies
- `NotificationMessageRenderer.php` — localized content rendering
- `ChannelSendResult.php` — DTO

**Package alternatives**:
- `CommunicationManager` → replaces `NotificationEngine`
- `DispatchManagedNotificationAction` → replaces the dispatch path
- `PlanCommunicationDeliveriesAction` → replaces fallback/cadence logic
- `CommunicationDelivery` model → replaces `NotificationDelivery`
- `QuietHoursResolver`, `ConsentResolver`, `PreferenceResolver` contracts (currently `Null*` stubs — would need real implementations)

**Assessment**: This sub-item is a **multi-session project**. The custom engine has features (quiet hours, fallback chains, per-trigger cadence, digest batching) that the package's contracts are stubbed for but don't implement. Recommend:
1. First: Map each local feature to its package contract/stub
2. Then: Implement the stubs one at a time
3. Finally: Switch the engine and drop local code

**Risk**: **HIGHEST** in the entire adoption cutover.

### AIA-COMMS-004: Replace NotificationSetting/Rule
**Goal**: Map user preferences to package `communication_preferences`.

**Local tables**:
- `notification_settings` — locale, timezone, quiet hours, digest schedule, preferred/fallback channels
- `notification_rules` — per-trigger/per-family enable/disable with cadence override

**Package table**: `communication_preferences` — per-user-per-channel preferences

**Gap**: The local tables have rich features (quiet hour windows, digest day/time, urgent override, per-trigger cadence) that the package's preference model doesn't directly support. The package has `QuietHoursResolver` and `PreferenceResolver` as contracts (stubbed).

**Recommendation**: This depends on AIA-COMMS-003 (the pipeline needs the resolvers before settings can be replaced). Defer until the pipeline cutover is underway.

### AIA-COMMS-006: Notification destinations (stays local)
**Goal**: Document that `notification_destinations` (push device tokens, WhatsApp numbers) stays local.

**Rationale**: The communications package has no device registration table. Addresses are stored per-delivery (encrypted on `communication_deliveries`). The local `NotificationDestination` model + `User::routeNotificationForPush()` pattern works well and has no package replacement.

**Action**: No cutover needed. Add a note to the adoption docs that this table is intentionally app-owned.

### AIA-COMMS-007: Enum alignment
**Goal**: Create a mapping layer between app enums and package enums.

**Local enums** (`App\Enums\`):
- `NotificationFamily` — 6 values (EventUpdates, DirectoryUpdates, System, Engagement, Dawah, Account)
- `NotificationTrigger` — 22 values
- `NotificationPriority` — 4 values
- `NotificationChannel` — 4 values (InApp, Email, Push, Whatsapp)

**Package enums** (`AIArmada\Communications\Enums\`):
- `NotificationFamily` — different values/names
- `NotificationPriority` — different values
- `NotificationTrigger` — different values
- No channel enum (channels are strings)

**Action**: Create `app/Support/Communications/EnumMapper.php` that converts between local and package enums. Used by the inbox channel (AIA-COMMS-002a) and the pipeline (AIA-COMMS-003).

---

## Execution order (revised)

```
AIA-COMMS-007 (enum mapping)     ← do first, it's a dependency
    ↓
AIA-COMMS-002a (inbox channel)   ← create new channel + backfill + drop notifications table
    ↓
AIA-COMMS-002b (inbox reads)     ← switch consumers to notification_inboxes
    ↓
AIA-COMMS-003 (pipeline)         ← multi-session: implement stubs → switch engine → drop staging tables
    ↓
AIA-COMMS-004 (settings/rules)   ← after pipeline needs the resolvers
    ↓
AIA-COMMS-006 (destinations)     ← document as intentionally local, no action
```

Each sub-item includes its own backfill + drop for its specific table(s). No final "drop everything" step.

---

## Column mapping reference

### `notifications` → `notification_inboxes`
| `notifications` column | `notification_inboxes` column | Notes |
|------------------------|-------------------------------|-------|
| `id` (uuid) | `id` (uuid) | Direct copy |
| `type` (notification class) | — | No equivalent; drop |
| `notifiable_type` | `recipient_type` | Morph map alias |
| `notifiable_id` | `recipient_id` | Direct copy |
| `data` (JSON: title, body, action_url, entity_*, etc.) | `title`, `body`, `data` | Split: extract title/body to columns, keep rest in data |
| `read_at` | `read_at` | Direct copy |
| `family` | `family` | Needs enum value mapping (AIA-COMMS-007) |
| `trigger` | `trigger` | Needs enum value mapping |
| `priority` | `priority` | Needs enum value mapping |
| `inbox_visible` (bool) | `archived_at IS NULL` | Inverse logic: visible = not archived |
| `is_digest` (bool) | — | No equivalent; could store in `data` |
| `occurred_at` | `scheduled_at` or `created_at` | Semantic shift |
| `action_url` | — | Store in `data` JSON |
| `entity_type` / `entity_id` | — | Store in `data` JSON |
| `fingerprint` | — | Store in `data` JSON |
| `created_at` / `updated_at` | `created_at` / `updated_at` | Direct copy |
| — | `communication_id` | NULL for backfilled records |
| — | `owner_type` / `owner_id` | NULL for backfilled records |

### `notification_messages` → `communications` (PendingNotification)
Deferred to AIA-COMMS-003 (pipeline). Mapping TBD when the pipeline cutover is designed.

### `notification_deliveries` → `communication_deliveries`
Deferred to AIA-COMMS-003. Delivery log semantics differ significantly.

---

## Risk summary

| Sub-item | Risk | Why |
|----------|------|-----|
| AIA-COMMS-007 (enum mapping) | Low | Pure mapping code, no behavior change |
| AIA-COMMS-002a (inbox channel) | Medium-High | Touches NotificationEngine dispatch path |
| AIA-COMMS-002b (inbox reads) | Medium | OwnerContext scoping + consumer updates |
| AIA-COMMS-003 (pipeline) | **HIGHEST** | 983-line service, custom channels, stubbed contracts |
| AIA-COMMS-004 (settings/rules) | Medium | Depends on pipeline resolvers |
| AIA-COMMS-006 (destinations) | None | No action needed |
