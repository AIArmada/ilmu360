# ilmu360° MCP Tool Examples

Use this companion reference alongside `docs/ilmu360_mcp_guide.md` when you want ChatGPT to treat the MCP connector like an API catalog.

For the full connector rules, tool catalog, and API mappings, see `docs/ilmu360_mcp_guide.md`.

## Admin MCP

### Discover resources

```json
{ "tool": "admin-list-resources", "arguments": { "verbose": false, "writable_only": false } }
```

### Read one record

```json
{ "tool": "admin-get-record", "arguments": { "resource_key": "speakers", "record_key": "ahmad-fauzi-my" } }
```

### Read one event with public change projections

```json
{ "tool": "admin-get-record", "arguments": { "resource_key": "events", "record_key": "weekly-kuliah-selasa" } }
```

Event record detail for `events` now includes the public change-surface projection fields `active_change_notice`, `change_announcements`, and `replacement_event` inside `data.record.attributes`.

### Generate an event cover image

Event image generation uses a 3-step workflow. First, call the MCP prompt to get the engineered prompt text and brand reference images:

```json
{
  "prompt": "admin-event-cover-image-prompt",
  "arguments": {
    "event_key": "weekly-kuliah-selasa",
    "creative_direction": "Premium editorial cover with deep emerald, warm gold, and strong Malay typography.",
    "include_existing_media": true,
    "max_reference_media": 6
  }
}
```

The prompt returns engineered prompt text and brand reference images. Use these with ChatGPT native image generation (e.g. `gpt-image-1`) to generate the image.

Then upload the generated result:

```json
{
  "tool": "admin-upload-event-cover-image",
  "arguments": {
    "event_key": "weekly-kuliah-selasa",
    "image": {
      "filename": "cover.png",
      "mime_type": "image/png",
      "download_url": "https://api.openai.com/files/file_id/content",
      "file_id": "file_xyz"
    },
    "creative_direction": "Premium editorial cover with deep emerald, warm gold, and strong Malay typography."
  }
}
```

The upload tool saves the image as a 16:9 `cover` in the Event media collection. Use `admin-event-poster-image-prompt` and `admin-upload-event-poster-image` when the intended asset is the 3:4 external-distribution poster.

When reference media is enabled, speaker context selection follows this fallback order: speaker `cover`, then speaker `avatar`, then organizer institution media from `event->organizer`.

If attaching reference media fails, retry the prompt without reference media:

```json
{
  "prompt": "admin-event-cover-image-prompt",
  "arguments": {
    "event_key": "weekly-kuliah-selasa",
    "creative_direction": "Premium editorial cover with deep emerald, warm gold, and strong Malay typography.",
    "include_existing_media": false,
    "max_reference_media": 0
  }
}
```

Then regenerate the image and upload with `admin-upload-event-cover-image`.

### Generate an event poster image

Event poster generation also uses the two-step workflow. Call the prompt first:

```json
{
  "prompt": "admin-event-poster-image-prompt",
  "arguments": {
    "event_key": "weekly-kuliah-selasa",
    "creative_direction": "Information-rich social poster with clear hierarchy and strong readability.",
    "include_existing_media": true,
    "max_reference_media": 6
  }
}
```

Use the returned prompt and reference images with ChatGPT native image generation, then upload the result:

```json
{
  "tool": "admin-upload-event-poster-image",
  "arguments": {
    "event_key": "weekly-kuliah-selasa",
    "image": {
      "filename": "poster.png",
      "mime_type": "image/png",
      "download_url": "https://api.openai.com/files/file_id/content",
      "file_id": "file_xyz"
    }
  }
}
```

The upload tool saves the image as a 3:4 `poster` in the Event media collection.

When reference media is enabled, speaker context selection follows this fallback order: speaker `cover`, then speaker `avatar`, then organizer institution media from `event->organizer`.

### Filter an event list

Use enum backing values in `filters`, and use date-only `YYYY-MM-DD` values for `starts_after`, `starts_before`, and `starts_on_local_date`.

```json
{
  "tool": "admin-list-records",
  "arguments": {
    "resource_key": "events",
    "filters": {
      "status": "approved",
      "is_active": true,
      "timing_mode": "absolute"
    },
    "page": 1,
    "per_page": 10
  }
}
```

### Search events with dedicated filters

Use the dedicated event search tool when you need richer discovery: geo-proximity, prayer-time windows, role-oriented constraints, and tag signals. All filter parameters are optional and combinable. Each parameter's inline description (visible in the tool schema) lists valid enum values and any interdependencies.

Keyword `query` expands to related institution, speaker/key-person, and reference matches by default. Use `search_include_institutions`, `search_include_speakers`, and `search_include_references` to narrow expansion when needed.

```json
{
  "tool": "admin-search-events",
  "arguments": {
    "query": "kuliah aqidah",
    "time_scope": "upcoming",
    "event_format": ["physical"],
    "is_muslim_only": true,
    "age_group": ["youth"],
    "lat": 3.139,
    "lng": 101.687,
    "radius_km": 10,
    "sort": "distance",
    "page": 1,
    "per_page": 12
  }
}
```

```json
{
  "tool": "admin-search-events",
  "arguments": {
    "query": "ibn qudamah",
    "reference_author_search": ["Ibn Qudamah"],
    "search_include_institutions": false,
    "search_include_speakers": false,
    "search_include_references": true,
    "time_scope": "all",
    "sort": "relevance",
    "per_page": 10
  }
}
```

```json
{
  "tool": "admin-search-events",
  "arguments": {
    "time_scope": "upcoming",
    "event_category_ids": ["khutbah_jumaat"],
    "timing_mode": "prayer_relative",
    "prayer_time": "friday_prayer",
    "state_id": 14,
    "sort": "time",
    "per_page": 20
  }
}
```

```json
{
  "tool": "admin-list-records",
  "arguments": {
    "resource_key": "events",
    "search": "Dhuha",
    "starts_on_local_date": "2026-04-24",
    "filters": {
      "status": "approved"
    }
  }
}
```

```json
{
  "tool": "admin-list-records",
  "arguments": {
    "resource_key": "events",
    "starts_after": "2026-04-24",
    "starts_before": "2026-04-30"
  }
}
```

### Preview a write

```json
{ "tool": "admin-get-write-schema", "arguments": { "resource_key": "speakers", "operation": "update", "record_key": "ahmad-fauzi-my" } }
```

```json
{
  "tool": "admin-update-record",
  "arguments": {
    "resource_key": "speakers",
    "record_key": "ahmad-fauzi-my",
    "payload": {
      "name": "Ahmad Fauzi bin Abdullah",
      "gender": "male",
      "status": "verified"
    },
    "validate_only": true,
    "apply_defaults": true
  }
}
```

### Write media descriptors

Fetch the write schema first and follow the field-specific `mcp_upload`, `accepted_mime_types`, `max_file_size_kb`, and `max_files` metadata. Single-file fields use one descriptor object. Multi-file fields use an array of descriptor objects. Descriptors support `content_base64`, `content_url`, or ChatGPT `download_url` / `file_id` parameters.

For event media writes, ratio checks are server-enforced on every write surface: `cover` must be `16:9` and `poster` must be `3:4`.

**Example with base64:**

```json
{
  "tool": "admin-update-record",
  "arguments": {
    "resource_key": "speakers",
    "record_key": "ahmad-fauzi-my",
    "payload": {
      "name": "Ahmad Fauzi bin Abdullah",
      "gender": "male",
      "status": "verified",
      "avatar": {
        "filename": "avatar.png",
        "mime_type": "image/png",
        "content_base64": "iVBORw0KGgo..."
      }
    },
    "validate_only": true
  }
}
```

**Manual event cover replacement with base64 descriptor (fallback path):**

```json
{
  "tool": "admin-update-record",
  "arguments": {
    "resource_key": "events",
    "record_key": "weekly-kuliah-selasa",
    "payload": {
      "cover": {
        "filename": "weekly-kuliah-selasa-cover.png",
        "mime_type": "image/png",
        "content_base64": "iVBORw0KGgo..."
      }
    },
    "validate_only": false
  }
}
```

**Example with explicit `download_url` descriptor:**

```json
{
  "tool": "admin-update-record",
  "arguments": {
    "resource_key": "speakers",
    "record_key": "ahmad-fauzi-my",
    "payload": {
      "gallery": [
        {
          "filename": "lecture-hall.webp",
          "mime_type": "image/webp",
          "download_url": "https://api.openai.com/files/file_id/content",
          "file_id": "file_12345"
        }
      ]
    },
    "validate_only": false
  }
}
```

### Create or update a book part reference

Fetch the live schema first so the client sees the current enum values and allowed parent-book rules.

```json
{ "tool": "admin-get-write-schema", "arguments": { "resource_key": "references", "operation": "update", "record_key": "riyadhus-solihin-jilid-2" } }
```

```json
{
  "tool": "admin-update-record",
  "arguments": {
    "resource_key": "references",
    "record_key": "riyadhus-solihin-jilid-2",
    "payload": {
      "title": "Riyadhus Solihin",
      "type": "book",
      "parent_reference_id": "0195f51f-70d6-70e5-bf9d-bc2e04b64f7a",
      "part_type": "jilid",
      "part_number": "2",
      "part_label": "Jilid 2"
    },
    "validate_only": true
  }
}
```

### Update a single event

Use `admin-update-event` to update an existing event identified by slug or UUID. Specify only the fields you want to change; omitted optional fields preserve the current value. `speaker_keys` and `reference_keys` are MCP-only route-key aliases for the underlying `speakers` and `references` UUID arrays. On update, they are presence-sensitive full syncs: omit or pass `null` to leave unchanged, pass `[]` to detach all, or pass a non-empty array to replace all.

```json
{
  "tool": "admin-update-event",
  "arguments": {
    "event_key": "weekly-kuliah-selasa",
    "title": "Kuliah Selasa — Siri Baharu",
    "event_date": "2026-05-13",
    "prayer_time": "after_maghrib",
    "venue_key": "masjid-wilayah-persekutuan",
    "speaker_keys": ["ahmad-fauzi-my", "ustaz-zaharuddin"],
    "validate_only": true
  }
}
```

To intentionally detach all speakers and references, send the empty arrays explicitly:

```json
{
  "tool": "admin-update-event",
  "arguments": {
    "event_key": "weekly-kuliah-selasa",
    "speaker_keys": [],
    "reference_keys": []
  }
}
```

### Batch create events

Use `admin-batch-create-events` to create up to 50 events in one call. Each item uses the same field contract as `admin-create-event`. Set `validate_only=true` to preview all rows without persisting. `apply_defaults=true` is a preview-only helper; it is ignored unless `validate_only=true`, so real create rows must include the values you want saved.

```json
{
  "tool": "admin-batch-create-events",
  "arguments": {
    "validate_only": false,
    "items": [
      {
        "external_row_id": "row-1",
        "title": "Kuliah Tafsir Selasa",
        "event_date": "2026-05-06",
        "prayer_time": "after_maghrib",
        "event_format": "physical",
        "institution_key": "masjid-wilayah-persekutuan",
        "speaker_keys": ["ahmad-fauzi-my"]
      },
      {
        "external_row_id": "row-2",
        "title": "Kuliah Tafsir Rabu",
        "event_date": "2026-05-07",
        "prayer_time": "after_isyak",
        "event_format": "physical",
        "institution_key": "masjid-wilayah-persekutuan",
        "speaker_keys": ["ustaz-zaharuddin"]
      }
    ]
  }
}
```

Each result in `data.results` has a `status` of `created`, `validation_failed`, `unresolved_key`, or `error`. The `summary` block counts totals per status.

### Batch update events

Use `admin-batch-update-events` to update up to 50 existing events in one call. Each item must include `event_key` (slug or UUID). Omitted optional fields preserve the current value. `speaker_keys` and `reference_keys` use the same update semantics as `admin-update-event`: omit or `null` preserves, `[]` detaches all, non-empty arrays replace all.

```json
{
  "tool": "admin-batch-update-events",
  "arguments": {
    "validate_only": false,
    "items": [
      {
        "event_key": "weekly-kuliah-selasa",
        "external_row_id": "row-1",
        "event_date": "2026-05-13",
        "prayer_time": "after_maghrib"
      },
      {
        "event_key": "kuliah-tafsir-rabu",
        "external_row_id": "row-2",
        "status": "approved"
      }
    ]
  }
}
```

### Batch create generic resource records

Use `admin-batch-create-records` to create up to 100 records for any writable admin resource in one call. Fetch the write schema first to confirm the required fields.

```json
{
  "tool": "admin-batch-create-records",
  "arguments": {
    "resource_key": "tags",
    "validate_only": false,
    "items": [
      {
        "external_row_id": "tag-1",
        "payload": { "name": { "ms": "Fiqh Muamalat", "en": "Fiqh Muamalat" } }
      },
      {
        "external_row_id": "tag-2",
        "payload": { "name": { "ms": "Sirah Nabawiyyah", "en": "Sirah Nabawiyyah" } }
      }
    ]
  }
}
```

### Batch update generic resource records

Use `admin-batch-update-records` to update up to 100 records for any writable admin resource in one call. Each item must include `record_key` (UUID or slug) and `payload`.

```json
{
  "tool": "admin-batch-update-records",
  "arguments": {
    "resource_key": "speakers",
    "validate_only": true,
    "items": [
      {
        "record_key": "ahmad-fauzi-my",
        "external_row_id": "spk-1",
        "payload": { "status": "verified" }
      },
      {
        "record_key": "ustaz-zaharuddin",
        "external_row_id": "spk-2",
        "payload": { "status": "verified" }
      }
    ]
  }
}
```

### Create a GitHub issue and auto-assign Copilot

```json
{
  "tool": "admin-create-github-issue",
  "arguments": {
    "category": "docs_mismatch",
    "title": "Clarify MCP GitHub issue reporting",
    "summary": "The tool and API contracts should document the same required fields and response shape.",
    "platform": "chatgpt",
    "client_name": "ChatGPT",
    "client_version": "GPT-5.4",
    "tool_name": "admin-create-github-issue",
    "proposal": "Keep one shared API + MCP issue-reporting contract in the docs."
  }
}
```

## Member MCP

### Discover accessible resources

```json
{ "tool": "member-list-resources", "arguments": { "verbose": false } }
```

### Read one record

```json
{ "tool": "member-get-record", "arguments": { "resource_key": "institutions", "record_key": "019d5cb5-7de1-7055-a4d3-b57ab007331e" } }
```

For member-scoped event reads, the same `data.record.attributes.active_change_notice`, `change_announcements`, and `replacement_event` fields are available on `member-get-record` when `resource_key` is `events`.

### Generate an accessible event cover image

Event image generation uses a 3-step workflow on the member server. First, call the prompt to get the engineered prompt text and brand reference images:

```json
{
  "prompt": "member-event-cover-image-prompt",
  "arguments": {
    "event_key": "weekly-kuliah-selasa",
    "creative_direction": null,
    "include_existing_media": true,
    "max_reference_media": 6
  }
}
```

Use the returned prompt and reference images with ChatGPT native image generation, then upload the result:

```json
{
  "tool": "member-upload-event-cover-image",
  "arguments": {
    "event_key": "weekly-kuliah-selasa",
    "image": {
      "filename": "cover.png",
      "mime_type": "image/png",
      "download_url": "https://api.openai.com/files/file_id/content",
      "file_id": "file_xyz"
    }
  }
}
```

The upload tool saves the image as a 16:9 `cover` in the accessible Event media collection.

When reference media is enabled, speaker context selection follows this fallback order: speaker `cover`, then speaker `avatar`, then organizer institution media from `event->organizer`.

### Generate an accessible event poster image

Call the poster prompt first:

```json
{
  "prompt": "member-event-poster-image-prompt",
  "arguments": {
    "event_key": "weekly-kuliah-selasa",
    "creative_direction": null,
    "include_existing_media": true,
    "max_reference_media": 6
  }
}
```

Use the returned prompt and reference images with ChatGPT native image generation, then upload the result:

```json
{
  "tool": "member-upload-event-poster-image",
  "arguments": {
    "event_key": "weekly-kuliah-selasa",
    "image": {
      "filename": "poster.png",
      "mime_type": "image/png",
      "download_url": "https://api.openai.com/files/file_id/content",
      "file_id": "file_xyz"
    }
  }
}
```

The upload tool saves the image as a 3:4 `poster` in the accessible Event media collection. Use `member-event-cover-image-prompt` and `member-upload-event-cover-image` when the intended asset is the 16:9 website/app cover.

When reference media is enabled, speaker context selection follows this fallback order: speaker `cover`, then speaker `avatar`, then organizer institution media from `event->organizer`.

### Preview a write

```json
{ "tool": "member-get-write-schema", "arguments": { "resource_key": "institutions", "record_key": "019d5cb5-7de1-7055-a4d3-b57ab007331e" } }
```

### Search events with dedicated filters

Use the member-scoped event search tool for rich discovery while respecting Ahli visibility boundaries.

Keyword `query` also expands to institution, speaker/key-person, and reference matches by default. Toggle `search_include_*` flags to include/exclude each relation family.

```json
{
  "tool": "member-search-events",
  "arguments": {
    "query": "dhuha",
    "time_scope": "all",
    "event_format": ["physical"],
    "children_allowed": true,
    "state_id": 14,
    "sort": "time",
    "page": 1,
    "per_page": 10
  }
}
```

```json
{
  "tool": "member-search-events",
  "arguments": {
    "query": "markaz ikhlas",
    "search_include_institutions": true,
    "search_include_speakers": false,
    "search_include_references": false,
    "sort": "relevance",
    "time_scope": "all",
    "per_page": 10
  }
}
```

```json
{
  "tool": "member-search-events",
  "arguments": {
    "time_scope": "upcoming",
    "event_format": ["online", "hybrid"],
    "has_live_url": true,
    "age_group": ["adults"],
    "sort": "time",
    "per_page": 20
  }
}
```

```json
{
  "tool": "member-update-record",
  "arguments": {
    "resource_key": "institutions",
    "record_key": "019d5cb5-7de1-7055-a4d3-b57ab007331e",
    "payload": {
      "name": "Updated Institution Name"
    },
    "validate_only": false
  }
}
```

### Update member media descriptors

**Example with base64:**

```json
{
  "tool": "member-update-record",
  "arguments": {
    "resource_key": "institutions",
    "record_key": "019d5cb5-7de1-7055-a4d3-b57ab007331e",
    "payload": {
      "name": "Updated Institution Name",
      "cover": {
        "filename": "institution-cover.jpg",
        "mime_type": "image/jpeg",
        "content_base64": "/9j/4AAQSkZJRgABAQ..."
      }
    },
    "validate_only": false
  }
}
```

**Example with explicit `download_url` descriptor:**

```json
{
  "tool": "member-submit-membership-claim",
  "arguments": {
    "subject_type": "speaker",
    "subject_id": "019d5cb5-7de1-7055-a4d3-b57ab007331e",
    "justification": "I am authorized to represent this speaker.",
    "evidence": [
      {
        "filename": "authorization-letter.pdf",
        "mime_type": "application/pdf",
        "download_url": "https://api.openai.com/files/file_id/content",
        "file_id": "file_xyz"
      }
    ]
  }
}
```

### Update a member-linked reference part label

```json
{ "tool": "member-get-write-schema", "arguments": { "resource_key": "references", "record_key": "019d5cb5-7de1-7055-a4d3-b57ab007331e" } }
```

```json
{
  "tool": "member-update-record",
  "arguments": {
    "resource_key": "references",
    "record_key": "019d5cb5-7de1-7055-a4d3-b57ab007331e",
    "payload": {
      "title": "Riyadhus Solihin",
      "type": "book",
      "parent_reference_id": "0195f51f-70d6-70e5-bf9d-bc2e04b64f7a",
      "part_type": "jilid",
      "part_number": "2",
      "part_label": "Jilid Kedua"
    },
    "validate_only": true
  }
}
```

### Create a plain GitHub issue

```json
{
  "tool": "member-create-github-issue",
  "arguments": {
    "category": "bug",
    "title": "Member MCP issue reporting edge case",
    "summary": "The member tool should create a plain issue without Copilot assignment.",
    "platform": "chatgpt",
    "client_name": "ChatGPT",
    "client_version": "GPT-5.4",
    "tool_name": "member-create-github-issue",
    "expected_behavior": "The issue is created with user and platform context.",
    "actual_behavior": "The feature is not available yet."
  }
}
```

## Reading rules

- Read-only tools are for discovery and preview.
- Write tools are schema-guided.
- Generic MCP delete tools are intentionally not exposed on admin/member servers.
- Media/file fields use JSON file descriptors (base64 or URL descriptors) only when the write schema advertises them; destructive `clear_*` media flags are rejected.
- If a capability is not listed in the server tool catalog, ChatGPT should not assume it exists.
