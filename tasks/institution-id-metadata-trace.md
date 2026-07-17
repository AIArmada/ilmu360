# Trace Report: `institution_id` in `events.metadata`

## Summary

The application has **two separate mechanisms** linking `Institution` to `Event`.
The `institution_id` stored in `events.metadata` JSON column is NOT the
organizer relationship — it represents the **location institution** (where the
event is held). The organizer relationship uses a completely different system
(`EventInvolvement` polymorphic pivot).

## The Two Links

### Link 1: Organizer — `EventInvolvement` (polymorphic)

| Aspect | Detail |
|--------|--------|
| Table | `event_involvements` |
| Role | `role_code = 'organizer'`, `is_primary = true` |
| Polymorphic | `involveable_type` / `involveable_id` → `Institution` or `Speaker` |
| Accessor | `$event->organizer` — morphTo via `primaryOrganizerInvolvement` |
| Purpose | **Who runs the event** — used for permissions, notifications, display |
| Model | `EventInvolvement` (with subclass `EventKeyPerson`) |
| Key code | `Event.php:526-531`, `Event.php:438-439` |

### Link 2: Location Institution — `events.metadata->'institution_id'`

| Aspect | Detail |
|--------|--------|
| Storage | JSON column `events.metadata->>'institution_id'` |
| Accessor | `$event->institution_id` (custom getter → metadata) |
| Relation | `$event->institution` — `belongsTo(Institution::class)`, FK resolved via getter |
| Purpose | **Where the event is held** — used for filtering, search, scoping |
| Type | Nullable UUID, no DB constraint |

### When They Coincide vs Diverge

| Scenario | `institution_id` (location) | `organizer` |
|----------|---------------------------|-------------|
| Event at Masjid A, organized by Masjid A | Masjid A | Masjid A |
| Event at Masjid A, organized by Speaker B | Masjid A | Speaker B |
| Event at Venue X, organized by Masjid A | null | Masjid A |
| Event at Venue X, organized by Speaker B | null | Speaker B |

Source: `EventContributionUpdateStateMapper.php:82-128`,
`SubmitFrontendEventAction.php:526-562`.

## Where `institution_id` Is Set (Written)

| Action / Service | File | Line | Value Source |
|-----------------|------|------|-------------|
| `PersistValidatedEventSubmissionAction` | `app/Actions/Events/PersistValidatedEventSubmissionAction.php` | 51 | `$submission->targetInstitutionId` — resolved from `resolveOrganizerAndLocation()` |
| `CreateAdvancedEventAction` | `app/Actions/Events/CreateAdvancedEventAction.php` | 55 | `$locationInstitutionId` — form input |
| `SaveAdminEventAction` | `app/Actions/Events/SaveAdminEventAction.php` | 217 | `resolveLocationState()` — independent from organizer field |
| `ContributionEntityMutationService` | `app/Services/ContributionEntityMutationService.php` | 562 | Payload or preserve existing |
| `EventContributionUpdateStateMapper` | `app/Support/Events/EventContributionUpdateStateMapper.php` | 82-128 | Normalization engine — explicitly sets based on location type |

Key insight: The `SaveAdminEventAction` treats `institution_id` and
`primary_organizer_id` as **independent form fields** — admin can pick any
combination. The `EventContributionUpdateStateMapper` has explicit branching
logic to determine whether `institution_id` = organizer's institution, a
different institution, or null.

## Where `institution_id` Is Read

### Attribute Access (`$event->institution_id`)

| Consumer | File | Line | Purpose |
|----------|------|------|---------|
| Space validation | `PersistValidatedEventSubmissionAction.php` | 101-102 | Verify space belongs to institution |
| Permission check | `MemberResourceMutationService.php` | 86-94 | Check user membership |
| Form defaults | `create.blade.php` (submit event) | 1655 | Check event matches scoped institution |
| Form defaults | `create.blade.php` (submit event) | 1685 | Default location institution |
| Duplicate | `create.blade.php` (submit event) | 1990 | Duplicate event defaults |

### Relationship Eager Loading (`->with('institution')`)

The `$event->institution` BelongsTo relationship (line 1737-1739) uses
`institution_id` as FK, resolved through the custom metadata getter. This is
eager-loaded extensively:

| Consumer | File | Line |
|----------|------|------|
| Scout indexable data | `Event.php` | 1451 |
| Notification service | `EventNotificationService.php` | 448 |
| Search controller | `SearchController.php` | 611, 621 |
| Moderation queue | `ModerationQueue.php` | 423 |
| Institution dashboard | `InstitutionDashboard.php` | 734 |
| Contributions index | `Contributions/Index.php` | 136 |
| Admin save action | `SaveAdminEventAction.php` | 272 |
| + many more | | |

### Queries (`where('institution_id', ...)` — routed to JSON path)

All go through `EventBuilder::mapColumn()` which translates to
`(metadata->>'institution_id')::uuid` (PostgreSQL) or
`json_unquote(json_extract(metadata, '$."institution_id"'))` (MySQL).

| Consumer | File | Line | Query Type |
|----------|------|------|------------|
| `Institution::events()` | `Institution.php` | 256 | WHERE |
| `EventSearchService` (DB fallback) | `EventSearchService.php` | 698 | Filter |
| `SearchController` | `SearchController.php` | 1079, 1588 | Institution events query |
| `InstitutionDashboard` | `InstitutionDashboard.php` | 384, 574, 732 | Counts + listings |
| `PendingApprovalEventsWidget` | `PendingApprovalEventsWidget.php` | 121 | Moderation filter |
| `MemberResourceRegistry` | `MemberResourceRegistry.php` | 406 | Member scope |
| `InstitutionWorkspaceController` | `InstitutionWorkspaceController.php` | 372 | Workspace |

### Filter UIs

| Page | File | Line | Component |
|------|------|------|-----------|
| Events index (public) | `Events/Index.php` | 109, 358 | `#[Url]` + Select filter |
| Advanced filters | `AdvancedFiltersPanel.php` | 244, 666 | Select filter |
| Saved searches | `SavedSearches/Index.php` | 344, 483, 542 | Filter key + label |
| Moderation queue | `ModerationQueue.php` | 254 | `SelectFilter::make('institution_id')->relationship('institution', 'name')` |

### MCP / API

| Consumer | File | Line | Usage |
|----------|------|------|-------|
| MCP write schema | `McpWriteSchemaFormatter.php` | 93 | `institution_key` → resolves to `institution_id` |
| MCP event search | `McpEventSearchService.php` | 209, 320 | `institution_id` filter parameter |
| Admin API mutation | `AdminResourceMutationService.php` | 1696 | Field definition (exclusive with `venue_id`) |
| Admin API validation | `AdminResourceMutationService.php` | 2282 | `nullable, uuid, exists:institutions,id` |

## The Institution Model's `events()` Method

```php
// Institution.php:254-257
public function events(): EventBuilder
{
    return Event::query()->where('institution_id', (string) $this->getKey());
}
```

Returns events **located at** the institution, NOT events organized by it.
Used by `InstitutionDashboard`, `SearchController`, and related scoping.

## Test Coverage

Tests that assert `institution_id` behavior:

| Test File | Key Lines | What It Tests |
|-----------|-----------|---------------|
| `SubmitEventLocationTest.php` | 65, 91, 115, 159 | Location institution assignment |
| `SubmitEventEntityAccessTest.php` | 125, 200 | Scope matching |
| `EventShowPageTest.php` | 397, 418, 510 | Display correctness |
| `EventApiContractTest.php` | 113, 123, 130, 690 | API response field |
| `EventSaveTest.php` | 174, 243 | CRUD persistence |
| `CalendarServiceTest.php` | 150 | Calendar generation |
| `ModerationQueueTest.php` | 35, 109, 136, 159 | Admin filtering |
| `PublicPagesTest.php` | 157, 169, 200, 335, 359 | Public listing |

## Verdict: Not Legacy

`institution_id` in metadata is **actively used** with a **distinct semantic
meaning** (location institution). It is NOT redundant with the organizer
relationship. However, there are open architectural questions:

1. Should it be a real column (`location_institution_id`) instead of JSON metadata?
2. Is the name `institution_id` misleading? Every setter/getter and the
   `EventContributionUpdateStateMapper` treat it as a location field, but
   the column name doesn't reflect this.
3. Should `Institution::events()` be renamed to `Institution::locatedEvents()`
   or similar to distinguish from organizer-scoped queries?
4. If moved to a real column, all the EventBuilder JSON path translation
   logic for `institution_id` can be removed.

## Broader Context: All Metadata-Backed Attributes

`institution_id` is one of 10 attributes stored in `events.metadata` JSON:

| Key | Type | Queried via JSON? | Should Be Real Column? |
|-----|------|-------------------|----------------------|
| `institution_id` | UUID (location) | Yes — heavily | **Consider** — queried everywhere |
| `user_id` | UUID (owner) | Sometimes | Maybe |
| `submitter_id` | UUID (submitter) | Sometimes | Maybe |
| `schedule_kind` | string | Rarely | No — domain-specific |
| `schedule_state` | string | Rarely | No |
| `timing_mode` | string | Rarely | No |
| `views_count` | integer | No | No — counter |
| `registrations_count` | integer | No | No — counter |
| `saves_count` | integer | No | No — counter |
| `going_count` | integer | No | No — counter |

The heavy query usage of `institution_id` (and to a lesser degree `user_id`,
`submitter_id`) through JSON path extraction is the primary performance
concern. The counters are fine in JSON.

## Files Referenced

```
app/Models/Event.php
app/Models/Institution.php
app/Models/Builders/EventBuilder.php
app/Actions/Events/PersistValidatedEventSubmissionAction.php
app/Actions/Events/CreateAdvancedEventAction.php
app/Actions/Events/SaveAdminEventAction.php
app/Actions/Events/SubmitFrontendEventAction.php
app/Support/Events/EventContributionUpdateStateMapper.php
app/Services/ContributionEntityMutationService.php
app/Services/EventSearchService.php
app/Services/MemberResourceMutationService.php
app/Filament/Pages/ModerationQueue.php
app/Filament/Ahli/Widgets/PendingApprovalEventsWidget.php
app/Livewire/Pages/Events/Index.php
app/Livewire/Pages/Events/AdvancedFiltersPanel.php
app/Livewire/Pages/SavedSearches/Index.php
app/Livewire/Pages/Dashboard/InstitutionDashboard.php
app/Http/Controllers/Api/Frontend/SearchController.php
app/Http/Controllers/Api/InstitutionWorkspaceController.php
app/Services/MemberResourceRegistry.php
app/Services/Api/Admin/AdminResourceMutationService.php
app/Services/Notifications/EventNotificationService.php
app/Mcp/Services/McpEventSearchService.php
app/Mcp/Formatters/McpWriteSchemaFormatter.php
resources/views/components/pages/submit-event/create.blade.php
resources/views/components/home/⚡stats.blade.php
```
