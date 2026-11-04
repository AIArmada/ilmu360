# Todo — Production Reference + Author Seeder

## Notification bell (2026-11-02)

- [x] Build `App\Livewire\Components\NotificationBell` + view: count on mount, list on open, All/Unread filter, Today/Earlier grouping in viewer timezone, unread dots, family icons, empty/loading/error states.
- [x] Reuse `MarkNotificationMessageReadAction` / `MarkAllNotificationMessagesReadAction`; scope queries/actions to the authenticated recipient, exclude archived, use explicit global owner context.
- [x] Wire the bell into the header (glass/light variants, 44px target, 99+ badge); remove the duplicate Inbox badges and layout count query; coordinate with the mobile menu.
- [x] Add EN/MS `notifications.bell` translations; keep See all → inbox `status=all`.
- [x] Dispatch `notification-count-updated` from `NotificationsIndex` so the bell follows inbox read actions.
- [x] Add feature tests for guest visibility, recipient isolation, archived exclusion, latest-eight ordering, filters, read/read-all, redirects, count sync, empty/error states, timezone grouping.
- [x] Run affected Pest once, Pint, PHPStan; browser-check mobile, 640–767, desktop; record evidence.

### Review

- The bell is a Livewire component rendered only for signed-in users. It reads the unread count on mount, loads the eight newest notifications when opened, groups them Today/Earlier in the viewer's timezone, and renders family icons, unread dots, relative times, and Empty/Loading/Retry states.
- The trigger icon fills while the panel is open, even when there are zero unread notifications, and returns to the outline variant on close. The unread state still fills the icon while closed.
- The unread badge is a sibling of the trigger rather than a child, so the trigger's `overflow: hidden` gloss no longer clips the badge; the full count stays visible over the circle edge.
- Queries and reads stay scoped to the authenticated recipient and exclude archived rows; both read actions are reused inside explicit global owner context. The panel reuses the existing Signals-aware mark-read actions.
- Header changes: glass variant on the home hero, light variant elsewhere; 44px target; gold `99+` badge. The duplicate Inbox badges and layout count query were removed. The desktop nav now starts at `lg` because the 768px layout no longer fit the bell, avatar, and actions without clipping.
- The full inbox dispatches `notification-count-updated` after read/read-all so the bell count follows inbox actions. The bell dispatches `notification-inbox-updated` when it marks items read, so the inbox list refreshes in the other direction. See all opens `/notifications?status=all`.
- Verification: `NotificationBellTest` 14 passed / 43 assertions; `NotificationInboxPageTest` 2/10; `ProductSignalsTelemetryTest` 16/81; `DashboardPagesTest` 27/286; `HeaderUserMenuTest` 2/6. Pint passed; PHPStan level 6 reported 0 errors. Browser probes at 1920, 768, 700, 375, and 1024 confirmed the styled panel, menu actions, unread filtering, read-all, inbox refresh, icon fill-on-open, no JavaScript errors, and the tablet header fix. Screenshots: `storage/verify/notification-bell/`.
- Seeded 8 demo notifications for `saiffil@maxx.my` in the local dev database (5 unread, 3 read; mixed families and Today/Earlier groups) so the bell can be reviewed with realistic data.
- A broad `PublicPagesTest` run surfaced unrelated failures in concurrent search/submission work; the focused notification, header, and dashboard checks pass.

## About page — divine intention upgrade (2026-11-02)

- [x] `Show.php`: cached live stats (events/institutions/speakers, active scopes, hidden when empty)
- [x] `about.php` ×6 locales: add `path` (Cari→Semak→Hadir→Ajak) + `stats` (real-numbers proof band)
- [x] `show.blade.php`: canonical + JSON-LD, path section, stats band, amanah callout, tagline `Dengar. Faham. Amal.`
- [x] `AboutPageTest.php`: cover path/stats/SEO/tagline without advertising missing capabilities
- [x] Run Pint, PHPStan level 6, affected Pest once

### Review

- Divine intention: ilmu360° exists so fulfilling the fardhu of seeking knowledge is not blocked by broken khabar — amanah (verified, fresh, correctable), tenang, hormat, boleh dibaca semua. Page now shows the calm path (find → verify → attend → invite) and proves it with live database numbers, never placeholders.
- Storyline pass: one human arc across the page — Hassan's Tuesday night (hero → gap), four named stakes (Hassan/Sarah/Amir/kejiranan in cost), five capabilities as the rescue (work), direct second-person address (audience), invitation close (cta). All six locales rewritten with the same arc.
- Verification: `vendor/bin/pint --dirty --format agent` passed; `vendor/bin/phpstan analyse` 0 errors; `vendor/bin/pest tests/Feature/AboutPageTest.php --parallel --compact` 13 passed, 50 assertions.
- Evidence: `/tmp/ilmu360-about-pint.log`, `/tmp/ilmu360-about-phpstan.log`, `/tmp/ilmu360-about-tests.log`, `/tmp/ilmu360-story-pint.log`, `/tmp/ilmu360-story-phpstan.log`, `/tmp/ilmu360-story-tests.log`.

## App feedback audit fixes (9f4f853c)

- [x] Preserve distinct attachment contents with random ULID suffixes in both shared upload entry points; add regression coverage.
- [x] Link acknowledgement and closure emails to the selected feedback, including older history entries; add regression coverage.
- [x] Serialize GitHub reconciliation and prevent late escalation results from replacing established links; cover stale and in-flight updates.
- [x] Run affected parallel Pest tests once, Pint, PHPStan level 6, and review the final diff.

### App feedback review

- Direct canonical fixes only: no compatibility branches, legacy aliases, transitional storage, or task-created temporary files in the repository.
- Keep existing server-confirmed submission Signals; these fixes introduce no new user intents or workflows requiring extra tracking.
- Verification: affected parallel Pest run passed 103 tests (334 assertions), including seven new regression cases. Pint, PHPStan level 6 (zero errors), and diff whitespace checks passed. Independent review found no further material issues.
- Evidence: `/tmp/ilmu360-feedback-fixes-tests.log`, `/tmp/ilmu360-feedback-fixes-phpstan.log`.

## Guest-share audit fixes

- [x] Centralize marked guest-share claim reads and exclude commercial package claims from reporting and cleanup.
- [x] Run guest claim mutations in explicit global context while retaining member recipient ownership.
- [x] Add regression coverage for commercial isolation and ownership-enabled claim, replay, history and deletion.
- [x] Run affected parallel Pest tests, Pint and PHPStan; record results.

### Review

- Scope: the two regressions reported in the review of `17d47cd`; preserve package primitives and the clean cutover.
- Existing audit evidence: 435/436 focused tests passed; rewards admin query-budget failure (598 queries) remains outside this fix scope.
- Canonical guest claims now require ownerless claim/affiliate records and the strict guest-share metadata marker; reporting, attribution, rewards, cohorts and cleanup reuse that boundary. Package claim/revoke mutations use explicit global context, with recipient creation retaining claimant context.
- Verification: affected parallel run 254/259 passed initially; four new fixture errors and one named-scope failure corrected, then all six regression cases passed (64 assertions). Earlier passing affected checks include PostgreSQL claim/delete races; no unchanged broad rerun. Final repository PHPStan: 0 errors; Pint and diff whitespace checks passed. Independent bounded review found no further material defect.
- Evidence: `/tmp/ilmu360-share-fix-tests.log`, `/tmp/ilmu360-share-fix-regression-tests.log`, `/tmp/ilmu360-share-fix-phpstan.log`.
- Speaker/person menu missing for admin: package `PersonResource::canViewAny()` requires `person.viewAny`, but `PermissionSeeder` never defined `person.viewAny`/`person.create` and granted admin zero person abilities. Added both permissions and granted admin the full person set (`viewAny`, `view`, `create`, `update`, `delete`, `manage-members`); re-seeded. Verified `canViewAny`/`shouldRegisterNavigation` true in tinker and `/persons` present in the admin sidebar via login flow; `FilamentPanelAccessTest` 9/9 green; Pint clean.

- [x] Inspect prod `ilmu360-prod` references (83 rows, free-text `author`, 1 part) + local schema
- [x] Create `database/seeders/ProductionReferenceSeeder.php` (source `ilmu360-prod`, preserve IDs, parse authors, sync contributors)
- [x] Verify: `vendor/bin/pint --dirty --format agent`, run seeder on local copy, run `ReferenceSeederTest`
- [ ] Document result in review section

## Review

- Replaced demo `ReferenceSeeder` with embedded production data (83 books, prod IDs preserved, 1 part relinked) — no `ilmu360-prod` connection, same pattern as `SpeakerSeeder`.
- Prod mapping: free-text `author` split on commas → `Person` (inactive, non-submittable) + `syncContributors(Author)` on works only (parts inherit); `kitab`→`book`; verified status; slug preserved or regenerated.
- Verified: fresh seed 83 refs; rerun 0 new / 83 updated (idempotent on pgsql + sqlite); `Al-Fiqh Al-Manhaji` got 3 author links; part kept `parent_id`; `ReferenceSeederTest` 2 passed (17 assertions), `InstitutionSeederTest` 2 passed; pint + phpstan clean.
- `db:seed` wiring: `DatabaseSeeder.php:69` `SpeakerSeeder` (guarded, runs first) + `ReferenceSeeder` in the main call list — both run on every non-production `db:seed`.
- Reference covers: embedded 51 prod media rows (48 front_cover, 1 back_cover, 2 gallery, disk s3) in `ReferenceSeeder`; S3 keys already match the local path generator so no bucket copy needed. Verified real S3 thumb URLs + rerun idempotent; `ReferenceSeederTest` now 3 tests. Full set (7 tests) green; pint + phpstan clean.
- Admin 403 on `admin.ilmu360.test/`: panel home `/` served the signals plugin dashboard (Filament Dashboard defaults to route `/`), whose `signal-report.view` ability is held by nobody — every admin got 403 while `/dashboard` worked. Fixed in the package (`commerce/packages/filament-signals/.../SignalsDashboard.php` + explicit `$routePath = '/signals'` so a plugin dashboard can never squat the panel home); reverted the temporary app-side flag. Verified `/` → 302 → `/dashboard` → 200, `/signals` correctly 403s as a gated page; 45 panel/dashboard tests green.

## Remove external calendar export (2026-11-01)

- [x] Remove add-to-calendar menus and `.ics` downloads from the event, date, and session pages.
- [x] Remove `events.calendar`, `events.occurrence.calendar`, and `events.session.calendar` routes, controller, and `CalendarService`.
- [x] Remove obsolete calendar tests and translation keys; update about copy and the public share-tracking allowlist.
- [x] Run Pint, route audit, PHPStan level 6, and affected Pest tests once.

### Review

- Public event surfaces no longer offer Google/Outlook/Office 365/Yahoo deep links or `.ics` files; the app is the only place readers see the current schedule.
- In-app dashboard/planner calendars remain because they query live saved/going/check-in data rather than exporting a snapshot.
- Verification: route list has no calendar routes; `vendor/bin/pint --dirty` passed; PHPStan level 6 reported 0 errors. Affected Pest run rendered the changed pages successfully; remaining failures in that run are pre-existing failures in unrelated in-progress work (hero/image, Waze link, share signal, replacement CTA, and query-budget assertions), with no remaining calendar references in the suite.
- Evidence: `/tmp/ilmu360-calendar-removal-tests.log`, `/tmp/ilmu360-calendar-removal-phpstan.log`.

## Followed / saved directory filters (2026-11-02)

- [x] Add `followed_only` URL filter to `/penceramah` with a directory toggle and followed-ID scoping across search paths.
- [x] Add `followed_only` to `/institusi` parent filter payload + child results query.
- [x] Add `followed_only` URL filter to `/rujukan` with scoped directory query.
- [x] Add `saved_only` URL filter to `/majlis`, resolving current user's saved event IDs into the event discovery query.
- [x] Add UI toggles, empty states, and translations.
- [x] Add feature tests for each filter (guest + authenticated + combined with search).
- [x] Run affected Pest tests, Pint, and PHPStan level 6 (no `graphify-out/` in this checkout, so no graph update).

### Review

- `/penceramah`, `/institusi`, and `/rujukan` now expose a bookmark-icon `Diikuti` toggle for signed-in users; `?followed_only=1` narrows listings to the current user's active follows and keeps the filter through search/sort/pagination.
- `/majlis` exposes a `Disimpan` toggle; `?saved_only=1` resolves the signed-in user's bookmarks on the server and adds a `saved_event_ids` predicate, so the filter composes with text search, time scope, location, and nearby search. Guests never see the control and direct guest URLs are ignored.
- Empty states are entity-specific (`No followed … yet`, `No saved majlis found`) and the saved/followed filter is included in the active-filter count and reset flow.
- Verification: 21 targeted tests pass (90 assertions); existing `InstitutionIndexTest` 38/38 and `ReferenceIndexTest` 17/17 pass; Pint and PHPStan level 6 are clean. The four-file regression run has pre-existing failures in unrelated in-progress work (`PersonIndexTest` CTA copy and `EventSearchTest` event-detail registration/share/banner tests), with the filter suite itself green.
- Follow-up fix: institution directory follow 500 (`Unknown named parameter $outcomeKey`). Replaced the stale named argument with the service's `businessKey` contract, reused `ShareOutcomeBusinessKey::follow()`, and guarded recording with `wasRecentlyCreated`. `InstitutionResultsTest` 8/8 and `InstitutionIndexTest` 38/38 pass; PHPStan clean.
- Evidence: `/tmp/ilmu360-followed-filters-tests.log`, `/tmp/ilmu360-followed-filters-regression-tests.log`, `/tmp/ilmu360-followed-filters-phpstan.log`, `/tmp/ilmu360-institution-follow-fix-tests.log`, `/tmp/ilmu360-institution-follow-fix-phpstan.log`.

## Card engagement performance (2026-11-03)

- [x] Trace action queries, registration/pass side effects, tracking and filtered listings.
- [x] Isolate homepage and /majlis card controls; retain parent action APIs and filtered refresh.
- [x] Verify persistence, counters, guest flow, duplicate card sync and discovery isolation.

Review: 10 focused engagement tests passed (40 assertions); card/homepage checks had 32 other passes with one unrelated date-filter missing-visibility error. PHPStan passed with zero errors. Existing engagement queries and action side effects retained; only full-page discovery/render work is avoided on ordinary clicks. Filter mode and return URL participate in child identity to preserve filtered refresh and guest destinations.

Live verification: reloaded PHP-FPM and fetched both pages successfully: homepage renders 3 isolated controls, /majlis renders 12. Shared partial lives outside the Blaze component directory so includes render correctly in staging.

Follow-up: removed the share button from all majlis cards (homepage featured cards, `/majlis`, and every other `x-events.card` surface); event-detail/hero share controls remain. Regression coverage now asserts the share control is absent from card markup while going/save remain present. Also removed the homepage date-filter N+1/`visibility` failure by grouping occurrence timestamps directly instead of hydrating partial `Event` models. Focused verification: 44 tests, 299 assertions, all passing; Pint clean, PHPStan 0 errors. Live `/` and `/majlis` return 200 and render 3/12 going + save controls with 0 card share controls. Earlier broader event/public-page run: 246/257 passing; the 11 failures are pre-existing unrelated event-detail banner/gallery/share-preview/registration, SEO, Waze and guest-submission cases.

## Directory card follow performance (2026-11-03)

- [x] Trace follow eligibility, owner context, share outcomes and followed-only state.
- [x] Share existing follow orchestration through an Action and isolate card controls.
- [x] Refresh parent followed IDs on hydration; refresh listings only when filtered.
- [x] Verify directory regressions, isolated query behavior, analysis and live rendering.

Review: initial directory suite ran 86 tests; after correcting reference hydration owner context and a child key prefix collision, all 28 affected follow/reference tests passed (124 assertions), with the other 58 tests passing in the initial run. PHPStan reports zero errors, Pint and diff checks pass. Live directories each render 12 isolated controls. Eligibility, User follow owner-context wrappers and share outcome semantics remain intact; hydration explicitly uses the global directory context and filtered lists refresh from authoritative IDs.

## Clockwork frontend profiling (2026-11-03)

- [x] Confirm Clockwork 5.3.5 is installed and inspect profiling/browser access.
- [ ] Enable private, on-demand collection and connect configured Chrome MCP.
- [ ] Profile public page families and representative detail pages; inspect engagement timings where authenticated access exists.
- [ ] Rank measured bottlenecks, validate safe fixes where justified, and preserve results with coverage limits.
## Homepage featured speakers — implementation plan (2026-11-04)

Scope: make the existing homepage speaker selection manageable through the Persons admin. Planning only; implementation awaits review. Revised ownership: generic featuring and ordering belong in `aiarmada/persons` and `aiarmada/filament-persons`; homepage placement and speaker eligibility remain in the app. Installed packages resolve to the sibling `commerce/packages` source tree; inspect that repository's rules before editing.

- [x] Inspect the homepage selection, Persons form/model, installed ordering package, and project rules.
- [x] Add an explicit `is_featured` boolean (default false) and nullable unsigned `order_column` to the canonical persons package schema migration in its source repository. This is editorial selection, independent of lifecycle `status` and app-specific `speaker_status`. Follow the existing-migration convention; do not edit installed vendor copies or reset data.
- [x] Add featuring casts/fillable fields and a reusable `featured` scope to the package Person model; implement Spatie `Sortable` / `SortableTrait` using `order_column`, preserving explicit positions on create and appending newly featured records. App's overridden fillable/casts carry the fields.
- [x] In Persons → Edit → Status, add the “Featured on homepage” toggle (built from the package `featuredToggle()` helper). Selection stays independent of status and speaker eligibility.
- [x] Add reusable `featuredColumn()`, `featuredFilter()`, and `applyFeaturedOrdering()` in `filament-persons`; the app table consumes them. Reordering is gated by the same `person.update` ability as editing.
- [x] Replace the hardcoded slug list in `featuredPersons()` with `active()->speakers()->featured()->ordered()->orderBy(id)->take(5)`.
- [x] No new tracking added; person curation changes flow through the model's existing audit logging.
- [x] Add regression coverage for admin selection/authorization, reorder authorization/order persistence, homepage ordering, five-speaker limit, exclusions, removal, and empty state. Package-level tests cover scope/order assignment.
- [x] Verify with affected parallel Pest files, Pint, and PHPStan level 6; inspect the scoped diff. Commerce monorepo tests/analysis could not run locally because that repository has no installed `vendor/`; app-side tests exercise the package behavior.

### Plan review

- Confirmed: homepage now uses the editorial selection; no hardcoded slugs remain.
- Behavior: admins select any number in Persons → Edit → Status. The homepage shows the first five eligible selections in curated order. Selections default off; initial curation happens in admin.
- Reorder: Persons list is reorderable by `order_column`; the trigger and request are authorized by `person.update`, and only visible records in the reorder request are renumbered.
- Eligibility: `verified`/`pending` status plus active `speaker_status`; inactive or non-speaker people never appear publicly.
- Schema rollout: the canonical package migration was edited in place. The local staging database was synchronized non-destructively with the two columns; other environments need the same rollout before deploying the code.
- Verification: `HomepageFeaturedSpeakersTest` 3/3, `PersonFeaturedAdminTest` 4/4, `HomePageTest` 27/27, `PersonAdminEditSocialMediaLabelTest` 5/5, `PersonAdminFollowersTableTest` 1/1, `FilamentPanelAccessTest` 9/9. App Pint and PHPStan level 6 clean; commerce Pint clean. Browser probe produced the styled homepage artifact at `storage/verify/homepage-featured-speakers/` (the command timed out on teardown, so no clean browser-run report).



## Featured speakers audit fixes (2026-11-04)

- [x] Restrict selection and ordering to global admin/super_admin roles; block non-admin model writes and hide inherited selection controls.
- [x] Introduce package reorder action that merges submitted subsets into the full locked featured sequence and uses Spatie ordering.
- [x] Record actor and before/after sequence through existing custom audits in the app transaction.
- [x] Verify direct authorization, ordering validation and audit persistence. Per user direction, omit the toggle from the member schema and remove the member-panel test.
- [x] Final verification: admin/authorization/reorder suite 7 passed (23 assertions); homepage suite 3 passed (13 assertions); PHPStan 0 errors; app/package Pint and diff checks passed. No additional database changes.

## Homepage majlis selection (2026-11-04)

- [x] Inspect public schedule discovery and active follow relations.
- [x] Select admin-featured upcoming schedules first, then random schedules related to followed speakers/institutions/references; use the user-requested nine-card limit and existing 14-day window, approved public visibility and deduplication.
- [x] Verified selection tests: 7 passed / 21 assertions; homepage regression suite: 27 passed. PHPStan 0 errors; Pint and diff checks passed. Covers guests, all active follow sources/unfollow, priority, nine-card cap, exclusions and insufficient matches. No database changes.
- Tracking review: this changes recommendation content only; existing card navigation/engagement tracking remains applicable.

## Admin event edit + homepage card centering (2026-11-04)

- [x] Add Edit to the Events admin list row actions and the event View header; content remains authorized by the Event policy.
- [x] Replace the homepage featured-majlis CSS grid with a centered flex wrap layout so incomplete last rows center on tablet and desktop.
- [x] Verify the edit URL appears on both list and view pages for an authorized admin, and the homepage suites still pass.

Review: the package `EventResource` now exposes `EditAction` in the table and `ViewEvent` header (canonical `commerce/packages/filament-events`). The homepage featured cards use `flex flex-wrap justify-center` with child width utilities (`*:w-full`, `sm:*:w-[calc(50%_-_0.5rem)]`, `xl:*:w-[calc(33.333%_-_0.6667rem)]`) matching the existing directory card pattern; the production Vite build confirmed the generated widths. Verification: `FilamentEventResourceTest` 3 passed (36 assertions), `HomePageTest` 27 passed, `HomepageMajlisSelectionTest` 7 passed; app and package Pint clean. No database changes.

## Admin Filament performance (2026-11-04)

- [x] Baseline with Chrome MCP: dashboard LCP 2,375 ms / TTFB 1,011 ms; `/events` TTFB ~0.95 s; `/persons` TTFB 1.5–2.8 s; 111 identical role queries per admin page; dashboard widgets defaulted to Filament's 5 s polling.
- [x] Memoise global-role verdicts per request (object-keyed WeakMap) and invalidate on role-table writes plus Octane request start; delegate `FilamentPermission` to the same checker.
- [x] Stop default 5 s widget polling: set explicit `null`/60 s intervals on dashboard stats and chart widgets.
- [x] Enable Filament SPA navigation with hover prefetch on the admin panel.
- [x] Cache S3 presigned media URLs for four minutes and cache S3 object-existence checks (6 h when present, 5 min when missing); previously every avatar URL did an S3 HEAD (~60 ms each).
- [x] Fix `scripts/deploy.sh` so `optimize:clear` is followed by `php artisan optimize` + `php artisan filament:optimize`; staging was serving with config/routes/events uncached after every deploy.
- [x] Verification: `AuthzGlobalRoleCacheTest` 3 passed (10 assertions), `FilamentPanelAccessTest` 9 passed, `MediaConversionsTest` 46 passed, `SubmitEventMediaTest` 3 passed, `FilamentMediaAssetTest` 3 passed; app/package Pint and PHPStan level 6 clean.
- Review: warm admin TTFB after deploy is ~0.51–0.60 s for dashboard/events/persons; dashboard LCP 1,754 ms; dashboard now fires three initial lazy-widget requests and no 5 s refresh loop; SPA navigation stays on one document and prefetches on hover. Remaining infrastructure win (requires nginx write access): enable `http2 on` and long-lived `Cache-Control` for `/build/assets` and `/js/filament`.
