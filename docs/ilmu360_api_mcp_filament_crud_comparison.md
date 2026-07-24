---
title: API / MCP / Filament Capability Matrix
verified_at: 2026-07-01
purpose: Canonical parity map for public workflow API, generic admin HTTP API, admin/member MCP, and the Filament admin/Ahli panels.
machine_readable_companion: docs/ilmu360_api_mcp_filament_crud_comparison.json
---

# API / MCP / Filament Capability Matrix

This document is the human-readable source of truth for capability parity across ilmu360° surfaces.

Machine-readable companion: `docs/ilmu360_api_mcp_filament_crud_comparison.json`.

Use this file to answer four questions:

- Which resources are actually registered at runtime in the admin and Ahli panels?
- Which resources are structurally writable through the generic admin HTTP API and MCP servers?
- Which capabilities live only in workflow-oriented public/authenticated API routes?
- Which transport rules matter for preview, media, and record traversal?

If code changes here, update this Markdown file, the JSON companion, and `tests/Unit/CrudComparisonDocsTest.php` in the same change set.

## Interpretation rules

- **Runtime panel registration wins.** The admin registry is built from `Filament::getPanel('admin')->getResources()`, not from a directory listing. Vendor/plugin resources count.
- **Structural support and actor permission are different things.** `getPages()` and generic write support tell you whether a route/tool exists; the current actor still needs to pass middleware and model policy checks.
- **Public and authenticated API are workflow-first.** They expose user-facing workflows and contracts, not a generic mirror of every Filament resource.
- **Admin HTTP API and Admin MCP share the same mutation backend.** The writable resource set comes from `AdminResourceMutationService`.
- **Member MCP is intentionally narrower.** It is Ahli-scoped, update-only for generic CRUD, and still narrower than admin even though it now supports one-level related-record traversal plus explicit workflow tools for contribution queues and membership claims.
- **Panel-only operations stay panel-only.** Delete, restore, replicate, and reorder are not exposed by the generic admin HTTP API or the current MCP tool sets.

## Canonical source files

### Runtime inventory and metadata

- `app/Support/Api/Admin/AdminResourceRegistry.php`
- `app/Support/Api/Member/MemberResourceRegistry.php`
- `app/Providers/Filament/AdminPanelProvider.php`
- `app/Providers/Filament/AhliPanelProvider.php`
- `app/Filament/Resources/**/*Resource.php`
- `app/Filament/Ahli/Resources/**/*Resource.php`

### Generic admin write path

- `routes/api.php`
- `app/Support/Api/Admin/AdminResourceService.php`
- `app/Support/Api/Admin/AdminResourceMutationService.php`
- `app/Http/Controllers/Api/Admin/ResourceController.php`

### MCP transport and schema shaping

- `routes/ai.php`
- `app/Mcp/Servers/AdminServer.php`
- `app/Mcp/Servers/MemberServer.php`
- `app/Mcp/Tools/Admin/*`
- `app/Mcp/Tools/Member/*`
- `app/Support/Mcp/McpWriteSchemaFormatter.php`
- `app/Support/Mcp/McpFilePayloadNormalizer.php`

### Verification

- `tests/Feature/Api/Admin/AdminApiTest.php`
- `tests/Feature/Mcp/AdminServerTest.php`
- `tests/Feature/Mcp/MemberServerTest.php`
- `tests/Feature/AdminResourcesCoverageTest.php`
- `tests/Unit/CrudComparisonDocsTest.php`

## Surface summary

| Surface | Auth boundary | Contract style | Canonical purpose |
| --- | --- | --- | --- |
| Public API | public plus route-specific throttles | workflow / discovery contracts | discovery, search, detail pages, auth entrypoints, public submit-event, guest-safe reads |
| Authenticated API | `auth:sanctum` | workflow / self-service contracts | contributions, reports, follows, saved searches, notifications, account settings, institution workspace, event state |
| Admin API | `auth:sanctum` + `EnsureAdminApiAccess` | generic resource HTTP contract | admin resource manifest, list/get/meta, related records, schema-guided create/update |
| Admin MCP | `auth:sanctum,api` + `EnsureAdminMcpAccess` | generic MCP tool contract | admin resource discovery, list/get/meta, related records, schema-guided create/update |
| Member MCP | `auth:sanctum,api` + `EnsureMemberMcpAccess` | scoped MCP tool contract | Ahli-scoped discovery, list/get/meta, schema-guided update, and member workflow tools for contribution queues and membership claims |
| Admin Panel | Filament admin panel | UI/resource surface | full operational UI, relation managers, deletes/restores/reorders where the resource supports them |
| Ahli Panel | Filament Ahli panel | UI/resource surface | member-scoped editing workspace for owned or linked records |

## Workflow-first API families

The public/authenticated API is not a second generic CRUD registry. It is a set of workflow contracts.

### Public discovery and form contracts

- `GET /api/v1/manifest`
- `GET /api/v1/forms/submit-event`
- `GET /api/v1/forms/contributions/institutions`
- `GET /api/v1/forms/contributions/persons`
- `GET /api/v1/catalogs/*`
- `GET /api/v1/search`
- `GET /api/v1/institutions*`
- `GET /api/v1/persons*`
- `GET /api/v1/venues/{venueKey}`
- `GET /api/v1/references*`
- `GET /api/v1/series/{series}`
- `GET /api/v1/events*`

### Public mutation workflows

- `POST /api/v1/auth/register`
- `POST /api/v1/auth/login`
- `POST /api/v1/auth/social/google`
- `POST /api/v1/auth/forgot-password`
- `POST /api/v1/auth/reset-password`
- `POST /api/v1/submit-event`
- `POST /api/v1/events/{event}/registrations`

### Authenticated workflow families

- account settings and MCP token self-service
- GitHub issue reporting for API and MCP/API integration feedback
- authenticated contribution create/suggest/review actions
- membership claims
- advanced event submission
- follows
- institution workspace member management
- reports
- current-user and per-event state (`/me/events/*`, `/events/{event}/me`, saved, going, check-ins, registrations)
- saved searches
- notifications and notification destinations/settings

## Event detail parity note

- Public `GET /api/v1/events/{eventOrSlug}` now returns `active_change_notice`, `change_announcements`, and `replacement_event`, and direct detail reads accept active unlisted events when the caller already has the UUID or slug.
- Admin API event detail plus admin/member MCP event record detail project the same event-change fields inside the returned event attributes and record payloads.
- Replacement targets are resolved to the latest still-reachable public or unlisted event so stale replacement chains do not leak dead public links.

## Search parity note

- Admin HTTP API now exposes a dedicated event discovery endpoint (`GET /api/v1/admin/events/search`) aligned with `admin-search-events`; both surfaces share the same validation and filter normalization contract, including cross-entity keyword expansion (institution/person/reference), scope toggles (`search_include_*`), and `reference_author_search`.
- Admin HTTP API and Admin/Member MCP also route list search for `persons`, `institutions`, and `references` through shared specialized search services.
- Public person, institution, and reference directory endpoints use the same underlying search services for those resource families, but public reads still enforce active + verified visibility while admin/member surfaces preserve their broader or scoped record sets.
- References now have a root-book plus child-part family model across all surfaces. Public directory pagination defaults to root/standalone references, while search can still return child parts. Shared search behavior now covers part labels/numbers, and root reference event filters expand to linked child parts automatically.
- This is search-engine parity, not full surface symmetry; transport, authorization, filters, and returned field sets still differ by surface.

## Surface sync operating model

Use **curated parity**, not full symmetry at any cost.

- Keep validation, normalization, side effects, and orchestration in shared actions/services; panel, API, and MCP should remain thin adapters over the same backend behavior.
- Classify every future change before implementation as one of:
	- `panel_only`
	- `generic_admin_crud`
	- `member_scoped_crud`
	- `workflow_api`
	- `workflow_action`
- Treat these as **workflow-first capabilities** and expose them as named actions instead of generic CRUD when they need parity:
	- event moderation
	- contribution review
	- membership-claim review
	- report triage
- Keep these operations panel-only by default unless a later product and security decision promotes them:
	- `delete`
	- `restore`
	- `replicate`
	- `reorder`
- Keep these resource groups out of parity expansion by default:
	- geography reference catalogs: `address-countries`
	- engagement read models: `bookmark-collections`, `bookmarks`, `follows`, `reactions`, `reminders`, `responses`, `subscriptions`
	- communications operations: `communications`, `communication-batches`, `communication-deliveries`, `communication-preferences`, `communication-suppressions`, `communication-templates`, `communication-threads`
	- system and admin ops: `ai-model-pricings`, `ai-usage-logs`, `audits`, `users`, `slug-redirects`, `roles`, `permissions`, `tracked-properties`, `signal-goals`, `signal-segments`, `signal-interaction-rules`, `saved-signal-reports`, `signal-alert-rules`, `signal-alert-logs`
- Every parity-affecting change should answer the same checklist in the PR or change description:
	- Does the admin panel surface change?
	- Does the admin API surface change?
	- Does the admin MCP surface change?
	- Does the Ahli panel surface change?
	- Does the member MCP surface change?
	- Does the public or authenticated workflow API surface change?
	- If a surface does not change, is the gap intentional and documented?

## Runtime admin resource inventory (66 registered resources)

This is the runtime admin panel inventory, not just the local `app/Filament/Resources` directory. It includes resources registered by application code and enabled plugins. The generic admin HTTP API and Admin MCP may additionally append explicit fallback resources when they are enabled outside the cached panel registration.

### Local app resources

| Resource key | Source | Pages | Generic admin write |
| --- | --- | --- | --- |
| `ai-model-pricings` | app | `index`, `create`, `edit` | no |
| `ai-usage-logs` | app | `index` | no |
| `audits` | app | `index`, `view` | no |
| `contribution-requests` | app | `index`, `view` | no |
| `donation-channels` | app | `index`, `create`, `edit` | yes |
| `inspirations` | app | `index`, `create`, `edit` | yes |
| `institutions` | app | `index`, `create`, `view`, `edit` | yes |
| `membership-applications` | app | `index`, `view` | no |
| `references` | app | `index`, `create`, `edit` | yes |
| `reports` | app | `index`, `create`, `edit` | yes |
| `series` | app | `index`, `create`, `edit` | yes |
| `slug-redirects` | app | `index`, `create`, `view`, `edit` | no |
| `spaces` | app | `index`, `create`, `view`, `edit` | yes |
| `persons` | app | `index`, `create`, `view`, `edit` | yes |
| `tags` | app | `index`, `create`, `edit` | yes |
| `users` | app | `index`, `create`, `view`, `edit` | no |

### Vendor/plugin resources

These are registered at runtime and therefore part of the admin registry surface too.

| Resource key | Package family | Pages | Generic admin write |
| --- | --- | --- | --- |
| `address-areas` | `aiarmada/filament-addressing` | `index`, `view`, `edit`, `create` | yes |
| `address-countries` | `aiarmada/filament-addressing` | `index`, `view` | no |
| `bookmark-collections` | `aiarmada/filament-engagement` | `index`, `create`, `edit` | no |
| `bookmarks` | `aiarmada/filament-engagement` | `index`, `view` | no |
| `communication-batches` | `aiarmada/filament-communications` | `index`, `view` | no |
| `communication-deliveries` | `aiarmada/filament-communications` | `index`, `view` | no |
| `communication-preferences` | `aiarmada/filament-communications` | `index`, `view` | no |
| `communication-suppressions` | `aiarmada/filament-communications` | `index`, `view` | no |
| `communication-templates` | `aiarmada/filament-communications` | `index`, `view` | no |
| `communication-threads` | `aiarmada/filament-communications` | `index`, `view` | no |
| `communications` | `aiarmada/filament-communications` | `index`, `view` | no |
| `event-attendances` | `aiarmada/filament-events` | `index`, `view` | no |
| `event-change-logs` | `aiarmada/filament-events` | `index`, `view` | no |
| `event-occurrences` | `aiarmada/filament-events` | `index`, `create`, `view`, `edit` | no |
| `event-registration-participants` | `aiarmada/filament-events` | `index`, `view` | no |
| `event-registrations` | `aiarmada/filament-events` | `index`, `view` | no |
| `event-sessions` | `aiarmada/filament-events` | `index`, `create`, `view`, `edit` | no |
| `events` | `aiarmada/filament-events` | `index`, `create`, `view`, `edit` | yes |
| `follows` | `aiarmada/filament-engagement` | `index` | no |
| `inventory-allocations` | `aiarmada/filament-inventory` | `index`, `view` | no |
| `inventory-batches` | `aiarmada/filament-inventory` | `index`, `create`, `view`, `edit` | no |
| `inventory-levels` | `aiarmada/filament-inventory` | `index`, `view`, `edit` | no |
| `inventory-locations` | `aiarmada/filament-inventory` | `index`, `create`, `view`, `edit` | no |
| `inventory-movements` | `aiarmada/filament-inventory` | `index`, `view` | no |
| `inventory-serials` | `aiarmada/filament-inventory` | `index`, `create`, `view`, `edit` | no |
| `pass-holders` | `aiarmada/filament-ticketing` | `index` | no |
| `pass-transfers` | `aiarmada/filament-ticketing` | `index` | no |
| `passes` | `aiarmada/filament-ticketing` | `index`, `view` | no |
| `permissions` | `aiarmada/filament-authz` | `index`, `create`, `edit` | no |
| `reactions` | `aiarmada/filament-engagement` | `index`, `view` | no |
| `reminders` | `aiarmada/filament-engagement` | `index`, `view` | no |
| `responses` | `aiarmada/filament-engagement` | `index`, `view` | no |
| `roles` | `aiarmada/filament-authz` | `index`, `create`, `edit` | no |
| `saved-signal-reports` | `aiarmada/filament-signals` | `index`, `create`, `edit` | no |
| `seat-maps` | `aiarmada/filament-seating` | `index`, `create`, `view`, `edit` | no |
| `signal-alert-logs` | `aiarmada/filament-signals` | `index` | no |
| `signal-alert-rules` | `aiarmada/filament-signals` | `index`, `create`, `edit` | no |
| `signal-goals` | `aiarmada/filament-signals` | `index`, `create`, `edit` | no |
| `signal-interaction-rules` | `aiarmada/filament-signals` | `index`, `create`, `edit` | no |
| `signal-segments` | `aiarmada/filament-signals` | `index`, `create`, `edit` | no |
| `subscriptions` | `aiarmada/filament-engagement` | `index`, `view` | no |
| `ticket-types` | `aiarmada/filament-ticketing` | `index`, `create`, `view`, `edit` | no |
| `tracked-properties` | `aiarmada/filament-signals` | `index`, `create`, `edit` | no |
| `venues` | `aiarmada/filament-events` | `index`, `view` | yes |

### What “generic admin write” means here

`yes` means the resource is structurally enabled in `AdminResourceMutationService` for:

- `GET /api/v1/admin/{resourceKey}/schema`
- `POST /api/v1/admin/{resourceKey}`
- `POST /api/v1/admin/{resourceKey}/batch`
- `PUT /api/v1/admin/{resourceKey}/{recordKey}`
- `PUT /api/v1/admin/{resourceKey}/batch`
- `admin-get-write-schema`
- `admin-create-event`
- `admin-create-record`
- `admin-batch-create-events`
- `admin-batch-create-records`
- `admin-update-event`
- `admin-update-record`
- `admin-batch-update-events`
- `admin-batch-update-records`

It does **not** mean every admin-facing actor can write it. Actual create/update access is still policy-driven per request.

## Runtime Ahli resource inventory (22 registered resources)

| Resource key | Pages | Generic member write | Scope |
| --- | --- | --- | --- |
| `bookmark-collections` | `index`, `create`, `edit` | no | plugin resource |
| `bookmarks` | `index`, `view` | no | plugin resource |
| `event-attendances` | `index`, `view` | no | plugin resource |
| `event-change-logs` | `index`, `view` | no | plugin resource |
| `event-occurrences` | `index`, `create`, `view`, `edit` | no | plugin resource |
| `event-registration-participants` | `index`, `view` | no | plugin resource |
| `event-registrations` | `index`, `view` | no | plugin resource |
| `event-sessions` | `index`, `create`, `view`, `edit` | no | plugin resource |
| `events` | `index`, `create`, `view`, `edit` | yes | member-owned or member-linked events |
| `follows` | `index` | no | plugin resource |
| `institutions` | `edit` | yes | institutions the current member belongs to |
| `reactions` | `index`, `view` | no | plugin resource |
| `references` | `index`, `edit` | yes | references the current member belongs to |
| `reminders` | `index`, `view` | no | plugin resource |
| `responses` | `index`, `view` | no | plugin resource |
| `persons` | `index`, `view`, `edit` | yes | persons the current member belongs to |
| `subscriptions` | `index`, `view` | no | plugin resource |
| `venues` | `index`, `view` | no | plugin resource |

The four application-owned Ahli resources are update-capable through Member MCP. Plugin resources are registered for panel visibility but are not generic member-write capable. None expose generic member create or generic member delete.

## Structural write-capable intersection

These twelve resources are the entire current generic admin write whitelist.

This write whitelist is sourced from the admin registry plus mutation service, so it can include fallback resources that are not present in the cached Filament panel inventory.

> **Legend:** `R` = read/list, `S` = schema, `C` = create, `BC` = batch create, `U` = update, `BU` = batch update, `P` = preview/validate-only, `meta` = resource metadata, `related` = related record traversal.

| Resource | Admin API | Admin MCP | Member MCP | Admin panel pages | Ahli panel pages | Workflow overlap |
| --- | --- | --- | --- | --- | --- | --- |
| `address-areas` | `R + meta + related + S + C + BC + U + BU + P` | `R + meta + related + S + C + BC + U + BU + P` | not exposed | not registered | not exposed | public and admin catalog lookups only |
| `donation-channels` | `R + meta + related + S + C + BC + U + BU + P` | `R + meta + related + S + C + BC + U + BU + P` | not exposed | `index`, `create`, `edit` | not exposed | public institution donation details; event default donation detail |
| `events` | `R + meta + related + S + C + BC + U + BU + P` | `R + meta + related + S + C + BC + U + BU + P` | `R + related + S + U + P` | `index`, `create`, `view`, `edit` | `index`, `view`, `edit` | public read/search/detail with event-change projections; public submit-event create; authenticated saved/going/check-ins/registrations |
| `inspirations` | `R + meta + related + S + C + BC + U + BU + P` | `R + meta + related + S + C + BC + U + BU + P` | not exposed | `index`, `create`, `edit` | not exposed | public random inspiration discovery |
| `institutions` | `R + meta + related + S + C + BC + U + BU + P` | `R + meta + related + S + C + BC + U + BU + P` | `R + related + S + U + P` | `index`, `create`, `view`, `edit` | `edit` | public read/detail; authenticated contribution create/suggest; institution workspace; follows |
| `references` | `R + meta + related + S + C + BC + U + BU + P` | `R + meta + related + S + C + BC + U + BU + P` | `R + related + S + U + P` | `index`, `create`, `edit` | `index`, `edit` | public read/list/detail; authenticated suggest update; follows |
| `reports` | `R + meta + related + S + C + BC + U + BU + P` | `R + meta + related + S + C + BC + U + BU + P` | not exposed | `index`, `create`, `edit` | not exposed | authenticated report submission; explicit admin report triage |
| `series` | `R + meta + related + S + C + BC + U + BU + P` | `R + meta + related + S + C + BC + U + BU + P` | not exposed | `index`, `create`, `edit` | not exposed | public read/detail; authenticated follows; event-series assignment |
| `spaces` | `R + meta + related + S + C + BC + U + BU + P` | `R + meta + related + S + C + BC + U + BU + P` | not exposed | `index`, `create`, `view`, `edit` | not exposed | public space catalogs; event space assignment |
| `persons` | `R + meta + related + S + C + BC + U + BU + P` | `R + meta + related + S + C + BC + U + BU + P` | `R + related + S + U + P` | `index`, `create`, `view`, `edit` | `index`, `view`, `edit` | public read/detail; authenticated contribution create/suggest; follows |
| `tags` | `R + meta + related + S + C + BC + U + BU + P` | `R + meta + related + S + C + BC + U + BU + P` | not exposed | `index`, `create`, `edit` | not exposed | taxonomy and event-tagging management |
| `venues` | `R + meta + related + S + C + BC + U + BU + P` | `R + meta + related + S + C + BC + U + BU + P` | not exposed | `index`, `create`, `view`, `edit` | not exposed | public read/detail plus public venue catalogs |

## Read-only generic admin groups

These resources are readable through the generic admin registry, but not writable through the current generic admin HTTP/MCP write path.

- **Geography:** `address-countries`
- **Moderation/workflow records:** `membership-claims`, `contribution-requests`
- **Engagement read models:** `bookmark-collections`, `bookmarks`, `follows`, `reactions`, `reminders`, `responses`, `subscriptions`
- **Communications operations:** `communications`, `communication-batches`, `communication-deliveries`, `communication-preferences`, `communication-suppressions`, `communication-templates`, `communication-threads`
- **System/auth/ops:** `ai-model-pricings`, `ai-usage-logs`, `audits`, `users`, `slug-redirects`, `roles`, `permissions`, `tracked-properties`, `signal-goals`, `signal-segments`, `signal-interaction-rules`, `saved-signal-reports`, `signal-alert-rules`, `signal-alert-logs`

## Explicit admin workflow actions

These capabilities remain outside generic admin CRUD, but now have explicit admin workflow parity where implemented.

- **Batch create/update (generic resources):**
	- HTTP create: `POST /api/v1/admin/{resourceKey}/batch` with `items` array; supports `validate_only` query param
	- HTTP update: `PUT /api/v1/admin/{resourceKey}/batch` with `items` array of `{record_key, payload}`; supports `validate_only` query param
	- MCP tool (create): `admin-batch-create-records` — generic batch create for any writable resource
	- MCP tool (update): `admin-batch-update-records` — generic batch update for any writable resource
	- MCP tool (events create): `admin-batch-create-events` — event-specific batch create with `organizer_key`, `person_keys`, `reference_keys`, `institution_key`, `venue_key`, `space_key` key resolution (same semantics as `admin-create-event`)
	- MCP tool (events update): `admin-batch-update-events` — event-specific batch update with the same key resolution as `admin-update-event`
	- All batch tools return a per-row result list (`created`/`updated`/`validation_failed`/`not_found`/`unresolved_key`/`error`/`preview`) plus a `summary` object
	- `external_row_id` per item enables idempotency tracking and safe retries after interruption
	- Maximum 100 items per batch for generic tools; 50 items per batch for event-specific batch tools
	- `validate_only=true` returns per-row `preview` status without persisting any records

- **Event discovery:**
	- HTTP endpoint: `GET /api/v1/admin/events/search`
	- MCP tool: `admin-search-events`
	- Shared contract: query validation, normalization, and execution flow are aligned via shared search services.

- **Event moderation:**
	- HTTP schema: `GET /api/v1/admin/events/{recordKey}/moderation-schema`
	- HTTP action: `POST /api/v1/admin/events/{recordKey}/moderate`
	- MCP action: `admin-moderate-event`
	- Action contract: `approve`, `request_changes`, `reject`, `cancel`, `reconsider`, `remoderate`, or `revert_to_draft` depending on the event status
- **Report triage:**
	- HTTP schema: `GET /api/v1/admin/reports/{recordKey}/triage-schema`
	- HTTP action: `POST /api/v1/admin/reports/{recordKey}/triage`
	- MCP action: `admin-triage-report`
	- Action contract: `triage`, `resolve`, `dismiss`, or `reopen` depending on the report status
- **Contribution-request review:**
	- HTTP schema: `GET /api/v1/admin/contribution-requests/{recordKey}/review-schema`
	- HTTP action: `POST /api/v1/admin/contribution-requests/{recordKey}/review`
	- MCP action: `admin-review-contribution-request`
	- Action contract: `approve` or `reject`; rejections require a `reason_code`
- **Membership-claim review:**
	- HTTP schema: `GET /api/v1/admin/membership-claims/{recordKey}/review-schema`
	- HTTP action: `POST /api/v1/admin/membership-claims/{recordKey}/review`
	- MCP action: `admin-review-membership-claim`
	- Action contract: `approve` or `reject`; approvals require a `granted_role_slug`

## Member workflow extensions

Member MCP is still update-only for generic CRUD, but it now carries explicit Ahli workflow parity for queue-like self-service flows.

- **Contribution-request queue tools:**
	- `member-list-contribution-requests`
	- `member-approve-contribution-request`
	- `member-reject-contribution-request`
	- `member-cancel-contribution-request`
- **Membership-claim tools:**
	- `member-list-membership-claims`
	- `member-submit-membership-claim`
	- `member-cancel-membership-claim`
- These tools sit beside generic member CRUD and should be treated as workflow actions, not as implied create/update parity for every Ahli resource.
- **Moderation/workflow records:** `membership-claims`, `contribution-requests`
- **System/auth/ops:** `ai-model-pricings`, `ai-usage-logs`, `audits`, `users`, `slug-redirects`, `roles`, `permissions`, `tracked-properties`, `signal-goals`, `signal-segments`, `saved-signal-reports`, `signal-alert-rules`, `signal-alert-logs`

## MCP media and preview semantics

This is the area where the previous version drifted the most.

### Admin HTTP API

- Write schemas come from `GET /api/v1/admin/{resourceKey}/schema`.
- Preview uses `validate_only=1` on the `POST` or `PUT` request.
- `apply_defaults=1` is supported during preview requests and returns a candidate autofilled payload in validation feedback.
- Validation failures now expose schema-driven `feedback` with allowed values, suggestions, defaults, and conditional requirement context.
- Validation failures in validate-only mode now return machine-readable remediation details: `fix_plan`, `remaining_blockers`, `normalized_payload_preview`, and `can_retry`.

- Schema `content_type` is resource-specific:
	- `multipart/form-data` for media-capable resources such as `events`, `institutions`, `reports`, `persons`, `references`, and `venues`
	- `application/json` for `address-areas`, `spaces`, and `tags`

### Admin MCP

- Write schemas are reformatted to MCP JSON contracts by `McpWriteSchemaFormatter`.
- Create/update preview is supported through the `validate_only` boolean tool argument.
- `apply_defaults` is supported on admin preview tools only (`validate_only=true`) and returns a candidate autofilled payload in validation feedback when the request is still invalid. It is ignored for persisted MCP writes; persisted creates/updates must include the values the caller wants saved.
- Validation failures now expose the same schema-driven `feedback` structure as the admin HTTP API.
- Validation failures in validate-only mode now return the same remediation fields as the admin HTTP API so AI clients can recover in one retry loop.
- When a schema advertises file fields, MCP uploads use `json_base64_descriptor`, not multipart.
- Descriptor normalization and staging is implemented by `McpFilePayloadNormalizer`.
- Dedicated MCP event tools are route-key wrappers over the shared admin event writer. On update, `person_keys` and `reference_keys` are presence-sensitive: omitted or `null` preserves existing relations, `[]` detaches all, and a non-empty array replaces all.
- Destructive media clear flags such as `clear_cover`, `clear_avatar`, `clear_gallery`, and siblings are intentionally removed from MCP schema fields and rejected by the write tools.

### Member MCP

- Update only; no create tool.
- Supports `validate_only` preview for updates.
- Supports one-level related-record traversal.
- Media fields follow the same descriptor transport as admin MCP when the schema advertises them.
- Destructive `clear_*` media flags are also rejected here.

## MCP Specialized Tools (AI and Workflow Extensions)

Beyond generic CRUD parity, MCP exposes specialized tools that remain MCP-exclusive because they represent higher-level agent workflows or AI integrations rather than direct HTTP contracts.

### AI Media Generation Tools

These tools extend event marketing asset workflows by combining AI image generation with structured uploads to specific media collections:

- **AdminUploadEventCoverImageTool**: AI-driven event cover image generation and upload (16:9 website/mobile ratio)
  - Generates or fetches images from ChatGPT/AI provider
  - Uploads to `event.cover` collection
  - Admin API equivalent: `PUT /admin/events/{recordKey}` with cover file in `multipart/form-data`
  - Filament equivalent: EventResource edit page → cover `SpatieMediaLibraryFileUpload` field
  - Why MCP-only: Specialized AI workflow; generic CRUD already handles media in `UpdateRecord`

- **AdminUploadEventPosterImageTool**: AI-driven event poster image generation and upload (4:5 portrait ratio)
  - Generates or fetches images from ChatGPT/AI provider
  - Uploads to `event.poster` collection
  - Admin API equivalent: `PUT /admin/events/{recordKey}` with poster file in `multipart/form-data`
  - Filament equivalent: EventResource edit page → poster `SpatieMediaLibraryFileUpload` field
  - Why MCP-only: Specialized AI workflow; generic CRUD already handles media in `UpdateRecord`

- **MemberUploadEventCoverImageTool**: Member-scoped version of admin cover tool
  - Limited to member-owned or member-linked events
  - Member API: Not exposed (member writes flow through Filament Ahli panel)
  - Filament equivalent: Ahli EventResource edit page → cover field

- **MemberUploadEventPosterImageTool**: Member-scoped version of admin poster tool
  - Limited to member-owned or member-linked events
  - Member API: Not exposed (member writes flow through Filament Ahli panel)
  - Filament equivalent: Ahli EventResource edit page → poster field

### Workflow Action Tools

These tools expose explicit state-transition and review workflows as named MCP tools. Unlike AI tools, these ARE exposed across multiple surfaces:

- **AdminModerateEventTool** (MCP + API): Moderate events with admin schema
  - API: `POST /admin/events/{recordKey}/moderate` with schema from `GET /admin/events/{recordKey}/moderation-schema`
  - MCP: `admin-moderate-event`
  - Filament: EventResource view page → Moderate action

- **AdminReviewContributionRequestTool** (MCP + API): Review contribution submissions
- **AdminReviewMembershipClaimTool** (MCP + API): Review membership claims
- **AdminTriageReportTool** (MCP + API): Triage user reports

### Documentation and Utility Tools (MCP-Only)

These tools provide agent-facing capabilities for documentation, debugging, and issue creation:

- `AdminDocumentationSearchTool`, `AdminDocumentationFetchTool`
- `AdminReadDebugLogTool`, `AdminCreateGitHubIssueTool`
- `MemberDocumentationSearchTool`, `MemberDocumentationFetchTool`
- `MemberReadDebugLogTool`, `MemberCreateGitHubIssueTool`

These are intentionally MCP-only because they serve agent workflows, not user-facing API or UI surfaces.

## Important asymmetries

- **Runtime admin inventory is broader than local app files.** Because the registry uses live Filament panel registration, vendor/plugin resources are part of the admin surface.
- **Page keys do not equal permission.** `getPages()` and generic write support describe structural capability; per-actor authorization still comes from middleware and model policies.
- **Admin API and Admin MCP share one mutation whitelist.** If `AdminResourceMutationService` changes, both transports change together.
- **Admin API and Admin/Member MCP share the dedicated event discovery contract (`/api/v1/admin/events/search` ↔ `admin-search-events`) and the richer directory search path for `persons`, `institutions`, and `references`.** Public directories use the same search services, but visibility and scope still differ.
- **Reference write semantics now include hierarchy metadata.** Admin API plus admin/member MCP reference schemas can carry `parent_reference_id`, `part_type`, `part_number`, and `part_label`, while list/detail payloads can expose `display_title` and `is_part` for human-safe rendering.
- **Member MCP is narrower than admin.** Generic member CRUD still maps only to the four Ahli resources and exposes updates only, but the server now also carries workflow tools for contribution queues and membership claims.
- **Public contributions and reports are authenticated workflows.** The API exposes form discovery publicly, but the actual create routes for contributions and reports live behind `auth:sanctum`.
- **Delete, restore, replicate, and reorder remain panel-led.** Do not assume generic admin HTTP or MCP parity for those operations.
- **Use returned `route_key` values for record-specific admin URLs and MCP record keys whenever available.**

## Maintenance rule

Whenever any of these change:

- Filament panel resource registration
- `AdminResourceMutationService`
- `MemberResourceMutationService`
- workflow API controllers or contracts
- `routes/api.php`
- `routes/ai.php`
- MCP schema formatter or file normalizer behavior

update this Markdown file, the JSON companion, and `tests/Unit/CrudComparisonDocsTest.php` in the same change set.
