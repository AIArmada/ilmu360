# ilmu360° Admin MCP Agent Guide

Updated: May 4, 2026 (contacts and social_media now embedded in get-record responses)
Audience: model-facing admin MCP agents and tool clients.

This guide is for the admin MCP surface only. For transport, connector, OAuth, inspector, and other setup details, use `docs/ilmu360_mcp_guide.md`. The member guide is separate and is not exposed through this server.

## Quick Start for Common Admin Reads

### Step 1 — Ensure the guide is loaded

Before the first operational tool call in a session, the guide must be in context:

```json
{
  "tool": "fetch",
  "arguments": { "id": "docs-admin-mcp-guide" }
}
```

### Step 2 — Handling `documentation_preflight_injected`

If you call an operational tool before the guide is loaded, the server auto-injects the guide and returns:

```json
{
  "action": "documentation_preflight_injected",
  "notice": "[Guide auto-loaded] The admin MCP guide has been loaded and the preflight is now satisfied. Re-invoke [admin-list-records] to continue."
}
```

This is **not a failure**. The server has now loaded the guide into the MCP session. **Re-run the exact same operational tool call immediately** and it will proceed.

The retry flow:
1. Call operational tool (e.g. `admin-list-records`)
2. Receive `documentation_preflight_injected`
3. Call the same operational tool again
4. Data returns normally

---

### Common Recipes

#### List events for a date range

Use `admin-list-records` with `resource_key: "events"`. Date filters are **date-only `YYYY-MM-DD` strings** — do not send ISO timestamps.

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

#### Filter to approved, active, public events

For user-facing event reports, scope results to the records that are publicly visible:

```json
{
  "resource_key": "events",
  "filters": {
    "status": "approved",
    "is_active": true,
    "visibility": "public"
  },
  "starts_after": "2026-05-03",
  "starts_before": "2026-05-09",
  "page": 1,
  "per_page": 50
}
```

Omit the `filters` block when you want all records regardless of approval or visibility status.

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
- `panel_routes.view` when an admin link is needed

Use `meta.pagination.total` for the total result count and `meta.pagination.has_more` to know whether additional pages exist.

If `data` is empty and `meta.pagination.total` is 0, the query succeeded but no records matched the filters. Do not treat this as an error.

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

---

## What MCP Means in ilmu360°

ilmu360° exposes the admin MCP server for full admin-surface resource access. Treat the live MCP tool descriptors as the source of truth for what the admin agent can do.

## Verified Documentation Resource

| Resource | URI | Purpose |
|---|---|---|
| `docs-admin-mcp-guide` | `file://docs/ilmu360_mcp_admin_agent_guide.md` | Admin-facing guide for auth, transport, discovery primitives, capability matrix, writable resources, and workflow guidance |

## Documentation search and fetch tools

The admin server exposes two read-only documentation tools for model discoverability. They search and fetch the broader verified, audience-scoped admin documentation catalog:

| Tool | Purpose | Notes |
|---|---|---|
| `search` | Search the verified admin MCP documentation catalog exposed by this server | Input: one `query` string |
| `fetch` | Fetch any verified admin documentation page by id | Input: one `id` string returned by `search` |

These tools do **not** search admin runtime records. The primary guide remains `docs-admin-mcp-guide`; the current admin catalog IDs are:

- `docs-api-mcp-filament-crud-comparison`
- `docs-event-domain-understanding`
- `docs-admin-mcp-guide`
- `docs-admin-event-csv-json-create-guide`
- `docs-general-mcp-guide`
- `docs-mcp-tool-examples`
- `docs-mobile-api-reference`
- `docs-technical-documentation`

## Documentation routing prompt

| Prompt | Purpose | Arguments |
|---|---|---|
| `documentation-tool-routing` | Guidance for deciding when to use `search` vs `fetch` for the verified admin docs page | `topic?` |

The prompt tells the model to:

- fetch `docs-admin-mcp-guide` directly when the question is clearly about ilmu360° admin MCP behavior
- use `search` when the topic is fuzzy or a discovery step is still helpful
- ensure the verified guide is already in context before the first operational admin MCP tool call
- keep runtime data access on the admin record tools instead of the docs tools
- optionally accept a `topic` hint such as `crud`, `auth`, `media uploads`, `runtime records`, `search`, or `fetch` for more targeted guidance

## Operational preflight rule

Before any ilmu360° admin MCP read, search, query, lookup, list, fetch, write, update, create, relation traversal, schema discovery, or workflow action, the client must ensure the verified admin guide is already in context. If it is not, fetch `docs-admin-mcp-guide` first, or use `search` then `fetch` when the topic is still fuzzy.

The admin MCP server enforces a transport-level version of this rule for operational `tools/call` requests: those calls are rejected until `docs-admin-mcp-guide` has been fetched through the MCP `fetch` tool or the guide resource has been read through MCP `resources/read` in the same initialized MCP session. When the guard fires, the server auto-injects the guide and returns a `documentation_preflight_injected` action — see the **Quick Start** section above for the retry pattern.

Apply this before operational tools such as:

- `admin-list-records`
- `admin-get-record`
- `admin-list-related-records`
- `admin-get-resource-meta`
- `admin-get-write-schema`
- `admin-create-event`
- `admin-batch-create-events`
- `admin-update-event`
- `admin-batch-update-events`
- `admin-create-record`
- `admin-batch-create-records`
- `admin-update-record`
- `admin-batch-update-records`
- `admin-get-event-moderation-schema`
- `admin-get-report-triage-schema`
- `admin-get-contribution-request-review-schema`
- `admin-get-membership-application-review-schema`
- `admin-moderate-event`
- `admin-triage-report`
- `admin-review-contribution-request`
- `admin-review-membership-application`

The client may skip a fresh docs fetch only when the verified guide is already active in context, or when the user provides the exact `resource_key`, `record_key`, tool, and intended read operation and no interpretation is required.

Even then, re-check live write schemas or explicit workflow schema/tool guidance before create, update, preview, moderation, triage, or review mutations.

Because the server cannot safely infer conversational shortcuts such as “the user already supplied the exact resource and tool with zero interpretation needed”, the runtime MCP guard remains stricter and still requires a same-session guide fetch or read before operational tool execution.

## What this server exposes

Tool-centric clients like ChatGPT and the OpenAI Responses MCP integration import tools from `tools/list`, not raw resources from `resources/list`.

- A client connected to `/mcp/admin` sees only the admin docs tools (`search`, `fetch`) plus `admin-*` tools. It will not see any `member-*` tools.
- The `docs-admin-mcp-guide` resource is the model-readable documentation page for the admin MCP surface, not a replacement for the live tool and resource descriptors.

## MCP capability matrix

Use this section as the quick admin-only capability summary.

| Capability | Admin MCP |
| --- | --- |
| Docs search | `search` |
| Docs fetch | `fetch` |
| Resource discovery | `admin-list-resources` |
| Resource metadata | `admin-get-resource-meta` |
| Dedicated event discovery | `admin-search-events` |
| Record list | `admin-list-records` |
| Record read | `admin-get-record` |
| Record action guidance | `admin-get-record-actions` |
| Explicit workflow schema discovery | `admin-get-event-moderation-schema`, `admin-get-report-triage-schema`, `admin-get-contribution-request-review-schema`, `admin-get-membership-application-review-schema` |
| Related-record traversal | `admin-list-related-records` |
| Write schema discovery | `admin-get-write-schema` |
| GitHub issue reporting | `admin-create-github-issue` |
| Event moderation | `admin-moderate-event` |
| Report triage | `admin-triage-report` |
| Contribution-request workflows | `admin-review-contribution-request` |
| Membership-application workflows | `admin-review-membership-application` |
| Event image prompts | `admin-event-cover-image-prompt` (prompt), `admin-event-poster-image-prompt` (prompt) |
| Event image upload | `admin-upload-event-cover-image`, `admin-upload-event-poster-image` |
| Dedicated event create | `admin-create-event` |
| Batch event create | `admin-batch-create-events` |
| Dedicated event update | `admin-update-event` |
| Batch event update | `admin-batch-update-events` |
| Create | `admin-create-record` |
| Batch create | `admin-batch-create-records` |
| Update | `admin-update-record` |
| Batch update | `admin-batch-update-records` |
| Validate-only preview | Yes, on create/update/batch tools |

## Event cover/poster image generation

Event image generation uses a 3-step workflow on the admin server:

1. Call the MCP prompt `admin-event-cover-image-prompt` (for `cover`) or `admin-event-poster-image-prompt` (for `poster`) with `event_key`, optional `creative_direction`, `include_existing_media`, and `max_reference_media` arguments. The prompt returns engineered prompt text and brand reference images.
2. Generate the image using ChatGPT native image generation with the returned prompt and reference images.
3. Upload the result with `admin-upload-event-cover-image` (writes `cover` at `16:9`) or `admin-upload-event-poster-image` (writes `poster` at `3:4`) by passing the `event_key` and an explicit image descriptor (`{content_base64, filename}`).

- Use `admin-upload-event-cover-image` for the website/app event visual.
- Use `admin-upload-event-poster-image` for the external-distribution flyer visual.
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
- Reference media listed in prompt context may be omitted from the actual image request when storage access cannot produce a supported attachment type at runtime.

## Writable resource matrix

| Resource | Admin MCP | Notes |
| --- | --- | --- |
| `events` | list/get/meta + schema + create + batch-create + update + batch-update + preview | Event moderation has a dedicated workflow schema tool |
| `inspirations` | list/get/meta + schema + create + batch-create + update + batch-update + preview | Admin-only through the current MCP surface |
| `institutions` | list/get/meta + schema + create + batch-create + update + batch-update + preview | Member scope is separate |
| `persons` | list/get/meta + schema + create + batch-create + update + batch-update + preview | Member scope is separate |
| `references` | list/get/meta + schema + create + batch-create + update + batch-update + preview | Member scope is separate |
| `reports` | list/get/meta + schema + create + batch-create + update + batch-update + preview | Admin-only CRUD plus explicit triage workflow |
| `donation-channels` | list/get/meta + schema + create + batch-create + update + batch-update + preview | Admin-only payment channel management |
| `series` | list/get/meta + schema + create + batch-create + update + batch-update + preview | Admin-only through the current MCP surface |
| `spaces` | list/get/meta + schema + create + batch-create + update + batch-update + preview | Admin-only through the current MCP surface |
| `tags` | list/get/meta + schema + create + batch-create + update + batch-update + preview | Admin-only taxonomy management |
| `venues` | list/get/meta + schema + create + batch-create + update + batch-update + preview | Admin-only through the current MCP surface |
| `subdistricts` | list/get/meta + schema + create + batch-create + update + batch-update + preview | No media upload fields |

## Current structurally write-capable admin resources include:

- `persons`
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

## Relation traversal rules

- Admin only: use `admin-get-resource-meta` to discover whether a relation is exposed, then call `admin-list-related-records` with that relation name.
- Do not assume every admin resource has a relation you can traverse; rely on metadata returned by the live tool.

## Record action guidance

- Use `admin-get-record-actions` when you already have a specific record and want the shortest model-visible list of next MCP calls.
- These read-only tools return focused next-step actions such as refreshing record detail, traversing exposed relations, fetching update schemas, previewing updates, and any explicit workflow tools that are currently valid for that record.
- On the admin surface, workflow-bearing records such as events, reports, contribution requests, and membership applications include both dedicated workflow-schema tool hints and the action-specific defaults, fields, conditional rules, and currently available workflow actions in the same response.
- These tools do not execute mutations; they only point the client at the correct next MCP tool call.

## Explicit workflow schema tools

- Use the dedicated admin workflow-schema tools when you want the canonical read-only workflow contract for one record before calling the matching mutation tool.
- These tools return the same workflow payload shape used by the HTTP admin schema endpoints: defaults, available actions, fields, and conditional rules.
- Current explicit workflow schema tools are `admin-get-event-moderation-schema`, `admin-get-report-triage-schema`, `admin-get-contribution-request-review-schema`, and `admin-get-membership-application-review-schema`.
- These workflow-schema tools and their matching execution tools (`admin-moderate-event`, `admin-triage-report`, `admin-review-contribution-request`, `admin-review-membership-application`) are **conditionally registered** based on the current user's permissions: moderation tools require `canModerate`, triage tools require `canTriage`, and review tools require `canReview`. If any of these tools are absent from `tools/list`, the authenticated admin user lacks the corresponding permission.
- `admin-create-github-issue` is also **conditionally registered** — it is only present when the server-side GitHub issue reporter is configured. If it is absent from `tools/list`, GitHub issue reporting has not been set up on this server instance.

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
- Institution write schemas expose `names` (multi-name array), `address`, `contacts`, and `social_media` semantics. `address` updates deep-merge omitted nested keys, and `address: {}` is effectively a no-op when the record already has an address with a stored country. Omitted `contacts` / `social_media` preserve the existing collection, `null` or `[]` clear it, and any submitted array replaces the stored collection — `admin-get-record` now returns `contacts` and `social_media` directly in `attributes`, so safe clients can fetch the current record, modify the collection locally, then resend the full array without a separate relation-traversal step. For multiple names (nicknames, abbreviations, local names), use the `names` array with items `{full_name, name_type (nickname|abbreviation|local|historical), language_code, is_primary}`. `names: []` clears all alternative names; omitted `names` preserves existing.
- Speaker write schemas expose `address`, `honorific`, `pre_nominal`, `post_nominal`, `qualifications`, `language_ids`, `contacts`, and `social_media` semantics. If you send `address`, include `address.country_id`; `address: {}` is invalid on the write path, while array-style fields replace when present. The visible region fields deep-merge when present; the hidden map fields (`line1`, `line2`, `postcode`, `lat`, `lng`, `google_maps_url`, `google_place_id`, `waze_url`) remain prohibited on the MCP write path. `admin-get-record` now returns `contacts` and `social_media` directly in `attributes`, making the fetch-modify-resend flow for these collection fields directly actionable.
- Venue write schemas expose `address`, `facilities`, `contacts`, and `social_media` semantics. Omitted address keys preserve the existing nested values, but `address: {}` deletes the stored venue address on the shared save path. `facilities`, `contacts`, and `social_media` are replacement collections; `facilities` input is normalized into canonical general-scope `venue_facilities` rows, so safe clients should resend the full enabled facility set.
- Reference write schemas expose `author`, `publication_year`, `publisher`, `parent_reference_id`, `part_type`, `part_number`, `part_label`, and `social_media` semantics. Omitted optional scalars preserve the existing value, while `null` or trimmed empty input clears `author`, `publication_year`, and `publisher` to `null`. `parent_reference_id` turns a book reference into a child part only when it points at a root book reference; `null` converts the record back to a root/standalone reference and clears the part fields. `part_type`, `part_number`, and `part_label` are used only when the reference remains a child book part, and the shared mutation layer normalizes blank part values to `null`. `social_media` follows the same replacement and canonicalization rules as the other write-capable directory resources.
- Reference record payloads and list results can now surface `display_title`, `parent_reference_id`, `part_type`, `part_number`, `part_label`, and `is_part`. Use `display_title` for human-facing labels when a record may be a specific jilid/bahagian/volume.
- Event write schemas expose `event_url`, `live_url`, `recording_url`, `languages`, `references`, `series`, `domain_tags`, `discipline_tags`, `source_tags`, `issue_tags`, `speakers`, `other_key_people`, `organizer_type`, `registration_mode`, and `status`. Omitted scalar and relation fields preserve the current value via server-side form-state merge, `null` or `[]` clear supported relation collections on the raw UUID-array fields, and submitted `speakers` / `other_key_people` arrays rebuild the underlying `key_people` rows with new order values. `status` accepts `draft`, `pending`, or `approved`; create defaults to `draft`; `approved` sets `published_at`; `draft` and `pending` clear it.
- Dedicated MCP event tools are convenience wrappers, not separate persistence paths. `admin-create-event`, `admin-update-event`, `admin-batch-create-events`, and `admin-batch-update-events` accept route-key aliases (`organizer_key`, `institution_key`, `venue_key`, `space_key`, `speaker_keys`, `reference_keys`) and normalize them into the same admin event payload used by the raw HTTP/admin resource writer.
- On update-only MCP event tools, `speaker_keys` and `reference_keys` have presence-sensitive full-sync semantics: omitted or `null` preserves the current relationship set; `[]` detaches all; a non-empty array replaces all with the resolved records. Do not send `[]` unless detaching all is intentional.
- Event record detail payloads also expose the public change-surface projection fields `active_change_notice`, `change_announcements`, and `replacement_event` so MCP clients can reason about the same published replacement-chain behavior as the public/mobile event detail contract without following stale links.
- Series write schemas expose `description`, `languages`, and `slug` semantics. `title`, `slug`, and `visibility` remain required on update; `description` clears on `null` / trimmed empty input and `languages` follows omit-preserve / null-clear / array-replace semantics.
- Donation channel write schemas expose `donatable_type`, `method`, `label`, `reference_note`, and the method-specific bank / DuitNow / ewallet fields. Owner-type aliases normalize to canonical stored morph values, method switches clear unrelated field groups, and destructive QR clear flags remain unsupported through MCP.
- Inspiration write schemas expose `content`, `source`, and `main`. `category`, `locale`, `title`, and `content` remain required on update; `source` clears on `null` / trimmed empty input, and the single `main` media field can only be replaced through MCP, not directly cleared through a destructive flag.
- Space write schemas expose `slug`, `capacity`, and `institutions`. `name` and `slug` remain required on update, `capacity` clears to `null`, and `institutions` follows omit-preserve / null-clear / array-replace relation sync semantics.
- Report write schemas expose `entity_type`, `entity_id`, `category`, `description`, `reporter_id`, `handled_by`, `resolution_note`, and `evidence`. `entity_type`, `entity_id`, `category`, and `status` remain required on update, `category` depends on `entity_type`, the optional text / user-reference fields clear on `null`, and `evidence` preserves on omission or `null` but clears on `[]`.
- Tag write schemas expose `name`, `name.ms`, `name.en`, and `order_column`. `name.en` falls back to `name.ms` when it is omitted, `null`, or trimmed empty input, and blank / null `order_column` values trigger sortable recomputation instead of storing `null`.
- Address geography uses package-native fields: `country_id`, `state_id`, `city_id`, `admin_area_1_id` (district), `admin_area_2_id` (subdistrict). Federal territories may omit district.
- Handle-style social platforms (`facebook`, `twitter`, `instagram`, `youtube`, `tiktok`, `telegram`, `whatsapp`, `linkedin`, `threads`) may canonicalize a submitted URL into stored `username`, so persisted `url` can come back as `null` after normalization.
- Even though the schema advertises model-layer normalization notes for Twitter / X, validated MCP payloads should still use the canonical platform value `twitter`, not `x`.
- Enum fields and filters use enum backing values, not display labels. For events, use values like `kuliah_ceramah`, `all_ages`, `prayer_relative`, `maghrib`, and `immediately` instead of labels like `Kuliah / Ceramah` or localized prayer text.
- Generic user record payloads intentionally redact `email`, `email_verified_at`, `phone`, `phone_verified_at`, `daily_prayer_institution_id`, and `friday_prayer_institution_id`.
- `tracked-properties` payloads intentionally redact ingestion credentials: `attributes.write_key` is returned as `"present"` (or `null` when missing) on admin API and admin MCP list/get record responses. Do not request or emit raw `write_key` values in assistant summaries, logs, or generated reports.
- Record lookups use `route_key` for record-specific paths; missing records return 404 rather than a generic server error.
- Public/mobile surface additions do not automatically imply new MCP tools. For example, a new public directory endpoint does not mean a matching dedicated MCP tool was added; the existing generic `admin-list-records` flow is still the correct path.
- MCP event records come from the admin resource service, not the public event controller. Public/mobile event detail payload changes do not automatically appear in `admin-get-record` results.
- This guide summarizes server-level capability only; actor-specific authorization still applies at runtime.

## Validate-only preview behavior

- `validate_only=true` is supported on `admin-create-event`, `admin-update-event`, `admin-batch-create-events`, `admin-batch-update-events`, `admin-create-record`, `admin-update-record`, `admin-batch-create-records`, and `admin-batch-update-records`.
- Previews normalize descriptors into file summaries without persisting media.
- `apply_defaults=true` is preview-only. It is honored only when `validate_only=true`, where it merges schema defaults into the candidate payload for validation feedback. It is ignored for persisted creates/updates; for real writes, send the exact values you want saved and rely only on the shared backend save action for true model defaults.
- schema-driven `feedback` issues may include suggested values, defaults, and conditional `required_because` context.
- Validation failures in validate-only mode include `fix_plan`, `remaining_blockers`, `normalized_payload_preview`, and `can_retry` so tool clients can recover in one retry loop.
- `clear_*` media flags are intentionally rejected in MCP even when the raw HTTP admin schema may mention destructive media handling.
- Update previews include the current record snapshot alongside the normalized payload so you can compare what would change before retrying without `validate_only`.

## MCP media/file upload contract

MCP write tools are JSON tools, not multipart HTTP endpoints. When a schema advertises a media/file field, send a descriptor object instead of a browser file upload:

```json
{
  "filename": "poster.png",
  "mime_type": "image/png",
  "content_base64": "iVBORw0KGgo..."
}
```

You can provide a URL-based descriptor when base64 content is unavailable:

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

Accepted aliases: `file_name`, `fileName`, or `name` for `filename`; `mime` or `mimeType` for `mime_type`; `base64`, `contentBase64`, or `data` for `content_base64`; and `contentUrl` or `url` for `content_url`. ChatGPT file params: `downloadUrl` or `download_url` for content URL, and `fileId` or `file_id` for metadata (ignored by server). Data URLs are accepted for `content_base64`. Filename extensions are recommended; when omitted, the server derives the staged extension from `mime_type` or response Content-Type header. For safety, URL-based descriptors (`content_url` or `download_url`) must be absolute `http(s)` URLs without embedded credentials, must resolve to public hosts only, and must not redirect.

If a client bridge/proxy file-URL rewrite fails before request dispatch (for example mount-rewrite errors), use `content_base64` descriptors as the fallback path. Event upload/create tools intentionally run descriptor-first and do not rely on rewrite metadata.

Schema fields describe the exact upload rules:

- `mcp_upload.shape` is `file_descriptor` for a single file and `array<file_descriptor>` for multiple files.
- `accepted_mime_types`, `max_file_size_kb`, and `max_files` are authoritative for that field.
- For event media fields, server validation enforces `cover = 16:9` and `poster = 3:4`.
- `mcp_upload.replacement_semantics` describes whether the submitted descriptor or descriptor array replaces the target media collection.
- When a write tool supports `validate_only=true`, previews normalize descriptors into file summaries without persisting media.
- `current_media` stays metadata-only and does not expose signed or temporary URLs.
- `clear_*` media flags remain unsupported through MCP and are rejected even when clients submit them manually.

## Admin MCP tool catalog

The admin server is the model-visible API-like surface for admin workflows. The tools below are the current contract that ChatGPT can call:

| Tool | Purpose | Raw HTTP admin equivalent |
|---|---|---|
| `search` | Search the verified admin MCP documentation pages exposed by this server | MCP-only documentation discovery tool |
| `fetch` | Fetch one verified admin documentation page by id | MCP-only documentation fetch tool |
| `admin-list-resources` | List accessible admin resources and their capability summary | `GET /api/v1/admin/manifest` |
| `admin-get-resource-meta` | Read one admin resource's metadata, pages, relations, abilities, and write-support flags | `GET /api/v1/admin/{resourceKey}/meta` |
| `admin-search-events` | Search events with rich filters: keyword (title + related institution/speaker/reference expansion by default), geo/nearby, date range, clock-time or prayer-relative window, event type, format, language, audience (gender, age group, children, Muslim-only), institution/venue, key-person roles, topic/tag/reference UUIDs, reference author filters, query expansion toggles (`search_include_*`), and boolean flags (has_event_url, has_live_url, has_end_time) | `GET /api/v1/admin/events/search` (same filter contract) |
| `admin-list-records` | List records for one admin resource with optional search, structured filters, date filters, and pagination | `GET /api/v1/admin/{resourceKey}` |
| `admin-list-related-records` | Traverse a named relation on one admin record | `GET /api/v1/admin/{resourceKey}/{recordKey}/relations/{relation}` |
| `admin-get-record` | Read one admin record and its permissions | `GET /api/v1/admin/{resourceKey}/{recordKey}` |
| `admin-get-record-actions` | Get focused next-step MCP actions for one admin record | MCP-only next-step action guidance tool |
| `admin-upload-event-cover-image` | Upload and save a pre-generated 16:9 website/app cover image for one admin-accessible event. Use `admin-event-cover-image-prompt` to build the prompt and reference images first, then generate with ChatGPT, then call this tool with the image descriptor. | MCP-only event cover upload tool |
| `admin-upload-event-poster-image` | Upload and save a pre-generated 3:4 portrait marketing poster for one admin-accessible event. Use `admin-event-poster-image-prompt` to build the prompt and reference images first, then generate with ChatGPT, then call this tool with the image descriptor. | MCP-only event poster upload tool |
| `admin-get-write-schema` | Discover the create/update contract for a writable admin record | `GET /api/v1/admin/{resourceKey}/schema` |
| `admin-get-event-moderation-schema` | Read the explicit moderation schema for one event | `GET /api/v1/admin/events/{recordKey}/moderation-schema` |
| `admin-get-report-triage-schema` | Read the explicit triage schema for one report | `GET /api/v1/admin/reports/{recordKey}/triage-schema` |
| `admin-get-contribution-request-review-schema` | Read the explicit review schema for one contribution request | `GET /api/v1/admin/contribution-requests/{recordKey}/review-schema` |
| `admin-get-membership-application-review-schema` | Read the explicit review schema for one membership application | `GET /api/v1/admin/membership-applications/{recordKey}/review-schema` |
| `admin-create-event` | MCP-only event wrapper for create/preview with event-first fields and relation route keys. Accepts scalar event fields (`title`, `event_date`, `prayer_time`, `event_category_ids`, `description`, `custom_time`, `end_time`, `timezone`, `event_format`, `visibility`, `event_url`, `live_url`, `recording_url`, `gender`, `age_group`, `children_allowed`, `is_muslim_only`, `status`, `registration_required`, `registration_mode`, `is_priority`, `is_featured`, `is_active`), relation route keys (`organizer_type`, `organizer_key`, `institution_key`, `venue_key`, `space_key`), speaker/reference route-key arrays (`speaker_keys`, `reference_keys`), language IDs (`languages`), tag arrays (`domain_tags`, `discipline_tags`, `source_tags`, `issue_tags`), `other_key_people`, optional `series`, media descriptors (`cover`, `poster`, `gallery`), and control flags (`validate_only`, `apply_defaults`). `apply_defaults` is preview-only. | `POST /api/v1/admin/{resourceKey}` with `resourceKey=events` (MCP event-first wrapper) |
| `admin-get-record-media` | List media attachments for one admin record to verify uploads or prefill image generation forms | MCP-only media inspection tool |
| `admin-read-debug-log` | Read recent filtered lines from the application debug log | MCP-only debug log reader |
| `admin-create-github-issue` | Create a GitHub issue in the configured repository and auto-assign Copilot | `POST /api/v1/github-issues` (admin caller path) |
| `admin-moderate-event` | Run one explicit moderation action on an event | `POST /api/v1/admin/events/{recordKey}/moderate` |
| `admin-triage-report` | Run one explicit triage action on a report | `POST /api/v1/admin/reports/{recordKey}/triage` |
| `admin-review-contribution-request` | Approve or reject one pending contribution request | `POST /api/v1/admin/contribution-requests/{recordKey}/review` |
| `admin-review-membership-application` | Approve or reject a pending membership application | `POST /api/v1/admin/membership-applications/{recordKey}/review` |
| `admin-create-record` | Create or preview a writable admin record | `POST /api/v1/admin/{resourceKey}` |
| `admin-update-record` | Update or preview a writable admin record | `PUT /api/v1/admin/{resourceKey}/{recordKey}` |

Admin tool behavior notes:

- `validate_only=true` is supported for create/update preview flows.
- `apply_defaults=true` has no effect unless `validate_only=true`. Treat schema defaults as preview/autofill hints, not as a persisted-write shortcut.
- `admin-list-resources` is a discovery manifest, not merely a small name list. Keep `verbose=false` for compact exploration and use `verbose=true` only when you need full metadata. Pass `writable_only=true` to filter the list to only resources with active write support.
- `current_media` is metadata only; it is useful for form prefill but does not expose signed URLs.
- `admin-search-events` is a dedicated event discovery tool and is aligned with `GET /api/v1/admin/events/search`. It supports keyword search with default cross-entity expansion (institution/speaker/reference), geo-proximity sorting (`sort=distance` with `lat`, `lng`, `radius_km`), date range (`starts_after`, `starts_before`, `time_scope`), clock-time or prayer-relative windows (`timing_mode`, `starts_time_from/until`, `prayer_time`), event type and format arrays, audience and audience-boolean filters, institution/venue/speaker/role filters, tag/reference UUID arrays, reference author filters (`reference_author_search`), and query expansion toggles (`search_include_institutions`, `search_include_speakers`, `search_include_references`). Each parameter's description in the tool schema lists valid enum values and interdependencies (e.g. `sort=distance` requires `lat`+`lng`).
- `admin-list-records` accepts a `filters` object keyed by the resource metadata filter keys, for example `{ "status": "approved", "is_active": true }` for `events`.
- `admin-upload-event-cover-image` and `admin-upload-event-poster-image` accept a pre-generated image via `{event_key, image, creative_direction?}` and save it to the event media collection. The cover tool writes `cover` at required ratio `16:9`; the poster tool writes `poster` at required ratio `3:4`. The `image` field is a file descriptor: pass `{content_base64, filename}`. Optionally include `mime_type` in the descriptor; it is auto-detected if omitted. Use the MCP prompts `admin-event-cover-image-prompt` and `admin-event-poster-image-prompt` before calling these tools — the prompts build engineered prompt text with brand reference images for ChatGPT native image generation. Speaker-context references follow this order: speaker `cover`, then speaker `avatar`, then organizer institution media from `event->organizer`.
- If attaching reference media fails, retry the prompt call with `include_existing_media=false` and `max_reference_media=0`, then re-generate and re-upload.
- For `speakers`, `institutions`, and `references`, `admin-list-records` search reuses the same specialized search services as the public directory endpoints; the main difference is record scope, not text-matching behavior.
- For date-aware resources, `starts_after`, `starts_before`, and `starts_on_local_date` are date-only `YYYY-MM-DD` strings interpreted in the resolved request timezone. Do not send ISO 8601 timestamps to those MCP arguments. `starts_after` is inclusive (on or after the given local date) and `starts_before` is inclusive (on or before the given local date) across both `admin-search-events` and `admin-list-records`. For a single local date, use `starts_on_local_date` instead.
- Event enum filters and payload values must be backing values, for example `filter[event_category_ids]=kuliah_ceramah` and `filter[timing_mode]=prayer_relative`.
- `admin-get-record-actions` is read-only and returns record-specific next-step MCP tools, including explicit workflow-schema tool hints when a moderation, triage, or review flow is currently available on that record.
- The dedicated admin workflow-schema tools are read-only and expose defaults, available actions, fields, and conditional rules for their matching moderation/review workflow.
- Media/file upload fields accept JSON descriptors when the matching write schema advertises them (`content_base64`, `content_url`, and `download_url` are supported by descriptor parsing).
- `clear_*` media flags are intentionally rejected in MCP even when the raw HTTP admin schema may mention destructive media handling.
- `admin-create-github-issue` creates a GitHub issue and, for admin actors, automatically assigns Copilot using the server-side configuration and model fallback chain.
- All read-only tools (discovery, list, get, schema, and workflow-schema tools) carry read-only and idempotent metadata hints; MCP clients that honor these hints can call them without requiring confirmation.
- Write and workflow execution tools carry non-read-only and non-idempotent metadata hints; MCP clients may prompt for confirmation before calling them.
- `admin-update-event` and `admin-batch-update-events` use the same route-key contract as `admin-create-event`, but update relation aliases are presence-sensitive: omit `speaker_keys`/`reference_keys` or pass `null` to preserve existing relationships; pass `[]` to detach all; pass a non-empty array to replace all.
- For event creation, prefer `admin-create-event` over `admin-create-record` to avoid raw UUID-heavy payloads and get event-specific confirmation text. Its payload is aligned with `/hantar-majlis` field semantics (excluding media-only UX concerns) and is normalized into the same admin API create contract used by `POST /api/v1/admin/events`: use route-key helpers (`organizer_key`, `institution_key`, `venue_key`, `space_key`, `speaker_keys`, `reference_keys`) plus direct arrays for tags (`domain_tags`, `discipline_tags`, `source_tags`, `issue_tags`), `other_key_people`, and `series` where needed. For persisted creates, include the actual defaultable values you want stored (`timezone`, `event_format`, `visibility`, `gender`, `age_group`, etc.); `apply_defaults` will not fill them unless the call is a validate-only preview.
- `admin-get-record-media` is useful after any media upload to confirm that the file persisted correctly and to retrieve collection metadata for image-generation reference prompts.
- `admin-read-debug-log` exposes recent application log lines. It is conditionally available and should only be invoked by trusted admin actors for debugging purposes.

## Agent Quick Rules

1. Before operational calls, ensure `docs-admin-mcp-guide` is loaded via `fetch`.
2. If an operational call returns `documentation_preflight_injected`, **repeat the exact same call** — the guide is now loaded and the call will succeed.
3. For event lists by date range, use `resource_key: "events"` with `starts_after` and `starts_before` (inclusive date-only boundaries).
4. For a single local date, prefer `starts_on_local_date` over a same-day range.
5. For user-facing event summaries, prefer `timing_display`, `starts_on_local_date`, `end_time_display`, and `event_categories` over raw UTC fields.
6. For any write, call `admin-get-write-schema` first and trust the live schema.
7. Never guess relation names — use `admin-get-resource-meta` to discover them.
8. Use `search` when the topic is fuzzy, `fetch` when the guide id is already known.
9. Keep setup, connector, and raw HTTP API concerns out of MCP-only reasoning.

## Explicit CRUD boundary and non-goals

- Admin MCP currently supports create and update for writable resources through schema-guided tools.
- Admin MCP does **not** expose generic delete tools (for example `admin-delete-record`).
- Treat delete, restore, reorder, and replicate as panel-led operations unless a future MCP tool is explicitly added.
- This guide is not the raw HTTP admin API contract. Read `docs/ilmu360_mobile_api_reference.md` for the HTTP surface, which has different payload shapes, destructive media flags, and raw schema semantics.
- This guide is not a Filament panel parity matrix. Do not infer panel capabilities from MCP tool availability or vice versa.
