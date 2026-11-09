# Ramadan closing majlis mobile hero rotation (2026-11-08)

- [x] Scope the dedicated mobile hero to the requested event and occurrence slug.
- [x] Generate three portrait Ramadan visuals and save optimized WebP assets.
- [x] Add a repeating mobile-only crossfade; keep desktop art unchanged and honor reduced-motion preferences.
- [x] Review scoped diff and generated asset sizes.

Review: the three dedicated mobile hero images are 105 KB, 61 KB, and 75 KB. The rotation is limited to the requested closing-night occurrence; desktop and other event pages retain their existing backdrop. CSS disables rotation for reduced-motion users. `git diff --check` and the frontend production build pass.

# Todo — Institution search: one request + direct paginated search (2026-11-08)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove the double Livewire request on `/institusi` search and replace the “resolve every matching ID then `whereIn`” pagination with a direct paginated search.

**Architecture:** Merge `pages.institutions.results` into `pages.institutions.index`; extract the results Blade into a partial; add `InstitutionSearchService::applyPublicSearch()`; paginate the scoped base query with `count(*) over ()`; keep the fuzzy ordered-ID fallback. No `@island` in Stage 1.

**Spec:** Chat design approved 2026-11-08 (Stage 1), plus Muse Spark 1.3 Contributor review (approve with changes: merge yes, island no).

## Global Constraints

- Livewire 4 SFC; follow existing component conventions.
- No new dependencies.
- Keep `?search=` URL, location filters, followed-only, pagination, clear/Escape, follow cards, and next-event badges.
- No islands in Stage 1.
- Do not delete tests; migrate them.
- Run targeted Pest once, Pint, PHPStan level 6.
- Do not commit without user approval (AGENTS git safety).

## Review Focus

- Location-filtered direct search must not lose matches (the current `publicSearchIds` is unscoped and capped before location filtering).
- Multi-word/token and nickname semantics must match `searchNameOrNickname`.
- Fuzzy fallback must still return score-ordered IDs.
- Filter dropdowns must update after a filter change even though the results query now runs in the same request.
- Back/forward `?search=&page=` must restore the input, results, and page.

---

### Task 1: Add `InstitutionSearchService::applyPublicSearch()`

**Files:**
- Modify: `app/Support/Search/InstitutionSearchService.php`
- Test: `tests/Unit/SearchServiceFallbackTest.php`

**Interfaces:**
- Produces: `public function applyPublicSearch(Builder $query, string $search): Builder`

- [x] **Step 1: Write the failing tests** in `tests/Unit/SearchServiceFallbackTest.php`:
  - `it('applies the public institution search strategy to a scoped query')`: config `scout.driver=collection`; create verified `Masjid Biru` and rejected `Masjid Biru Hidden`; call `applyPublicSearch(Institution::query()->where('status', 'verified'), 'Masjid Biru')`; assert the verified ID is returned and the rejected ID is not.
  - `it('falls back to database institution search when public query typesense fails')`: anonymous subclass with `shouldUseTypesenseSearch(): true` and `searchIdsWithScout()` throwing; call `applyPublicSearch(Institution::query(), 'Masjid Biru')`; assert the institution ID is returned.
- [x] **Step 2: Run the tests to verify they fail**:
  `vendor/bin/pest tests/Unit/SearchServiceFallbackTest.php --filter="public institution search strategy|public query typesense"`
- [x] **Step 3: Implement `applyPublicSearch()`** mirroring `publicSearchIds()`: normalize, use Typesense when `shouldUseTypesenseSearch()` and healthy (catch + log fallback), otherwise `applyDatabaseSearch()`; return `whereRaw('1 = 0')` for an empty normalized query.
- [x] **Step 4: Run the tests to verify they pass.**

### Task 2: Add `InstitutionDirectoryQuery`

**Files:**
- Create: `app/Support/Search/InstitutionDirectoryQuery.php`
- Test: `tests/Feature/InstitutionDirectoryQueryTest.php`

**Interfaces:**
- Consumes: `InstitutionSearchService::applyPublicSearch()`, `publicFuzzySearchIds()`.
- Produces: `public function paginate(?string $search, array $locationIds, bool $followedOnly, array $followingInstitutionIds, int $page, array $paginationMeta, int $perPage = 12): LengthAwarePaginator`

- [x] **Step 1: Write the failing tests** in `tests/Feature/InstitutionDirectoryQueryTest.php`:
  - `it('paginates direct institution search without resolving every matching id')`: create 15 verified `Masjid Ujian NN` institutions; enable query log; call `paginate('Masjid Ujian', [], false, [], 1, ['path' => '/institusi', 'query' => []])`; assert total 15, 12 items, no `select "institutions"."id"` query, one `count(*) over ()` query, no `count(*) as "aggregate"`.
  - `it('falls back to fuzzy search when direct search has no matches')`: create `Masjid Al Hidayah`; call `paginate('Hidayh', ...)`; assert total 1 and the item name.
  - `it('applies location scope to direct search')`: create two institutions in different states with the same searchable name; pass the matching state ID in `locationIds`; assert only the matching institution.
  - `it('preserves multi-word token matching')`: create `Masjid Besi Putrajaya`, `Masjid Al Hidayah`, `Pusat Komuniti Besi`; call `paginate('masjid besi', ...)`; assert only `Masjid Besi Putrajaya`.
- [x] **Step 2: Run the tests to verify they fail** (class missing).
- [x] **Step 3: Implement `InstitutionDirectoryQuery`** by moving the results component's query methods (`baseInstitutionsQuery`, `scopedInstitutionsQuery`, `applyFollowedOnlyFilter`, `applyLocationScope`, `upcomingOccurrenceCountSubquery`, `nextPublicEventQuery`, `attachNextPublicEvents`, `nextPublicEventDetails`, `filterSearchIdsToCurrentScope`, `orderedIdPaginator`, `hydrateOrderedIds`, `emptyPaginator`). Replace `directSearch`/`paginateDirectMatches` with `paginateDirectSearch` using `applyPublicSearch()` + `count(*) over ()` + `forPage()`. Keep `fuzzySearch()` using `publicFuzzySearchIds()` and score-ordered IDs. Use the passed `paginationMeta` instead of `request()`.
- [x] **Step 4: Run the tests to verify they pass.**

### Task 3: Merge the results into the index component

**Files:**
- Modify: `resources/views/components/pages/institutions/⚡index.blade.php`
- Create: `resources/views/components/pages/institutions/partials/results.blade.php`
- Delete: `resources/views/components/pages/institutions/⚡results.blade.php`
- Test: `tests/Feature/InstitutionIndexTest.php`, `tests/Feature/InstitutionResultsTest.php`, `tests/Feature/DirectoryCardFollowTest.php`

- [x] **Step 1: Update the tests first** (behaviour-preserving migration):
  - `InstitutionResultsTest.php`: target `pages.institutions.index`; replace `call('syncFilters', [...])` with `set(...)`; replace `Livewire::test('pages.institutions.results', ['filters' => ...])` with `Livewire::test('pages.institutions.index', ['search' => ...])`; drop the `syncFilters` non-string test (no `syncFilters` remains).
  - `InstitutionIndexTest.php`: replace the three `assertDispatched('institution-filters-updated')` tests with state/result assertions (`toggleFollowedOnly` shows the followed institution; country change filters the results; `clearFilters` restores the unfiltered list).
  - `DirectoryCardFollowTest.php`: institution dataset becomes `['institution', 'pages.institutions.index', 'followingInstitutionIds']`; replace the institution branch's `dispatch('institution-filters-updated', ...)` with `set('followed_only', true)` for all types.
- [x] **Step 2: Run the three test files** to establish the pre-merge baseline.
- [x] **Step 3: Merge the PHP logic** into `⚡index.blade.php`: add `WithPagination`, `followingInstitutionIds`, `institutions()` computed via `InstitutionDirectoryQuery`, `normalizedSearch()`, `toggleFollow()`, `hydrate()`, `refreshFollowedListing()`, `paginationMeta()`. Replace every `syncResults()` with `resetPage()`. Remove `filterPayload()` and `syncResults()`. Keep `locationIds()` building the slug array directly.
- [x] **Step 4: Extract the Blade** from `⚡results.blade.php` (the part after `?>`) into `partials/results.blade.php`; include it from the index; change `$parent.clearFilters()` to `clearFilters`; set `$institutionLoadingTarget` to `'search,country,state,city,locality,district,subdivision,areas,followed_only,toggleFollowedOnly,clearSearch,clearFilters,gotoPage,setPage,nextPage,previousPage'`; keep `wire:transition="institution-results"`. Delete `⚡results.blade.php`.
- [x] **Step 5: Run the three test files; fix failures.**
- [x] **Step 6: Run Pint** (`vendor/bin/pint --dirty --format agent`).

### Task 4: Verification

- [x] **Step 1: Run targeted Pest once**:
  `vendor/bin/pest --parallel tests/Feature/InstitutionIndexTest.php tests/Feature/InstitutionResultsTest.php tests/Feature/DirectoryCardFollowTest.php tests/Feature/InstitutionDirectoryQueryTest.php tests/Unit/SearchServiceFallbackTest.php --compact`
- [x] **Step 2: Run PHPStan level 6**: `vendor/bin/phpstan analyse --ansi`.
- [x] **Step 3: Review the diff** (`git diff --stat`, `git diff` on the touched files) and confirm no unrelated changes.
- [x] **Step 4: Ask the user before committing** (AGENTS git safety). Record the verification output in this section.

---

## Review — Institution search

- Merged `pages.institutions.results` into `pages.institutions.index`; the index now owns search, filters, results, pagination, follow state, and next-event hydration. The results Blade lives in `partials/results.blade.php`; the old child component is removed.
- Added `InstitutionSearchService::applyPublicSearch()` (Typesense when available, DB LIKE otherwise) and `InstitutionDirectoryQuery`; direct search now paginates the scoped query with `count(*) over ()` instead of resolving every matching ID and sending a huge `whereIn`. Fuzzy fallback still uses `publicFuzzySearchIds()` and score-ordered IDs.
- Search now produces one Livewire request per pause instead of a parent `$commit` plus a child `__dispatch`.
- Final review (Muse Spark 1.3 Contributor xhigh): Approve with changes; all Critical/Important findings fixed — the `hydrate()` lifecycle collision became `refreshFollowingIds()`, Typesense public search now passes the status filter (location cap documented), page-beyond-end coverage added for direct/fuzzy search, pagination metadata excludes `page`, mount uses the global owner context, and the district/subdivision alias invariant is documented.
- Verification: combined targeted Pest run 94 tests / 363 assertions passed. Pint passed on the changed PHP files. PHPStan on the changed files reported 0 errors; the full PHPStan run is blocked by the environment's 512M memory limit (parallel worker OOM), not by this change.
- Deferred minors: add search+followed_only combined regression; add `?search=&page=` back/forward restore test; add clearSearch/Escape regression test; link the <3-char fuzzy gate to `resolvedPublicSearchIds()` behavior with a comment.
- Note: the old `syncFilters` non-string test was dropped because the method no longer exists; the merged index's typed public properties replace that boundary. `InstitutionResultsTest.php` is retained and migrated to `pages.institutions.index`.
- Unrelated working-tree changes from the user were left untouched.

### Island optimization (2026-11-08)

- The search bar and results are two linked `@island(name: 'institution-directory', always: true)` regions. Both render together on search, clear/Escape, pagination, and empty-state actions; the hero, filters, and CTA are skipped.
- The empty-state “Clear Filters” action uses Alpine `$dispatch('institution-clear-filters')` handled by `#[On]`, so it forces a full render and resets the filter dropdowns. Direct filter changes still full-render the component.
- Key detail: a single island action skips the main component render, so `always: true` alone did not render the results island. Giving both islands the same name links them and renders both. Verified in Chrome MCP.
- Chrome MCP (staging, modified working tree): search “Masjid” → 1 request, 299ms, 2 island fragments, 112,985 bytes decoded / 9,414 transfer, no hero/filters; “Masjid Jamek” → 1 request, 270ms, 2 cards, 25,349 decoded / 4,356 transfer; pagination page 2 → 1 request, 333ms, 2 fragments, 31,444 decoded / 6,266 transfer; filter change → full render 65,170 decoded / 9,926 transfer (expected). Clear button, Escape, and empty-state clear filters all reset the input/URL/results. Console: no JS errors.
- Pagination links now pass `scrollTo => '#institution-results'`, so a page change scrolls to the first result row instead of the hero. Chrome: results top 736, scrollY 880 after page 2 (not 0).
- Tests: 97 passed / 370 assertions; new coverage for island markup, hero stability, query-string restore, pagination scroll target, and the empty-state clear-filters event. Pint passed; PHPStan changed files 0 errors.
- Final island review (Muse Spark 1.3 Contributor xhigh): Approve with changes; Important findings fixed — added a rendered assertion for the empty-state dropdown reset, added `selectGroupedSubdivisionLocality,__dispatch` to the loading targets, added island behaviour/URL regression tests, removed the per-morph `wire:transition`, and verified focus stays on the search input. Ruling: the empty-state `#[On]` listener already forces a full render, so no second Alpine window listener was added (it would double-fire).

### Directory island rollout (2026-11-08)

- `/penceramah`: search bar and results share the `person-results` island; the results root no longer uses `wire:transition`. Pagination already scrolled to `#person-results`. Tests: 52 passed / 208 assertions.
- `/rujukan`: search bar and results share the `reference-results` island; pagination now passes `scrollTo => '#reference-results'`; the results root no longer uses `wire:transition`. Tests: 19 passed / 82 assertions.
- `/majlis`: search form and results share the `event-results` island; the results root has `id="event-results"` and no `wire:transition`; pagination passes `scrollTo => '#event-results'`; the empty-state “View all events” button dispatches `events-clear-all-filters`, handled by `#[On]` on `clearAllFilters()` so the filter panel resets. Tests: 118 total, 112 passed, 6 pre-existing failures (Pending Approval badge text + event detail/gallery/share/registration), unchanged from before this rollout.
- Homepage: its hero search is an Alpine GET form that navigates to the directory pages; there is no Livewire search/result island to optimize there. The destination pages now carry the optimization.
- Chrome MCP (staging): `/penceramah` search “Ahmad” → 1 request, 713ms, 2 island fragments, 6 speakers, no hero; `/rujukan` search “Riyad” → 1 request, 1137ms, 2 fragments, 2 references, no hero; `/majlis` search “fiqh” → 1 request, 835ms, 2 fragments, 12 cards, no hero/filter panel; `/majlis` empty-state clear → search and URL reset, 18 cards restored. Focus stays in the search input; no JS errors.
- Pint passed; PHPStan on the changed events component reported 0 errors.

---

# Todo — Tetapan Hero (2026-11-07)

- [x] Generate desktop + mobile account-settings hero art with codex imagegen.
- [x] Give `/tetapan` the full-bleed courtyard hero and floating overlay header.
- [x] Pin the hero asset paths in the settings test, then run tests, Pint, PHPStan and browser probes.

## Review — Tetapan hero

- Art: `public/images/settings/account-settings-hero-{desktop,mobile}-v1.{png,webp}` — desktop 1672×941 (157 KB WebP), mobile 1024×1536 (152 KB WebP); subject is a brass clock, key on an emerald tray, and an emerald notebook (time, security, personal details).
- `/tetapan` now shares the `hero-field` picture/wash structure and `living-majlis-field` surface; the profile/notifications tabs sit in the hero, and the existing form cards remain unchanged below.
- Added `account-settings` to `$useHeroOverlayHeader` so the hero runs behind the floating menu pill.
- Verification: AccountSettingsPageTest 25/244 green (including new hero asset assertions); browser probes for profile, notifications and 390×844 mobile PASS with no JS errors; Pint green; PHPStan 0 errors. Evidence in `storage/verify/settings/`.
- Moved “Buka peti notifikasi” out of the hero into the top of the notifications content card, right-aligned above the mandatory notice; re-verified on desktop and 390×844 with the link present below the hero and no JS errors.
- Removed the redundant outer white content card and duplicate inner `max-w-6xl` wrapper; the profile Filament section and notification cards now sit directly on the `living-majlis-field` surface, aligned with the hero. Re-verified desktop, notifications, and mobile: 25 tests / 244 assertions green, no JS errors.
- Note: unrelated working-tree changes in `tests/Feature/AccountSettingsPageTest.php` (pre-existing extra tests) and `resources/lang/ms/legal.php` were left untouched and uncommitted.

# Todo — Legal Heroes (2026-11-07)

- [x] Generate separate desktop + mobile heroes for privasi, terma, hubungi kami with codex imagegen.
- [x] Wire per-page art into shared `pages/legal.blade.php` with per-page OG images.
- [x] Verify all three pages desktop + mobile, run LegalPagesTest, Pint, PHPStan, fresh-reviewer review.

## Review — Legal heroes

- Art (codex imagegen, built-in): `public/images/legal/{privacy,terms,contact}-hero-{desktop,mobile}-v1.{png,webp}` — desktops 1672×941, mobiles 1024×1536, WebP 148–192 KB.
- Subjects: privasi = carved screen + brass lockbox, terma = open book + seal + emerald volumes, contact = doorway + lantern + sealed envelopes; left ~60% empty cream for copy on all six.
- Template reuses the directory `hero-field` picture/wash pattern; unknown `pageKey` falls back to privacy art; tabs, contact email-reveal, and social buttons unchanged.
- Verification: browser probes PASS no JS errors on all three pages (desktop + 390×844 mobile each); LegalPagesTest 4/33 green; Pint green; PHPStan 0 errors; fresh-reviewer verdict “With fixes”, both Important items closed (mobile evidence captured, forced-colors recorded as inherited systemic limitation).
- Full-suite TIA run: 67 failures, all pre-existing environment issues (missing PostgreSQL 17 binary, Pango system libs, owner-context admin tests) — zero touch legal/privacy/terms/contact/feedback/hero paths.
- Evidence in `storage/verify/legal/`.

# Todo — Maklum Balas Page Polish (2026-11-07)

## Agent-friendly architecture migration (2026-11-07)

Authoritative active plan: [agent-architecture-migration.md](agent-architecture-migration.md). Historical ledger and completed-stage evidence: [agent-architecture-migration-history.md](agent-architecture-migration-history.md). This todo entry is only a pointer; do not grow a second migration ledger here.

## Review — Maklum Balas page polish

- `page_url` is no longer read-only; `?dari=tambah-majlis` now normalizes to `/tambah-majlis` and pre-fills the field. Helper copy explains it is editable.
- Removed the password/payment/personal-data warning from both MS and EN descriptions.
- `AppFeedbackCategory::options()` excludes the historical `ui_ux` case; `feature_request` and `ui_ux` both render as “Cadangan” for old records. Labels now “Ralat” and “Prestasi”.
- Hero images generated with codex `imagegen` (built-in), matching the `/penceramah` and `/institusi` warm, high-key courtyard style: `public/images/app-feedback/hero-desktop-v2.png` (1672×941) and `hero-mobile-v3.png` (1024×1536), with WebP variants (166 KB / 168 KB).
- The hero is now full-bleed with the shared `living-majlis-field` surface, cream left-to-right wash, dark emerald display heading, and directory-style CTAs; the form/history sit below in the existing `max-w-5xl` reading column.
- Responsive `<picture>` serves the recomposed portrait mobile art below 640px and the wide desktop art otherwise; the mobile art reserves the left 60% for copy.
- Verification: 76 AppFeedback tests passed (397 assertions); Pint passed; PHPStan level 6 0 errors; browser probe passed with no JS errors and screenshots at desktop + 390×844. Evidence in `storage/verify/app-feedback/`.
- Hero-behind-header: added `app-feedback.*` to `$useHeroOverlayHeader` in `resources/views/layouts/app.blade.php:153`, switching the feedback page from sticky to the absolute overlay header used by `/penceramah` and `/institusi`; hero art now runs edge-to-edge behind the floating menu pill. Re-verified with 76 AppFeedback tests passed plus a styled browser probe (no JS errors) at desktop and 390×844.
- `graphify` is not installed in this checkout, so `graphify update` could not run.

# Todo — Cloudflare Turnstile Existing Widget (2026-11-07)

Existing widget sitekey: `0x4AAAAAAF3NID96p30IuFuG`. Follow the Turnstile
existing-widget flow: no widget creation, guarded secret handling, canonical
siteverify, destination validation.

- [x] Wire the existing sitekey through env/config; keep the secret in the ignored `.env` only.
- [x] Enforce canonical siteverify: `success`, expected action, approved hostnames, token bounds, 10s timeout, fail closed.
- [x] Add explicit-render `action` and reset lifecycle for rejected/expired tokens.
- [x] Update captcha tests for action/hostname; add verifier regression coverage.
- [x] Run affected Pest once, Pint, PHPStan.
- [x] Store the widget secret (private secret card) and validate it with a dummy siteverify token.
- [ ] Validate one real protected submission and replay rejection (headless browser is blocked by Cloudflare; needs a human-solved token).

## Review — Turnstile existing widget

- Sitekey `0x4AAAAAAF3NID96p30IuFuG` is wired in the ignored `.env` with `TURNSTILE_ENABLED=true`, `TURNSTILE_EXPECTED_ACTION=submit_event`, and hostnames `ilmu360.masyuk.com,ilmu360.test,localhost,127.0.0.1,dev.ilmu360.com`; `.env.example` documents the same keys with safe blanks.
- The existing widget secret arrived through the private secret card, was validated against siteverify with `XXXX.DUMMY.TOKEN.XXXX` (`invalid-input-response`, not `invalid-input-secret`), and was written to `.env` without being printed by the write path.
- `TurnstileVerifier` now requires `success === true`, the expected action, an approved hostname, a non-empty token of at most 2048 bytes, a 10s timeout, and fails closed when config is incomplete. Exceptions return `false`.
- Explicit render now sends `action: 'submit_event'`, retains the widget ID, resets on expiry and on server-side captcha rejection (`turnstile-reset` dispatched by `Create::submit()`), and re-renders if Livewire replaces the container.
- The public API form contract exposes `captcha_action` so clients can request the same action.
- Verification: `TurnstileVerifierTest` 7 passed / 13 assertions; `SubmitEventCaptchaTest` 2/10; adjacent submit-event regressions 118 tests passed; `FrontendApiParityTest` 105/749; `ApiDocumentationSchemaSerializationTest` 2/11; Pint passed; full PHPStan level 6 reported 0 errors.
- Live `https://ilmu360.masyuk.com/tambah-majlis` renders the Cloudflare iframe and “Verify you are human” checkbox after `php artisan octane:reload`. The headless browser cannot complete the challenge, so a real-token protected submission and replay rejection remain pending.
- Pre-existing package mismatch (missing `EventOccurrenceFormExtension` / `is_featured` support) was resolved by the user merging `audit/filament-performance-20261104` into commerce `main` (`eb520363b`); full PHPStan is now clean.
- **Security note:** `php artisan config:show services.turnstile` printed the widget secret into this session transcript. The old secret should be rotated in Cloudflare if this transcript is retained or shared, then the new value stored in `.env` and Octane reloaded.

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


## Admin performance recovery and full-menu review (2026-11-04)

- [x] Recover earlier Clockwork measurements and confirm login availability.
- [x] Complete safe performance fixes, preserve report coverage and cache isolation.
- [x] Verify authorization freshness, report behavior, static analysis, and full-menu HTTP sweep.

Scope: retain existing access rules, report populations, filters and navigation. Check every registered list/report route under both saved admin sessions; do not apply additional database changes or replace the server driver.

Review: completed GET sweeps for all 128 registered list/report routes: admin 93 successful /35 permission denials; super-admin 126 successful /2 permission denials; no server errors in either final full sweep. Median successful TTFB: admin 686ms, super-admin 773ms (historical baseline 3527ms; historical page set differs by fixed errors). Current responses omit Clockwork IDs, so query metrics are explicitly unavailable rather than inferred from older records.

Finished request authorization caching with connection/team isolation, mutation/rollback invalidation and worker/job resets; report summary/details reuse one computation and invalidate on changed filters. Restored original report population limits and cities sorting. Analytics caches preserve viewer timezone/locale and reporting definition. Share affiliate owners eager-load. Navigation components search on demand with explicit whitelist validation and per-page option memo; final warmed navigation GET was 2681ms with ~6.15MB HTML (static-options intermediate was ~10.61MB). Navigation remains a heavy form; retention remains ~2.1s. Fixed invalid event postponement icons from earlier work.

Verification: app analytics/access 22 tests/62 assertions; authz 6 cache tests/26 assertions plus 16 existing freshness tests passed; Signals 11 tests/73 assertions; report pages 6 tests/14 assertions; navigation initial 30 passes plus both repaired regressions and final 5 async/whitelist tests passed. App and changed package PHPStan level 6 clean; Pint/diff checks clean. Initial test discovery and analysis memory/tool invocation failures were corrected before final successful runs.

Runtime: staging config cache refreshed and FrankenPHP workers restarted successfully through the documented worker-restart endpoint (HTTP200); Octane configuration PATCH disconnected and supervisor recovered before final verification. Live login and final hotspot GETs are healthy. Chrome MCP fallback renders Events, People and Retention; reused-session asynchronous419 means interactive save/filter browser verification remains incomplete. No new schema changes applied during this continuation; pre-existing untracked migrations/backups preserved. UI tracking review: selector search is presentation of existing settings workflow; existing save/audit behavior retained, no additional product event required.

Evidence: `storage/verify/admin-performance/` contains sanitized route TSVs, summary JSON and browser measurements. Test/analysis logs are in `/tmp/admin-*-tests*.log`, `/tmp/admin-*-phpstan.log` and `/tmp/admin-resumed-app-tests.log`.

## Featured occurrences — occurrence-only curation (2026-11-04)

- [x] Move the featured flag from `events` (EventAttribute `is_featured`) to `event_occurrences.is_featured`; edit the package migration in place.
- [x] Remove every event-level featured path: Event accessor/scope/fillable/EAV sync, admin event toggle, dashboard stat, event API payload, admin API/MCP fields, docs.
- [x] Add an occurrence form extension seam to `filament-events`; expose a global-admin-only “Feature this occurrence” toggle and read-only table column/filter.
- [x] Enforce global-admin-only writes through a dedicated occurrence observer; keep factories/seeders/console writes working.
- [x] Redefine homepage selection: featured occurrences lead, followed-event occurrences fill the remaining slots, guests see only featured occurrences.
- [x] Update factories and tests; add regression coverage for occurrence-only curation, admin authorization, and homepage selection.
- [x] Run Pint, PHPStan level 6, focused Pest; verify no event-level `is_featured` references remain.

Review: featured curation is now occurrence-only. `event_occurrences.is_featured` is the single storage location; the event-level `EventAttribute` accessor, `Event::featured()` scope, event admin toggle, event API/MCP/API-schema fields, dashboard label, and stale docs are gone. The homepage selection reads featured occurrences directly, constrains the featured branch to public schedule status/visibility, and only lets followed-event occurrences fill remaining slots (guests therefore see featured occurrences only). The occurrence admin form receives a `filament-events` extension seam; the app registers a global-admin-only toggle, and a dedicated observer rejects occurrence-featured writes from non-admin users. Table support: featured icon column + ternary filter. Local dev schema was synchronized with a one-off `ALTER TABLE` matching the edited package migration; tests migrate from the edited migration. Verification: final consolidated 55 tests/386 assertions, 4 MCP event write tests/85 assertions, 15 commerce package tests/448 assertions; PHPStan 0 errors app + commerce. Full AdminApiTest had 3 pre-existing `RewardAppealPolicy::create()` signature failures; full MCP run had 2 pre-existing failures (viewer manifest, prayer offset). Two unrelated `DashboardPagesTest`/`EventShowPageTest` copy/venue failures surfaced in the broader run and are outside this change.

## Repeated Livewire 419 after browser reset (2026-11-04)

- [x] Capture exact exception safely and reproduce the failed request.
- [x] Fix the confirmed cause; verify session and Livewire behavior.
- [x] Remove temporary diagnostics and record evidence.

Review: clean Chrome reproduced automatic notification calls returning empty 419 on both admin login and public homepage. Temporary exception diagnostics confirmed `Livewire\Exceptions\MethodNotFoundException` for `pullNotifications`. Published Filament notification JS was stale relative to installed filament/notifications v5.11.0 PHP (`pullNotificationsFromSession`). Ran `php artisan filament:assets --no-interaction` to synchronize all published Filament assets; added the same command to `scripts/deploy.sh`. Removed temporary bootstrap diagnostics completely (no net bootstrap change). Final real-Chrome checks: admin/public notification calls 200, login authenticate validation 200, public filter refresh 200, authenticated admin dashboard GET and automatic Livewire call 200; no expired dialogs or page errors. Published notification asset matches vendor byte-for-byte; shell syntax and scoped diff whitespace checks pass. No PHP source changes or new dependencies. UI tracking: asset repair restores existing workflows; no new product transitions. Earlier cookie/snapshot diagnoses were unproven; worker restart alone does not invalidate unchanged release tokens.

## Cached notification script recovery (2026-11-04)

- [x] Confirm repeated homepage GETs/419s without fresh notification asset requests.
- [x] Version Filament scripts by content and cover changed bytes under the same package version.
- [x] Reload workers and verify recovery with a browser holding the old script.

Review: repeated user homepage requests after asset republishing made no new notification script request; the script URL still used only `?v=5.11.0.0`. Added `ContentVersionedJs`, bound through Filament's container-backed `Js::make`, to append a hash of local source bytes to package versions. Remote URLs remain unchanged; no request/host state is retained in workers. Reloaded FrankenPHP. Controlled Chrome reproduction served the old script at the old version URL: 419 and expiry dialog. Same browser session, normal reload using the new content-versioned URL: notification call 200 and no dialog. Focused Pest tests cover stable/changed bytes under the same package version, actual notification registration, and unchanged remote URLs. PHPStan level 6 passes. This is an asset-cache fix, not a session-domain change. UI tracking: no new user workflow or event.

## Featured occurrence not on homepage — staging diagnosis (2026-11-04)

- [x] Verify the staging occurrence row: `01a18ea4-d677-73c4-8f46-583b951d5cd4` has `is_featured = true`.
- [x] Confirm the first exclusion reason: occurrence runs 2026-01-05 (past); past occurrences are never shown.
- [x] Confirm the second exclusion reason: occurrence runs 2026-11-25, outside the 14-day recommendation window.
- [x] Split selection rules: featured occurrences are filtered by “upcoming” only; the 14-day window now applies only to followed-event filler.
- [x] Prove with `homepageSelection()` and the live homepage that `01a196f5-027a-72b5-b61b-45d3dae8cebb` now appears.
- [x] Add admin helper text: past occurrences warn that they will not appear; future occurrences state that featured upcoming occurrences appear. Tested via `EventOccurrenceFeaturedAdminTest` (5 passed / 11 assertions).
- [x] Add regression coverage for featured occurrences beyond 14 days and filler inside the window. Final focused suite: 58 passed / 391 assertions.

## Website legal pages rewrite (2026-11-05)

- [x] Review the legal page renderer, existing Malay/English copy, platform data and tracking practices.
- [x] Check current Malaysian PDPA notice guidance and amendment commencement dates.
- [x] Rewrite the Terms and Privacy Notice in Malay and English, matching platform registration, payments, user content, cookies, retention and user choices.
- [x] Check PHP syntax and scoped diff whitespace; no behavior tests added for this copy-only change.

Review: after a full editorial pass, the privacy copy focuses on the data collected, purposes, sharing, cookies/location, retention and user choices. The Terms focus on platform and organiser roles, registrations/payments, content permissions, acceptable use and consumer rights. The legal operator’s registered name and postal address are not in the project; per user direction, the pages identify the ilmu360° brand and admin@ilmu360.com only. Add the registered operator identity and address before treating the notice as fully compliant or legally approved.


## Simplify Dakwah dashboard route (2026-11-05)

- [x] Rename the route family paths and names to `/dakwah` and `dakwah*`.
- [x] Update application links and existing route references in tests and route inventory docs.
- [x] Review scoped diff and confirm no old route paths/names remain in application code.

Review: the route was simplified from the dashboard-prefixed variant to the final `/dakwah` route family. All three authenticated routes use `/dakwah`, `/dakwah/links`, and `/dakwah/links/{link}` with `dakwah*` names; application links, route-based tests, and route inventory references are updated. `php artisan route:list --path=dakwah` shows all three routes, Pint passes, and `git diff --check` passes. Component and view filenames remain unchanged. Test runs: `Wp1TrustBoundaryTest` 29/29 passed and `ShareTrackingTest` 47/47 passed; `DashboardPagesTest` has one pre-existing failure at `renders the account settings notifications tab` (asserts copy that exists nowhere in the current views or lang files), unrelated to the route rename.

## Rename the Dakwah feature (2026-11-05)

- [x] Rename Livewire classes to `DakwahIndex` and `DakwahLinkShow`, with matching view files and view keys.
- [x] Rename `dakwahImpactSummary()` to `dakwahSummary()` and update dashboard bindings.
- [x] Rename the feature test to `ShareTrackingTest` and the operations runbook to `dakwah-operations.md`.
- [x] Replace the old display copy, translation keys/values, and live documentation references.
- [x] Verify routes, class loading, Pint, and affected tests.

Review: the feature is now simply "Dakwah" throughout the live application. Classes are `DakwahIndex`/`DakwahLinkShow`, views are `dakwah-index`/`dakwah-link-show`, the dashboard summary method is `dakwahSummary()`, the feature test is `ShareTrackingTest`, and the runbook is `dakwah-operations.md`. UI label and translations are `Dakwah`/`Dakwah`/`الدعوة`. Route URLs and names remain `dakwah*`. Historical task logs, `tasks/lessons.md`, `docs/superpowers` specs, and `docs/trash` archives may retain the old name.

## Remove the legacy share layer, keep Dakwah as the analytics page (2026-11-05)

- [x] Rename the legacy share classes/middleware to `Share*` and the share panel component to `share-panel`.
- [x] Consolidate the legacy share config into `config/share-tracking.php` and update config keys.
- [x] Rename the web share route names to `share.payload`/`share.track`/`share.redirect`; keep `/kongsi/*` URLs unchanged.
- [x] Rename the feature test to `ShareTrackingTest` and update live documentation references.
- [x] Preserve cookies, headers, env vars, DB enum values, and the share-member hash salt.
- [x] Verify routes, autoload, Pint, and affected tests.

Review: `Dakwah` is now only the analytics page (`DakwahIndex`/`DakwahLinkShow`). The share machinery uses `ShareController`, `ShareOutcomeType`, `ShareSubjectType`, `TrackShareAttribution`, the `share-tracking` config, `share.*` route names, `ShareTrackingTest`, and the `share-panel` Blade component. Kept: `/kongsi/*` URLs and enum backing values. Cookies, headers, env vars, member salt, and the signal event name were later rebranded to `i360_*`/`SHARE_*`/`share.*`; see the i360 contract section below. `route:list` resolves the renamed routes; `composer dump-autoload` was run; Pint and `git diff --check` pass. Focused tests: `ShareTrackingTest` 47/47, `AdminShareAnalyticsPageTest` 10/10, `SignupAttributionTest` 12/12, `SignupAttributionSourceResolverTest` 31/31, `SubmitEventCompletionAtomicityTest` 13/13. Pre-existing failures remain unrelated: `ShareAnalyticsApiTest` and `SignalsIntegrationTest` still treat the `/k/{slug}` short link as a 200 landing instead of following its 302; `ShareCards` tests expect 200 from card routes that redirect to placeholders; one `ShareTracking` race test needs the PostgreSQL 17 binary. Historical `docs/superpowers` plans/specs, `docs/trash`, and `tasks/lessons.md` may retain the old name.

## Rebrand share contracts to i360 (2026-11-05)

- [x] Rename cookies to `i360_share`, `i360_guest`, and `i360_guest_claim`.
- [x] Rename headers to `X-Guest-Credential` and `X-Claim-Capability`.
- [x] Rename env vars to `SHARE_SIGNING_KEY` and `SHARE_REPORTING_START`.
- [x] Rename the member hash salt to `share-member-v1` and the member code prefix to `I360`.
- [x] Rename the committed-share signal event to `share.committed_share`.
- [x] Move the local examples to `app:share:activate-local-examples`, `share-points-v1`, and `ilmu360-share-community`.
- [x] Remove legacy table guards and stale legacy references from live docs and tests.
- [x] Audit remaining `Dakwah` references and confirm they are page-only; remove all legacy share aliases.
- [x] Re-run share tracking, guest claim, short-link, analytics, and config tests.

Review: all live share contracts now use i360/share naming with no compatibility aliases. `.env` and `.env.example` use `SHARE_REPORTING_START`; the share-member hash salt is `share-member-v1` with an `I360` code prefix; the committed-share signal is `share.committed_share`; local examples use `app:share:activate-local-examples`, `share-points-v1`, and `ilmu360-share-community`. Live code and docs retain `Dakwah` only for the analytics page (`/dakwah`, `DakwahIndex`, `DakwahLinkShow`, `dakwahSummary`, page labels, and page docs). Historical `tasks/lessons.md`, `docs/superpowers`, and `docs/trash` still contain old names as archives. Verification: `ShareTrackingTest` 46/46 (legacy table guard removed), `ActivateLocalShareExamplesTest` 4/4, `RefactorTest` 3/3, `TicketAffiliateCommissionTest` 10/10, `ShareTracking` 139 passed with 46 PostgreSQL-binary errors and 1 related failure, `SignupAttributionTest` 12/12, `SignupAttributionSourceResolverTest` 31/31, `RewardEventWiringTest` 6/6, `RewardActivityFactsTest` 11/11, `ReferralInviteLandingTest` 4/4. Pre-existing unrelated failures: `ShareAnalyticsApiTest` short-link 302 assertion; `RewardDeliveryTest` date-sensitive cohort and digest-trigger expectations.

## Fix Dakwah member experience (2026-11-05)

- [x] Confirm Dakwah remains the sharing analytics page, with separate invitation/reward workflows.
- [x] Correct dashboard invitation navigation; connect Dakwah, invitations, and rewards.
- [x] Put sharing actions before analytics and allow re-sharing from link rows using the shared panel.
- [x] Withhold unconfigured measurements consistently, fix activity/channel semantics, remove internal identifiers, and reduce repeated queries.
- [x] Verify focused regressions, PHPStan, formatting, and frontend build; record results.

Review: Dakwah stays the private sharing report. Added prominent browse/share, personal invitation and reward destinations; dashboard invitation CTA now opens the invitation form, with reciprocal navigation. Reused the existing share panel on index/detail so outbound tracking stays on the existing engine; invitation outcomes retain existing server-side Signals. Reduced hero to four metrics with counting explanations, removed internal subject keys and old impact copy, localized additions, and improved responsive grids/focus/contrast. Measurements and measurement filters are withheld consistently before reporting is configured. Actual outbound touchpoints determine last-shared time; direct/unknown channels reconcile totals. A request-computed link library and batched measurement queries remove per-link query growth without cross-request caches. Daily bucket labels use the same viewer timezone as aggregation.

Validation: 53 focused tests passed across ShareTrackingTest, DakwahPageTest, DakwahAnalyticsSemanticsTest, DakwahReportingStateTest and the dashboard CTA regression (50 passing in the combined corrected run, the corrected channel fixture passed separately, and 2 reporting-state/timezone regressions passed). Full PHPStan level 6: zero errors using temporary CLI-only memory settings; Pint, production Vite build and git diff --check passed. The broader initial dashboard run exposed an unrelated existing account-settings assertion expecting “Manage your account and notifications from one place.”; account-settings code was not changed. No dependencies or database schema changed.

## Reconcile every Dakwah count (2026-11-05)

- [x] Map every dashboard/Dakwah summary, library, subject, channel, daily and response count to its canonical source.
- [x] Audit reporting windows, ownership/claimed guest profiles, distinct browser identity, reversals, deduplication and invitation/reward boundaries.
- [x] Fix discrepancies and add explicit known-total reconciliation regressions.
- [x] Run focused tests once, formatting/static analysis and record verified invariants and remaining limitations.

Count-audit review: traced every Dakwah-related dashboard and Dakwah index/detail value through its component, DTO, query and committed source. Fixed additional defects: invalid/relative/impossible reporting-start dates now withhold centrally; every Dakwah fact query stops at read time; detail facts must belong to the same affiliate as their link; attribution-only browser channels remain visible; report URL resolution is authorized/read-only and no longer prepares extra member links from claimed guest reports or fails on deactivated history. Dashboard labels now identify responses/visits/account signups/browsers exactly, with withheld states instead of zero success claims. Removed fabricated active-activity badges and explained counters honestly.

Verified metric map:
- Outbound shares: committed outbound_share touchpoints; prepared link counts are separate identities.
- Visits: committed visit touchpoints (landing/return/navigation, ingestion dedupe retained), not people or delivered messages.
- Browsers: unique nonempty frozen visitor_key union from landing attributions and visits. Channel/browser counts overlap and are intentionally non-additive.
- Signups, registrations, check-ins, submissions: matching conversion_type with qualified/approved/paid status; total responses includes all 11 action types, not sessions, current following/saved counts, or rewards.
- Library/type/subject counts: source-link groupings; additive activity totals reconcile where links survive. Historical null-link facts remain in overall/channel totals and are disclosed, not silently dropped.
- Detail outcome and channel breakdowns reconcile with its summary. Recent responses are limited to 12 globally/20 per detail; recent visits to 20. Daily breakdown is 14 viewer-local calendar days, with start/read bounds and DST tested. Library filters do not alter global summaries.
- Marked primary member plus actively claimed guest history only; other members/commercial profiles/revoked claims excluded. Deactivation/attribution expiry stop future credit, not historical reporting. Reversed/rejected/pending conversions excluded from valid counts without deleting history.
- Referral acceptance/qualification and reward ledgers are independent; recorded share responses are not invitation totals or balances. Existing signup/referral/reward integration tests cover their interaction and reversal/idempotency paths.

Proof: 168 distinct relevant tests verified (159 passed in initial integration run; nine failures were corrected and affected suites re-run: 74/74 passed). New reconciliation fixtures assert 3 outbound shares, 5 visits, 3 global browsers, 12 valid responses across all 11 action types, excluded statuses, exact boundaries, ownership, guest claim/revocation, deactivated history, orphan history and 25-hour DST buckets. Seven initial failures came from a relation-callback type error caught/fixed by static analysis and runtime tests; two test expectations were corrected for claim-created recipient profiles and retained unknown-channel browser evidence. PHPStan level 6 zero errors, Pint, production build and git diff --check pass. Validation uses isolated records plus real-ingestion integration tests, not a production database export.

## Rename the page to the Malay spelling (2026-11-05)

- [x] Rename the page routes to `/dakwah*` with matching `dakwah*` route names.
- [x] Rename the page classes, views, and summary method to the `Dakwah` spelling.
- [x] Update UI labels, translation keys, internal links, tests, and live docs.
- [x] Rename the operations runbook to `dakwah-operations.md`.
- [x] Audit for remaining English-spelling references and verify routes, autoload, Pint, and page tests.

Review: the page now uses the Malay spelling throughout. Routes are `/dakwah`, `/dakwah/links`, and `/dakwah/links/{link}` with `dakwah`/`dakwah.links`/`dakwah.links.show` names; classes are `DakwahIndex`/`DakwahLinkShow`; views are `dakwah-index`/`dakwah-link-show`; the summary method is `dakwahSummary()`; the runbook is `dakwah-operations.md`. The UI/translation key is `Dakwah` (English value `Dakwah`, Malay/Indonesian `Dakwah`, Arabic `الدعوة`). Six page test files were renamed to the `Dakwah` spelling. `route:list`, Composer autoload, Pint, and `git diff --check` pass; page tests pass except the known pre-existing `DashboardPagesTest` account-settings copy failure. Live code, filenames, and docs have no remaining English-spelling references; only `docs/trash`, `docs/superpowers`, and `tasks/lessons.md` retain historical spelling.

## Match the About 360° collage to the supplied reference (2026-11-05)

- [x] Crop the photo-and-compass portion of the supplied reference and generate a matching six-ribbon photo collage.
- [x] Rebuild the section proportions, photo overlay labels, and central compass to follow the reference.
- [x] Build frontend assets and attempt a desktop/mobile page preview.

Review: the page section follows the supplied crop with a narrow editorial copy column, three paired photo ribbons, six localized HTML labels, and a compass with “360°” baked into the collage image. `npm run build` and scoped `git diff --check` pass. Desktop and mobile captures from the user-provided URL were inspected with local Playwright; the shared preview browser is blocked by the host's AppArmor policy.

## Blend the About closing cover into adjacent page colors (2026-11-06)

- [x] Generate an ultra-wide closing cover with soft top and bottom edges that match the surrounding ivory and sage-ivory backgrounds.
- [x] Update the closing banner to use the new cover without a visible crop seam.
- [x] Build and inspect the banner at desktop and mobile widths.

Review: added `closing-continuous.webp`, a 3:1 sunrise panorama based on the screenshot and prior cover. Its upper edge fades to the page's warm ivory, while the lower landscape fades to the footer's pale sage-ivory. The closing section now uses matching top/bottom gradient colors so the transition stays smooth after the responsive crop. `npm run build` and scoped `git diff --check` pass; desktop and mobile captures were inspected from the supplied page URL.

## Fade the homepage community CTA into the footer (2026-11-06)

- [x] Inspect the homepage CTA, footer gap, and separate desktop/mobile covers.
- [x] Generate desktop and mobile CTA background variants with a lower ivory fade.
- [x] Add a matching page-color fade below the CTA content and verify desktop/mobile rendering.

Review: the homepage now uses new desktop and mobile lake covers with lower-edge fades into `#f8f7f1`. A final CSS fade finishes at the same color as the homepage surface, below the CTA content, so both crops transition into the footer gap without washing out the benefit labels. `npm run build` and scoped `git diff --check` pass; desktop and mobile captures were inspected.

## Give the homepage community CTA more vertical room (2026-11-06)

- [x] Increase the responsive section minimum heights and loosen spacing between the CTA copy and benefit items.
- [x] Rebuild and inspect the taller desktop and mobile layouts, including the bottom fade.

Review: increased the CTA to 32rem at desktop, 36rem at tablet, and 40rem on mobile; centered the desktop copy and added lower padding so the benefits clear the fade. `npm run build` passes. Playwright inspection confirms a 512px desktop section with 52px between benefits and fade, and a 640px mobile section with 49px clearance. CTA actions and benefits remain uncrowded on mobile.

## Feedback form usability fixes (2026-11-06)

- [x] Make category questions reactive and group optional details separately from the required summary.
- [x] Preserve drafts on failure and clear them only after confirmed submission; complete Malay/English guidance.
- [x] Verify category visibility, submission outcomes, localization, draft behavior, formatting, and static analysis.

Review: category changes now refresh the matching questions. The required summary is followed by a collapsed optional details section and a separate page/attachments section. Malay and English guidance match, and steps placeholders use real line breaks. Drafts restore category and written details, survive failed submissions, and clear only after confirmed success. All 72 feedback tests pass (383 assertions), full PHPStan analysis and scoped Pint pass, and scoped diff checks pass. Isolated authenticated desktop/mobile renders were inspected with no horizontal overflow and working detail expansion. Real Alpine draft save, failed-request retention, and success cleanup were checked in the isolated browser; live authenticated submission was not exercised because the available browser session requires Google login. Existing server-confirmed feedback Signals remain the outcome tracking seam.

## Match the About page journey to the reference (2026-11-06)

- [x] Inspect the About page journey strip against the supplied reference at desktop and mobile widths.
- [x] Refine the journey strip to match the five connected pills, gold icons, and centered caption.
- [x] Build and inspect responsive layouts and translated copy.

Review: the About journey is now a centered five-step strip with taller cream pills, gold line icons and chevrons, and the original centered caption framed by gold flourishes. The existing Malay copy remains in its About translation data. `npm run build` passes; Playwright inspection at 1440px, 390px, and 320px confirms all five labels and no horizontal overflow. The homepage CTA was restored after the user clarified the target page.

## Improve About page mobile usability (2026-11-06)

- [x] Inspect the full About page at phone and tablet widths; identify cramped layouts, image crops, and overflow.
- [x] Adjust responsive layouts and add a mobile-specific image where the desktop crop loses useful context.
- [x] Build and inspect at narrow phone, standard phone, tablet, and desktop sizes.

Review: the About hero and closing CTA now switch to portrait mobile artwork below 640px; the hero has enough height for its copy and keeps the family and mosque visible. Removed the About collage's 12px mobile bleed. `npm run build` passes, and Playwright inspection at 320px, 390px, 768px, and 1440px confirms the correct image variants load with no horizontal overflow.

## Refine the homepage community CTA mobile background (2026-11-06)

- [x] Generate a portrait CTA background composed around the mobile copy and benefits, with a clean fade into the footer color.
- [x] Wire the new image into the homepage CTA's mobile source.
- [x] Build and inspect the CTA at phone and desktop widths.

Review: added `community-lake-cta-mobile-v3.webp`, a 1122×1402 portrait scene with quiet lake space behind the mobile copy and a warm ivory fade into the footer. The CTA loads it below 640px and keeps the existing landscape art on desktop. Reduced the heading size on narrow phones to avoid a 4px overflow at 320px. `npm run build` and scoped `git diff --check` pass; Playwright inspection confirms the mobile asset at 320px and 390px, the desktop asset at 1440px, and no horizontal overflow.

## Center the final About feature card on mobile (2026-11-06)

- [x] Check the feature grid breakpoints and handle odd item counts.
- [x] Center the orphan card in the two-column mobile layout.
- [x] Create and use a portrait version of the 360° illustration on mobile.
- [x] Build and inspect the phone and desktop layouts.

Review: the last card now spans the two-column phone row at half width and stays centered; the class resets at wider breakpoints. Added a 3:4 mobile 360° collage with the compass integrated into the image and retained localized HTML labels. The illustration uses the portrait source below 640px and the original wide source at larger sizes. `npm run build` and scoped `git diff --check` pass. Playwright inspection at 320px and 390px confirms the final card center delta is 0 and there is no horizontal overflow; at 768px and 1440px the wide illustration remains selected.

## Keep mobile collage labels off the illustration (2026-11-06)

- [x] Show mobile labels beneath the 360° illustration instead of over the photos.
- [x] Preserve the desktop overlay labels and verify both responsive layouts.

Review: the six Malay captions now render in a two-column grid below the portrait illustration on phones, leaving all people and scenes unobstructed. The wide layout keeps its overlay pills. Playwright checks at 320px and 390px confirm all six captions sit below the image and there is no horizontal overflow; at 1440px the original overlay layout remains intact. `npm run build` and scoped `git diff --check` pass.

## Keep the About mobile portrait subject fully in frame (2026-11-06)

- [x] Give the mobile photo a taller crop and align it to preserve the man's head.
- [x] Keep the existing desktop image treatment and verify phone rendering.

Review: the mobile image now uses a 4:3 frame aligned to the top, preserving the full head and phone interaction at narrow widths. Desktop keeps its centered, auto-height crop. `npm run build` and scoped `git diff --check` pass; Playwright screenshots at 320px and 390px show the full subject.

## Align About hero copy across desktop and mobile (2026-11-06)

- [x] Right-align the About hero title, description, and quote on desktop.
- [x] Move the mobile hero copy lower without colliding with the photo subject.
- [x] Build and inspect the hero at phone and desktop widths.

Review: the hero title, description, and quote align right at desktop widths, including the quote border on the right. On phones the copy starts lower; the hero is taller to keep the quote clear of the family at 320px. `npm run build` and scoped `git diff --check` pass. Playwright inspection at 320px and 390px confirms the mobile image, and at 1440px confirms right alignment with the desktop image.

## Refine About hero alignment and mobile offset (2026-11-06)

- [x] Move the desktop hero copy container to the right edge, not only right-align its text.
- [x] Lower the phone copy slightly more while keeping it clear of the photo subject.
- [x] Build and inspect at narrow phone, standard phone, and desktop sizes.

Review: the desktop wrapper now sits flush with the hero's inner right edge and all copy stays right-aligned. Mobile copy moves down 16px; the 576px phone hero keeps a clear gap from the family at 320px. `npm run build` and scoped `git diff --check` pass. Playwright checks at 320px, 390px, and 1440px confirm the offsets, alignments, and no horizontal overflow.

## Give the About mobile hero a clear text-safe background (2026-11-06)

- [x] Generate a mobile portrait hero image with open sky behind the current copy position.
- [x] Switch only the mobile hero preload and image source to the new artwork.
- [x] Build and inspect phone and desktop hero rendering.

Review: added `page-hero-mobile-v2.webp`, with open sky behind the headline, body, and quote while keeping the family and mosque low in the scene. The existing mobile text placement is unchanged; the new image is used only below 640px, while desktop keeps its original background. `npm run build` and scoped `git diff --check` pass. Playwright inspection at 320px, 390px, and 1440px confirms the mobile variant loads and the desktop image is unchanged.

## Fix the About journey book icon (2026-11-06)

- [x] Keep the open-book SVG path inside its viewBox.
- [x] Build and inspect the journey icon on phone and desktop.

Review: the open-book icon now uses a symmetric path bounded by the 24×24 viewBox; the right page is no longer clipped. `npm run build` and scoped `git diff --check` pass. Playwright renders at 390px and 1440px show the complete icon, and the path bounds stay within the viewBox.

Migration history: [completed stage records](agent-architecture-migration-history.md). Current plan: [agent-architecture-migration.md](agent-architecture-migration.md).
