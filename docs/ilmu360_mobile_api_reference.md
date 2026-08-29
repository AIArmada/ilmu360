# ilmu360° Mobile API Reference

**Last Updated:** 2026-07-19
**Audience:** Android, iOS application developers, and AI agents
**Public Base Path:** `/api/v1`
**Admin Base Path:** `/api/v1/admin`

This is the current mobile-facing API contract. It reflects the live routes and controllers, including the Action-class refactors and the native-client endpoints for auth tokens, share tracking, native app telemetry, analytics, going, registration, and check-in.

Use this document as the source of truth for mobile and AI agent integrations.

---

## AI Quickstart

If you are building an AI client, use this read order:

1. Fetch `/docs.json` first for the current OpenAPI contract.
2. Choose the correct routing surface: `/api/v1` for public and client behavior, `/api/v1/admin` for admin-only reads and writes.
3. Call `GET /manifest` for public workflow discovery or `GET /admin/manifest` for admin resource discovery.
4. Before any write, fetch the exact contract first: `GET /forms/*` for public flows, or `GET /admin/{resourceKey}/schema` for admin writes.
5. Use the admin record `route_key` returned by admin collection or detail payloads for record-specific schema and mutation paths.
6. Send raw timestamp fields in UTC. For date-only filters, send the user's local calendar date together with timezone context so the server can convert it to UTC boundaries.
7. For any file field, read `accepted_mime_types`, `max_file_size_kb`, and `max_files` from the form/schema response before uploading. For event media, also honor `required_aspect_ratio`: `cover` is `16:9` and `poster` is `3:4`.
8. Treat `error.code` as the machine-readable failure classifier and `meta.request_id` as the trace identifier for retries and support.

If you are evaluating the MCP connector rather than the raw HTTP admin API, switch to `docs/ilmu360_mcp_guide.md`. The MCP server is intentionally sanitized and uses its own write-schema surface; when it advertises media/file fields, clients send JSON base64 file descriptors instead of multipart files.

Event image generation over MCP is target-specific (not ratio-selectable). The workflow uses 3 steps: call the image prompt, generate with ChatGPT, then upload with the upload tool:

- `*-upload-event-cover-image` (preceded by `*-event-cover-image-prompt`) writes the Event `cover` collection at `16:9`.
- `*-upload-event-poster-image` (preceded by `*-event-poster-image-prompt`) writes the Event `poster` collection at `3:4`.

---

## Quick-reference: Routing Surfaces

This API has **two distinct routing surfaces**. Understanding the difference is critical before making any request.

| Surface | Base path | Auth | Audience | Record scope |
|---|---|---|---|---|
| **Public / Client** | `/api/v1` | Optional or Sanctum bearer | Mobile apps, public consumers, AI readers | Resource-specific public visibility contracts |
| **Admin** | `/api/v1/admin` | Sanctum bearer + Filament admin-panel access | Admin apps, operators, AI writers | All records, regardless of lifecycle status |

Key routing rules:

- **Public query routes** (`/api/v1/persons`, `/api/v1/institutions`, etc.) apply each resource's public visibility contract and never return drafts or rejected records. References specifically require a non-null `published_at` and `status` of `verified` or `pending`.
- **Admin routes** (`/api/v1/admin/persons`, etc.) use Filament's own Eloquent query, which includes all records regardless of active or status state. The same `search=...` parameter on both surfaces therefore returns different result sets.
- **Admin mutation routes** (POST / PUT) use the resource key and the record's admin **route_key** for record-specific paths. The format is `/api/v1/admin/{resourceKey}/{recordKey}`. Use the `route_key` returned by the admin collection or detail payloads.
- Do not send public contribution payloads to `/api/v1/admin`, and do not expect admin schemas from `/api/v1/forms/...`.

Speaker-specific discovery tip:

- Use `filter[search]` when you want to search event titles and descriptions.
- Use `filter[speaker]` when you want events for a specific speaker. Pass the speaker's UUID, not the name or slug.
- If you only know the speaker's name, first search the speaker directory:

```http
GET /api/v1/persons?search=Norhafizah
```

Then use the `id` from that response with the events filter:

```http
GET /api/v1/events?filter[speaker]=019d5cb5-7de1-7055-a4d3-b57ab007331e&filter[starts_after]=2026-04-18
```

- For a person's event history, open `GET /api/v1/persons/{personKey}` and read the `upcoming_events` and `past_events` arrays.

---

## Timezone Semantics

**Raw API timestamp fields are stored and returned in UTC (ISO 8601 with `Z` suffix).**

| Context | Behavior |
|---|---|
| Raw API timestamps (`created_at`, `updated_at`, `starts_at`, `ends_at`, etc.) | Always UTC (`...Z`) |
| Viewer-facing helper fields (`timing_display`, `end_time_display`) | Localized only when the request provides timezone context; otherwise they fall back to UTC |
| Request timezone context | Authenticated user preference, `X-Timezone`, `user_timezone`, cookie, or session |
| Date-only inputs (`event_date`, `filter[starts_after]`, `filter[starts_before]`, etc.) | Interpreted in the resolved request timezone, then converted to UTC day boundaries for storage or querying |
| Application/browser defaults | The web client commonly supplies `Asia/Kuala_Lumpur`, but generic API consumers should not assume that default |

Date-only event filters such as filter[starts_after], filter[starts_before], and filter[starts_on_local_date] are interpreted in the resolved request timezone.

## Enum Value Semantics

Enum fields in API filters, form payloads, admin write payloads, and MCP arguments use the enum backing value, not the display label.

Examples:

- Send `event_category_ids = ["kuliah_ceramah"]`, not `["Kuliah / Ceramah"]`
- Send `age_group = ["all_ages"]`, not `["Semua Peringkat Umur"]`
- Send `timing_mode = "prayer_relative"`, not `"Prayer Time"`
- Send `prayer_reference = "maghrib"` and `prayer_offset = "immediately"`, not localized prayer labels

Labels may change for translation or product wording. Backing values are the stable API contract.

**Implications for API consumers:**

- When you receive `starts_at: "2026-04-12T00:00:00Z"`, that is midnight UTC, which is **08:00 MYT** on the same calendar date.
- Helper fields such as `timing_display` and `end_time_display` are localized only when the request provides timezone context. A bare API request with no `X-Timezone`, `user_timezone`, cookie, session, or authenticated user preference will see those helper fields in UTC.
- `filter[starts_after]`, `filter[starts_before]`, `filter[ends_after]`, and `filter[ends_before]` are date-boundary filters. Send date-only values in the user's local calendar together with timezone context, and the API will convert them to UTC boundaries internally.
- To fetch events on MYT April 12 ("today" in Malaysia), send `X-Timezone: Asia/Kuala_Lumpur` with `filter[starts_after]=2026-04-12&filter[starts_before]=2026-04-12`. If you omit timezone context, the same filter values are interpreted in UTC instead.
- The app config stores `timezone = UTC` for the server. The web application often supplies `Asia/Kuala_Lumpur` automatically, but standalone API consumers must send timezone context explicitly when they need localized helper fields or local-date filtering.
- Push notification payloads embed a `timezone` field (e.g., `"Asia/Kuala_Lumpur"`) so the server can localize scheduled reminders.

---

## Search Visibility Difference

The `search` parameter on public and admin surfaces queries different record scopes.

| Surface | Endpoint | Records returned |
|---|---|---|
| Public | `GET /api/v1/persons?search=...` | Resource-specific public visibility scope |
| Public | `GET /api/v1/institutions?search=...` | Resource-specific public visibility scope |
| Public | `GET /api/v1/references?search=...` | `published_at IS NOT NULL` AND `status IN ('verified', 'pending')` |
| Admin | `GET /api/v1/admin/persons?search=...` | **All** records — active, inactive, pending, rejected |
| Admin | `GET /api/v1/admin/institutions?search=...` | **All** records — active, inactive, pending, rejected |

This is intentional. The admin surface mirrors Filament's resource query, which does not apply visibility filters. A record that returns zero results on the public surface may appear on the admin surface because it fails that resource's public visibility contract.

For `persons`, `institutions`, and `references`, the admin HTTP API now reuses the same specialized search services that also back the public directory endpoints and the admin/member MCP `*list-records` tools. That means decorated person-title matching, institution name-or-alias matching across the `institution_names` table, and reference descriptive-text matching behave similarly across those surfaces; the main difference is which records each surface is allowed to return.

Public event discovery follows the same principle: use `filter[search]` for event title/description text matching and `filter[person]` when you need an exact person UUID match.

**AI agent guidance:** Never assume that a search result from one surface tells you anything definitive about results from the other. For a reference, public visibility requires both a non-null `published_at` and `status` `verified` or `pending`.

---

## Share Payload and Tracking

Mobile and native clients can now use the same share-tracking contract as the web share modal through the public API surface.

### Endpoints

| Method | Path | Auth | Purpose |
|---|---|---|---|
| `GET` | `/share/payload` | Optional bearer | Builds a canonical share payload for a public ilmu360° URL |
| `POST` | `/share/track` | Optional bearer | Records that the client actually invoked a share action |
| `GET` | `/share/analytics` | Bearer required | Returns the authenticated share dashboard and paginated link library |
| `GET` | `/share/analytics/links/{link}` | Bearer required | Returns analytics detail for one tracked share link |

### `GET /share/payload`

Query parameters:

- `url` required internal ilmu360° URL to share.
- `text` required share text.
- `title` optional fallback title.
- `origin` optional share origin. Current advertised values are `web`, `iosapp`, `android`, and `macapp`; future client identifiers are accepted and normalized to lowercase.

Response highlights:

- `url`: canonical tracked share URL. Authenticated callers receive personalized tracking; anonymous callers receive reusable guest-scoped tracking when request context is available.
- `platform_links`: provider intent links for channels such as WhatsApp, Telegram, Threads, Facebook, X, Instagram, TikTok, and email.
- `channel_urls`: tracked destination URLs per channel, including `copy_link` and `native_share`.
- `native_share`: ready-to-use `{ title, text, url, message }` payload for iOS, Android, and macOS share sheets.
- `tracking_token`: returned with tracked payloads. Authenticated and guest API clients can send it back to `/share/track` after the client actually performs the share action.

Example:

```http
GET /api/v1/share/payload?url=https://ilmu360.test/events/my-event&text=Join%20this%20majlis&title=Weekly%20Kuliah&origin=iosapp
Authorization: Bearer <access_token>
```

### `POST /share/track`

Request body:

```json
{
  "provider": "copy_link",
  "tracking_token": "abcd1234wxyz6789"
}
```

Supported `provider` values include all social channels plus `copy_link` and `native_share`.

Recommended client flow:

1. Call `GET /share/payload` with the public URL, share text, and the current client `origin`.
2. Use `platform_links` for provider intents, `channel_urls.copy_link` for clipboard copy, or `native_share` for system share sheets.
3. After the share action is actually triggered, authenticated API clients call `POST /share/track` with the chosen channel and returned `tracking_token`.

Guest/native clients can use the same `POST /share/track` endpoint without a bearer token when they are sharing from an anonymous session.

### `GET /share/analytics`

Authenticated share dashboard endpoint for iOS and Android clients.

Query parameters:

- `type` optional shared subject filter. Supported values are `all`, `event`, `institution`, `speaker`, `series`, `reference`, `search`, and `page`.
- `sort` optional link sort mode. Supported values are `recent`, `visits`, `signups`, `registrations`, `checkins`, and `submissions`.
- `status` optional link activity filter. Supported values are `all`, `active`, and `inactive`.
- `outcome` optional outcome filter. Supported values mirror the dashboard outcome buckets such as `signup`, `event_registration`, `event_checkin`, `event_submission`, `event_save`, `event_going`, `institution_follow`, `speaker_follow`, `series_follow`, `reference_follow`, and `saved_search_created`.
- `page` pagination page number for the link library.
- `per_page` pagination page size for the link library.

Response highlights:

- `summary`: aggregate outbound shares, visits, unique visitors, and conversion totals for the authenticated user.
- `provider_breakdown`: per-channel activity summary for the tracked share channels.
- `subject_summaries` and `top_subjects`: cross-link and per-subject share performance.
- `top_links`: the most active tracked links for quick mobile dashboard cards.
- `recent_responses`: the latest recorded share outcomes.
- `links`: paginated filtered link results with pagination metadata.

Example:

```http
GET /api/v1/share/analytics?type=event&sort=visits&status=active&page=1&per_page=12
Authorization: Bearer <access_token>
```

### `GET /share/analytics/links/{link}`

Authenticated detail endpoint for a single tracked share link.

Path parameter:

- `link` tracked share link UUID returned by the analytics dashboard.

Response highlights:

- `link`: the tracked link record with destination URL, subject metadata, and activity counters.
- `summary`: link-specific outbound share, visit, and conversion totals.
- `provider_breakdown`: per-channel activity for the selected link.
- `share_links`: provider share-again URLs for the tracked destination.
- `daily_performance`: 14-day link performance series.
- `outcome_breakdown`: conversion bucket summary for the link.
- `recent_visits` and `recent_outcomes`: latest visit and conversion records.
- `activity_window`: first/last activity timestamps for the link.

Example:

```http
GET /api/v1/share/analytics/links/18c51525-c8ed-4bb5-92df-9d842a7c4441
Authorization: Bearer <access_token>
```

---

## Nearby Institutions

The public institution directory supports both the original coordinate parameters and a nearby alias route for native clients.

- Existing directory route: `GET /institutions?lat=3.139&lng=101.6869&radius_km=15`
- Nearby alias route: `GET /institutions/near?near=3.139,101.6869&radius_km=15`
- `radius_km` is always expressed in kilometers, clamped from `1` to `100`, and defaults to `15`.
- `near` must use `lat,lng` order.

---

## 1. Authentication

Authenticated endpoints use Laravel Sanctum bearer tokens.

Critical authorization rule:

- Bearer tokens identify the current user. They do not grant extra permissions beyond the user account behind the token.
- Public and admin authorization is recalculated from the live user state on every request.
- If you add or remove global roles, scoped roles, or direct permissions from a user, existing bearer tokens immediately reflect that change.
- Do not rely on Sanctum token abilities such as `tokenCan()` to predict whether a request will be allowed. The application policy and role system is the source of truth.

Required header:

```http
Authorization: Bearer <access_token>
Accept: application/json
```

### Token endpoints

| Method | Path | Purpose |
|---|---|---|
| `POST` | `/auth/register` | Create a user account and issue a bearer token |
| `POST` | `/auth/login` | Issue a bearer token for an existing user |
| `POST` | `/auth/social/google` | Exchange a Google access token for a bearer token |
| `POST` | `/auth/logout` | Revoke the current bearer token |
| `GET` | `/user` | Return the authenticated user |
| `DELETE` | `/user` | Delete the authenticated user account, revoke its tokens, and retain an admin-restorable snapshot during the grace period |

Notes:

- The `user` payload includes a `roles` array with the authenticated user's global role names.

### `DELETE /user`

Deletes the current authenticated account, including the bearer token(s) and the account data handled by the backend cleanup hooks.

The API revokes the user's transient credentials immediately, but the backend also keeps a sanitized deleted-account snapshot so an admin can restore the account during the grace-period restore flow.

Use this endpoint from the mobile app's account/profile screen when the user chooses to remove their account. Client copy should not promise irreversible self-service deletion because recovery is now an admin-led grace-period operation.

### `POST /auth/register`

Request body:

```json
{
  "name": "Mobile User",
  "email": "mobile@example.com",
  "password": "password",
  "password_confirmation": "password",
  "device_name": "iPhone 15 Pro"
}
```

Notes:

- `email` or `phone` is required.
- `device_name` is required and is used as the Sanctum token name.
- Response includes `access_token`, `token_type`, and `user`.

### `POST /auth/login`

Request body:

```json
{
  "login": "mobile@example.com",
  "password": "password",
  "device_name": "Pixel 9"
}
```

Notes:

- `login` accepts either email or phone.
- Response includes `access_token`, `token_type`, and `user`.

### `POST /auth/social/google`

Request body:

```json
{
  "access_token": "google-oauth-access-token",
  "device_name": "Pixel 9"
}
```

Notes:

- `access_token` must be a Google OAuth access token obtained by the client.
- `device_name` is required and is used as the Sanctum token name.
- Response includes `access_token`, `token_type`, and `user`.

Use this endpoint when the client has already completed Google sign-in and only needs to exchange the Google token for an ilmu360° API session.

Public vs admin routing:

- Public/mobile flows live under `/api/v1/...`.
- Admin-panel parity flows live under `/api/v1/admin/...`.
- Do not send public contribution payloads to `/api/v1/admin`, and do not expect admin schemas from `/api/v1/forms/...`.
- See the **Quick-reference: Routing Surfaces** section at the top of this document for a full comparison of the two surfaces.

---

## 2. Response Conventions

Most endpoints return:

```json
{
  "data": {},
  "meta": {
    "request_id": "uuid"
  }
}
```

Mutation endpoints may also include a top-level `message`.

Application and domain errors return a stable top-level `error` envelope:

```json
{
  "message": "The requested resource could not be found.",
  "error": {
    "code": "not_found",
    "message": "The requested resource could not be found."
  },
  "meta": {
    "request_id": "uuid"
  }
}
```

Paginated list endpoints generally return:

```json
{
  "data": [],
  "meta": {
    "pagination": {
      "page": 1,
      "per_page": 20,
      "total": 120
    }
  }
}
```

`meta.pagination` is the canonical pagination bag for list endpoints.

Authenticated engagement lists `GET /me/events/saved` and `GET /me/events/going` intentionally use a simpler pagination bag optimized for infinite mobile lists:

```json
{
  "meta": {
    "pagination": {
      "page": 1,
      "per_page": 20,
      "has_more": true,
      "next_page": 2
    }
  }
}
```

Those two endpoints do **not** return `meta.pagination.total`.

List endpoints clamp `per_page` to server-supported maxima. Public `/events`, `/institutions`, and `/persons` currently cap at 50. Most authenticated collections and admin resource listings currently cap at 100.

### Sparse list fields

Public list endpoints now support an optional top-level `fields` query parameter for lighter mobile pagination payloads.

- Supported endpoints: `GET /events`, `GET /institutions`, `GET /institutions/near`, and `GET /persons`.
- Format: comma-separated top-level field names, for example `fields=id,name,location`.
- Unsupported field names return HTTP `422` with a `fields` validation error.
- When omitted, each endpoint returns its full default list payload.

Examples:

```http
GET /api/v1/institutions?fields=id,name,location
GET /api/v1/events?fields=id,title,starts_at,card_image_url
GET /api/v1/persons?fields=id,name,status,is_active,avatar_url
```

Validation failures use the same `error` envelope with a field-level bag preserved for direct form binding:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "email": [
      "The email field is required."
    ]
  },
  "error": {
    "code": "validation_error",
    "message": "The given data was invalid.",
    "details": {
      "fields": {
        "email": [
          "The email field is required."
        ]
      }
    }
  },
  "meta": {
    "request_id": "uuid"
  }
}
```

---

## 2A. Client Parity API

For native clients and AI agents that need to mirror the current web client behavior rather than the lower-level REST resources, use the high-level client surface under `/api/v1`.

Interactive API docs are available on the API host under `/docs`, with the generated OpenAPI JSON published at `/docs.json`.

### Discovery and contracts

| Method | Path | Auth | Purpose |
|---|---|---|---|
| `GET` | `/manifest` | Optional | Top-level flow manifest and endpoint discovery |
| `GET` | `/forms/mobile-telemetry` | Optional | Native mobile telemetry contract for real iOS/iPadOS/Android apps |
| `GET` | `/forms/submit-event` | Optional | Submit-event schema, defaults, and validation metadata |
| `GET` | `/forms/contributions/institutions` | Optional | Institution contribution contract |
| `GET` | `/forms/contributions/persons` | Optional | Person contribution contract |
| `GET` | `/forms/report` | Required | Report form contract, including optional evidence upload metadata |
| `GET` | `/forms/github-issue-report` | Required | GitHub issue-report contract for MCP/API bugs, docs mismatches, and proposals |
| `GET` | `/forms/account-settings` | Required | Account-settings contract |
| `GET` | `/forms/advanced-events` | Required | Advanced parent-program builder contract |
| `GET` | `/forms/institution-workspace` | Required | Institution workspace contract |
| `GET` | `/forms/membership-claims/{subjectType}` | Required | Membership-claim contract for the selected subject type |
| `GET` | `/forms/contributions/{subjectType}/{subject}/suggest` | Required | Suggest-update context and editable state |

### Public query endpoints

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/search` | Unified search payload for events, persons, and institutions |
| `GET` | `/share/payload` | Build a share payload for web, iOS, Android, and native share sheets |
| `GET` | `/institutions` | Public institution listing filters |
| `GET` | `/institutions/near` | Nearby institution alias using `near=lat,lng` |
| `GET` | `/institutions/{institutionKey}` | Public institution detail by slug or UUID |
| `GET` | `/persons` | Public person listing filters; person directory items include `status` and `is_active` in the default payload |
| `GET` | `/persons/{personKey}` | Public person detail by slug or UUID |
| `GET` | `/references` | Public reference listing filters; default directory pages show root/standalone references, while searched child parts can also appear. Reference directory items include `display_title`, `parent_reference_id`, `part_type`, `part_number`, `part_label`, `is_part`, `author`, `type`, `publisher`, `publication_year`, `status`, `events_count`, `front_cover_url`, and `is_following` in the default payload |
| `GET` | `/inspirations/random` | Random active inspiration payload with category and media metadata |
| `GET` | `/venues/{venueKey}` | Public venue detail by slug or UUID |
| `GET` | `/references/{referenceKey}` | Public reference detail by slug or UUID; child-part detail accepts `include_all_parts=true` to aggregate the whole book family |
| `GET` | `/series/{series}` | Public series detail |

Notes:

- **Visibility rule:** public resources use their own visibility scopes. References returned by `/references` and `/references/{referenceKey}` must have `published_at IS NOT NULL` and `status IN ('verified', 'pending')`; an approved/published timestamp alone does not make another moderation status public. To access all records including drafts, use the admin surface.
- Public person directory list items expose `status` and `is_active` alongside the existing summary fields. Keep client logic aligned with those canonical fields instead of inferring alternate aliases.
- Public reference directory list items expose `display_title`, `parent_reference_id`, `part_type`, `part_number`, `part_label`, `is_part`, `author`, `type`, `publisher`, `publication_year`, `status`, `events_count`, `front_cover_url`, and `is_following` by default. `display_title` is the safest client-facing label because child parts can render as values like `Riyadhus Solihin — Jilid 2`.
- Default `/references` pagination intentionally hides child parts unless the client is actively searching. Search queries can return both root books and matching child parts.
- `GET /references/{referenceKey}` now returns the same part metadata in the `reference` payload. Root books aggregate events from the whole family by default. Child parts return only exact-part events by default, but clients can opt into whole-book aggregation with `include_all_parts=true`.
- The public event index supports `filter[reference_ids][]=<reference-uuid>` so native clients can paginate all public events for a given reference without relying on the capped preview lists from `GET /references/{referenceKey}`. When the supplied UUID is a root book reference, the filter automatically expands to child parts; when it is already a child part reference, filtering remains exact to that part.
- When clients need an exact timeline split around the current moment (for example, separating `Majlis Akan Datang` from `Majlis Terdahulu` on reference event screens), the public event index also accepts ISO 8601 timestamp filters through `filter[starts_at_after]` and `filter[starts_at_before]`.
- These detail payloads now mirror the web client media collections and public-contact visibility rules.
- Institution payloads expose `public_image_url` as the canonical cover -> logo -> placeholder image. Use that for cards and previews. Use `logo_url` or `cover_url` only when you need those explicit assets.
- Public event detail payloads now serialize linked references with a normalized image contract for mobile cards and previews: `media.front_cover_url`, `media.back_cover_url`, plus top-level aliases `front_cover_url`, `back_cover_url`, `cover_url`, and `thumb_url`.
- Unified search accepts `search` as the canonical query parameter and `q` as a compatibility alias, for example `GET /api/v1/search?search=Kuliah` or `GET /api/v1/search?q=Kuliah`.
- Institution directory requests can filter by the device's current location: `GET /api/v1/institutions?lat=3.1390&lng=101.6869&radius_km=15` or `GET /api/v1/institutions/near?near=3.1390,101.6869&radius_km=15`. The `/institutions/near` alias requires either `near=lat,lng` or both `lat` and `lng`; calling it without coordinates intentionally returns a validation error. `radius_km` defaults to 15, is clamped between 1 and 100, and is always expressed in kilometers. Nearby results are sorted nearest-first and include `distance_km`; non-nearby requests return `distance_km: null`.
- Followed directory records are listed through the same directory endpoints with `following=true`, for example `GET /api/v1/persons?following=true`, `GET /api/v1/institutions?following=true`, and `GET /api/v1/references?following=true`. There are no plural follow-list routes such as `/follows/persons`; the `/follows/{type}/{subject}` endpoints are only for a single record's follow state and follow/unfollow mutations.
- Public `/events`, `/institutions`, `/institutions/near`, `/persons`, and `/references` list endpoints accept `fields=` for sparse top-level responses when mobile clients need smaller pagination payloads.
- The inspiration endpoint returns `title`, plain-text `content`, `content_html`, `preview_text`, `source`, category metadata, and both thumb/full media URLs when an image exists.
- `personKey`, `venueKey`, `institutionKey`, and `referenceKey` intentionally bypass the app-wide public-slug route binders so the API can safely resolve slug or UUID itself.
- `GET /catalogs/spaces` returns only global spaces when `institution_id` is omitted. When `institution_id` is provided, the response includes those global spaces plus spaces linked to the selected institution.

### Submission and authenticated workflow endpoints

| Method | Path | Purpose |
|---|---|---|
| `POST` | `/mobile/telemetry/events` | Record batched UI telemetry from real iOS, iPadOS, or Android app sessions |
| `POST` | `/submit-event` | Submit the public/authenticated event form, including cover/poster/gallery uploads |
| `POST` | `/share/track` | Record a guest or authenticated outbound share action for analytics |
| `GET` | `/share/analytics` | Fetch the authenticated Dawah impact dashboard for mobile clients |
| `GET` | `/share/analytics/links/{link}` | Fetch detailed performance for one tracked share link |
| `GET` | `/account-settings` | Return current profile settings |
| `PUT` | `/account-settings` | Update current profile settings |
| `GET` | `/contributions` | Contribution inbox for the current user |
| `POST` | `/contributions/institutions` | Submit a new institution contribution |
| `POST` | `/contributions/persons` | Submit a new person contribution |
| `POST` | `/contributions/{subjectType}/{subject}/suggest` | Suggest an update or apply a direct edit when authorized |
| `POST` | `/contributions/{requestId}/approve` | Approve a reviewable contribution request |
| `POST` | `/contributions/{requestId}/reject` | Reject a reviewable contribution request |
| `POST` | `/contributions/{requestId}/cancel` | Cancel the current user’s pending contribution request |
| `GET` | `/membership-claims` | List the current user’s membership claims |
| `POST` | `/membership-claims/{subjectType}/{subject}` | Submit a membership claim with evidence uploads |
| `DELETE` | `/membership-claims/{claimId}` | Cancel the current user’s pending membership claim |
| `POST` | `/reports` | Submit a report with optional evidence uploads |
| `POST` | `/github-issues` | Create a GitHub issue in the ilmu360 repository for integration, MCP, or API feedback |
| `POST` | `/advanced-events` | Create an advanced parent program submission |
| `GET` | `/follows/{type}/{subject}` | Return follow state for the current user |
| `POST` | `/follows/{type}/{subject}` | Follow a public institution, speaker, reference, or series |

Event media upload contract:
- `cover` is the website/mobile-app image and must be 16:9.
- `poster` is the external-distribution image and must be 3:4 portrait.
- `gallery` accepts normal image uploads without a fixed aspect-ratio requirement.
| `DELETE` | `/follows/{type}/{subject}` | Unfollow a record |
| `GET` | `/institution-workspace` | Institution dashboard payload for events, members, and role options |
| `POST` | `/institution-workspace/{institutionId}/members` | Add an institution member |
| `PUT` | `/institution-workspace/{institutionId}/members/{memberId}` | Change an institution member role |
| `DELETE` | `/institution-workspace/{institutionId}/members/{memberId}` | Remove an institution member |

Authorization note:

- These endpoints intentionally mirror the current web client policy and role checks.
- `can_direct_edit`, `direct_edit_media_fields`, institution workspace access, and approval abilities all come from live policy and role evaluation for the authenticated user, not from bearer-token abilities.
- Institution workspace permissions currently follow the existing app model where scoped member roles are assigned per subject type, not per individual institution. The API matches that behavior exactly.
- `GET /institution-workspace` auto-selects the first accessible institution when `institution_id` is omitted and always returns a non-null `selected_institution` block together with `events_pagination` and `members_pagination` metadata.
- `POST /github-issues` uses the same authenticated user identity as the rest of the client API. Non-admin users create a plain issue. Application admins create the issue and auto-assign Copilot, with the Copilot model resolved from server config and fallbacks.

### Native mobile telemetry

Use this dedicated endpoint when the user is inside the real iOS, iPadOS, or Android app and you need to record UI behavior such as screen views, button taps, drawer opens, tab changes, or deep-link entrypoints.

**Do not use this endpoint for mobile web browsing.** Web page views and browser-side UI interactions should keep using the web tracker that posts to the shared browser telemetry surface.

| Method | Path | Auth | Purpose |
|---|---|---|---|
| `GET` | `/forms/mobile-telemetry` | Optional bearer | Returns the native telemetry contract, helper field metadata, and required headers |
| `POST` | `/mobile/telemetry/events` | Optional bearer | Accepts batched telemetry events from real native app sessions |

Required request header:

```http
X-Ilmu360-Client-Origin: iosapp | ipadosapp | androidapp
```

Optional request headers:

- `X-Ilmu360-Client-Name`
- `X-Ilmu360-Client-Version`
- `X-Ilmu360-Client-Build`

Anonymous/native identity rules:

- Bearer auth is optional.
- If the request is authenticated, the API attaches the current user automatically.
- If the request is anonymous, send at least one of:
  - `anonymous_id`
  - `session_identifier`

Recommended client behavior:

1. Use a stable install/device identifier for `anonymous_id`.
2. Use an app-session identifier for `session_identifier`.
3. Batch up to 50 events per request.
4. Send `screen_name`, `component`, and `action` inside each event when available.
5. Keep using the web tracker for browser sessions, even on phones or tablets.

Canonical request shape:

```json
{
  "anonymous_id": "ios-installation-123",
  "session_identifier": "ios-session-2026-04-22T10:00:00Z",
  "session_started_at": "2026-04-22T10:00:00Z",
  "events": [
    {
      "event_name": "screen.viewed",
      "event_category": "navigation",
      "occurred_at": "2026-04-22T10:00:05Z",
      "path": "/home",
      "screen_name": "home",
      "properties": {
        "entrypoint": "push_notification"
      }
    },
    {
      "event_name": "ui.clicked",
      "event_category": "engagement",
      "occurred_at": "2026-04-22T10:00:18Z",
      "path": "/events/weekly-kuliah",
      "screen_name": "event_detail",
      "component": "register_button",
      "action": "tap"
    }
  ]
}
```

Response shape:

```json
{
  "message": "Mobile telemetry accepted.",
  "data": {
    "received_events": 2,
    "recorded_events": 2,
    "dropped_events": 0,
    "authenticated": false,
    "client": {
      "client_origin": "ios",
      "client_family": "mobile",
      "client_transport": "api",
      "client_name": "ilmu360° iOS",
      "client_version": "1.2.3",
      "client_build": "456"
    }
  },
  "meta": {
    "request_id": "uuid"
  }
}
```

Notes:

- The API returns `202 Accepted` when the batch is accepted for processing.
- `recorded_events` may be lower than `received_events` if the analytics backend drops one or more events after acceptance.
- Client metadata is normalized into analytics properties as `client_origin`, `client_family`, `client_transport`, `client_name`, `client_version`, and `client_build`.
- This route is intentionally app-specific so analytics can distinguish real native usage from browser traffic.

### GitHub issue reporting workflow

Use this workflow when the client needs to report:

- an ilmu360° MCP bug or confusing tool behavior
- an API contract mismatch
- a documentation mismatch or missing example
- a proposal, feature request, or parameter-change request

Recommended flow:

1. Fetch `GET /api/v1/forms/github-issue-report` first.
2. Read the returned `fields`, `defaults`, and allowed `category` values.
3. Submit `POST /api/v1/github-issues` with the current client/platform context.

Payload guidance:

- `category`, `title`, `summary`, and `platform` are the minimum required inputs.
- `client_name`, `client_version`, `current_endpoint`, `tool_name`, `steps_to_reproduce`, `expected_behavior`, `actual_behavior`, `proposal`, and `additional_context` are optional but strongly recommended for useful triage.
- The server adds authenticated reporter context automatically so maintainers can trace the issue back to the ilmu360° user account without exposing raw credentials.

Assignment behavior:

- Non-admin callers create a plain GitHub issue.
- Admin callers create the issue and automatically assign Copilot unless `GITHUB_ISSUE_REPORTING_ADMIN_COPILOT_ASSIGNMENT_ENABLED=false` is set on the server.
- Copilot model selection is config-driven on the server and may fall back across multiple configured values before using `Auto`.

### Public contribution rules you must follow

#### `GET /forms/contributions/institutions`

- Use this as the authoritative create contract for public institution submissions.
- Institution create requires an explicit address country.
- Send `address.country_id`.
- Public institution create accepts media fields `cover` and `gallery`.
- If the same normalized institution name plus the same `state_id`, `admin_area_1_id` (district), and `admin_area_2_id` (subdistrict) already exists, create will fail with HTTP `422` on `name`.

#### `GET /forms/contributions/persons`

- Use this as the authoritative create contract for public speaker submissions.
- Speaker create requires an explicit country plus region selectors. Send:
  - `address.country_id`
  - `address.state_id`
  - `address.admin_area_1_id` (district)
  - `address.admin_area_2_id` (subdistrict)
- Do not send:
  - `address.line1`
  - `address.line2`
  - `address.postcode`
  - `address.lat`
  - `address.lng`
  - `address.google_maps_url`
  - `address.google_place_id`
  - `address.waze_url`
- Public speaker create accepts media fields `avatar`, `cover`, and `gallery`.
- If the same normalized speaker identity already exists in the same country, create will fail with HTTP `422` on `name`. The duplicate check compares normalized `name`, `gender`, `honorific`, `pre_nominal`, effective `post_nominal`, and country values.

#### `GET /forms/contributions/{subjectType}/{subject}/suggest`

- Call this before every update screen.
- `can_direct_edit = true` means the caller can apply changes immediately.
- `can_direct_edit = false` means the same endpoint will create a review request instead.
- `direct_edit_media_fields` is the only allowed file-upload contract for direct edits.
- Current direct-edit media sets are:
  - Events: `['cover', 'poster', 'gallery']`
  - Institutions: `['cover', 'gallery']`
  - Speakers: `['avatar', 'cover', 'gallery']`
  - References and non-maintainers: `[]`

Public upload metadata:

- Public event submissions accept `cover`, `poster`, and `gallery`.
- Event `cover` uploads are validated as `16:9`.
- Event `poster` uploads are validated as `3:4`.
- Membership claims accept `evidence` files.
- Reports accept `evidence` files.
- Image upload fields accept `image/jpeg`, `image/png`, and `image/webp`.
- Evidence fields accept `image/jpeg`, `image/png`, `image/webp`, and `application/pdf`.
- `max_file_size_kb` comes from `config('media-library.max_file_size')` and defaults to 10 MB.

#### `POST /contributions/{subjectType}/{subject}/suggest`

- This endpoint is sparse-update only. Send only changed top-level keys plus optional `proposer_note`.
- File uploads are allowed only when `can_direct_edit = true` and only for field names listed in `direct_edit_media_fields`.
- Unsupported upload fields return HTTP `422` with a `files` validation error.
- Direct-edit response mode:

```json
{
  "data": {
    "mode": "direct_edit"
  }
}
```

- Review-request response mode:

```json
{
  "data": {
    "mode": "review"
  }
}
```

---

## 2B. Admin API Foundation

> **Read this section as the raw HTTP admin contract, not the MCP contract.**
> The Filament admin panel, the HTTP admin API, and the MCP admin server share the same domain model, but they are not identical surfaces.
> In particular, the MCP guide documents the sanitized `/mcp/admin` behavior, while this document describes `/api/v1/admin`.

For authenticated users who can access the Filament admin panel, an admin surface is available under `/api/v1/admin`. This follows Filament resource discovery and authorization at the resource/record level, and is intended for admin apps and agents that need to browse and, for selected resources, mutate what the admin panel can currently expose.

Current scope:

- Resource manifest and capability discovery
- Per-resource metadata
- Resource-specific write schema discovery for supported resources
- Generic record listing with search and resource-specific filters
- Generic record detail with per-record abilities
- Named relation traversal for related admin records
- Shared create/update write support for `persons`, `institutions`, `venues`, `references`, `events`, and `subdistricts`
- Optional `validate_only=true` preview mode for admin create/update requests
- `current_media` is metadata only; it is useful for pre-populating edit forms, but it does not expose signed or temporary file URLs
- File fields in schema responses include `accepted_mime_types`, `max_file_size_kb`, and `max_files` where applicable
- User record payloads intentionally redact sensitive fields such as `email`, `email_verified_at`, `phone`, `phone_verified_at`, `daily_prayer_institution_id`, and `friday_prayer_institution_id`

Current limitation:

- This is not yet full create-edit-delete parity for every complex Filament workflow. Write support currently exists only where the Filament save path has been extracted into reusable actions.
- Do not infer destructive behavior from HTTP method alone. The write endpoints are schema-guided and the underlying save actions merge optional omitted fields where that resource is designed to preserve them.

### Admin endpoints

| Method | Path | Auth | Purpose |
|---|---|---|---|
| `GET` | `/admin/manifest` | Required Filament admin-panel access | List admin resources visible through the API |
| `GET` | `/admin/{resourceKey}/meta` | Required Filament admin-panel access | Return metadata, pages, relations, abilities, and write-support flags for one admin resource |
| `GET` | `/admin/{resourceKey}/schema?operation=create` | Required Filament admin-panel access | Return the create contract for supported write resources |
| `GET` | `/admin/{resourceKey}/schema?operation=update&recordKey={recordKey}` | Required Filament admin-panel access | Return the update contract plus current defaults/media for one supported record |
| `GET` | `/admin/{resourceKey}` | Required Filament admin-panel access | Paginated record listing for the selected resource. Supports search, date filters, and resource-specific `filter[...]` query parameters when available |
| `GET` | `/admin/{resourceKey}/{recordKey}/relations/{relation}` | Required Filament admin-panel access | Paginated listing for a named relation on one admin record |
| `POST` | `/admin/{resourceKey}` | Required Filament admin-panel access + resource create policy | Create a record for supported write resources, or add `?validate_only=1` to preview the normalized payload and warnings without persisting. Add `&apply_defaults=1` during previews to receive a server-side autofill candidate payload in validation feedback. Validate-only failures also return remediation details for one-retry recovery loops |
| `POST` | `/admin/{resourceKey}/batch` | Required Filament admin-panel access + resource create policy | Create up to 100 records in a single request. Request body must contain an `items` array; each item may include an optional `external_row_id` for idempotency tracking and a `payload` matching the create schema. Add `?validate_only=1` to preview all items without persisting. Response includes a `data.results` array with per-row status (`created`, `validation_failed`, `error`, or `preview`) and a `data.summary` block |
| `GET` | `/admin/{resourceKey}/{recordKey}` | Required Filament admin-panel access | Generic record detail and per-record abilities |
| `PUT` | `/admin/{resourceKey}/{recordKey}` | Required Filament admin-panel access + record update policy | Update a record for supported write resources, or add `?validate_only=1` to preview the current record snapshot, normalized payload, and warnings without persisting. Add `&apply_defaults=1` during previews to receive a server-side autofill candidate payload in validation feedback. Validate-only failures also return remediation details for one-retry recovery loops |
| `PUT` | `/admin/{resourceKey}/batch` | Required Filament admin-panel access + resource update policy | Update up to 100 records in a single request. Request body must contain an `items` array; each item must include a `record_key` and `payload` matching the update schema, plus an optional `external_row_id`. Add `?validate_only=1` to preview all items without persisting. Response includes a `data.results` array with per-row status (`updated`, `validation_failed`, `not_found`, `error`, or `preview`) and a `data.summary` block |

### Write preview mode

The admin create and update endpoints accept `validate_only=true` as a query parameter. When present, the API validates and normalizes the request, then returns a preview envelope instead of mutating the database.

If you also send `apply_defaults=true`, the server applies schema defaults before validating and returns the candidate autofilled payload inside the validation feedback envelope when the request is still invalid.

Preview responses include:

- `data.preview.validate_only = true`
- `data.preview.normalized_payload`
- `data.preview.warnings` for destructive clear-flags such as `clear_cover`
- `data.preview.current_record` for updates so you can compare the existing state against the previewed payload

Validation errors now include schema-driven `error.details.feedback` hints for both preview and non-preview writes, including:

- `issues[].allowed_values`
- `issues[].suggested`
- `issues[].closest_valid_value`
- `issues[].default`
- `issues[].auto_fill_safe`
- `issues[].required_because`
- `normalized_payload` when `validate_only=true&apply_defaults=true`

Validation failures in validate-only mode return machine-readable remediation details:

- `error.details.fix_plan`
- `error.details.remaining_blockers`
- `error.details.normalized_payload_preview`
- `error.details.can_retry`

Safe defaults are surfaced as `set_field` actions, unresolved enums/catalog choices are surfaced as `choose_one` / `choose_many`, and `can_retry=true` means the client can retry immediately using the normalized preview without another schema round trip.

> **Record-key format:** `{recordKey}` in GET and PUT admin record routes should use the `route_key` field returned by the admin collection or detail endpoints.

> **Record filtering:** event admin collections support `filter[status]`, `filter[visibility]`, `filter[event_format]`, `filter[event_category_ids]`, `filter[timing_mode]`, and `filter[prayer_reference]`. Speaker admin collections support `filter[status]`, `filter[is_active]`, and `filter[has_events]`. Date-aware admin collections also support `starts_after`, `starts_before`, and `starts_on_local_date`.

> **Enum filters:** send enum backing values in filters. For example, use `filter[event_category_ids]=kuliah_ceramah`, `filter[timing_mode]=prayer_relative`, and `filter[prayer_reference]=maghrib`; do not send display labels.

> **Admin date-filter shape:** on `/api/v1/admin/{resourceKey}`, the date arguments are top-level query params, for example `/api/v1/admin/events?starts_on_local_date=2026-04-24&filter[status]=approved` or `/api/v1/admin/events?starts_after=2026-04-24&starts_before=2026-04-30`. Do not nest admin date filters under `filter[...]`.

Authorization note:

- The admin API now follows the same top-level access rule as the Filament admin panel: any authenticated user with application admin-panel access can reach it.
- Bearer token abilities do not elevate a non-admin user into this surface.
- Within that surface, per-resource create/view and per-record update/view/delete abilities are still computed from the underlying Laravel policies, so the payload advertises what the current user can actually do.
- For `persons` and `institutions`, the API write path now reuses the same save actions as the Filament create/edit pages, including address/contact/social sync, media handling, and public-submission toggle rules.
- Slugs for these write-capable resources are treated as auto-managed by the API contract. Clients should not attempt to persist custom slugs through these endpoints.

### Schema endpoint — key behaviors

The `GET /admin/{resourceKey}/schema` endpoint is the authoritative source for what fields are required and allowed for any given mutation.

```
GET /api/v1/admin/persons/schema?operation=create
GET /api/v1/admin/persons/schema?operation=update&recordKey=ahmad-fauzi-my
```

Rules:

- `recordKey` should use the record `route_key` returned by the admin collection or record-detail payload.
- The schema response embeds an `endpoint` field with the exact URL you should POST or PUT to — use that directly.
- The schema response also embeds `defaults` with current field values, and `current_media` with existing media metadata, enabling pre-population of edit forms without exposing signed media URLs.
- File fields include `accepted_mime_types`, `max_file_size_kb`, and `max_files` where applicable. Use `multipart/form-data` for raw HTTP API writes that include files.
- The `method` field tells you whether to use `POST` or `PUT`.
- `conditional_rules` describe fields that become required based on other field values (e.g., `job_title` is required when `is_freelance = true` for persons).

### Always-required fields for admin speaker write operations

Speaker `PUT` (update) is **not** a partial/PATCH operation. The following fields are always required on every create **and** update:

| Field | Type | Notes |
|---|---|---|
| `name` | `string` | Required on both create and update |
| `gender` | `string` | `male` or `female`. Required on both create and update |
| `status` | `string` | `pending`, `verified`, or `rejected`. Required on both create and update |
| `address` | `object` | Must be present on create (empty object `{}` is valid); optional on update, but must be a valid object if sent |

The `bio`, `qualifications`, `honorific`, `pre_nominal`, `post_nominal`, `language_ids`, `contacts`, `social_media`, and media fields are all optional (`nullable` / `sometimes`) on both operations.

To avoid unexpected `422` errors, always fetch the schema first and mirror the `required: true` fields verbatim.

Person-specific update rules:

- `address` is optional on update, but if you send it you must also send `address.country_id`.
- `address = {}` returns HTTP `422` for persons. Omit the `address` key entirely when you intend "no address change".
- Omitted person address region keys preserve the existing visible address values.
- Hidden person address fields (`line1`, `line2`, `postcode`, `lat`, `lng`, `google_maps_url`, `google_place_id`, `waze_url`) remain prohibited on admin writes and are preserved only by omission.
- The array-style speaker fields `honorific`, `pre_nominal`, `post_nominal`, `qualifications`, `language_ids`, `contacts`, and `social_media` all use replacement semantics when present: omit to preserve, send `null` or `[]` to clear where the schema allows it, and resend the full array/list when editing.
- `language_ids` syncs the exact set of selected languages; it is not patchable item-by-item.
- `contacts` and `social_media` recreate rows rather than patching item ids in place. Payload order controls `order_column` when it is omitted.
- For social media platform values, use the canonical enum values from the schema. For Twitter / X, the accepted raw HTTP write value is `twitter`, not `x`.

### Always-required fields for admin institution write operations

Institution `PUT` is schema-guided: the core identity fields below are always required, but nested `address`, `contacts`, and `social_media` rules vary by field type.

| Field | Type | Notes |
|---|---|---|
| `name` | `string` | Required on both create and update |
| `type` | `string` | Required on both create and update |
| `status` | `string` | `pending`, `verified`, `rejected`, or `inactive`. Required on both create and update |

Institution-specific update rules:

- `address` is **optional on update**. If you send it, omitted nested keys preserve the existing institution address values.
- `address.country_id` is **required on create**, but on update it may be omitted when the institution already has an address with a stored country.
- For institutions that already have an address, `address = {}` is effectively a no-op because the existing country is reused during normalization before persistence.
- `nickname` has been replaced by the `names` array. Use `names: [{full_name: "...", name_type: "nickname", ...}]` for alternative names.
- `contacts` and `social_media` are destructive replacement collections on institution writes: omit the field to preserve the existing collection, send `null` or `[]` to clear it, and send the **full modified array** when you want to keep some existing items.
- Submitted `contacts` and `social_media` arrays recreate rows rather than patching items in place, so item ids are not stable across updates.
- When `order_column` is omitted, contact/social ordering follows payload order.

Nested collection item contracts for institutions:

- `contacts[]`
  - `category` + `value` are the paired identity fields.
  - `type` is a valid write field and defaults to `main` when omitted.
  - `is_public` is optional.
  - `order_column` is optional.
  - Use `value` for all categories, including `phone` and `whatsapp`.
- `social_media[]`
  - `platform` is required when the row has identity data.
  - At least one of `username` or `url` must be present.
  - `order_column` is optional.
  - Handle-style platforms (`facebook`, `twitter`, `instagram`, `youtube`, `tiktok`, `telegram`, `whatsapp`, `linkedin`, `threads`) may canonicalize a submitted URL or handle into stored `username`, and the persisted `url` may become `null` after normalization.
  - Use `twitter` as the raw HTTP write value for Twitter / X; do not submit `x`.

### Venue-specific update rules

- Venue `PUT` is sparse. `name`, `type`, and `status` are required on create, but they are not required on update.
- `address` is optional on update. If you send a non-empty address object, omitted nested keys preserve the existing stored address values.
- `address.country_id` is required on create, but on update it may be omitted when the venue already has a stored country.
- `address = {}` is destructive for venues: it deletes the existing stored address. Omit `address` entirely when you intend no address change.
- `facilities` is a replacement set, not a patchable map: omit to preserve, send `null` or `[]` to clear general venue facilities, and send the full enabled facility list when updating. The save layer syncs active codes into `venue_facilities` rows without touching space-scoped facilities.
- `contacts` and `social_media` use destructive replacement semantics exactly like institutions.
- For social media platform values, use `twitter` as the raw HTTP write value for Twitter / X.

### Reference-specific update rules

- Reference `PUT` still requires `title`, `type`, and `status`.
- `author`, `publication_year`, and `publisher` are normalized string scalars: omit to preserve, send `null` to clear, and raw HTTP empty strings also clear because request middleware normalizes them to `null` before persistence.
- `social_media` uses destructive replacement semantics: omit to preserve, send `null` or `[]` to clear, and resend the full collection when editing.
- Handle-style social platforms may canonicalize submitted URLs or handles into stored `username`, with persisted `url` returned as `null` after normalization.
- For Twitter / X, use the canonical write value `twitter`.

### Event-specific update rules

- Event `PUT` is sparse on the raw admin API. Core fields such as `title`, `event_date`, `prayer_time`, `timezone`, `event_format`, `visibility`, `gender`, `age_group`, and `event_category_ids` are required on create, but they may be omitted on update.
- Admin event writes accept `status` values `draft`, `pending`, and `approved`. When omitted on create, the default is `draft`. `approved` sets `published_at`, while `draft` and `pending` clear it.
- Event enum write values must use backing values from the schema. Do not submit display labels for `event_category_ids`, `age_group`, `timing_mode`, `prayer_reference`, or `prayer_offset`.
- Event `cover` uploads are validated as `16:9`, and event `poster` uploads are validated as `3:4` on admin write paths.
- Event media clear flags (`clear_cover`, `clear_poster`, `clear_gallery`) remove existing media when truthy (`true`, `1`, `"1"`, `"true"`). Poster clears are reflected immediately in the update response (`has_poster=false`, `poster_url=null`) and in update schema `current_media.poster`.
- Optional URL scalars like `event_url`, `live_url`, and `recording_url` preserve the current value when omitted and clear to `null` when you send `null` or `""`.
- The relation arrays `languages`, `references`, `series`, `domain_tags`, `discipline_tags`, `source_tags`, and `issue_tags` use server-merged replacement semantics on update: omit to preserve the current set, send `null` or `[]` to clear, and send the full replacement list when changing them.
- `persons` and `other_key_people` also preserve on omission, but any submitted array rebuilds the underlying `key_people` rows. Stable item ids are not preserved, and payload order becomes the new `order_column` sequence (person rows first, then `other_key_people`).
- `organizer_type` accepts the canonical class names plus the raw HTTP aliases `institution` and `speaker`.
- `registration_mode` may remain locked to the current stored value when the event already has registrations.

### Series-specific update rules

- Series `PUT` still requires `title`, `slug`, and `visibility`.
- `description` preserves on omission and clears to `null` when you send `null` or `""`.
- `languages` is a replacement relation: omit to preserve, send `null` or `[]` to clear, and send the full list when changing it.
- `slug` is trimmed before persistence and must remain unique across `series` records.

### Donation-channel-specific update rules

- Donation channel `PUT` still requires `donatable_type`, `donatable_id`, `recipient`, `method`, and `status`.
- `donatable_type` is normalized to the canonical owner morph value. The raw HTTP admin API accepts alias inputs like `institutions`, `persons`, `events`, and the model class names, but stored output is canonicalized.
- `label`, `reference_note`, `bank_code`, `ewallet_handle`, and `ewallet_qr_payload` are trimmed optional scalars: omit to preserve, send `null` or `""` to clear.
- Switching `method` clears unrelated method-specific fields. Example: changing from `bank_account` to `duitnow` clears the bank and ewallet fields before persisting the DuitNow payload.
- `clear_qr=true` is supported on the raw HTTP admin API when you need to remove the stored QR media without uploading a replacement.

### Inspiration-specific update rules

- Inspiration `PUT` still requires `category`, `locale`, `title`, and `content`.
- `content` is normalized as rich text and can accept either a plain string or a rich-text document payload.
- `source` is a normalized optional scalar: omit to preserve, send `null` or `""` to clear.
- `main` is a single-file media collection: omitting it preserves the current media, uploading a new file replaces the collection, and raw HTTP `clear_main=true` removes the stored file without uploading a replacement.

### Space-specific update rules

- Space `PUT` still requires `name` and `slug`.
- `slug` is trimmed before persistence and must remain unique across `spaces` records.
- `capacity` preserves on omission and clears to `null` when you send `null` or `""`; non-null values must still be integers greater than or equal to `1`.
- `institutions` is a replacement relation: omit to preserve, send `null` or `[]` to clear, and send the full institution id list when updating it.

### Report-specific update rules

- Report `PUT` still requires `entity_type`, `entity_id`, `category`, and `status`.
- `category` values are resolved from `entity_type`, so changing the entity type can also change which categories are valid.
- `description` and `resolution_note` are normalized optional scalars: omit to preserve, send `null` or `""` to clear.
- `reporter_id` and `handled_by` are optional user references: omit to preserve, send `null` to clear, and otherwise send a valid user id.
- `evidence` is a replacement file collection on raw HTTP writes: omit or send `null` to preserve, send `[]` to clear, and send the full file array when replacing it. The raw HTTP `clear_evidence=true` flag also clears the collection.

### Tag-specific update rules

- Tag `PUT` still requires `name.ms`, `type`, and `status`.
- `name.en` is optional and falls back to `name.ms` when it is omitted, `null`, or `""`.
- `order_column` preserves on omission. Sending `null` or `""` does not clear it to `null`; it hands ordering back to the sortable scope and the server recomputes the stored order value.

### Administrative-area update rules

- Administrative-area `PUT` still requires `country_id`, an applicable parent, and `name`.
- `name` is trimmed before persistence.
- The parent and level are determined by the selected country address profile; no country-specific hierarchy is hardcoded in the client contract.

### Admin write-contract rules you must follow

- Always fetch `/admin/{resourceKey}/schema` before `POST` or `PUT`.
- Treat the returned schema as authoritative for allowed and prohibited fields.
- For `persons`, admin write contracts now require the same explicit country plus region address model as the public person flows.
- Admin speaker create/update clients must send `address.country_id` when they send an address block.
- Admin speaker create/update clients must not send:
  - `address.line1`
  - `address.line2`
  - `address.postcode`
  - `address.lat`
  - `address.lng`
  - `address.google_maps_url`
  - `address.google_place_id`
  - `address.waze_url`
- Admin speaker clients should send `address.country_id`, `address.state_id`, `address.city_id` (optional), `address.admin_area_1_id` (district), and `address.admin_area_2_id` (subdistrict).
- The `allow_public_event_submission` field is only accepted on `PUT` (update), not on `POST` (create). Sending it on create returns `422`.
- For events, sparse `PUT` updates are supported. Omitted scalar fields and relation arrays preserve the current stored value; you only need to send the arrays that should actually change.
- For events, submitted `persons` or `other_key_people` arrays rebuild the combined `key_people` rows. Do not rely on row ids surviving an update.
- For institutions, persons, venues, and references, admin and member get-record responses now embed `contacts` and `social_media` directly in `attributes` alongside `address`. To update these collections, fetch the current record, modify locally, then resend the **full** array. Generic patch-style array updates are not supported.
- For persons, apply the same fetch-modify-resend rule to `honorific`, `pre_nominal`, `post_nominal`, `qualifications`, and `language_ids` whenever you want to preserve existing entries.
- For venues, never send `address = {}` as a no-op placeholder. On the shared save path it removes the stored address.
- For series, `languages` follows omit-preserve / null-clear / array-replace semantics, but `title`, `slug`, and `visibility` remain required on update.
- For donation channels, remember that switching `method` intentionally wipes the unrelated bank / DuitNow / ewallet fields before saving the new method-specific payload.
- For inspirations, raw HTTP `clear_main=true` is the explicit clear operation for the single `main` media collection.
- For spaces, `institutions` is an exact replacement sync, not an append-only relation update.
- For reports, remember that `evidence: []` clears the media collection while `evidence: null` preserves the current uploads.
- For tags, treat `name.en` as optional display sugar: if you omit it, the server falls back to `name.ms`, and blank / null `order_column` values trigger sortable reordering instead of storing `null`.
- For administrative areas, parent fields are optional only when the selected country profile defines that level as a root or allows a direct State-to-area mapping.

### Example: full admin speaker create/update flow

```http
# 1. Discover the resource
GET /api/v1/admin/manifest

# 2. Get the create schema
GET /api/v1/admin/persons/schema?operation=create

# 3. Create
POST /api/v1/admin/persons
Content-Type: multipart/form-data

name=Ahmad Fauzi
gender=male
status=verified
is_freelance=false
is_active=true
address[state_id]=14

# 4. Note the returned admin route_key
# { "data": { "record": { "route_key": "ahmad-fauzi-my", ... } } }

# 5. Get the update schema using the route_key returned by the record detail payload
GET /api/v1/admin/persons/schema?operation=update&recordKey=ahmad-fauzi-my

# 6. Update — name, gender, status are always required
PUT /api/v1/admin/persons/ahmad-fauzi-my
Content-Type: multipart/form-data

name=Ahmad Fauzi bin Abdullah
gender=male
status=verified
```

---

## 3. Event Discovery

### Public endpoints

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/events` | Event index with filters, includes, and sorting |
| `GET` | `/events/{eventOrSlug}` | Event detail by UUID or slug for active public events, plus active unlisted events when the client already has the direct identifier |

### `GET /events`

> **Timezone note:** `filter[starts_after]`, `filter[starts_before]`, `filter[ends_after]`, and `filter[ends_before]` are date-boundary filters. Send date-only values in the user's local calendar and provide timezone context via `X-Timezone` or `user_timezone`; the API converts them to UTC start/end-of-day boundaries. If you omit timezone context, these filters default to UTC.

Supported filters:

- `filter[status]`
- `filter[visibility]`
- `filter[event_format]`
- `filter[institution_id]`
- `filter[venue_id]`
- `filter[event_category_ids]`
- `filter[starts_after]`
- `filter[starts_before]`
- `filter[ends_after]`
- `filter[ends_before]`
- `filter[state_id]`
- `filter[admin_area_1_id]` (district)
- `filter[admin_area_2_id]` (subdistrict)
- `filter[city_id]`
- `filter[speaker]`
- `filter[key_person_roles]`
- `filter[moderator_ids]`
- `filter[imam_ids]`
- `filter[khatib_ids]`
- `filter[bilal_ids]`
- `filter[series]`
- `filter[search]`
- `filter[prayer_time]`

Notes:

- `filter[search]` matches event title and description text only. It does **not** search nested speaker payloads.
- `filter[person]` accepts one or more person UUIDs and returns events linked to those persons.
- If you need to discover all events for a specific person, use `GET /api/v1/persons/{personKey}`; the person detail payload includes `upcoming_events` and `past_events`.

Supported includes:

- `venue`
- `venue.address`
- `venue.address.state`
- `venue.address.district`
- `venue.address.subdistrict`
- `venue.address.city`
- `institution`
- `institution.address`
- `keyPeople`
- `keyPeople.speaker`
- `persons`
- `series`
- `mediaLinks`
- `settings`
- `languages`
- `address`
- `address.state`
- `address.district`
- `address.subdistrict`
- `address.city`
- `donationChannels` on detail requests

Supported sorts:

- `title`
- `starts_at`
- `ends_at`
- `created_at`
- `updated_at`

Examples:

```http
GET /api/v1/events?filter[search]=kuliah&filter[state_id]=10&include=institution,venue,persons,settings&sort=starts_at
```

```http
GET /api/v1/events?filter[person]=019d5cb5-7de1-7055-a4d3-b57ab007331e&include=institution,venue,persons&sort=starts_at
```

Mobile recommendation:

- For event detail screens, request `institution`, `venue.address`, `persons`, `settings`, `languages`, and `donationChannels` when needed.
- The detail endpoint returns active `public` events plus active `unlisted` events when the client already has the UUID or slug. The `/events` index still excludes unlisted events.
- Event detail payloads now include `active_change_notice`, `change_announcements`, and `replacement_event` so native clients can render the same source-of-truth notice, change history, and replacement CTA behavior as the web event page.
- `active_change_notice` is the latest published public notice or `null`.
- `change_announcements` is the published history ordered newest-first.
- `replacement_event` and per-announcement `replacement_event` fields resolve replacement chains to the latest still-reachable public or unlisted event. If later targets become private, inactive, or otherwise unreachable, the payload falls back to the last reachable target or omits the field entirely.

---

## 4. Event Engagement

### Event state

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/events/{event}/me` | Return the authenticated user’s saved, going, registration, and check-in state for an active public or unlisted event |

`GET /events/{event}/me` returns:

```json
{
  "data": {
    "saved": {
      "is_saved": false,
      "saves_count": 12
    },
    "going": {
      "is_going": true,
      "going_count": 48
    },
    "registration": {
      "is_registered": true,
      "registration": {
        "id": "uuid",
        "event_id": "uuid",
        "user_id": "uuid",
        "name": "Registered User",
        "email": "user@example.com",
        "phone": null,
        "status": "registered",
        "created_at": "2026-04-17T12:00:00+00:00"
      }
    },
    "check_in": {
      "is_checked_in": false,
      "available": true,
      "reason": null,
      "method": "registered_self_checkin",
      "registration_id": "uuid"
    }
  }
}
```

### Saved events

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/me/events/saved` | List saved events for the authenticated user |
| `PUT` | `/events/{event}/saved` | Idempotently save an event |
| `DELETE` | `/events/{event}/saved` | Idempotently remove saved state |

Notes:

- Use `GET /events/{event}/me` for the current saved flag and count.
- `GET /me/events/saved` uses simple pagination metadata: `page`, `per_page`, `has_more`, and `next_page`.
- `PUT /events/{event}/saved` is restricted to active public events in engageable statuses.
- `PUT /events/{event}/saved` returns `201` on first save and `200` when the event was already saved.
- `DELETE /events/{event}/saved` always returns the final saved state payload.

### Going

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/me/events/going` | List events the authenticated user marked as going |
| `PUT` | `/events/{event}/going` | Idempotently mark the event as going |
| `DELETE` | `/events/{event}/going` | Remove the going record |

Notes:

- Use `GET /events/{event}/me` for the current going flag and count.
- `GET /me/events/going` uses simple pagination metadata: `page`, `per_page`, `has_more`, and `next_page`.
- `PUT /events/{event}/going` is restricted to active public, engageable, future events.
- `PUT /events/{event}/going` returns `201` on first write and `200` when the state was already set.

---

## 5. Registrations And Check-In

### Registrations

| Method | Path | Auth | Purpose |
|---|---|---|---|
| `POST` | `/events/{event}/registrations` | Optional | Register for an event |
| `GET` | `/user/registrations` | Required | List the authenticated user’s registrations |
| `GET` | `/events/{event}/registrations/export` | Required | Export event registrations as CSV if authorized |

Registration request body:

```json
{
  "name": "Registrant Name",
  "email": "registrant@example.com",
  "phone": "+60123456789"
}
```

Notes:

- Guests must provide either `email` or `phone`.
- Authenticated users may register without `email` or `phone`.
- Registration follows the same `RegisterForEventAction` rules as the web route.
- Unlisted events remain registerable when registration is enabled, matching the existing web behavior.
- The current authenticated registration state lives under `data.registration` in `GET /events/{event}/me`, including active unlisted events when the client already has the event identifier.

### Check-in

| Method | Path | Purpose |
|---|---|---|
| `POST` | `/events/{event}/check-ins` | Record a check-in for the authenticated user |

Notes:

- Check-in uses the shared `ResolveEventCheckInStateAction`, so API and web eligibility rules now match.
- Current check-in availability and checked-in state live under `data.check_in` in `GET /events/{event}/me`.
- If the event requires registration, the check-in state returns `registered_self_checkin` and the relevant `registration_id`.
- Duplicate check-ins return HTTP `200` with `data.status = "duplicate"` instead of creating another row.

---

## 6. Saved Searches

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/saved-searches` | List saved searches |
| `POST` | `/saved-searches` | Create a saved search |
| `GET` | `/saved-searches/{savedSearch}` | Show one saved search |
| `PUT` / `PATCH` | `/saved-searches/{savedSearch}` | Update a saved search |
| `DELETE` | `/saved-searches/{savedSearch}` | Delete a saved search |
| `POST` | `/saved-searches/{savedSearch}/execute` | Execute the saved search and return event results |

Selected fields:

- `name`
- `query`
- `filters`
- `radius_km`
- `lat`
- `lng`
- `notify`

Saved-search `filters` use the same enum backing-value contract as event discovery. Store values such as `event_category_ids: ["kuliah_ceramah"]`, `event_format: ["online"]`, `gender: "all"`, `age_group: ["all_ages"]`, `prayer_time: "selepas_maghrib"`, and `timing_mode: "prayer_relative"`; do not store display labels.

---

## 7. Reports

| Method | Path | Purpose |
|---|---|---|
| `POST` | `/reports` | Submit a report against an event, institution, speaker, venue, or reference |

Auth:

- Authentication is required.
- Rate limiting uses `throttle:reports`.

Request body (`multipart/form-data` when evidence is included):

```http
entity_type=event
entity_id=uuid
category=wrong_info
description=Optional text when category is other.
evidence[]=<image-or-pdf-file>
```

Notes:

- `evidence[]` is optional, accepts up to 8 files, and each file may be `image/jpeg`, `image/png`, `image/webp`, or `application/pdf`.
- Evidence file size follows `config('media-library.max_file_size')`, 10 MB by default.
- The response includes report evidence metadata with media id, display name, stored filename, MIME type, byte size, and URL.
- Duplicate reports from the same reporter fingerprint within 24 hours return `409`.
- High-risk event reports can trigger re-moderation.

---

## 8. Notifications

### Inbox and settings

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/notifications` | List inbox-visible notifications |
| `POST` | `/notifications/{message}/read` | Mark one notification as read |
| `POST` | `/notifications/read-all` | Mark all inbox-visible notifications as read |
| `GET` | `/notification-settings/catalog` | Return notification families, triggers, and selectable options |
| `GET` | `/notification-settings` | Return the user’s current notification state |
| `PUT` | `/notification-settings` | Update notification settings |

Useful query params for `GET /notifications`:

- `family`
- `status=unread|read|all`
- `per_page`

### Push destinations

| Method | Path | Purpose |
|---|---|---|
| `POST` | `/notification-destinations/push` | Register a push destination |
| `PUT` | `/notification-destinations/push/{installation}` | Update a push destination |
| `DELETE` | `/notification-destinations/push/{installation}` | Remove a push destination |

Push registration body:

```json
{
  "installation_id": "ios-primary",
  "platform": "ios",
  "fcm_token": "token-value",
  "app_version": "1.0.0",
  "device_label": "Aiman iPhone",
  "locale": "ms",
  "timezone": "Asia/Kuala_Lumpur"
}
```

Notes:

- Supported `platform` values are `ios` and `android`.
- This is the current native push registration surface for mobile apps.

---

## 9. Mobile Integration Notes

Recommended event detail fetch sequence:

1. `GET /events/{eventOrSlug}` with the includes required for the screen.
2. If authenticated, fetch `GET /events/{event}/me`.

Event-detail media note:

- The event detail payload is now sufficient for reference-card artwork on native clients; you should not need an extra `GET /references/{referenceKey}` call just to render a linked book cover once the current backend deployment is in place.

Recommended auth flow:

1. Use `POST /auth/register` or `POST /auth/login`.
2. Persist the bearer token securely on-device.
3. Send `Authorization: Bearer <token>` on authenticated requests.
4. Use `POST /auth/logout` when explicitly signing out that device session.

---

## 10. Current Gaps To Be Aware Of

- Registration export is CSV-only and intended for operator workflows rather than general attendee-facing mobile screens.

---

## 11. AI Agent Quick-Reference

This section summarizes the non-obvious rules that AI agents must internalize before calling this API.

### Record key rules

| Use case | Field to use |
|---|---|
| Read a record detail via admin API | `route_key` |
| Fetch write schema (`?operation=update&recordKey=...`) | `route_key` |
| PUT to update a record via admin API | `route_key` in `/admin/{resourceKey}/{recordKey}` |
| Resolve public person/institution | Slug or UUID both accepted (`/persons/{personKey}`) |

> The `route_key` field in admin API responses is the canonical record-specific path key.

### Field requirement rules

| Resource | Always required (create AND update) | Update-only optional |
|---|---|---|
| Speaker | `name`, `gender`, `status` | `allow_public_event_submission` |
| Institution | `name`, `type`, `status` | `allow_public_event_submission` |
| Venue | `name`, `type`, `status` | — |
| Reference | `title`, `type`, `status` | — |
| Event | `title`, `event_date`, `prayer_time`, `timezone`, `event_format`, `visibility`, `gender`, `age_group`, `event_category_ids` | — |

> Admin PUT is a full-field replacement for required fields. Always send all `required: true` fields from the schema even when updating.

Important exceptions and mixed-semantic reminders:

- Speaker updates still require `name`, `gender`, and `status`, but `address` remains optional; if you send it, include `address.country_id`, and never use `address = {}` as a no-op.
- Venue updates are sparse; `name`, `type`, and `status` are not required on update. `address = {}` deletes the stored venue address.
- Reference updates still require `title`, `type`, and `status`, while optional normalized scalars like `author` and `publisher` clear to `null` when you send `null`.
- For social-media writes across institutions, persons, venues, and references, use `twitter` as the canonical write value for Twitter / X.

### Search result scope

| Surface | Returns |
|---|---|
| `GET /api/v1/persons?search=` | The public person visibility scope |
| `GET /api/v1/institutions?search=` | The public institution visibility scope |
| `GET /api/v1/references?search=` | Published references with `status` `verified` or `pending` |
| `GET /api/v1/admin/persons?search=` | All persons (any status, active or inactive) |
| `GET /api/v1/admin/institutions?search=` | All institutions (any status, active or inactive) |

### Timestamp interpretation

All timestamps in API responses end in `Z` (UTC). Convert them to the viewer's timezone in the client, or rely on localized helper fields when you send timezone context. For date-only filters, send the user's local calendar date together with timezone context (for example `X-Timezone`) so the server can convert that local date to the correct UTC boundaries.

### Prohibited address fields for persons

Never send any of the following for person create or update (both public contribution and admin):
`address.line1`, `address.line2`, `address.postcode`, `address.lat`, `address.lng`, `address.google_maps_url`, `address.google_place_id`, `address.waze_url`

The server will reject them with HTTP `422`. Send `address.country_id`, then `address.state_id`, optional `address.city_id`, `address.admin_area_1_id` (district), and `address.admin_area_2_id` (subdistrict).
