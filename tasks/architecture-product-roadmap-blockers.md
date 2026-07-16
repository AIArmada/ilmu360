# Architecture Product Roadmap Blockers

## B3 — Legacy escalation state cutover

**Status:** blocked pending a product-contract decision

### Conflict

B3 requires the absence check for `is_priority|escalated_at` to be empty across
`app/`, `tests/`, and `database/`, while the roadmap's non-negotiable rules require
public API, MCP, Livewire, Filament, and mobile request/response contracts to remain
unchanged unless a ticket explicitly changes them. B3 describes removing persistence
columns and job state, but does not authorize removing the existing public/admin
fields or their UI behavior.

### Evidence

- `app/Models/Event.php` exposes both fields through `EventAttribute` accessors and
  synchronization hooks; they are not ordinary `events.*` columns in the current
  schema.
- `app/Filament/Pages/ModerationQueue.php` reads `is_priority` for the moderation
  table and ordering.
- `app/Actions/Events/SaveAdminEventAction.php` reads and writes both fields.
- `app/Support/Api/Admin/AdminResourceMutationService.php` validates and exposes
  both fields in the admin API contract.
- `app/Mcp/Tools/Admin/AdminCreateEventTool.php`,
  `AdminUpdateEventTool.php`, `AdminBatchCreateEventsTool.php`, and
  `AdminBatchUpdateEventsTool.php` expose or validate these fields.
- `app/Data/Api/Event/EventPayloadData.php` exposes `is_priority` in event payloads.
- `tests/Feature/ModerationQueueTest.php` and existing lifecycle/error fixtures
  assert the current behavior.
- No migration currently declares `events.is_priority` or `events.escalated_at`;
  the active legacy values are stored in `event_attributes`.

### Required decision

Choose one explicitly before B3 implementation continues:

1. Authorize a public/admin/MCP contract change and a coordinated removal of these
   fields from all listed surfaces; or
2. Narrow B3 to remove only lifecycle reads from the job and preserve the existing
   priority/admin presentation contract, updating the absence check accordingly.

No alias, dual write, fallback read, or substitute cutover was added.
