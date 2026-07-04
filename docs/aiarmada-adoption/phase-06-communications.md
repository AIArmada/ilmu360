# Phase 6 - Communications And Notifications

State: `Assessed`

## Objective

Replace the app notification engine with `communications` while keeping provider-specific channels (FCM, WhatsApp) and digest scheduling app-owned.

## Target Packages

| Package | Status | Filament Adapter | Notes |
|---|---|---|---|
| `communications` | Exists, installed | `filament-communications` exists | 16 models, 18 migrations, 31 actions, 15 contracts |
| `filament-communications` | Exists, not installed | N/A | 7 resources, 3 relation managers, 1 widget |

## Package Assessment

### Communications Package (16 models, 18 migrations)

**Package provides:**

| Domain Area | Package Model(s) | Coverage |
|---|---|---|
| Core communication | `Communication` | Full: direction, category, priority, status, scheduling, idempotency |
| Batch grouping | `CommunicationBatch` | Full: grouping, Laravel batch integration, counts |
| Thread support | `CommunicationThread` | Full: per-subject threading, status, archival |
| Recipients | `CommunicationRecipient` | Full: role, locale/timezone, destination snapshot |
| Deliveries | `CommunicationDelivery` | **Full lifecycle** (18 timestamp states), attempt tracking, cost, encryption |
| Attempts | `CommunicationAttempt` | Full: per-attempt request/response, duration, failure codes |
| Content | `CommunicationContent` | Full: per-channel+locale content, template binding, checksum |
| Events | `CommunicationEvent` | Full: webhook/provider event tracking |
| Templates | `CommunicationTemplate` + `CommunicationTemplateVersion` | Full: versioned, channel-specific, variable schema, publishing |
| Preferences | `CommunicationPreference` | Full: channel+category scoped, quiet hours, opt-in/out |
| Suppressions | `CommunicationSuppression` | Full: time-bound, reason-tracked, audit trail |
| Attachments | `CommunicationAttachment` | Full: storage, MIME, inline content |
| Tracking | `CommunicationTrackingToken` | Full: click/open tracking with token hashing |
| Inbox | `NotificationInbox` | Full: family/priority/trigger, read/archive, `HasInbox` trait |
| References | `CommunicationReference` | Full: polymorphic references per communication |

**Key contracts (15) for extension:**
- `DestinationResolver` — resolve notifiable+channel to destination
- `ContentRenderer` — render notification content per channel
- `PreferenceResolver` — check if enabled/opted-in
- `SuppressionResolver` / `ConsentResolver` / `QuietHoursResolver` / `RateLimiter`
- `CommunicationManager` — facade-style notify API
- `CommunicationRecorder` — record communication lifecycle events

**Filament integration (`filament-communications`):**
- 7 resources (Communication, Delivery, Thread, Template, Preference, Suppression, Batch)
- 3 relation managers (Communications, Timeline, Deliveries)
- 1 widget (DeliveryStatusOverviewWidget)
- All owner-scoped, config-driven toggles

### App Notification System

**App provides (11 notification classes, 6 models, 2 custom channels, 7 services):**

| Domain Area | App Implementation | Package Equivalent |
|---|---|---|
| Delivery tracking | `NotificationDelivery`, `NotificationDeliveryLogger`, `NotificationDeliveryStatus` | `CommunicationDelivery`, `CommunicationAttempt`, `CommunicationEvent` |
| In-app inbox | `NotificationMessage` (extends `DatabaseNotification`), Livewire `NotificationsIndex`, API controllers | `NotificationInbox` + `HasInbox` trait + Livewire component |
| User preferences | `NotificationSetting` (per-user), `NotificationRule` (per-family, per-trigger) | `CommunicationPreference` (per channel+category) |
| Custom channels | `PushChannel` (FCM HTTP v1), `WhatsappChannel` (Meta Cloud API) | Channel-agnostic — bind via `DestinationResolver` + custom channel class |
| Digest scheduling | `DispatchNotificationDigests` (15-min cron, timezone-aware windows) | `CommunicationBatch` + `DispatchDueCommunicationsCommand` |
| Notification catalog | `NotificationFamily` (6 values), `NotificationTrigger` (16 values), `NotificationCatalog` | Package `NotificationFamily` (46 values), `NotificationTrigger` (35 values) |
| Templates | None (inline rendering in each notification class) | `CommunicationTemplate` + `CommunicationTemplateVersion` + `ContentRenderer` |
| Destination management | `NotificationDestination` (email/phone/push tokens) | Via `DestinationResolver` contract (encrypted storage in delivery) |
| Queue routing | 4 dedicated Horizon queues (mail, inbox, push, whatsapp) | No specific routing — config via regular Laravel notification channels |
| FCM/WhatsApp config | `config/notification-center.php` | No equivalent — app keeps this |
| Signals tracking | `notification.read`, `notification.read_all` | No equivalent — app keeps this |
| Dedup/fingerprint | `PendingNotification.fingerprint` | Package `communication.idempotency_key` |

## WP-16 Migration Plan

Effort: **High** — the app has a sophisticated notification system that's deeply integrated.

### What gets replaced:
- **Delivery tracking** → `CommunicationDelivery` + `CommunicationAttempt` + `CommunicationEvent`
- **In-app inbox** → `NotificationInbox` + `HasInbox` trait + package Livewire component (replace `NotificationMessage`)
- **Suppressions** → `CommunicationSuppression`
- **Attachments** → `CommunicationAttachment`
- **Template rendering** → `CommunicationTemplate` + `CommunicationTemplateVersion` + `ContentRenderer`
- **Communication model** → `Communication` (central aggregate)
- **Batch grouping** → `CommunicationBatch`

### What gets bound through package contracts:
- **FCM push channel** → Implement `DestinationResolver` for push destinations, keep `PushChannel` class as app-owned custom channel, record deliveries via `CommunicationRecorder`
- **WhatsApp channel** → Same pattern, keep `WhatsappChannel` class
- **User preferences** → Implement `PreferenceResolver` (map `NotificationSetting` + `NotificationRule` to channel+category queries)
- **Quiet hours** → Implement `QuietHoursResolver` (use app's existing `NotificationSetting`)
- **Suppression logic** → Implement `SuppressionResolver` / `ConsentResolver`
- **Rate limiting** → Implement `RateLimiter` contract

### What stays app-owned:
- **`NotificationSetting`** + **`NotificationRule`** — app's per-family/per-trigger granularity is richer than package's `CommunicationPreference`. Keep as app-level models, implement `PreferenceResolver` to translate.
- **Digest scheduling** app's `DispatchNotificationDigests` — keep the cron job and digest grouping logic, but write deliveries through package models.
- **FCM/WhatsApp channel classes** — custom channel implementations with provider logic
- **Notification catalog** (`NotificationFamily`, `NotificationTrigger` enums) — map to package's richer enums or keep app's
- **Signals tracking** for notification events
- **Horizon queue configuration** — app's 4 dedicated queues

### Key decisions:
1. **Family/Trigger enums**: Package has 46 families and 35 triggers. App has 6 families and 16 triggers. Recommend keeping app's enums and mapping to package enums via metadata, OR adopting package's enums for new triggers and mapping existing ones.
2. **Digest logic**: App's digest scheduling is timezone-aware with per-user windows. Package's batch system is simpler. Keep app's`DispatchNotificationDigests` job, have it create `CommunicationBatch` records.
3. **Inbox migration**: Package's `NotificationInbox` is a separate model. App's `NotificationMessage` extends `DatabaseNotification`. The package's approach is cleaner — migrate to separate inbox model.
4. **Template adoption**: The app doesn't have notification templates (inline rendering). Adopting package templates would be a significant change — consider deferring to post-cutover.

### Steps:
- [ ] Publish `config/communications.php` and `config/filament-communications.php`
- [ ] Run package migrations (18 tables)
- [ ] Implement `DestinationResolver` for email, FCM push, WhatsApp
- [ ] Implement `PreferenceResolver` wrapping `NotificationSetting` + `NotificationRule`
- [ ] Implement `QuietHoursResolver` wrapping `NotificationSetting.quiet_hours_*`
- [ ] Implement `SuppressionResolver` wrapping `CommunicationSuppression`
- [ ] Implement `ConsentResolver` (start with pass-through)
- [ ] Replace `NotificationDelivery` + `NotificationDeliveryLogger` with `CommunicationDelivery` + actions
- [ ] Adopt `HasInbox` trait on User model
- [ ] Replace `NotificationMessage` inbox with `NotificationInbox` inbox
- [ ] Wire `DispatchDueCommunicationsCommand` into schedule
- [ ] Register `FilamentCommunicationsPlugin` on admin panels
- [ ] Replace app notification models: `PendingNotification`, `NotificationMessage`, `NotificationDelivery`
- [ ] Keep app: `NotificationSetting`, `NotificationRule`, `NotificationDestination`, `PushChannel`, `WhatsappChannel`, digest jobs, Signals tracking, Horizon config

## Verification

```bash
vendor/bin/pest --parallel --compact --filter=Notification
vendor/bin/pest --parallel --compact --filter=Communication
vendor/bin/phpstan analyse --ansi
```

## Exit Criteria

- Notification persistence and delivery state are package-backed.
- App-specific channels (FCM, WhatsApp) are contract-bound, tested, and retry-safe.
- In-app inbox works from package data.
- Digest scheduling preserved and writes through package batch/delivery models.
- Signals tracking remains for meaningful notification outcomes.

## Stop And Re-plan Triggers

- Package eligibility cannot represent app quiet-hours/timezone requirements through generic contracts.
- Provider channels require package-specific hardcoding.
- Delivery events cannot be recorded reliably after queue retries.
- App's per-family/per-trigger preference granularity cannot be represented through `CommunicationPreference`.
