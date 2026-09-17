# Bug Hunt Report — ilmu360° (branch `refactor`)

**Date:** 2026-09-17 · **Mode:** Static review (no test suite run, per user instruction)
**Scope:** Uncommitted working-tree changes on `refactor`, application code, project rule compliance, and stale CI artifacts.

---

## Executive summary

| # | Finding | Location | Severity | Status |
|---|---------|----------|----------|--------|
| 1 | "This Weekend" filter drifts to Sun→Mon on Sundays | `app/Livewire/Pages/Events/Index.php:1803` | **High** (core search correctness, user-visible) | **Found & fixed, then reverted** (user requested find-only; the working tree is back to the original buggy state) |
| 2 | Stale CI failure logs misreport already-fixed bugs | `ci-failures.md`, `gh-pest-failures.txt` | Medium (process/time cost) | Reported |
| 3 | Outdated route in app-map documentation | `tasks/complete-app-map.md:180` | Low | Reported |
| 4 | Untracked, referenced 2 MB PNG assets | `public/images/` (2 files) | Medium (will 404 on fresh checkout; page weight) | Reported |
| 5 | Test files deleted in working tree | `tests/Feature/PersonEditTabsTest.php`, `tests/Feature/PublicMarketSelectorTest.php` | Low (process — needs user approval) | Reported |
| 6 | Date-filter chips formatted in storage timezone | `resources/views/livewire/pages/events/index.blade.php:799-802` | Low (latent; harmless for UTC+8 audience) | Reported |

The rest of the review found **no violations**: legacy-geography scan, DB-constraint scan, SoftDeletes scan, Livewire `wire:key` integrity, media accessor null-safety, and CSS accessibility (`prefers-reduced-motion`, `forced-colors`) all pass.

---

## 1. "This Weekend" date filter drift on Sundays — HIGH

**Where:** `app/Livewire/Pages/Events/Index.php`, private `weekendDateRange()` (line ~1798–1807, current HEAD state).

**The bug:**

```php
$weekendStart = ($today->isSaturday() || $today->isSunday())
    ? $today->copy()
    : $today->copy()->next(CarbonInterface::SATURDAY);
return [$weekendStart, $weekendStart->copy()->addDay()];
```

On a **Saturday** the range is Sat→Sun — correct. On a **Sunday**, however, the code anchors on *today* (Sunday) and adds one day, producing **Sun→Mon**:

- **Monday events leak into "This Weekend"** — events that already belong to *next week* are included.
- **Saturday events of the current weekend are excluded** — even though the user picking "Hujung minggu" on a Sunday almost certainly still wants to see what remains of the weekend.

The range is also asymmetric between the two weekend days depending on when a user visits — the same filter yields different windows mid-weekend.

**Verification performed:** simulated the date math for Thu 2026-09-17 through Mon 2026-09-21 (Asia/Kuala_Lumpur). Sunday 2026-09-20 produced `starts_after=2026-09-20`, `starts_before=2026-09-21` — i.e., Sunday→Monday. Every other weekday produced the correct Sat→Sun window.

**The fix that was applied and then reverted** (preserved at `/tmp/weekend-fix-backup.patch`):

```php
$weekendStart = $today->isSunday()
    ? $today->copy()->subDay()
    : ($today->isSaturday() ? $today->copy() : $today->copy()->next(CarbonInterface::SATURDAY));
```

Verified: every day of the week then resolves to Sat→Sun. Past Saturday events are not resurrected because the discovery layer (`PostgresEventDiscovery::startsAfterDateTime()`) clamps `starts_after` to `now()` for the `upcoming` time scope. **Current state: the file matches HEAD exactly; the bug is present.** Re-apply with the editing tool (not git) if desired.

**Risk of the bug in practice:** any Sunday visitor who selects "This Weekend" — one of seven days in the week — gets a wrong window both forward (Monday) and backward (Saturday already passed but still "this weekend" colloquially). Combined with saved-search URLs carrying `starts_after/starts_before`, the wrong window can be shared.

---

## 2. Stale CI failure artifacts misdescribing the codebase — MEDIUM

Two tracked files record old failures and no longer describe reality:

**`gh-pest-failures.txt`** (committed 2026-07-25, "44 failed tests across 10 shards") and **`ci-failures.md`** (2026-07-27). Spot-checks against the current code show most of their content is obsolete:

- **The 500 on institution show pages** (`MissingAttributeException: The attribute [position] either does not exist... MorphPivot`) existed when `Institution::persons()` was a `morphToMany` (verified in the historical commit tree). Today `Institution::persons()` is a plain `BelongsToMany` over the `affiliations` table with a standard `Pivot` (`app/Models/Institution.php:328`), and its `withPivot(['position', 'is_primary'])` columns are always selected. The failure mode is structurally gone.
- **The `admin-area-level-1` 404:** the admin catalog route is now `api/v1/admin/catalogs/administrative-districts` (verified via `php artisan route:list`), and no current test references the old path.
- **Area-validation failures** cite test line numbers that no longer exist; the tests have been rewritten (`it rejects unchanged person region-only address round trips...` etc.).

**Risk:** any agent or developer opening these files will chase ghosts. The July files describe 40+ failures of which the majority appear resolved; meanwhile *current* failures are undocumented anywhere.

**Recommendation:** delete both files (or replace with a fresh capture from one test run), and never commit failure logs as long-lived documents.

---

## 3. Outdated route in the app map — LOW

`tasks/complete-app-map.md:180` still documents `GET /v1/catalogs/admin-area-level-1` → `CatalogController@adminAreaLevel1`. That route no longer exists; the current equivalent is `api/v1/admin/catalogs/administrative-districts`. Same root cause as finding 2: the rename happened without sweeping the doc.

---

## 4. Untracked hero/placeholder images referenced by modified views — MEDIUM

The uncommitted view changes reference two images that are **untracked**:

| File | Referenced by | Size |
|---|---|---|
| `public/images/institutions/pusat-ilmu-hero-background-v1.png` | `resources/views/components/pages/institutions/⚡index.blade.php` (hero) | ~2.0 MB |
| `public/images/placeholders/institution-v2.png` | same view (card fallback) | ~2.1 MB |

Verified untracked via `git status` (`?? public/images/institutions/`, `?? public/images/placeholders/institution-v2.png`). If this working tree is ever cloned, reset, or branched elsewhere, both render as broken images.

**Secondary concern — page weight:** ~4 MB of PNG on the institution index page. The codebase already uses optimized WebP hero art (`penceramah-hero-art.webp` is ~150 KB vs the ~2.1 MB PNG sibling in the same folder). Converting both files to WebP would cut roughly 3.7 MB from the page with no visual change expected; the `<img>` tags already lack `srcset`/responsive variants, so a single optimized WebP is a pure win. (`loading="eager"` on the hero is intentional LCP behavior, fine as is.)

---

## 5. Test files deleted in the working tree — LOW (process)

`tests/Feature/PersonEditTabsTest.php` and `tests/Feature/PublicMarketSelectorTest.php` are deleted in the uncommitted changes. Flagged for awareness only: the project's rules forbid deleting tests without explicit approval, and these deletions belong to the user's (or another agent's) changes — not something I made or touched. Whoever commits the current tree should confirm the deletions are intentional (e.g., features removed during the refactor) rather than accidental.

---

## 6. Date-filter chips formatted in storage timezone — LOW (latent)

`resources/views/livewire/pages/events/index.blade.php:799-802` renders the active `Held from`/`Held until` chips with:

```php
\Illuminate\Support\Carbon::make($startsAfter)?->format('d M Y')
```

`$startsAfter`/`$startsBefore` are date-only strings (`Y-m-d`) and are parsed through `UserDateTimeFormatter::parseUserDateToUtc()` for querying — the correct pattern. But the chip formatting ignores the viewer's timezone: at any instant, the wall-clock date *behind* UTC is one day earlier than in storage. This is harmless for the product's UTC+8 Malaysia audience (storage-TZ output is effectively the local date there), which is why it was left untouched, but it violates the project's own timezone rule ("never `->format()` in public views unless storage-tz output is intended") and will silently break for any future international audience. Fix when touched next: `UserDateTimeFormatter::translatedFormat($date, 'd M Y')` or plain string formatting of the `Y-m-d` value.

---

## Verified clean (no action needed)

- **Legacy geography keys:** grep for `admin_area_[1-4]_id`, `state_area_id`, `district_id`, `subdistrict_id`, `stateArea(`, etc. across `app/ database/ resources/` — clean, matching `.ai/addressing` rules.
- **DB constraints:** no `->constrained()` or `->cascadeOnDelete()` in `database/migrations/` — the UUID-no-FK policy holds.
- **SoftDeletes:** none; `spatie/laravel-deleted-models` convention holds.
- **Uncommitted view/CSS work:** the `living-majlis` art-direction changes are internally consistent — all referenced CSS classes exist in `app.css` (45 occurrences), the reduced-motion block covers every animated selector including the new `living-majlis-header-button`, `forced-colors` handling is present, `wire:key` usage is correct, and the fallback chain `public_cover_url → public_logo_url → placeholder` is null-safe.
- **`Event::active()` scope:** resolves correctly via the Laravel `#[Scope]` attribute (`protected function active(Builder $query)` on `App\Models\Event`) — initially a false alarm during the hunt, confirmed present.
- **Home date-filter component:** counts events per user-local day via `UserDateTimeFormatter::format($event->starts_at, 'Y-m-d')` and passes matching date-only strings to the events index — correct timezone handling.

---

## Method and limitations

- **No tests or PHPStan were run** (user instruction). All findings come from reading code, git history comparison, route inspection (`php artisan route:list`), targeted runtime probes via `php artisan tinker --execute`, and isolated PHP simulations of date math.
- **Test failure logs were treated as stale after cross-checking** against the current commit tree (both artifacts date to July; the branch has 22 newer commits).
- The **weekend fix was applied, verified via date simulation, then reverted at the user's request** — it is preserved at `/tmp/weekend-fix-backup.patch` and can be re-applied manually with the editing tool if asked. `/tmp` is volatile; that backup disappears on reboot.
- Two other verification passes the user may want later but were out of scope here: a fresh full test run to establish *current* failures (the July logs are useless for that), and PHPStan level 6 per project policy.
