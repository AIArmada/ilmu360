# ilmu360° Member MCP Agent Guide

Updated: May 4, 2026
Audience: model-facing member MCP agents and tool clients.

This guide is for the member MCP surface only. For transport, connector, OAuth, inspector, and other setup details, use `docs/ilmu360_mcp_guide.md`. The admin guide is separate and is not exposed through this server.

## Quick Start for Common Member Reads

### Step 1 — Ensure the guide is loaded

Before the first operational tool call in a session, the guide must be in context:

```json
{
  "tool": "fetch",
  "arguments": { "id": "docs-member-mcp-guide" }
}
```

### Step 2 — Handling `documentation_preflight_injected`

If you call an operational tool before the guide is loaded, the server auto-injects the guide and returns:

```json
{
  "action": "documentation_preflight_injected",
  "notice": "[Guide auto-loaded] The member MCP guide has been loaded and the preflight is now satisfied. Re-invoke the tool to continue."
}
```

This is **not a failure**. The server has now loaded the guide into the MCP session. **Re-run the exact same operational tool call immediately** and it will proceed.

The retry flow:
1. Call operational tool (e.g. `member-list-records`)
2. Receive `documentation_preflight_injected`
3. Call the same operational tool again
4. Data returns normally

---

### Common Recipes

#### List events for a date range

Use `member-list-records` with `resource_key: "events"`. Date filters are **date-only `YYYY-MM-DD` strings** — do not send ISO timestamps.

```json
{
  "resource_key": "events",
  "starts_after": "2026-04-25",
  "starts_before": "2026-05-02",
  "page": 1,
  "per_page": 50
}
```

**Date boundary rule:** `starts_after` and `starts_before` are inclusive boundaries. Use the exact first and last local dates you want to include:

| Goal | `starts_after` | `starts_before` |
|---|---|---|
| Events on 3–9 May | `2026-05-03` | `2026-05-09` |
| Inclusive week example: 25 Apr – 1 May | `2026-04-25` | `2026-05-01` |
| Events on exactly 30 Apr | use `starts_on_local_date` | — |

#### List events on one exact local date

```json
{
  "resource_key": "events",
  "starts_on_local_date": "2026-04-30",
  "page": 1,
  "per_page": 50
}
```

Prefer `starts_on_local_date` over a same-day range for single-date queries.

#### Summarize event results

Event payloads are large. For user-facing summaries, prefer these fields:

- `title`
- `attributes.starts_on_local_date`
- `attributes.timing_display` ← best for user-facing time, includes prayer-relative labels
- `attributes.end_time_display`
- `attributes.event_categories`
- `attributes.institution.name`
- `attributes.institution.address_line`
- `attributes.reference_study_subtitle`

Use `meta.pagination.total` for the total result count and `meta.pagination.has_more` to know whether additional pages exist.

If `data` is empty and `meta.pagination.total` is 0, the query succeeded but no records matched. Do not treat this as an error.

Avoid dumping large media payloads or raw UTC timestamps unless specifically requested.

#### User-facing time display

Event records expose multiple time representations:

| Field | Use for |
|---|---|
| `timing_display` | User-facing summaries — includes prayer-relative labels like "Selepas Maghrib" |
| `end_time_display` | User-facing end time |
| `starts_on_local_date` | Date-only display or filtering |
| `starts_at_local` | Local datetime when precise display is needed |
| `starts_at` | Machine processing only — stored in UTC |

For prayer-relative events, `timing_display` is always better than converting `starts_at`.

#### List my contribution requests

Use `member-list-contribution-requests` to see pending and historical contribution requests for the authenticated member:

```json
{
  "tool": "member-list-contribution-requests"
}
```

Key fields in each result:

- `status` — `pending`, `approved`, `rejected`, or `cancelled`
- `resource_key` / `record_key` — the target resource and record
- `payload_summary` — human-readable summary of the proposed change
- `submitted_at`

To act on a pending request, use `member-approve-contribution-request`, `member-reject-contribution-request`, or `member-cancel-contribution-request` with the `request_id` parameter. Note that `reason_code` is **required** (not optional) when calling `member-reject-contribution-request`.

#### List my membership applications

Use `member-list-membership-applications` to see the authenticated member's applications:

```json
{
  "tool": "member-list-membership-applications"
}
```

Key fields: `status`, `institution.name`, `submitted_at`, `id`.

To cancel a pending application, use `member-cancel-membership-application` with the `application_id` parameter (pass the `id` value returned by the list tool).

---

## What MCP Means in ilmu360°

ilmu360° exposes the member MCP server for Ahli-scoped resource access. Treat the live MCP tool descriptors as the source of truth for what the member agent can do.

## Verified Documentation Resource

| Resource | URI | Purpose |
|---|---|---|
| `docs-member-mcp-guide` | `file://docs/ilmu360_mcp_member_agent_guide.md` | Member-facing guide for auth, transport, discovery primitives, capability matrix, writable resources, and workflow guidance |

## Documentation search and fetch tools

The member server exposes two read-only documentation tools for model discoverability. They search and fetch the broader verified, audience-scoped member documentation catalog:

| Tool | Purpose | Notes |
|---|---|---|
| `search` | Search the verified member MCP documentation catalog exposed by this server | Input: one `query` string |
| `fetch` | Fetch any verified member documentation page by id | Input: one `id` string returned by `search` |

These tools do **not** search member runtime records. The primary guide remains `docs-member-mcp-guide`; the current member catalog IDs are:

- `docs-api-mcp-filament-crud-comparison`
- `docs-event-domain-understanding`
- `docs-admin-event-csv-json-create-guide`
- `docs-general-mcp-guide`
- `docs-member-mcp-guide`
- `docs-mcp-tool-examples`
- `docs-mobile-api-reference`
- `docs-technical-documentation`

## Documentation routing prompt

| Prompt | Purpose | Arguments |
|---|---|---|
| `documentation-tool-routing` | Guidance for deciding when to use `search` vs `fetch` for the verified member docs page | `topic?` |

The prompt tells the model to:

- fetch `docs-member-mcp-guide` directly when the question is clearly about ilmu360° member MCP behavior
- use `search` when the topic is fuzzy or a discovery step is still helpful
- ensure the verified guide is already in context before the first operational member MCP tool call
- keep runtime data access on the member record tools instead of the docs tools
- optionally accept a `topic` hint such as `crud`, `auth`, `media uploads`, `runtime records`, `search`, or `fetch` for more targeted guidance

## Operational preflight rule

Before any ilmu360° member MCP read, search, query, lookup, list, fetch, write, update, create, relation traversal, schema discovery, or workflow action, the client must ensure the verified member guide is already in context. If it is not, fetch `docs-member-mcp-guide` first, or use `search` then `fetch` when the topic is still fuzzy.

The member MCP server enforces a transport-level version of this rule for operational `tools/call` requests: those calls are rejected until `docs-member-mcp-guide` has been fetched through the MCP `fetch` tool or the guide resource has been read through MCP `resources/read` in the same initialized MCP session. When the guard fires, the server auto-injects the guide and returns a `documentation_preflight_injected` action — see the **Quick Start** section above for the retry pattern.

Apply this before operational tools such as:

- `member-list-records`
- `member-get-record`
- `member-list-related-records`
- `member-get-resource-meta`
- `member-get-write-schema`
- `member-update-record`
- `member-list-contribution-requests`
- `member-approve-contribution-request`
- `member-reject-contribution-request`
- `member-cancel-contribution-request`
- `member-list-membership-applications`
- `member-submit-membership-application`
- `member-cancel-membership-application`

The client may skip a fresh docs fetch only when the verified guide is already active in context, or when the user provides the exact `resource_key`, `record_key`, tool, and intended read operation and no interpretation is required.

Even then, re-check live write schemas or explicit workflow schema/tool guidance before update, preview, or workflow mutations.

Because the server cannot safely infer conversational shortcuts such as “the user already supplied the exact resource and tool with zero interpretation needed”, the runtime MCP guard remains stricter and still requires a same-session guide fetch or read before operational tool execution. When the guard fires, the server auto-injects the guide and returns a `documentation_preflight_injected` action — see the **Quick Start** section above for the retry pattern.

## What this server exposes

Tool-centric clients like ChatGPT and the OpenAI Responses MCP integration import tools from `tools/list`, not raw resources from `resources/list`.

- A client connected to `/mcp/member` sees only the member docs tools (`search`, `fetch`) plus `member-*` tools. It will not see any `admin-*` tools.
- The `docs-member-mcp-guide` resource is the model-readable documentation page for the member MCP surface, not a replacement for the live tool and resource descriptors.

## MCP capability matrix

Use this section as the quick member-only capability summary.

| Capability | Member MCP |
| --- | --- |
| Docs search | `search` |
| Docs fetch | `fetch` |
| Resource discovery | `member-list-resources` |
| Resource metadata | `member-get-resource-meta` |
| Dedicated event discovery | `member-search-events` |
| Record list | `member-list-records` |
| Record read | `member-get-record` |
| Record action guidance | `member-get-record-actions` |
| Related-record traversal | `member-list-related-records` |
| Write schema discovery | `member-get-write-schema` |
| GitHub issue reporting | `member-create-github-issue` |
| Contribution-request workflows | `member-list-contribution-requests`, `member-approve-contribution-request`, `member-reject-contribution-request`, `member-cancel-contribution-request` |
| Membership-application workflows | `member-list-membership-applications`, `member-submit-membership-application`, `member-cancel-membership-application` |
| Event image prompts | `member-event-cover-image-prompt` (prompt), `member-event-poster-image-prompt` (prompt) |
| Event image upload | `member-upload-event-cover-image`, `member-upload-event-poster-image` |
| Update | `member-update-record` |
| Validate-only preview | Yes, on `member-update-record` |

## Event cover/poster image generation

Event image generation uses a 3-step workflow on the member server:

1. Call the MCP prompt `member-event-cover-image-prompt` (for `cover`) or `member-event-poster-image-prompt` (for `poster`) with `event_key`, optional `creative_direction`, `include_existing_media`, and `max_reference_media` arguments. The prompt returns engineered prompt text and brand reference images.
2. Generate the image using ChatGPT native image generation with the returned prompt and reference images.
3. Upload the result with `member-upload-event-cover-image` (writes `cover` at `16:9`) or `member-upload-event-poster-image` (writes `poster` at `3:4`) by passing the `event_key` and an explicit image descriptor (`{content_base64, filename}`).

- Use `member-upload-event-cover-image` for the website/app event visual.
- Use `member-upload-event-poster-image` for the external-distribution flyer visual.
- Target ratio is fixed and server-enforced:
  - `cover` = `16:9`
  - `poster` = `3:4`
- The upload tool accepts `event_key`, `image` (file descriptor), and an optional `creative_direction` note.
- The prompt's `include_existing_media` and `max_reference_media` arguments control reference-image selection at prompt time. If the prompt call fails while attaching references, retry with `include_existing_media=false` and `max_reference_media=0`.
- Reference-media selection for speaker likeness/context follows:
  1. speaker `cover`
  2. speaker `avatar`
  3. organizer institution media from `event->organizer` when it is an `Institution`
- If speaker and organizer institution media are unavailable, the prompt still returns usable prompt text.
- Operational fallback: if attaching reference media fails, retry the prompt call with `include_existing_media=false` and `max_reference_media=0`, then re-generate and re-upload.

## Writable resource matrix

| Resource | Member MCP | Notes |
| --- | --- | --- |
| `events` | list/get/meta + schema + update + preview | Member scope only; no member create |
| `institutions` | list/get/meta + schema + update + preview | Member scope limited to linked institutions |
| `persons` | list/get/meta + schema + update + preview | Member scope limited to linked persons |
| `references` | list/get/meta + schema + update + preview | Member scope limited to linked references |

## Current member-write-capable resources include:

- `institutions`
- `persons`
- `references`
- `events`

## Relation traversal rules

- Member MCP: use `member-get-resource-meta` to discover whether a relation is exposed, then call `member-list-related-records` with that relation name.
- Do not assume every member resource has a relation you can traverse; rely on metadata returned by the live tool.

## Record action guidance

- Use `member-get-record-actions` when you already have a specific record and want the shortest model-visible list of next MCP calls.
- These read-only tools return focused next-step actions such as refreshing record detail, traversing exposed relations, fetching update schemas, previewing updates, and any explicit workflow tools that are currently valid for that record.
- These tools do not execute mutations; they only point the client at the correct next MCP tool call.

## Entity selection heuristics for record search

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

## Quick search playbook

When the user asks you to “look for” a named place, start with the most likely top-level resource instead of fanning out across multiple resource types immediately:

- Mosque, surau, school, campus, madrasah, maahad, pondok, or university-style names → search `institutions` first.
- Standalone hall/dewan, auditorium, stadium, library, field, or hotel-style names → search `venues` first.
- Room, wing, floor, block, or hall inside a known institution → resolve the parent `institution` first, then use `spaces` for the internal location when needed.
- Only broaden to a second top-level resource when the first pass returns no good match or the user’s wording is genuinely ambiguous.

## Record and schema reading rules

- Use `record_key` values from prior MCP results; prefer returned route-key-style identifiers when the record payload exposes them.
- Treat live write schemas as the field-level source of truth for payload structure, required fields, and media support.
- Institution write schemas expose `names` (multi-name array), `address`, `contacts`, and `social_media` semantics. `address` updates deep-merge omitted nested keys, and `address: {}` is effectively a no-op when the record already has an address with a stored country. Omitted `contacts` / `social_media` preserve the existing collection, `null` or `[]` clear it, and any submitted array replaces the stored collection — `member-get-record` now returns `contacts` and `social_media` directly in `attributes`, so safe clients can fetch the current record, modify the collection locally, then resend the full array without a separate relation-traversal step. For multiple names (nicknames, abbreviations, local names), use the `names` array with items `{full_name, name_type (nickname|abbreviation|local|historical), language_code, is_primary}`. `names: []` clears all alternative names; omitted `names` preserves existing.
- Speaker write schemas expose `address`, `honorific`, `pre_nominal`, `post_nominal`, `qualifications`, `language_ids`, `contacts`, and `social_media` semantics. If you send `address`, include `address.country_id`; `address: {}` is invalid on the write path, while array-style fields replace when present. The visible region fields deep-merge when present; the hidden map fields (`line1`, `line2`, `postcode`, `lat`, `lng`, `google_maps_url`, `google_place_id`, `waze_url`) remain prohibited on the MCP write path. `member-get-record` now returns `contacts` and `social_media` directly in `attributes`, making the fetch-modify-resend flow for these collection fields directly actionable.
- Reference write schemas expose `author`, `publication_year`, `publisher`, `parent_reference_id`, `part_type`, `part_number`, `part_label`, and `social_media` semantics. Omitted optional scalars preserve the existing value, while `null` or trimmed empty input clears `author`, `publication_year`, and `publisher` to `null`. `parent_reference_id` can point only to a root book reference; `null` converts the record back to a root/standalone reference and clears the part fields. `part_type`, `part_number`, and `part_label` are used only when the record remains a child book part, and blank part values normalize to `null`.
- Reference record payloads and list results can now surface `display_title`, `parent_reference_id`, `part_type`, `part_number`, `part_label`, and `is_part`. Use `display_title` for member-facing labels when the linked record is a specific jilid/bahagian/volume.
- Event write schemas expose `event_url`, `live_url`, `recording_url`, `languages`, `references`, `series`, `domain_tags`, `discipline_tags`, `source_tags`, `issue_tags`, `speakers`, `other_key_people`, `organizer_type`, and `registration_mode`. Omitted scalar and relation fields preserve the current value via server-side form-state merge, `null` or `[]` clear supported relation collections, and submitted `speakers` / `other_key_people` arrays rebuild the underlying `key_people` rows with new order values. Member event update schemas inherit the same event semantics because the member MCP surface delegates to the shared admin write service.
- Event record detail payloads also expose the public change-surface projection fields `active_change_notice`, `change_announcements`, and `replacement_event` so MCP clients can reason about the same published replacement-chain behavior as the public/mobile event detail contract without following stale links.
- Handle-style social platforms (`facebook`, `twitter`, `instagram`, `youtube`, `tiktok`, `telegram`, `whatsapp`, `linkedin`, `threads`) may canonicalize a submitted URL into stored `username`, so persisted `url` can come back as `null` after normalization.
- Even though the schema advertises model-layer normalization notes for Twitter / X, validated MCP payloads should still use the canonical platform value `twitter`, not `x`.
- Enum fields and filters use enum backing values, not display labels. For events, use values like `kuliah_ceramah`, `all_ages`, `prayer_relative`, `maghrib`, and `immediately` instead of labels like `Kuliah / Ceramah` or localized prayer text.
- Generic user record payloads intentionally redact `email`, `email_verified_at`, `phone`, `phone_verified_at`, `daily_prayer_institution_id`, and `friday_prayer_institution_id`.
- Record lookups use `route_key` for record-specific paths; missing records return 404 rather than a generic server error.
- Public/mobile surface additions do not automatically imply new MCP tools. The existing generic `member-list-records` flow is still the correct path for resources without a dedicated tool.
- MCP event records come from the member resource service, not the public event controller. Public/mobile event detail payload changes do not automatically appear in `member-get-record` results.
- This guide summarizes server-level capability only; actor-specific authorization still applies at runtime.

## Validate-only preview behavior

- `validate_only=true` is supported on `member-update-record`.
- Previews normalize descriptors into file summaries without persisting media.
- schema-driven `feedback` issues may include suggested values, defaults, and conditional `required_because` context.
- Validation failures in validate-only mode include `fix_plan`, `remaining_blockers`, `normalized_payload_preview`, and `can_retry` so tool clients can recover in one retry loop.
- `clear_*` media flags are intentionally rejected in MCP even when the raw HTTP schema may mention destructive media handling.
- Update previews include the current record snapshot alongside the normalized payload so you can compare what would change before retrying without `validate_only`.

## MCP media/file upload contract

Member tools like `member-submit-membership-application` accept media/file descriptors in JSON format. File uploads work identically to the admin MCP surface; see `docs/ilmu360_mcp_admin_agent_guide.md#mcp-mediafile-upload-contract` for full descriptor shape, aliases, and security rules.

**Quick reference** — all of these descriptor formats are accepted:

```json
// Base64 (always supported)
{ "filename": "proof.pdf", "content_base64": "...", "mime_type": "application/pdf" }

// Content URL
{ "filename": "proof.pdf", "content_url": "https://example.com/file.pdf" }

// Descriptor using download_url/file_id (from file widget)
{ "filename": "proof.pdf", "download_url": "https://api.openai.com/files/...", "file_id": "file_xyz" }
```

For field-specific rules, inspect the tool's `file_descriptor_shape` in the schema after calling `member-get-write-schema`.

## Member media/file upload contract (detailed)

MCP write tools are JSON tools, not multipart HTTP endpoints. When a schema advertises a media/file field, send a descriptor object instead of a browser file upload:

```json
{
  "filename": "poster.png",
  "mime_type": "image/png",
  "content_base64": "iVBORw0KGgo..."
}
```

You can also provide a URL-based descriptor when base64 content is unavailable:

```json
{
  "filename": "poster.png",
  "content_url": "https://example.com/uploads/poster.png"
}
```

**ChatGPT-style descriptor keys** are supported as an alternative to `content_url`:

```json
{
  "filename": "poster.png",
  "download_url": "https://api.openai.com/files/file_id/content",
  "file_id": "file_12345",
  "mime_type": "image/png"
}
```

Accepted aliases are `file_name`, `fileName`, or `name` for `filename`; `mime` or `mimeType` for `mime_type`; and `base64`, `contentBase64`, or `data` for `content_base64`. URL aliases `contentUrl` and `url` are accepted for `content_url`. ChatGPT file params: `downloadUrl` or `download_url` for content URL, and `fileId` or `file_id` for metadata (ignored by server). Data URLs are accepted for `content_base64`. Filename extensions are recommended; when they are omitted, the server derives the staged extension from `mime_type` or the fetched response content type. For safety, URL-based descriptors (`content_url` or `download_url`) must be absolute `http(s)` URLs without embedded credentials, must resolve to public hosts only, and must not redirect.

If a client bridge/proxy file-URL rewrite fails before request dispatch (for example mount-rewrite errors), use `content_base64` descriptors as the fallback path. Event upload tools intentionally run descriptor-first and do not rely on rewrite metadata.

Schema fields describe the exact upload rules:

- `mcp_upload.shape` is `file_descriptor` for a single file and `array<file_descriptor>` for multiple files.
- `accepted_mime_types`, `max_file_size_kb`, and `max_files` are authoritative for that field.
- For event media fields, server validation enforces `cover = 16:9` and `poster = 3:4`.
- `mcp_upload.replacement_semantics` describes whether the submitted descriptor or descriptor array replaces the target media collection.
- When a write tool supports `validate_only=true`, previews normalize descriptors into file summaries without persisting media.
- `current_media` stays metadata-only and does not expose signed or temporary URLs.
- `clear_*` media flags remain unsupported through MCP and are rejected even when clients submit them manually.

## Member MCP tool catalog

The member server is the model-visible API-like surface for Ahli-scoped workflows. The tools below are the current contract that ChatGPT can call:

| Tool | Purpose | Raw HTTP member equivalent |
|---|---|---|
| `search` | Search the verified member MCP documentation pages exposed by this server | MCP-only documentation discovery tool |
| `fetch` | Fetch one verified member documentation page by id | MCP-only documentation fetch tool |
| `member-list-resources` | List accessible member resources and their capability summary | `GET /api/v1/member/manifest` |
| `member-get-resource-meta` | Read one member resource's metadata, pages, relations, abilities, and write-support flags | `GET /api/v1/member/{resourceKey}/meta` |
| `member-search-events` | Search events with rich filters: keyword (title + related institution/speaker/reference expansion by default), geo/nearby, date range, clock-time or prayer-relative window, event type, format, language, audience (gender, age group, children, Muslim-only), institution/venue, key-person roles, topic/tag/reference UUIDs, reference author filters, query expansion toggles (`search_include_*`), and boolean flags (has_event_url, has_live_url, has_end_time) | MCP-only dedicated event discovery tool |
| `member-list-records` | List records for one member resource with optional search, date filters, and pagination | `GET /api/v1/member/{resourceKey}` |
| `member-list-related-records` | Traverse a named relation on one member record | `GET /api/v1/member/{resourceKey}/{recordKey}/relations/{relation}` |
| `member-get-record` | Read one member record and its permissions | `GET /api/v1/member/{resourceKey}/{recordKey}` |
| `member-get-record-actions` | Get focused next-step MCP actions for one member record | MCP-only next-step action guidance tool |
| `member-upload-event-cover-image` | Upload and save a pre-generated 16:9 website/app cover image for one accessible event. Use `member-event-cover-image-prompt` to build the prompt and reference images first, then generate with ChatGPT, then call this tool with the image descriptor. | MCP-only event cover upload tool |
| `member-upload-event-poster-image` | Upload and save a pre-generated 3:4 portrait marketing poster for one accessible event. Use `member-event-poster-image-prompt` to build the prompt and reference images first, then generate with ChatGPT, then call this tool with the image descriptor. | MCP-only event poster upload tool |
| `member-get-write-schema` | Discover the update contract for a writable member record | `GET /api/v1/member/{resourceKey}/schema` |
| `member-create-github-issue` | Create a GitHub issue in the configured repository | `POST /api/v1/github-issues` (member caller path) |
| `member-update-record` | Update or preview a writable member record | `PUT /api/v1/member/{resourceKey}/{recordKey}` |
| `member-list-contribution-requests` | List the authenticated member's contribution queue and pending approvals | `GET /api/v1/member/contribution-requests` |
| `member-approve-contribution-request` | Approve a pending contribution request | `POST /api/v1/member/contribution-requests/{requestKey}/approve` |
| `member-reject-contribution-request` | Reject a pending contribution request | `POST /api/v1/member/contribution-requests/{requestKey}/reject` |
| `member-cancel-contribution-request` | Cancel one of the member's pending contribution requests | `DELETE /api/v1/member/contribution-requests/{requestKey}` |
| `member-list-membership-applications` | List the authenticated member's membership applications | `GET /api/v1/member/membership-applications` |
| `member-submit-membership-application` | Submit a membership application with evidence uploads | `POST /api/v1/member/membership-applications/{subjectType}/{subject}` |
| `member-cancel-membership-application` | Cancel one of the member's membership applications | `DELETE /api/v1/member/membership-applications/{applicationId}` |

Member tool behavior notes:

- `validate_only=true` is supported for member update previews.
- `member-search-events` is a dedicated event discovery tool. It supports keyword search with default cross-entity expansion (institution/speaker/reference), geo-proximity sorting (`sort=distance` with `lat`, `lng`, `radius_km`), date range (`starts_after`, `starts_before`, `time_scope`), clock-time or prayer-relative windows (`timing_mode`, `starts_time_from/until`, `prayer_time`), event type and format arrays, audience and boolean filters, institution/venue/speaker/role filters, tag/reference UUID arrays, reference author filters (`reference_author_search`), and query expansion toggles (`search_include_institutions`, `search_include_speakers`, `search_include_references`). Respects member MCP scope boundaries. Each parameter's inline description lists valid values.
- `member-list-records` shares the same discovery behavior as admin for the overlapping readable resources, but still respects member visibility and ownership boundaries. Unlike admin, `member-list-records` does **not** accept a `filters` object; use `search`, `starts_after`, `starts_before`, and `starts_on_local_date` to narrow results.
- `member-upload-event-cover-image` and `member-upload-event-poster-image` accept a pre-generated image via `{event_key, image, creative_direction?}` and save it to the accessible event media collection. The cover tool writes `cover` at required ratio `16:9`; the poster tool writes `poster` at required ratio `3:4`. The `image` field is a file descriptor: pass `{content_base64, filename}`. Optionally include `mime_type` in the descriptor; it is auto-detected if omitted. Use the MCP prompts `member-event-cover-image-prompt` and `member-event-poster-image-prompt` before calling these tools — the prompts build engineered prompt text with brand reference images for ChatGPT native image generation. Speaker-context references follow this order: speaker `cover`, then speaker `avatar`, then organizer institution media from `event->organizer`.
- If attaching reference media fails, retry the prompt call with `include_existing_media=false` and `max_reference_media=0`, then re-generate and re-upload.
- For date-aware resources, `starts_after`, `starts_before`, and `starts_on_local_date` are date-only `YYYY-MM-DD` strings interpreted in the resolved request timezone. Do not send ISO 8601 timestamps to those MCP arguments. `starts_after` is inclusive (on or after the given local date) and `starts_before` is inclusive (on or before the given local date) across both `member-search-events` and `member-list-records`. For a single local date, use `starts_on_local_date` instead.
- The member surface intentionally does not expose admin-only moderation, triage, or create workflows.
- Member update schemas reuse the same resource-level write semantics where the resource is shared, so clients should still fetch the current record and re-send the full intended collection when a field is replacement-based.
- `member-create-github-issue` creates a plain GitHub issue only; it does not assign Copilot.
- All read-only member tools (discovery, list, get, schema, and workflow listing tools) carry read-only and idempotent metadata hints; MCP clients that honor these hints can call them without requiring confirmation.
- `member-update-record` does **not** support `apply_defaults` (unlike `admin-update-record`). To apply server-side schema defaults during a preview, call `member-get-write-schema` and use the returned defaults directly in the payload.

## Agent Quick Rules

1. Before operational calls, ensure `docs-member-mcp-guide` is loaded via `fetch`.
2. If an operational call returns `documentation_preflight_injected`, **repeat the exact same call** — the guide is now loaded and the call will succeed.
3. For event lists by date range, use `resource_key: "events"` with `starts_after` and `starts_before` (inclusive date-only boundaries).
4. For a single local date, prefer `starts_on_local_date` over a same-day range.
5. For user-facing event summaries, prefer `timing_display`, `starts_on_local_date`, `end_time_display`, and `event_categories` over raw UTC fields.
6. For any update, call `member-get-write-schema` first and re-send the full intended value for replacement-based fields.
7. Never guess relation names — use `member-get-resource-meta` to discover them.
8. Member scope is limited to linked resources — do not assume the full record set is visible.
9. Use `search` when the topic is fuzzy, `fetch` when the guide id is already known.
10. Keep setup, connector, and raw HTTP API concerns out of MCP-only reasoning.

## Explicit CRUD boundary and non-goals

- Member MCP currently supports read flows and schema-guided updates on writable member-scoped resources.
- Member MCP does **not** expose generic delete tools (for example `member-delete-record`).
- Treat delete-like lifecycle actions as explicit workflows only where dedicated tools exist (for example membership-application or contribution cancellation).
- This guide is not the raw HTTP member API contract. Do not infer HTTP endpoint shapes, destructive media flags, or raw schema semantics from MCP behavior.
- This guide is not a Filament panel parity matrix. Do not infer panel capabilities from MCP tool availability or vice versa.
