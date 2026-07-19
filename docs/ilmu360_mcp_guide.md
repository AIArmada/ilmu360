# ilmu360° MCP Guide

Updated: July 19, 2026
Audience: developers and AI-client integrators.

This is the human setup and broader integration guide.
The MCP-facing guides consumed by agents are `docs/ilmu360_mcp_admin_agent_guide.md`, `docs/ilmu360_mcp_member_agent_guide.md`, and `docs/ilmu360_mcp_event_csv_json_creation_guide.md` for spreadsheet-style event creation workflows.

## What MCP Means in ilmu360°

ilmu360° exposes two web MCP servers plus two local testing handles that AI clients can connect to:

- `Admin MCP` for full admin-surface resource access.
- `Member MCP` for Ahli-scoped access to the member surface.

Both web servers are registered in `routes/ai.php` and protected by bearer-token or OAuth-based authentication. Local testing handles are also registered there so you can exercise the full MCP stack through the app container and its configured database.

## How To Read Connector Reports

When you are comparing a report, a Filament page, an HTTP admin endpoint, and an MCP response, treat them as different surfaces with different contracts:

| Surface | Source of truth | Use it for | Do not assume |
|---|---|---|---|
| Filament admin panel | `app/Filament/Resources/*` and related pages | Human admin UX and resource configuration | Exact API payload shape |
| Raw HTTP admin API | `docs/ilmu360_mobile_api_reference.md` and `/api/v1/admin` | HTTP admin contracts, schemas, and record payloads | MCP write-schema parity |
| MCP admin server | `/mcp/admin` | AI-client tool calls and sanitized write schemas | Raw HTTP schema parity |

Key rules:

- `GET /api/v1/admin/{resourceKey}/schema` documents the raw HTTP admin contract.
- `GET /mcp/admin` uses a separate MCP write-schema formatter; destructive `clear_*` media flags are removed there, while supported media/file fields are advertised with file descriptor metadata supporting `content_base64`, `content_url`, or ChatGPT `download_url` / `file_id` parameters.
- Public/mobile event detail payload changes do not automatically imply MCP event-record changes. For example, the public `GET /api/v1/events/{event}` surface now exposes normalized reference cover aliases for native clients, but MCP event records still come from the admin/member resource services rather than `App\Http\Controllers\Api\EventController`.
- Event record detail through `admin-get-record` and `member-get-record` now embeds the same public change-surface projections as the public event detail payload under `data.record.attributes.active_change_notice`, `data.record.attributes.change_announcements`, and `data.record.attributes.replacement_event`.
- Those MCP event-detail projections resolve replacement chains to the latest still-reachable public or unlisted target and omit stale unreachable replacements rather than exposing dead public links.
- `admin-get-record` and `member-get-record` responses now embed `contacts` and `social_media` alongside `address` directly in `data.record.attributes` for resources that expose those relations (institutions, speakers, venues, and references with social media). The fetch-modify-resend flow for these collection fields is now directly actionable from a single get-record call without a separate relation-traversal step.
- Public/mobile discovery additions also do not automatically imply new MCP tools. For example, the public `GET /api/v1/references` directory now exists for native reference browsing, but MCP still uses the existing generic `admin-list-records` and `member-list-records` flows for the `references` resource rather than a dedicated public-reference tool.
- For `speakers`, `institutions`, and `references`, `admin-list-records` and `member-list-records` now reuse the same specialized search services as the public directory endpoints. Expect decorated speaker-title matching, institution nickname or typo-tolerant matching, and reference descriptive-text matching to behave similarly while still honoring each surface's own visibility or membership scope.
- `current_media` contains metadata only; it does not expose signed or temporary URLs.
- Generic user record payloads intentionally redact `email`, `email_verified_at`, `phone`, `phone_verified_at`, `daily_prayer_institution_id`, and `friday_prayer_institution_id`.
- Record lookups use `route_key` for record-specific paths, and missing records return 404 rather than a generic server error.
- Supported admin update flows are schema-guided `PUT` requests: required fields must be sent, while optional omitted fields are merged by the underlying save actions when that resource supports merge-based updates.

If you are writing or reviewing an AI-generated report, read the raw HTTP admin reference for `/api/v1/admin` behavior and this guide for MCP-only behavior. Do not infer one surface from the other.

## Available Servers

### Admin MCP

- Route: `/mcp/admin`
- Server class: `App\Mcp\Servers\AdminServer`
- Intended for admin workflows such as resource discovery, record browsing, record detail, named relation traversal, schema discovery, and supported create/update operations.

### Member MCP

- Route: `/mcp/member`
- Server class: `App\Mcp\Servers\MemberServer`
- Intended for Ahli/member workflows such as resource discovery, record browsing, record detail, schema discovery, supported updates on writable member-visible resources, contribution-request queues, and membership claims.

Important connector-surface rule:

- A client connected to `/mcp/admin` sees only the shared docs tools (`search`, `fetch`) plus `admin-*` tools. It will not see any `member-*` tools.
- A client connected to `/mcp/member` sees only the shared docs tools plus `member-*` tools. It will not see any `admin-*` tools.
- In ChatGPT, test or operate member tools through a separate connector pointed at `/mcp/member` with a member-scoped token or OAuth session. Missing `member-*` tools from an `/mcp/admin` connector is expected, not evidence that the member server is unregistered.

## How To Connect

### 1. Issue a bearer token

The quickest way to use MCP with this app is to issue a scoped Sanctum token for the server you want to call.

```shell
php artisan mcp:token someone@example.com "VS Code Admin MCP" --server=admin
php artisan mcp:token someone@example.com "VS Code Member MCP" --server=member
```

Use the returned token as an `Authorization: Bearer <token>` header in your MCP client.

The token is server-scoped:

- `admin` tokens carry the `mcp:admin` ability.
- `member` tokens carry the `mcp:member` ability.

### 2. Or use OAuth-capable clients

The app also registers MCP OAuth routes under `oauth/mcp` in `routes/ai.php`.

Use this path when your client supports OAuth instead of manual bearer tokens. The OAuth setup is backed by Passport and the published MCP authorization view in `resources/views/mcp/authorize.blade.php`.

### ChatGPT Connector Settings

If ChatGPT shows the optional OAuth advanced settings, use these values for this server:

- Registration method: `Dynamic Client Registration (DCR)`
- Default scopes: `mcp:use`
- Base scopes: leave blank unless the server later advertises additional scopes
- OpenID support: leave disabled, because this server does not advertise an OIDC configuration URL
- Auth URL / Token URL / Registration URL / Authorization server base / Resource: keep the discovered endpoints that ChatGPT already populated

Why this is the right choice:

- The MCP server advertises a Registration URL, so DCR is available.
- CIMD is unavailable because the server does not advertise CIMD support.
- The server’s MCP OAuth flow is built around the `mcp:use` scope.
- The server does not advertise OIDC discovery, so OpenID Connect is not needed for this connector.

If ChatGPT asks which resource to use, point it at the same MCP resource route you are connecting to, such as `/mcp/admin` for the admin server or `/mcp/member` for the member server.

### 3. Or manage tokens inside the app

Authenticated users can manage their MCP tokens from the account-settings API:

- `GET /api/v1/account-settings/mcp-tokens`
- `POST /api/v1/account-settings/mcp-tokens`
- `DELETE /api/v1/account-settings/mcp-tokens/{tokenId}`

### 4. Test the local MCP stack

If you want to verify the MCP servers against your local database without going through remote MCP transport, use the local handles:

```shell
php artisan mcp:inspector ilmu360-admin-local
php artisan mcp:inspector ilmu360-member-local
```

These local handles run inside the app process, so they use the database configured for the current environment:

- In local development, they point at your local database connection.
- In automated tests, they use the test database created for the test run.

Use these handles when you want to confirm the MCP tools, resource discovery, and auth behavior locally before wiring the same server class into a remote client.

## Inspect And Debug

Use the MCP Inspector when you want to test the server before wiring it into a client:

```shell
php artisan mcp:inspector /mcp/admin
php artisan mcp:inspector /mcp/member
php artisan mcp:inspector ilmu360-admin-local
php artisan mcp:inspector ilmu360-member-local
```

The inspector is useful for:

- Verifying authentication.
- Checking which tools are exposed.
- Trying tool calls interactively.
- Debugging client configuration issues.

## What Each Server Exposes

### How ChatGPT Reads These Tools

ChatGPT does not infer server capabilities from the Laravel controllers or database schema. It reasons from the MCP tool descriptors you expose.

For each tool, ChatGPT sees:

- the tool `name`
- the human-readable `title` / `description`
- the input schema for arguments
- impact annotations such as read-only, destructive, idempotent, and open-world hints
- any UI linkage metadata like `_meta.ui.resourceUri`
- the tool response `structuredContent` and `content`

ChatGPT does **not** read `_meta` as model context unless the tool response explicitly includes it in model-visible content. Treat `_meta` as host/UI-only data.

If you want ChatGPT to understand a capability, expose it as a dedicated tool with a clear schema and description. One tool should map to one user intent, the same way one API operation maps to one HTTP action.

### Verified documentation resources

Each MCP server exposes its own read-only markdown guide through MCP `resources/list` and `resources/read`:

| Resource | URI | Purpose |
|---|---|---|
| `docs-admin-mcp-guide` | `file://docs/ilmu360_mcp_admin_agent_guide.md` | Verified guide for admin MCP auth, transport, discovery primitives, capability matrix, media rules, and admin write behavior |
| `docs-admin-event-csv-json-create-guide` | `file://docs/ilmu360_mcp_event_csv_json_creation_guide.md` | Verified playbook for CSV/JSON event creation, validation-retry loops, and batch-safe MCP ingestion workflows |
| `docs-member-mcp-guide` | `file://docs/ilmu360_mcp_member_agent_guide.md` | Verified guide for member MCP auth, transport, discovery primitives, capability matrix, media rules, and member write behavior |

Treat the matching server guide as the model-readable documentation page for that MCP surface, not as a replacement for the live tool/resource descriptors.

The broader internal cross-surface parity docs (`ilmu360_api_mcp_filament_crud_comparison.*`) are intentionally not exposed through MCP.

Tool-centric clients like ChatGPT and the OpenAI Responses MCP integration import tools from `tools/list`, not raw resources from `resources/list`. To make these docs reliably discoverable in those clients, each server also exposes read-only `search` and `fetch` documentation tools scoped to its own guide.

### Documentation search and fetch tools

Each server exposes two MCP-standard read-only documentation tools for model discoverability:

| Tool | Purpose | Notes |
|---|---|---|
| `search` | Search the verified documentation page exposed by this server | Input: one `query` string. Returns JSON text with `{results:[{id,title,url}]}`. |
| `fetch` | Fetch the verified documentation page by id | Input: one `id` string returned by `search`. Returns JSON text with `{id,title,text,url,metadata}`. |

These tools search and fetch only the verified MCP guide exposed by that same server. They do **not** search admin/member resource records.

The `url` returned by `search` and shown in the raw MCP resource list is informational. The `fetch` tool schema accepts `id` only; call it as `{"id":"docs-admin-mcp-guide"}` or `{"id":"docs-member-mcp-guide"}` depending on the connected server, and do not pass `url` or `file://...`.

### Documentation routing prompt

Each server also exposes one small MCP prompt for clients that support prompt discovery:

| Prompt | Purpose | Arguments |
|---|---|---|
| `documentation-tool-routing` | Short guidance for deciding when to use `search` vs `fetch` for the verified docs pages | `topic?` |

The prompt tells the model to:

- fetch the matching server guide directly when the question is clearly about ilmu360° MCP docs
- ensure the verified guide is already in context before the first operational admin or member MCP tool call, fetching it first when needed
- use `search` when the topic is fuzzy or a discovery step is still helpful
- keep runtime data access on the admin/member record tools instead of the docs tools
- optionally accept a `topic` hint such as `crud`, `auth`, `media uploads`, `runtime records`, `search`, or `fetch` for more targeted guidance

### Operational preflight rule

Before any ilmu360° MCP read, search, query, lookup, list, fetch, write, update, create, relation traversal, schema discovery, or workflow action, the client must ensure the matching verified MCP guide is already in context. If it is not, fetch `docs-admin-mcp-guide` or `docs-member-mcp-guide` first, or use `search` then `fetch` when the topic is still fuzzy.

The current admin and member MCP servers enforce a stricter transport-level version of this rule for operational `tools/call` requests: those calls are rejected until the matching server guide has been fetched through the MCP `fetch` tool or the guide resource has been read through MCP `resources/read` in the same initialized MCP session.

Apply this before operational tools such as:

- `admin-list-records`, `member-list-records`
- `admin-get-record`, `member-get-record`
- `admin-list-related-records`, `member-list-related-records`
- `admin-get-resource-meta`, `member-get-resource-meta`
- `admin-get-write-schema`, `member-get-write-schema`
- `admin-create-record`, `admin-batch-create-records`, `admin-update-record`, `admin-batch-update-records`, `admin-create-event`, `admin-batch-create-events`, `admin-update-event`, `admin-batch-update-events`, `member-update-record`
- `admin-get-event-moderation-schema`, `admin-get-report-triage-schema`, `admin-get-contribution-request-review-schema`, `admin-get-membership-claim-review-schema`
- `admin-moderate-event`, `admin-triage-report`, `admin-review-contribution-request`, `admin-review-membership-claim`
- `member-list-contribution-requests`, `member-approve-contribution-request`, `member-reject-contribution-request`, `member-cancel-contribution-request`, `member-list-membership-claims`, `member-submit-membership-claim`, and `member-cancel-membership-claim`

The client may skip a fresh docs fetch only when the verified guide is already active in context, or when the user provides the exact `resource_key`, `record_key`, tool, and intended read operation and no interpretation is required.

Even then, re-check live write schemas or explicit workflow schema/tool guidance before create, update, preview, moderation, triage, or review mutations.

Because the server cannot safely infer conversational shortcuts such as “the user already supplied the exact resource and tool with zero interpretation needed”, the runtime MCP guard remains stricter and still requires a same-session guide fetch or read before operational tool execution.

### MCP capability matrix

Use this section as the quick MCP-only capability summary.

| Capability | Admin MCP | Member MCP |
| --- | --- | --- |
| Docs search | `search` | `search` |
| Docs fetch | `fetch` | `fetch` |
| Resource discovery | `admin-list-resources` | `member-list-resources` |
| Resource metadata | `admin-get-resource-meta` | `member-get-resource-meta` |
| Dedicated event discovery | `admin-search-events` | `member-search-events` |
| Record list | `admin-list-records` | `member-list-records` |
| Record read | `admin-get-record` | `member-get-record` |
| Record action guidance | `admin-get-record-actions` | `member-get-record-actions` |
| Explicit workflow schema discovery | `admin-get-event-moderation-schema`, `admin-get-report-triage-schema`, `admin-get-contribution-request-review-schema`, `admin-get-membership-claim-review-schema` | Not exposed |
| Related-record traversal | `admin-list-related-records` | `member-list-related-records` |
| Write schema discovery | `admin-get-write-schema` | `member-get-write-schema` |
| GitHub issue reporting | `admin-create-github-issue` | `member-create-github-issue` |
| Event moderation | `admin-moderate-event` | Not exposed |
| Report triage | `admin-triage-report` | Not exposed |
| Contribution-request workflows | `admin-review-contribution-request` | `member-list-contribution-requests`, `member-approve-contribution-request`, `member-reject-contribution-request`, `member-cancel-contribution-request` |
| Membership-claim workflows | `admin-review-membership-claim` | `member-list-membership-claims`, `member-submit-membership-claim`, `member-cancel-membership-claim` |
| Event image prompts | `admin-event-cover-image-prompt` (prompt), `admin-event-poster-image-prompt` (prompt) | `member-event-cover-image-prompt` (prompt), `member-event-poster-image-prompt` (prompt) |
| Event image upload | `admin-upload-event-cover-image`, `admin-upload-event-poster-image` | `member-upload-event-cover-image`, `member-upload-event-poster-image` |
| Dedicated event create | `admin-create-event` | Not exposed |
| Batch event create | `admin-batch-create-events` | Not exposed |
| Dedicated event update | `admin-update-event` | Not exposed |
| Batch event update | `admin-batch-update-events` | Not exposed |
| Create | `admin-create-record` | Not exposed |
| Batch create | `admin-batch-create-records` | Not exposed |
| Update | `admin-update-record` | `member-update-record` |
| Batch update | `admin-batch-update-records` | Not exposed |
| Validate-only preview | Yes, on all create/update/batch tools | Yes, on `member-update-record` |

Admin GitHub issue reports can skip Copilot assignment entirely by setting `GITHUB_ISSUE_REPORTING_ADMIN_COPILOT_ASSIGNMENT_ENABLED=false` on the server.

### Event cover/poster image contract

Event image generation uses a 3-step workflow on each server:

1. Call the MCP prompt `admin-event-cover-image-prompt` or `admin-event-poster-image-prompt` (admin server) / `member-event-cover-image-prompt` or `member-event-poster-image-prompt` (member server) with `event_key`, optional `creative_direction`, `include_existing_media`, and `max_reference_media` arguments. The prompt returns engineered prompt text and brand reference images.
2. Generate the image using ChatGPT native image generation with the returned prompt and reference images.
3. Upload the result with `admin-upload-event-cover-image` or `admin-upload-event-poster-image` (admin) / `member-upload-event-cover-image` or `member-upload-event-poster-image` (member) by passing the `event_key` and an explicit image descriptor (`{content_base64, filename}`).

- Event image upload tools are intentionally split by collection per surface:
  - cover upload tools write the `cover` collection
  - poster upload tools write the `poster` collection
- Ratio is fixed by target and enforced server-side:
  - `cover` = `16:9` (website/app visual)
  - `poster` = `4:5` (external/social flyer visual)
- The upload tool also accepts an optional `creative_direction` note saved as metadata.
- For event prompt reference-media selection, speaker media fallback order is:
  1. speaker `cover`
  2. speaker `avatar`
  3. organizer institution media from `event->organizer` (when organizer type is `Institution`)
- If none are available, the prompt still returns usable prompt text without reference images.
- Operational fallback: if attaching reference media fails, retry the prompt call with `include_existing_media=false` and `max_reference_media=0`.

### Writable resource matrix

| Resource | Admin MCP | Member MCP | Notes |
| --- | --- | --- | --- |
| `events` | list/get/meta + schema + create + update + preview | list/get/meta + schema + update + preview | Member scope only; no member create |
| `inspirations` | list/get/meta + schema + create + update + preview | Not exposed | Admin-only through the current MCP surface |
| `institutions` | list/get/meta + schema + create + update + preview | list/get/meta + schema + update + preview | Member scope limited to linked institutions |
| `speakers` | list/get/meta + schema + create + update + preview | list/get/meta + schema + update + preview | Member scope limited to linked speakers |
| `references` | list/get/meta + schema + create + update + preview | list/get/meta + schema + update + preview | Member scope limited to linked references |
| `reports` | list/get/meta + schema + create + update + preview | Not exposed | Admin CRUD plus explicit triage workflow |
| `donation-channels` | list/get/meta + schema + create + update + preview | Not exposed | Admin-only payment channel management |
| `series` | list/get/meta + schema + create + update + preview | Not exposed | Admin-only through the current MCP surface |
| `spaces` | list/get/meta + schema + create + update + preview | Not exposed | Admin-only through the current MCP surface |
| `tags` | list/get/meta + schema + create + update + preview | Not exposed | Admin-only taxonomy management |
| `venues` | list/get/meta + schema + create + update + preview | Not exposed | Admin-only through the current MCP surface |
| `subdistricts` | list/get/meta + schema + create + update + preview | Not exposed | No media upload fields |

### Relation traversal rules

- Admin only: use `admin-get-resource-meta` to discover whether a relation is exposed, then call `admin-list-related-records` with that relation name.
- Member MCP: use `member-get-resource-meta` to discover whether a relation is exposed, then call `member-list-related-records` with that relation name.
- Do not assume every admin resource has a relation you can traverse; rely on metadata returned by the live tool.

### Record action guidance

- Use `admin-get-record-actions` or `member-get-record-actions` when you already have a specific record and want the shortest model-visible list of next MCP calls.
- These read-only tools return focused next-step actions such as refreshing record detail, traversing exposed relations, fetching update schemas, previewing updates, and any explicit workflow tools that are currently valid for that record.
- On the admin surface, workflow-bearing records such as events, reports, contribution requests, and membership claims include both dedicated workflow-schema tool hints and the action-specific defaults, fields, conditional rules, and currently available workflow actions in the same response.
- These tools do not execute mutations; they only point the client at the correct next MCP tool call.

### Explicit workflow schema tools

- Use the dedicated admin workflow-schema tools when you want the canonical read-only workflow contract for one record before calling the matching mutation tool.
- These tools return the same workflow payload shape used by the HTTP admin schema endpoints: defaults, available actions, fields, and conditional rules.
- Current explicit workflow schema tools are `admin-get-event-moderation-schema`, `admin-get-report-triage-schema`, `admin-get-contribution-request-review-schema`, and `admin-get-membership-claim-review-schema`.

### Entity selection heuristics for record search

Use these heuristics before guessing which top-level resource to search:

- Search `institutions` first when the noun matches an institution type from `App\Enums\InstitutionType`:
  - `masjid`
  - `surau`
  - `madrasah`
  - `maahad`
  - `pondok`
  - `sekolah`
  - `kolej`
  - `universiti`
- Search `venues` first when the noun matches a venue type from `App\Enums\VenueType`:
  - `dewan`
  - `auditorium`
  - `stadium`
  - `perpustakaan`
  - `padang`
  - `hotel`
- Treat `spaces` as finer-grained sublocations inside an institution, not as the default first lookup target for named mosques, surau, or other institution identities.
- Example: `Masjid Abidin` should be searched in `institutions` first. If the noun does not match the institution-type terms and sounds like a standalone physical place, look in `venues` next.

### Quick search playbook

When the user asks you to “look for” a named place, start with the most likely top-level resource instead of fanning out across multiple resource types immediately:

- Mosque, surau, school, campus, madrasah, maahad, pondok, or university-style names → search `institutions` first.
- Standalone hall/dewan, auditorium, stadium, library, field, or hotel-style names → search `venues` first.
- Room, wing, floor, block, or hall inside a known institution → resolve the parent `institution` first, then use `spaces` for the internal location when needed.
- Only broaden to a second top-level resource when the first pass returns no good match or the user’s wording is genuinely ambiguous.

### Record and schema reading rules

- Use `record_key` values from prior MCP results; prefer returned route-key-style identifiers when the record payload exposes them.
- Treat live write schemas as the field-level source of truth for payload structure, required fields, and media support.
- Institution write schemas now expose additional field semantics for `nickname`, `address`, `contacts`, and `social_media`.
- For institutions, `address` updates deep-merge omitted nested keys, and `address: {}` is effectively a no-op when the record already has an address with a stored country.
- For institutions, omitted `contacts` / `social_media` preserve the existing collection, `null` or `[]` clear it, and any submitted array replaces the stored collection. Safe MCP clients should fetch the current record, modify the collection locally, then resend the full array.
- On direct MCP institution writes, `nickname: null` preserves the current stored nickname while `nickname: ""` reaches the mutation layer and clears the stored nickname to `null`.
- Speaker write schemas now expose additional field semantics for `address`, `honorific`, `pre_nominal`, `post_nominal`, `qualifications`, `language_ids`, `contacts`, and `social_media`.
- For speakers, omit `address` entirely when you mean “no address change”. If you send `address`, include `address.country_id`; `address: {}` is invalid on the MCP write path just like the raw admin HTTP API.
- For speakers, the visible region fields deep-merge when present, the hidden map fields (`line1`, `line2`, `postcode`, `lat`, `lng`, `google_maps_url`, `google_place_id`, `waze_url`) remain prohibited, and the array-style fields (`honorific`, `pre_nominal`, `post_nominal`, `qualifications`, `language_ids`, `contacts`, `social_media`) replace when present. Omit them to preserve, and use fetch-modify-resend when you want to keep existing entries.
- Venue write schemas now expose additional field semantics for `address`, `facilities`, `contacts`, and `social_media`.
- For venues, omitted address keys preserve the existing nested values, but `address: {}` deletes the stored venue address on the shared save path. Omit the `address` key entirely when you want a no-op.
- For venues, `facilities`, `contacts`, and `social_media` are replacement collections. `facilities` input is normalized into canonical `venue_facilities` rows for the general venue scope, so safe clients should resend the full enabled facility set.
- Reference write schemas now expose additional field semantics for `author`, `publication_year`, `publisher`, and `social_media`.
- For references, omitted optional scalars preserve the existing value, while `null` or trimmed empty input clears `author`, `publication_year`, and `publisher` to `null`. `social_media` follows the same replacement and canonicalization rules as the other write-capable directory resources.
- Event write schemas now expose additional field semantics for `event_url`, `live_url`, `recording_url`, `languages`, `references`, `series`, `domain_tags`, `discipline_tags`, `source_tags`, `issue_tags`, `speakers`, `other_key_people`, `organizer_type`, `registration_mode`, and `status`.
- For events, update schemas are sparse: omitted scalar and relation fields preserve the current value via server-side form-state merge, `null` or `[]` clear the supported relation collections, and submitted `speakers` / `other_key_people` arrays rebuild the underlying `key_people` rows with new order values.
- On admin create/update, `status` is writable and constrained to `draft`, `pending`, or `approved`. MCP create defaults to `draft` when omitted. `approved` sets `published_at`; `draft` and `pending` clear it.
- Event record detail payloads also expose the public change-surface projection fields `active_change_notice`, `change_announcements`, and `replacement_event` so MCP clients can reason about the same published replacement-chain behavior as the public/mobile event detail contract without following stale links.
- Member event update schemas inherit the same event semantics because the member MCP surface delegates to the shared admin write service.
- Series write schemas now expose additional field semantics for `description`, `languages`, and `slug`.
- For series, `title`, `slug`, and `visibility` remain required on update; `description` clears on `null` / trimmed empty input and `languages` follows omit-preserve / null-clear / array-replace semantics.
- Donation channel write schemas now expose additional field semantics for `donatable_type`, `method`, `label`, `reference_note`, and the method-specific bank / DuitNow / ewallet fields.
- For donation channels, owner-type aliases normalize to canonical stored morph values, method switches clear unrelated field groups, and destructive QR clear flags remain unsupported through MCP.
- Inspiration write schemas now expose additional field semantics for `content`, `source`, and `main`.
- For inspirations, `category`, `locale`, `title`, and `content` remain required on update; `source` clears on `null` / trimmed empty input, and the single `main` media field can only be replaced through MCP, not directly cleared through a destructive flag.
- Space write schemas now expose additional field semantics for `slug`, `capacity`, and `institutions`.
- For spaces, `name` and `slug` remain required on update, `capacity` clears to `null`, and `institutions` follows omit-preserve / null-clear / array-replace relation sync semantics.
- Report write schemas now expose additional field semantics for `entity_type`, `entity_id`, `category`, `description`, `reporter_id`, `handled_by`, `resolution_note`, and `evidence`.
- For reports, `entity_type`, `entity_id`, `category`, and `status` remain required on update, `category` depends on `entity_type`, the optional text / user-reference fields clear on `null`, and `evidence` preserves on omission or `null` but clears on `[]`. The destructive raw-HTTP `clear_evidence` flag is still not available through MCP.
- Tag write schemas now expose additional field semantics for `name`, `name.ms`, `name.en`, and `order_column`.
- For tags, `name.en` falls back to `name.ms` when it is omitted, `null`, or trimmed empty input, and blank / null `order_column` values trigger sortable recomputation instead of storing `null`.
- Geography write schemas use package-native fields: `country_id`, `state_id`, `city_id`, `admin_area_1_id` (district), `admin_area_2_id` (subdistrict).
- For address hierarchy, `state_id` is the package State table; districts use `admin_area_1_id`; subdistricts use `admin_area_2_id`. Federal territories may omit district.
- Handle-style social platforms (`facebook`, `twitter`, `instagram`, `youtube`, `tiktok`, `telegram`, `whatsapp`, `linkedin`, `threads`) may canonicalize a submitted URL into stored `username`, so persisted `url` can come back as `null` after normalization.
- Even though the schema advertises model-layer normalization notes for Twitter / X, validated MCP payloads should still use the canonical platform value `twitter`, not `x`.
- Enum fields and filters use enum backing values, not display labels. For events, use values like `kuliah_ceramah`, `all_ages`, `prayer_relative`, `maghrib`, and `immediately` instead of labels like `Kuliah / Ceramah` or localized prayer text.
- This guide summarizes server-level capability only; actor-specific authorization still applies at runtime.

### Non-goals

- This guide is not the raw HTTP admin API contract.
- This guide is not a Filament panel parity matrix.
- This guide does not promise delete, restore, reorder, or replicate support through MCP.

### Admin MCP tool catalog

The admin server is the model-visible API-like surface for admin workflows. The tools below are the current contract that ChatGPT can call:

| Tool | Purpose | Raw HTTP admin equivalent |
|---|---|---|
| `search` | Search the verified ilmu360° MCP documentation pages exposed by this server | MCP-only documentation discovery tool |
| `fetch` | Fetch one verified ilmu360° documentation page by id | MCP-only documentation fetch tool |
| `admin-list-resources` | List accessible admin resources and their capability summary | `GET /api/v1/admin/manifest` |
| `admin-get-resource-meta` | Read one admin resource's metadata, pages, relations, abilities, and write-support flags | `GET /api/v1/admin/{resourceKey}/meta` |
| `admin-search-events` | Search events with rich filters: keyword (title + related institution/speaker/reference expansion by default), geo/nearby, date range, clock-time or prayer-relative window, event type, format, language, audience (gender, age group, children, Muslim-only), institution/venue, key-person roles, topic/tag/reference UUIDs, reference author filters, query expansion toggles (`search_include_*`), and boolean flags (has_event_url, has_live_url, has_end_time) | `GET /api/v1/admin/events/search` (same filter contract) |
| `admin-list-records` | List records for one admin resource with optional search, structured filters, date filters, and pagination | `GET /api/v1/admin/{resourceKey}` |
| `admin-list-related-records` | Traverse a named relation on one admin record | `GET /api/v1/admin/{resourceKey}/{recordKey}/relations/{relation}` |
| `admin-get-record` | Read one admin record and its permissions | `GET /api/v1/admin/{resourceKey}/{recordKey}` |
| `admin-get-record-actions` | Get focused next-step MCP actions for one admin record | MCP-only next-step action guidance tool |
| `admin-upload-event-cover-image` | Upload and save a pre-generated 16:9 website/app cover image for one event. Use `admin-event-cover-image-prompt` to build the prompt and reference images first, then generate with ChatGPT, then call this tool with the image descriptor. | MCP-only event cover upload tool |
| `admin-upload-event-poster-image` | Upload and save a pre-generated 4:5 portrait marketing poster for one event. Use `admin-event-poster-image-prompt` to build the prompt and reference images first, then generate with ChatGPT, then call this tool with the image descriptor. | MCP-only event poster upload tool |
| `admin-get-write-schema` | Discover the create/update contract for a writable admin record | `GET /api/v1/admin/{resourceKey}/schema` |
| `admin-get-event-moderation-schema` | Read the explicit moderation schema for one event | `GET /api/v1/admin/events/{recordKey}/moderation-schema` |
| `admin-get-report-triage-schema` | Read the explicit triage schema for one report | `GET /api/v1/admin/reports/{recordKey}/triage-schema` |
| `admin-get-contribution-request-review-schema` | Read the explicit review schema for one contribution request | `GET /api/v1/admin/contribution-requests/{recordKey}/review-schema` |
| `admin-get-membership-claim-review-schema` | Read the explicit review schema for one membership claim | `GET /api/v1/admin/membership-claims/{recordKey}/review-schema` |
| `admin-create-github-issue` | Create a GitHub issue in the configured repository and auto-assign Copilot | `POST /api/v1/github-issues` (admin caller path) |
| `admin-moderate-event` | Run one explicit moderation action on an event | `POST /api/v1/admin/events/{recordKey}/moderate` |
| `admin-triage-report` | Run one explicit triage action on a report | `POST /api/v1/admin/reports/{recordKey}/triage` |
| `admin-review-contribution-request` | Approve or reject one pending contribution request | `POST /api/v1/admin/contribution-requests/{recordKey}/review` |
| `admin-review-membership-claim` | Approve or reject a pending membership claim | `POST /api/v1/admin/membership-claims/{recordKey}/review` |
| `admin-create-record` | Create or preview a writable admin record | `POST /api/v1/admin/{resourceKey}` |
| `admin-batch-create-records` | Create up to 100 records in a single request; each item processed independently with per-row results | `POST /api/v1/admin/{resourceKey}/batch` |
| `admin-create-event` | Create or preview a new event with event-first fields and relation route keys, optionally including cover, poster, or gallery image descriptors | `POST /api/v1/admin/{resourceKey}` with `resourceKey=events` (MCP wrapper with route-key conveniences) |
| `admin-batch-create-events` | Create up to 50 events in a single request; same field contract as `admin-create-event` with per-row results | `POST /api/v1/admin/{resourceKey}/batch` with `resourceKey=events` |
| `admin-get-record-media` | List media attachments for one admin record to verify uploads or prefill forms | MCP-only media inspection tool |
| `admin-read-debug-log` | Read recent filtered lines from the application debug log | MCP-only debug log reader |
| `admin-update-record` | Update or preview a writable admin record | `PUT /api/v1/admin/{resourceKey}/{recordKey}` |
| `admin-batch-update-records` | Update up to 100 records in a single request; each item identified by `record_key` with per-row results | `PUT /api/v1/admin/{resourceKey}/batch` |
| `admin-update-event` | Update or preview an existing event with event-first fields and relation route keys | `PUT /api/v1/admin/{resourceKey}/{recordKey}` with `resourceKey=events` (MCP wrapper with route-key conveniences) |
| `admin-batch-update-events` | Update up to 50 existing events in a single request; same field contract as `admin-update-event` with per-row results | `PUT /api/v1/admin/{resourceKey}/batch` with `resourceKey=events` |

Admin tool behavior notes:

- `validate_only=true` is supported for create/update/batch preview flows.
- `apply_defaults=true` is preview-only. It is honored only when `validate_only=true`, where it merges schema defaults into the candidate payload for validation feedback. It is ignored for persisted creates/updates; for real writes, send the exact values you want saved.
- Batch tools (`admin-batch-create-events`, `admin-batch-update-events`, `admin-batch-create-records`, `admin-batch-update-records`) process each item independently. The response contains a `data.results` array with per-row `status` values (`created`, `updated`, `validation_failed`, `unresolved_key`, `not_found`, `error`, or `preview`) and a `data.summary` block with per-status counts. Include `external_row_id` per item for idempotency tracking and safe retries.
- For batch event tools, unresolved `organizer_key`, `institution_key`, `venue_key`, `space_key`, `speaker_keys`, or `reference_keys` yield `unresolved_key` at the row level without failing the whole batch.
- `admin-batch-create-events` and `admin-batch-update-events` have a per-batch maximum of 50 items; `admin-batch-create-records` and `admin-batch-update-records` have a per-batch maximum of 100 items.
- Dedicated MCP event tools are convenience wrappers, not separate persistence paths. They accept route-key aliases (`organizer_key`, `institution_key`, `venue_key`, `space_key`, `speaker_keys`, `reference_keys`) and normalize them into the same admin event payload used by the raw HTTP/admin resource writer.
- `admin-update-event` and `admin-batch-update-events` use presence-sensitive relation aliases: omit `speaker_keys`/`reference_keys` or pass `null` to preserve existing relationships; pass `[]` to detach all; pass a non-empty array to replace all.
- `admin-list-resources` is a discovery manifest, not merely a small name list. Keep `verbose=false` for compact exploration and use `verbose=true` only when you need full metadata. Pass `writable_only=true` to filter the list to only resources with active write support.
- `current_media` is metadata only; it is useful for form prefill but does not expose signed URLs.
- `admin-search-events` is the dedicated event-discovery MCP path and is aligned with `GET /api/v1/admin/events/search`. It supports keyword search with default cross-entity expansion (institution/speaker/reference), geo-proximity sorting (`sort=distance` with `lat`, `lng`, `radius_km`), date range (`starts_after`, `starts_before`, `time_scope`), clock-time or prayer-relative windows (`timing_mode`, `starts_time_from/until`, `prayer_time`), event type and format arrays, audience and audience-boolean filters, institution/venue/speaker/role filters, tag/reference UUID arrays, reference author filters (`reference_author_search`), and query expansion toggles (`search_include_institutions`, `search_include_speakers`, `search_include_references`). Each parameter includes an inline description of valid values in the tool schema.
- `admin-list-records` accepts a `filters` object keyed by the resource metadata filter keys, for example `{ "status": "approved", "is_active": true }` for `events`.
- `admin-upload-event-cover-image` and `admin-upload-event-poster-image` accept a pre-generated image via `{event_key, image, creative_direction?}` and save it to the event media collection. The cover tool writes `cover` at required ratio `16:9`; the poster tool writes `poster` at required ratio `4:5`. The `image` field is a file descriptor: pass `{content_base64, filename}`. Optionally include `mime_type` in the descriptor; it is auto-detected if omitted. Use the MCP prompts `admin-event-cover-image-prompt` and `admin-event-poster-image-prompt` before calling these tools — the prompts build engineered prompt text with brand reference images for ChatGPT native image generation. Speaker-context references follow this order: speaker `cover`, then speaker `avatar`, then organizer institution media from `event->organizer`.
- If attaching reference media fails, retry the prompt call with `include_existing_media=false` and `max_reference_media=0`, then re-generate and re-upload.
- For `speakers`, `institutions`, and `references`, `admin-list-records` search now reuses the same specialized search services as the public directory endpoints; the main difference is record scope, not text-matching behavior.
- For date-aware resources, `starts_after`, `starts_before`, and `starts_on_local_date` are date-only `YYYY-MM-DD` strings interpreted in the resolved request timezone. Do not send ISO 8601 timestamps to those MCP arguments.
- Event enum filters and payload values must be backing values, for example `filter[event_category_ids]=kuliah_ceramah` and `filter[timing_mode]=prayer_relative`.
- `admin-get-record-actions` is read-only and returns record-specific next-step MCP tools, including explicit workflow-schema tool hints when a moderation, triage, or review flow is currently available on that record.
- The dedicated admin workflow-schema tools are read-only and expose defaults, available actions, fields, and conditional rules for their matching moderation/review workflow.
- Media/file upload fields accept JSON file descriptors when the matching write schema advertises them; descriptor content may be provided via `content_base64`, `content_url`, or `download_url`.
- If the prompt call fails while attaching reference images, retry the prompt call with `include_existing_media=false` and `max_reference_media=0`.
- Event media writes enforce fixed ratios across MCP writes: `cover` must be `16:9` and `poster` must be `4:5`.
- `clear_*` media flags are intentionally rejected in MCP even when the raw HTTP admin schema may mention destructive media handling.
- `admin-create-event` now maps to the `/hantar-majlis` wizard payload model for event creation and writes through the admin API create endpoint (`POST /api/v1/admin/events` via `POST /api/v1/admin/{resourceKey}`). It accepts scalar event fields, relation route keys (`organizer_key`, `institution_key`, `venue_key`, `space_key`), speaker/reference route-key arrays (`speaker_keys`, `reference_keys`), language IDs (`languages`), tag arrays (`domain_tags`, `discipline_tags`, `source_tags`, `issue_tags`), `other_key_people`, optional `series`, and media descriptors (`cover`, `poster`, `gallery`). For persisted creates, include the actual defaultable values you want stored (`timezone`, `event_format`, `visibility`, `gender`, `age_group`, etc.); `apply_defaults` will not fill them unless the call is a validate-only preview.
- `admin-get-record-media` returns media collection metadata for one admin record. Use it to verify that cover/poster/gallery uploads persisted and to build reference-media descriptors for image generation prompts.
- `admin-read-debug-log` returns recent application debug-log lines, optionally filtered by a keyword. Restrict access to trusted admin actors only.
- `admin-create-github-issue` creates a GitHub issue and, for admin actors, automatically assigns Copilot using the server-side configuration and model fallback chain. This tool is **conditionally registered** and only present when the GitHub issue reporter is configured; it will be absent from `tools/list` if GitHub issue reporting has not been set up.
- The admin workflow tools (`admin-get-event-moderation-schema`, `admin-moderate-event`, `admin-get-report-triage-schema`, `admin-triage-report`, `admin-get-contribution-request-review-schema`, `admin-review-contribution-request`, `admin-get-membership-claim-review-schema`, `admin-review-membership-claim`) are **conditionally registered** based on the current user's permissions: moderation tools require `canModerate`, triage tools require `canTriage`, and review tools require `canReview`. If these tools are absent from `tools/list`, the authenticated admin user lacks the corresponding workflow permission.
- All read-only discovery, list, get, and schema tools carry `#[IsReadOnly]` + `#[IsIdempotent]` annotations; AI clients that honor MCP tool annotations can call them freely without confirmation prompts.
- All write and workflow execution tools carry `#[IsReadOnly(false)]` + `#[IsIdempotent(false)]` annotations; AI clients that honor MCP annotations may prompt for confirmation before calling them.

### Member MCP tool catalog

The member server is the model-visible API-like surface for Ahli-scoped workflows. These tools expose the member boundary as ChatGPT-readable operations:

| Tool | Purpose |
|---|---|
| `search` | Search the verified ilmu360° MCP documentation pages exposed by this server |
| `fetch` | Fetch one verified ilmu360° documentation page by id |
| `member-list-resources` | List accessible Ahli-scoped member resources |
| `member-get-resource-meta` | Read one member resource's metadata, permissions, and available write support |
| `member-search-events` | Search events with rich filters: keyword (title + related institution/speaker/reference expansion by default), geo/nearby, date range, clock-time or prayer-relative window, event type, format, language, audience (gender, age group, children, Muslim-only), institution/venue, key-person roles, topic/tag/reference UUIDs, reference author filters, query expansion toggles (`search_include_*`), and boolean flags (has_event_url, has_live_url, has_end_time) |
| `member-list-records` | List records for one member resource with optional search and pagination |
| `member-list-related-records` | List related records for one member record |
| `member-get-record` | Read one member record by resource key and record key |
| `member-get-record-actions` | Get focused next-step MCP actions for one member record |
| `member-upload-event-cover-image` | Upload and save a pre-generated 16:9 website/app cover image for one accessible event. Use `member-event-cover-image-prompt` to build the prompt and reference images first, then generate with ChatGPT, then call this tool with the image descriptor. |
| `member-upload-event-poster-image` | Upload and save a pre-generated 4:5 portrait marketing poster for one accessible event. Use `member-event-poster-image-prompt` to build the prompt and reference images first, then generate with ChatGPT, then call this tool with the image descriptor. |
| `member-get-write-schema` | Discover the writable update schema for one member record |
| `member-list-contribution-requests` | List the authenticated member's own contribution requests plus any pending approvals |
| `member-approve-contribution-request` | Approve one reviewable contribution request |
| `member-reject-contribution-request` | Reject one reviewable contribution request |
| `member-cancel-contribution-request` | Cancel one pending contribution request owned by the authenticated member |
| `member-list-membership-claims` | List the authenticated member's membership claims |
| `member-submit-membership-claim` | Submit a membership claim with justification and evidence uploads |
| `member-cancel-membership-claim` | Cancel one pending membership claim owned by the authenticated member |
| `member-create-github-issue` | Create a GitHub issue in the configured repository |
| `member-update-record` | Update a writable member record using the schema-guided payload contract |
| `member-read-debug-log` | Read recent filtered lines from the application debug log | MCP-only debug log reader |

Member tool behavior notes:

- Member tools are constrained to the Ahli workspace boundary and live membership relationships.
- `member-search-events` is the dedicated event-discovery path for rich filtering while still respecting member MCP scope boundaries. Supports the same filter contract as `admin-search-events`: keyword (with default institution/speaker/reference expansion), geo-proximity, date range, time window, event type/format arrays, audience and boolean filters, speaker/role IDs, tag/reference UUID arrays, reference author filters, and query-expansion toggles.
- `member-get-record-actions` is read-only and returns record-specific next-step MCP tools for the Ahli surface, including update-schema and relation traversal follow-ups when they are available.
- `member-upload-event-cover-image` and `member-upload-event-poster-image` accept a pre-generated image via `{event_key, image, creative_direction?}` and save it to the accessible event media collection within Ahli scope. The cover tool writes `cover` at required ratio `16:9`; the poster tool writes `poster` at required ratio `4:5`. The `image` field is a file descriptor: pass `{content_base64, filename}`. Optionally include `mime_type` in the descriptor; it is auto-detected if omitted. Use the MCP prompts `member-event-cover-image-prompt` and `member-event-poster-image-prompt` before calling these tools — the prompts build engineered prompt text with brand reference images for ChatGPT native image generation. Speaker-context references follow this order: speaker `cover`, then speaker `avatar`, then organizer institution media from `event->organizer`.
- If attaching reference media fails, retry the prompt call with `include_existing_media=false` and `max_reference_media=0`, then re-generate and re-upload.
- Update tools are schema-guided and should be treated as the member-side API equivalent of the relevant HTTP workflow.
- Event media writes enforce fixed ratios across MCP writes: `cover` must be `16:9` and `poster` must be `4:5`.
- Member update tools support `validate_only=true` for preview-only member writes.
- Member related-record traversal is limited to one level and only for relations exposed by member resource metadata.
- For `speakers`, `institutions`, and `references`, `member-list-records` search reuses the same specialized search services as the public directory endpoints, while still respecting Ahli membership scope. Unlike `admin-list-records`, `member-list-records` does **not** accept a `filters` object; use `search`, `starts_after`, `starts_before`, and `starts_on_local_date` to narrow results.
- Contribution-request workflow tools cover listing, approving, rejecting, and cancelling queue items that the authenticated member can legitimately act on through the Ahli surface.
- Membership-claim workflow tools cover listing, submitting with evidence uploads, and cancelling the member's own pending claims.
- `member-create-github-issue` creates a plain GitHub issue only; it does not assign Copilot.
- `member-update-record` does **not** support `apply_defaults` (unlike `admin-update-record`). Preview calls via `validate_only=true` normalize the payload but do not apply schema defaults.
- Media/file upload fields accept JSON file descriptors when the matching member write schema advertises them; descriptor content may be provided via `content_base64`, `content_url`, or `download_url`.
- If the prompt call fails while attaching reference images, retry the prompt call with `include_existing_media=false` and `max_reference_media=0`.
- As with admin tools, ChatGPT only understands what the tool descriptor exposes; if a capability is not registered as a tool, the model will not assume it exists.

### MCP media/file upload contract

MCP write tools are JSON tools, not multipart HTTP endpoints. When a schema advertises a media/file field, send a descriptor object instead of a browser file upload:

```json
{
  "filename": "poster.png",
  "mime_type": "image/png",
  "content_base64": "iVBORw0KGgo..."
}
```

Accepted aliases are `file_name` or `name` for `filename`, `mime` for `mime_type`, and `base64` or `data` for `content_base64`. Data URLs are accepted for `content_base64`. Filename extensions are recommended; when they are omitted, the server derives the staged extension from `mime_type`.

Schema fields describe the exact upload rules:

- `mcp_upload.shape` is `file_descriptor` for a single file and `array<file_descriptor>` for multiple files.
- `accepted_mime_types`, `max_file_size_kb`, and `max_files` are authoritative for that field.
- `mcp_upload.replacement_semantics` describes whether the submitted descriptor or descriptor array replaces the target media collection.
- When a write tool supports `validate_only=true` (currently admin create/update and member update), previews normalize descriptors into file summaries without persisting media.
- Admin preview validation failures now include schema-driven `feedback` hints (`allowed_values`, `suggested`, `closest_valid_value`, `default`, `required_because`) and can return a candidate `normalized_payload` when `apply_defaults=true`.
- `current_media` stays metadata-only and does not expose signed or temporary URLs.
- `clear_*` media flags remain unsupported through MCP and are rejected even when clients submit them manually.

### Admin MCP resource scope

The admin tool catalog above is the canonical list of model-visible operations. The admin server exposes those tools for resource discovery, record browsing, relation traversal, schema discovery, and supported create/update workflows.

Current structurally write-capable admin resources include:

- `speakers`
- `events`
- `inspirations`
- `institutions`
- `references`
- `reports`
- `donation-channels`
- `series`
- `spaces`
- `tags`
- `venues`
- `subdistricts`

Read-only admin resources are still discoverable through the resource list and metadata tools; use the capability matrix for the full runtime inventory.

Write-tool preview tip:

- `admin-create-record` and `admin-update-record` accept `validate_only=true` to validate, normalize, and preview a write without persisting it.
- Add `apply_defaults=true` only on preview calls (`validate_only=true`) when you want the server to apply schema defaults before validation and return a candidate autofilled payload in validation feedback. `apply_defaults` is ignored on persisted admin MCP writes.
- Preview responses return the normalized payload plus warning metadata for supported write-side checks.
- Validation failures return schema-driven `feedback` issues with suggested values, defaults, and conditional `required_because` context.
- Validation failures in validate-only mode now include `fix_plan`, `remaining_blockers`, `normalized_payload_preview`, and `can_retry` so tool clients can recover in one retry loop.
- MCP write schemas advertise supported media/file fields with JSON descriptor metadata, do not advertise destructive media clear-flags, and reject clear-flags if a client submits them anyway.
- Update previews also include the current record snapshot so you can compare what would change before retrying without `validate_only`.

### Member MCP resource scope

The member tool catalog above is the canonical list of model-visible operations. The member server exposes those tools for resource discovery, record browsing, schema discovery, and supported member-side update workflows.

Current member-write-capable resources include:

- `institutions`
- `speakers`
- `references`
- `events`

## Configuration Notes

The MCP configuration lives in `config/mcp.php`.

Useful environment variables:

- `MCP_REDIRECT_DOMAINS` — add hosted OAuth redirect domains here.
- `MCP_CUSTOM_SCHEMES` — allow private-use callback schemes for desktop clients such as `vscode` or `claude`.
- `MCP_AUTHORIZATION_SERVER` — override the OAuth issuer identifier if needed.
- `PASSPORT_PRIVATE_KEY` / `PASSPORT_PUBLIC_KEY` — optional raw PEM secrets for Passport; leave them blank to use the generated `storage/oauth-*.key` files. Do not set file paths here.

## Common Troubleshooting Checks

- `401 Unauthorized` usually means the client is missing a bearer token or the token does not match the target server.
- `403 Forbidden` usually means the user does not have the required admin or member access.
- OAuth redirect failures usually mean the redirect domain or custom scheme has not been allowlisted in `config/mcp.php`.

## Relevant Files

- `routes/ai.php` — MCP server registration and OAuth routes.
- `app/Mcp/Servers/AdminServer.php` — admin MCP server definition.
- `app/Mcp/Servers/MemberServer.php` — member MCP server definition.
- `app/Mcp/Resources/Docs/*` — verified markdown MCP resources exposed by both servers.
- `app/Support/Mcp/McpWriteSchemaFormatter.php` — sanitized MCP write-schema formatter.
- `app/Support/Mcp/McpFilePayloadNormalizer.php` — JSON file descriptor staging and validation for MCP writes.
- `tests/Unit/McpGuideDocsTest.php` — MCP guide regression coverage for verified resource and CRUD claims.
- `app/Console/Commands/IssueMcpToken.php` — command for issuing scoped tokens.
- `app/Support/Mcp/McpTokenManager.php` — token issuance, listing, and revocation logic.
- `tests/Feature/Mcp/AdminServerTest.php` — admin MCP regression coverage.
- `tests/Feature/Mcp/MemberServerTest.php` — member MCP regression coverage.
- `tests/Feature/Mcp/LocalServerRegistrationTest.php` — local MCP registration coverage.

## Quick Start

1. Decide whether you need the admin or member server.
2. Issue a matching bearer token, or start an OAuth flow if your client supports it.
3. Point your MCP client at `/mcp/admin` or `/mcp/member`.
4. Use `php artisan mcp:inspector` if you need to verify the connection or inspect available tools; prefer the local handles when you want to exercise the app’s local database and full MCP transport end to end.

## Appendix: Compact Tool Reference

Use this as the quick scan list when you want ChatGPT to reason about the connector as an API surface.

### Admin MCP tools

| Tool | When to use it | Core arguments |
|---|---|---|
| `search` | Search the verified MCP/docs pages exposed by this server | `query` |
| `fetch` | Fetch the full text of one verified docs page | `id` |
| `admin-list-resources` | Discover accessible admin resources | `verbose?`, `writable_only?` |
| `admin-get-resource-meta` | Inspect one resource’s metadata, routes, relations, and abilities | `resource_key` |
| `admin-search-events` | Run dedicated event discovery with rich filters | `query?`, `sort?` (time/relevance/distance), `time_scope?` (upcoming/past/all), `starts_after?`, `starts_before?`, `starts_time_from?`, `starts_time_until?`, `timing_mode?`, `prayer_time?`, `event_category_ids?` (array), `event_format?` (array: physical/online/hybrid), `language_codes?` (array), `gender?`, `age_group?` (array), `children_allowed?`, `is_muslim_only?`, `country_id?`, `state_id?`, `city_id?`, `admin_area_1_id?`, `admin_area_2_id?`, `lat?`, `lng?`, `radius_km?`, `institution_id?`, `venue_id?`, `speaker_ids?` (array), `key_person_roles?` (array), `person_in_charge_ids?`, `person_in_charge_search?`, `moderator_ids?`, `imam_ids?`, `khatib_ids?`, `bilal_ids?`, `topic_ids?` (array), `domain_tag_ids?` (array), `source_tag_ids?` (array), `issue_tag_ids?` (array), `reference_ids?` (array), `reference_author_search?` (array), `search_include_institutions?`, `search_include_speakers?`, `search_include_references?`, `has_event_url?`, `has_live_url?`, `has_end_time?`, `page?`, `per_page?` |
| `admin-list-records` | Search and paginate records for one admin resource | `resource_key`, `search?`, `filters?`, `starts_after?`, `starts_before?`, `starts_on_local_date?`, `page?`, `per_page?` |
| `admin-list-related-records` | Traverse a named relation on a record | `resource_key`, `record_key`, `relation`, `search?`, `page?`, `per_page?` |
| `admin-get-record` | Read one admin record and its permissions | `resource_key`, `record_key` |
| `admin-get-record-actions` | Get focused next-step MCP actions for one admin record | `resource_key`, `record_key` |
| `admin-upload-event-cover-image` | Upload a pre-generated image to the event `cover` collection at `16:9` (use `admin-event-cover-image-prompt` first) | `event_key`, `image`, `creative_direction?` |
| `admin-upload-event-poster-image` | Upload a pre-generated image to the event `poster` collection at `4:5` (use `admin-event-poster-image-prompt` first) | `event_key`, `image`, `creative_direction?` |
| `admin-create-event` | MCP-only wrapper to create or preview a new event with event-first fields and relation route keys, aligned to `/hantar-majlis` non-media payload structure. `apply_defaults?` is preview-only. | `title`, `event_date`, `event_category_ids` (array), `prayer_time`, `description?`, `custom_time?`, `end_time?`, `timezone?`, `event_format?`, `visibility?`, `event_url?`, `live_url?`, `recording_url?`, `gender?`, `age_group?` (array), `children_allowed?`, `is_muslim_only?`, `organizer_type?`, `organizer_key?`, `institution_key?`, `venue_key?`, `space_key?`, `speaker_keys?` (array), `reference_keys?` (array), `languages?` (array of IDs), `domain_tags?` (array), `discipline_tags?` (array), `source_tags?` (array), `issue_tags?` (array), `other_key_people?` (array), `series?` (array), `status?`, `registration_required?`, `registration_mode?`, `is_priority?`, `is_featured?`, `is_active?`, `cover?`, `poster?`, `gallery?`, `validate_only?`, `apply_defaults?` |
| `admin-batch-create-events` | MCP-only batch create for up to 50 events; same field contract per item as `admin-create-event`, plus per-batch `validate_only?`, `apply_defaults?`. `apply_defaults?` is ignored unless `validate_only=true`. | `items` (array, max 50), `validate_only?`, `apply_defaults?` — each item: same event fields + optional `external_row_id` |
| `admin-update-event` | MCP-only update wrapper with event-first fields and relation route keys. `speaker_keys`/`reference_keys`: omit or `null` to preserve, `[]` to detach all, non-empty array to replace all. | `event_key`, all `admin-create-event` fields except required scalars, `validate_only?` |
| `admin-batch-update-events` | MCP-only batch update for up to 50 existing events; each item must include `event_key`; same relation presence semantics as `admin-update-event`. | `items` (array, max 50), `validate_only?` — each item: `event_key` + same event fields + optional `external_row_id` |
| `admin-get-record-media` | List media attachments for one admin record | `resource_key`, `record_key` |
| `admin-read-debug-log` | Read recent filtered lines from the debug log | `filter?`, `lines?`, `all?` |
| `admin-create-github-issue` | Create a GitHub issue in the configured repository and auto-assign Copilot (conditionally registered) | `category`, `title`, `summary`, `platform?`, `proposal?`, `description?` (plus additional diagnostic fields) |
| `admin-get-write-schema` | Fetch the create/update contract for a writable admin record | `resource_key`, `operation`, `record_key?` |
| `admin-get-event-moderation-schema` | Fetch the explicit moderation schema for one event | `record_key` |
| `admin-get-report-triage-schema` | Fetch the explicit triage schema for one report | `record_key` |
| `admin-get-contribution-request-review-schema` | Fetch the explicit review schema for one contribution request | `record_key` |
| `admin-get-membership-claim-review-schema` | Fetch the explicit review schema for one membership claim | `record_key` |
| `admin-moderate-event` | Run one explicit moderation action on an event | `record_key`, `action`, `reason_code?`, `note?` |
| `admin-triage-report` | Run one explicit triage action on a report | `record_key`, `action`, `resolution_note?` |
| `admin-review-contribution-request` | Approve or reject one pending contribution request | `record_key`, `action`, `reason_code?`, `reviewer_note?` |
| `admin-review-membership-claim` | Approve or reject one pending membership claim | `record_key`, `action`, `granted_role_slug?`, `reviewer_note?` |
| `admin-create-record` | Create or preview a writable admin record | `resource_key`, `payload`, `validate_only?`, `apply_defaults?` |
| `admin-batch-create-records` | Batch create up to 100 records for a writable admin resource | `resource_key`, `items` (array, max 100), `validate_only?` — each item: `payload` + optional `external_row_id` |
| `admin-update-record` | Update or preview a writable admin record | `resource_key`, `record_key`, `payload`, `validate_only?`, `apply_defaults?` |
| `admin-batch-update-records` | Batch update up to 100 records for a writable admin resource | `resource_key`, `items` (array, max 100), `validate_only?` — each item: `record_key`, `payload`, optional `external_row_id` |

### Member MCP tools

| Tool | When to use it | Core arguments |
|---|---|---|
| `search` | Search the verified MCP/docs pages exposed by this server | `query` |
| `fetch` | Fetch the full text of one verified docs page | `id` |
| `member-list-resources` | Discover accessible Ahli-scoped resources | `verbose?` |
| `member-get-resource-meta` | Inspect one member resource’s metadata and write support | `resource_key` |
| `member-search-events` | Run dedicated event discovery with rich filters | `query?`, `sort?` (time/relevance/distance), `time_scope?` (upcoming/past/all), `starts_after?`, `starts_before?`, `starts_time_from?`, `starts_time_until?`, `timing_mode?`, `prayer_time?`, `event_category_ids?` (array), `event_format?` (array: physical/online/hybrid), `language_codes?` (array), `gender?`, `age_group?` (array), `children_allowed?`, `is_muslim_only?`, `country_id?`, `state_id?`, `city_id?`, `admin_area_1_id?`, `admin_area_2_id?`, `lat?`, `lng?`, `radius_km?`, `institution_id?`, `venue_id?`, `speaker_ids?` (array), `key_person_roles?` (array), `person_in_charge_ids?`, `person_in_charge_search?`, `moderator_ids?`, `imam_ids?`, `khatib_ids?`, `bilal_ids?`, `topic_ids?` (array), `domain_tag_ids?` (array), `source_tag_ids?` (array), `issue_tag_ids?` (array), `reference_ids?` (array), `reference_author_search?` (array), `search_include_institutions?`, `search_include_speakers?`, `search_include_references?`, `has_event_url?`, `has_live_url?`, `has_end_time?`, `page?`, `per_page?` |
| `member-list-records` | Search and paginate records for one member resource | `resource_key`, `search?`, `starts_after?`, `starts_before?`, `starts_on_local_date?`, `page?`, `per_page?` |
| `member-list-related-records` | Traverse a named relation on a member record | `resource_key`, `record_key`, `relation`, `search?`, `page?`, `per_page?` |
| `member-get-record` | Read one member record | `resource_key`, `record_key` |
| `member-get-record-actions` | Get focused next-step MCP actions for one member record | `resource_key`, `record_key` |
| `member-upload-event-cover-image` | Upload a pre-generated image to the accessible event `cover` collection at `16:9` (use `member-event-cover-image-prompt` first) | `event_key`, `image`, `creative_direction?` |
| `member-upload-event-poster-image` | Upload a pre-generated image to the accessible event `poster` collection at `4:5` (use `member-event-poster-image-prompt` first) | `event_key`, `image`, `creative_direction?` |
| `member-create-github-issue` | Create a GitHub issue in the configured repository (conditionally registered) | `category`, `title`, `summary`, `platform?`, `proposal?`, `description?` (plus additional diagnostic fields) |
| `member-read-debug-log` | Read recent filtered lines from the debug log | `filter?`, `lines?`, `all?` |
| `member-get-write-schema` | Fetch the writable update contract for one member record | `resource_key`, `record_key` |
| `member-update-record` | Update or preview a writable member record. Supports `validate_only?` but **not** `apply_defaults` (unlike `admin-update-record`). | `resource_key`, `record_key`, `payload`, `validate_only?` |
| `member-list-contribution-requests` | List the authenticated member's contribution queue and pending approvals | none |
| `member-approve-contribution-request` | Approve one reviewable contribution request | `request_id`, `reviewer_note?` |
| `member-reject-contribution-request` | Reject one reviewable contribution request | `request_id`, `reason_code`, `reviewer_note?` |
| `member-cancel-contribution-request` | Cancel one pending contribution request owned by the member | `request_id` |
| `member-list-membership-claims` | List the authenticated member's membership claims | none |
| `member-submit-membership-claim` | Submit a membership claim with evidence uploads | `subject_type`, `subject`, `justification`, `evidence` |
| `member-cancel-membership-claim` | Cancel one pending membership claim owned by the member | `claim_id` |

### Reading rules for the appendix

- Read-only tools should be treated as discovery and preview operations.
- Write tools are schema-guided and should always be preceded by the matching write-schema call.
- The model should treat these tools as the full ChatGPT-visible connector API. If a capability is not listed here, it is not part of the supported tool contract.
- `documentation-tool-routing` is an optional prompt for clients that support prompts; it explains when to use the verified docs `search` and `fetch` tools and accepts an optional `topic` hint for more targeted routing advice.
- For tiny call examples, see `docs/ilmu360_mcp_tool_examples.md`.
