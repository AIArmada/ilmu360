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
