# ilmu360° Developer Technical Documentation

Updated: July 19, 2026
Audience: Engineers onboarding to build, maintain, and extend ilmu360°.

---

## 1. System Overview
ilmu360° is a Laravel-based platform for Islamic event discovery, submission, moderation, and publishing.

At runtime it has three main surfaces:
1. Public web experience (event discovery, detail, submission).
2. Authenticated user experience (dashboard, registrations, saved searches).
3. Admin operations via Filament (resource CRUD, moderation queue, report handling).

Core architecture style is modular monolith:
- Laravel app and Eloquent models as domain core.
- Livewire page components for interactive public/user UI.
- Filament panel for admin operations.
- Scout + Typesense (with DB fallback) for event search.
- Queue jobs and scheduler for digest/escalation/maintenance tasks.

---

## 2. Tech Stack and Major Packages

### 2.1 Runtime
- PHP 8.4+ (runtime: PHP 8.5)
- Laravel 13.20
- Postgres (primary relational store)
- Laravel Scout + Typesense (search)
- Livewire (via app stack)
- Filament v5 (admin + form/table system)
- Laravel AI SDK (`laravel/ai`)
- Laravel MCP (`laravel/mcp`)
- Laravel Octane (long-lived worker runtime)

### 2.2 Security/Auth/Access
- Laravel Fortify (auth flows)
- Laravel Sanctum (API auth)
- Laravel Socialite (Google OAuth)
- `aiarmada/filament-authz` (role/scope authorization in admin and domain context)

### 2.3 Data/Domain Support
- Spatie Tags
- Spatie Medialibrary + Filament media plugin
- Spatie Eloquent Sortable
- Spatie Deleted Models (instead of SoftDeletes)
- Owen-it Laravel Auditing
- `aiarmada/addressing` (UUID geography: country / state / city / admin_area_1 (district) / admin_area_2 (subdistrict))
- `aiarmada/contacting`, `aiarmada/engagement`, `aiarmada/events`, `aiarmada/membership`, `aiarmada/moderation`, `aiarmada/references`, `aiarmada/signals`, `aiarmada/ticketing`, `aiarmada/inventory`, `aiarmada/seating`, `aiarmada/authz`, `aiarmada/commerce-support`, `aiarmada/affiliates`, `aiarmada/communications`

### 2.4 Developer Tooling
- Pest v4
- Pint
- Larastan/PHPStan
- Rector

---

## 3. Repository Layout

Top-level directories of interest:
- `app/Models`: domain entities and relationships.
- `app/Livewire/Pages`: interactive pages (`Events`, `Dashboard`, `SavedSearches`).
- `resources/views/components/pages`: page-level Blade/Volt components including submit-event wizard.
- `app/Filament/Resources`: admin CRUD resources.
- `app/Filament/Pages`: custom admin pages (`ModerationQueue`).
- `app/Services`: application services (search, moderation, prayer times, calendar).
- `app/Jobs`: queue jobs.
- `routes/web.php`, `routes/api.php`, `routes/console.php`: HTTP and schedule topology.
- `database/migrations`, `database/seeders`, `database/factories`: schema + test/seed data.
- `tests/Feature`, `tests/Unit`: test suite.
- `docs`: project docs and handover guides.

---

## 4. Domain Model Summary

## 4.1 Core entities
- `Event`: primary content object; searchable and moderated.
- `Institution`: organizer and location authority context.
- `Speaker`: profile + media + affiliation to institutions.
- `Venue`: physical location object with address context.
- `Series`: grouping recurring/related events.
- `Reference`: books/sources used in events, including root books and optional child parts (for example jilid, bahagian, or volume records).
- `Tag`: unified topic taxonomy (domains/discipline/source/issue).
- `Report`: public/user reporting of suspicious/problematic content.
- `SavedSearch`, `Registration`, `DonationChannel`, `ModerationReview`.

## 4.2 Important relationships
- Event belongs to Institution/Venue and has many Speakers.
- Event has many Tags, References, Languages.
- Reference can belong to a parent root reference and can have many child part references.
- Speaker belongsToMany Institutions through `institution_speaker` pivot.
- Institution belongsToMany Speakers through same pivot.
- `institution_speaker` pivot includes:
  - `position`
  - `is_primary`
  - `joined_at`

## 4.3 Event status/visibility
Event uses state casting (`App\States\EventStatus\*`) and enum casting for visibility/gender/format/age group.

Searchability and active rendering are gated by:
- `is_active = true`
- status in approved/pending (depending on surface)
- `visibility = public` for public discovery/indexing

---

## 5. Public Surface Architecture

## 5.1 Public routes
Defined in `routes/web.php` (Malay slugs in production URLs):
- Home: `/`
- Events index/detail: `/majlis`, `/majlis/{slug}`
- Event calendar export: `/majlis/{slug}/kalendar.ics`
- Institutions index/detail: `/institusi`, `/institusi/{slug}`
- Speakers index/detail: `/penceramah`, `/penceramah/{slug}`
- Venues index/detail: `/tempat`, `/lokasi/{slug}`
- References index/detail: `/rujukan`, `/rujukan/{slug}`
- Series detail: `/siri/{slug}`
- Submit-event flow: `/hantar-majlis`, `/hantar-majlis/berjaya`
- Sitemap endpoints under `/peta-laman*.xml`

## 5.2 Submit-event flow
The submit wizard is in:
- `resources/views/components/pages/submit-event/create.blade.php`

Main behavior:
- Multi-step form for event details, organizer/location, speakers/media, review/submit.
- Organizer branching for institution vs speaker.
- Quick-create options for institution/speaker/venue/reference from selects.
- Captcha token integration.
- Poster/gallery upload support for event.

Recent speaker quick-create capabilities:
- Rich biography (`bio` JSON content).
- Avatar and main image support.
- Single affiliated institution selection and optional pivot position capture.

Policy note:
- Public submit-event route is intentionally not throttled in current product policy.

---

## 6. Authenticated User Surface

Routes (auth middleware in `routes/web.php`):
- `/dashboard`
- `/dashboard/institutions`
- `/saved-searches`

Capabilities:
- View personal metrics and registrations.
- Manage saved searches and run them.
- Institution dashboard has base data views but still has pending management capabilities per MVP status.

---

## 7. Admin Surface (Filament)

## 7.1 Panel config
- Provider: `app/Providers/Filament/AdminPanelProvider.php`
- Panel path: `/admin`
- Top navigation, custom theme, auth middleware, and FilamentAuthz plugin.

## 7.2 Resources currently registered
Resource registration is runtime-driven and includes app + vendor/plugin resources.

Current verified inventory is documented in:
- `docs/ilmu360_api_mcp_filament_crud_comparison.md`
- `docs/ilmu360_api_mcp_filament_crud_comparison.json`

At the time of this update:
- Admin panel runtime resources: `61` (16 local app + 45 vendor/plugin via `aiarmada/filament-*`)
- Ahli panel runtime resources: `19` (3 local app + 16 vendor/plugin)

Use the parity docs test (`tests/Unit/CrudComparisonDocsTest.php`) as the canonical guardrail for these counts.

## 7.3 Moderation queue
- Page: `app/Filament/Pages/ModerationQueue.php`
- Supports tabs and actions for:
  - pending
  - needs changes
  - reports
  - recently rejected
- Actions include approve/reject/request changes with reason/note.

---

## 8. Search Architecture

## 8.1 Search service
- `app/Services/EventSearchService.php`
- Uses Typesense when configured, otherwise falls back to DB query path.
- Always applies active/public/status filtering logic.

## 8.2 Typesense schema and settings
- `config/scout.php`
- Event collection schema includes filtering facets (`state_id`, `city_id`, `admin_area_1_id`, `admin_area_2_id`, language, status, visibility, topic/speaker) and geopoint.

## 8.3 Geo and filter support
Search supports:
- text query
- geography filters
- topic/speaker/institution filters
- date window
- near-me distance sort (Typesense + DB fallback strategy)

## 8.4 Index maintenance
Command:
- `php artisan search:index-events --fresh`

Use this after major searchable payload changes or when bootstrapping a fresh search index.

---

## 9. Media Architecture

Core pieces:
- Global upload defaults in `app/Providers/AppServiceProvider.php`.
- Naming strategy: `app/Support/Media/MediaFileNamer.php`.
- Path strategy: `app/Support/Media/MediaPathGenerator.php`.

Global behavior includes:
- max upload size from media config
- immutable cache-control headers
- slug-aware unique filenames
- consistent custom media properties

Common media usage:
- Event: cover/poster/gallery (`cover` is required 16:9 for website/mobile-app display; `poster` is required 4:5 portrait for external distribution)
- Institution: logo/cover/gallery
- Speaker: avatar/main/gallery
- Reference/report/donation channel specific collections

Scheduled media maintenance:
- `media-library:clean --delete-orphaned --force` daily
- `media-library:regenerate --only-missing --with-responsive-images --force` weekly

---

## 10. API Surface

Defined in `routes/api.php` under `/api/v1`.

Current source of truth for native clients:
- `docs/ilmu360_mobile_api_reference.md`

Public:
- `POST /auth/register`
- `POST /auth/login`
- `GET /forms/mobile-telemetry`
- `POST /mobile/telemetry/events`
- `GET /events`
- `GET /events/{event}`
- `POST /events/{event}/registrations`

Authenticated (`auth:sanctum`):
- `POST /auth/logout`
- `GET /user`
- `DELETE /user`
- `GET /user/registrations`
- `GET /me/events/going`
- `GET /me/events/saved`
- `GET /events/{event}/me`
- `POST /events/{event}/check-ins`
- `PUT/DELETE /events/{event}/going`
- `PUT/DELETE /events/{event}/saved`
- Saved-search CRUD + execute
- `POST /reports` (reports throttle applied)
- Notifications inbox/settings endpoints
- Push destination registration endpoints
- Event registration export

Notes:
- Native app telemetry now has a dedicated client route: `POST /api/v1/mobile/telemetry/events`, with `GET /api/v1/forms/mobile-telemetry` as the canonical write contract for real iOS/iPadOS/Android sessions.
- That telemetry route is intentionally separate from browser tracking so native-app usage is not confused with users browsing the website on mobile Safari/Chrome.
- `DELETE /user` now keeps a sanitized deleted-account snapshot for the admin grace-period restore flow while still revoking transient credentials immediately.
- `GET /me/events/going` and `GET /me/events/saved` now use simple pagination metadata (`page`, `per_page`, `has_more`, `next_page`) and do not expose `total`.
- `GET /events/{event}` now serializes linked references with normalized cover aliases (`media.front_cover_url`, `front_cover_url`, `cover_url`, `thumb_url`) so native clients can render reference cards without depending on a second reference-detail request.
- Public reference reads now support family-aware behavior: root references aggregate linked child-part events, child references can opt into whole-family event aggregation, and directory/search payloads expose `display_title`, `parent_reference_id`, `part_type`, `part_number`, `part_label`, and `is_part` so clients can distinguish a whole book from a specific part.

Controllers:
- `app/Http/Controllers/Api/*`

---

## 11. Scheduling and Jobs

Schedule definitions: `routes/console.php`

Jobs/commands configured:
- `SendSavedSearchDigest` daily/weekly
- `EscalatePendingEvents` hourly
- `app:prune-orphaned-entities` daily
- media maintenance commands (daily/weekly)

Job classes:
- `app/Jobs/EscalatePendingEvents.php`
- `app/Jobs/SendSavedSearchDigest.php`

Additional operational commands:
- `app:prune-orphaned-entities`
- `search:index-events`
- `app:media:migrate-structure` (media structure migration)

---

## 12. Rate Limiting and Abuse Controls

Current policy/implementation:
- Search pages use `throttle:search`.
- Registration endpoint uses `throttle:registration`.
- Report API uses `throttle:reports`.
- Native mobile telemetry uses `throttle:mobile-telemetry` and keys by authenticated user, anonymous install id, session id, or IP fallback.
- Submit-event route is intentionally unthrottled (business policy decision).
- Captcha support exists through Turnstile service settings.

---

## 13. Configuration and Environment

## 13.1 Key environment/config groups
- App + DB config (`.env`, `config/database.php`)
- Auth (Fortify/Sanctum)
- Social login (`config/services.php` -> Google)
- Turnstile (`config/services.php` -> `turnstile`)
- Search (`config/scout.php`, Typesense node/API key)
- Media library (`config/media-library.php`)

## 13.2 Local domain
With Laravel Herd, app URL convention is:
- `https://ilmu360.test`

---

## 14. Database Rules and Conventions

Project-level conventions used in this codebase:
- Primary keys are UUID for all tables (domain entities and geography).
- Foreign keys use UUID columns without DB-level FK constraints/cascades.
- SoftDeletes are not used; deleted-model tracking uses Spatie deleted models.
- Integrity and cascade behavior are handled in application logic.
- Geography is UUID end-to-end via `aiarmada/addressing`: `country_id`, `state_id`, `city_id`, `admin_area_1_id` (district), `admin_area_2_id` (subdistrict). There are no integer geography tables and no `district_id` / `subdistrict_id` aliases.

Recent schema change to note:
- `speakers.bio` now uses `jsonb` and is edited via rich editor JSON content.

---

## 15. Testing and Quality Gates

## 15.1 Test framework
- Pest (parallel execution preferred)
- Global test bootstrap: `tests/Pest.php`

## 15.2 Typical commands
```bash
# Full suite (parallel)
vendor/bin/pest --parallel --compact

# Targeted examples
vendor/bin/pest --parallel --compact --filter='SubmitEventRateLimitTest|SpeakerCreateOptionSchemaTest'

# Code style
vendor/bin/pint --dirty --format agent

# Static analysis
vendor/bin/phpstan analyse --ansi
```

## 15.3 Current suite scale
- Feature test files: ~215
- Unit test files: ~16
- Total test files: ~230 (individual `test()`/`it()` cases higher; run `vendor/bin/pest --parallel --compact` for the live count)

---

## 16. Local Development Runbook

## 16.1 Initial setup
```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm install
npm run build
```

## 16.2 Daily development
```bash
# Herd serves the app URL; run worker/dev processes only.
# Combined development process script
composer run dev

# Or run granular processes manually
php artisan horizon
npm run dev
```

## 16.3 Useful maintenance
```bash
# Clear stale optimization/cache state
php artisan optimize:clear

# Rebuild optimized caches (if desired)
php artisan optimize

# Re-index search content
php artisan search:index-events --fresh
```

## 16.4 Queue Operations
```bash
# Run Horizon under a process monitor in production
php artisan horizon

# Gracefully restart Horizon workers during deployment
php artisan horizon:terminate
```

---

## 17. Developer Handover Notes

## 17.1 High-impact files to know first
- Submission UX: `resources/views/components/pages/submit-event/create.blade.php`
- Search logic: `app/Services/EventSearchService.php`
- Event model/search payload: `app/Models/Event.php`
- Speaker quick-create schema: `app/Forms/SpeakerFormSchema.php`
- Admin moderation: `app/Filament/Pages/ModerationQueue.php`

## 17.2 Common gotchas
1. Cached state confusion
- If behavior looks stale, run `php artisan optimize:clear`.

2. Historical migration edits
- Some workflows directly updated older migrations; if local schema drifts, use fresh migration flow for clean environment setup.

3. Search parity
- If Typesense and DB results differ, verify index freshness and Scout configuration, then re-index.

4. Media expectations
- Follow global media naming/path conventions; avoid ad-hoc file naming.

## 17.3 Recommended first-day checklist for new developers
1. Run full setup and verify `/majlis`, `/hantar-majlis`, `/admin`.
2. Run focused tests on submit-event + search + public pages.
3. Read MVP status doc: `docs/ilmu360_mvp_status.md`.
4. Validate Typesense connectivity and run `search:index-events` if needed.
5. Review moderation queue behavior end-to-end.

---

## 18. Open Work Summary (From Current MVP State)

Most impactful unfinished work:
1. Institution dashboard completion (event/profile/member management depth).
2. Moderation UX upgrades (diff and SLA visibility).
3. Trust scoring and auto-approval strategy.
4. API contract documentation (OpenAPI) for external consumers.

Use `docs/ilmu360_mvp_status.md` as the execution-level product status tracker.
