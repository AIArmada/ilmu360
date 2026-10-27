---
name: verify
description: "Drive the ilmu360° public web app the way a visitor does and capture proof. Use when asked to check, verify, confirm, or screenshot public behavior — home discovery, event search, event detail, or sign-in — before claiming a change works."
---

# Verify ilmu360°

This skill proves user-facing behavior in the public Laravel + Livewire web app the way a visitor experiences it. It runs each probe in a disposable application instance (SQLite `:memory:`, `RefreshDatabase`) with a real Chromium driven by Pest's browser plugin, so nothing touches the Herd site or its PostgreSQL data.

Read [`features/README.md`](./features/README.md) before driving. It holds the baseline preconditions, the feature index, and the rules for reporting proof.

## Launch

There is no server to keep alive. Each probe boots the application and Chromium inside one process, then tears everything down when the probe exits. "Launching" means preparing the pieces that survive between probes:

```bash
npm install
npx playwright install chromium
npm run build
```

- `npm install` must give Playwright `>=1.63.0` (currently `1.64.1`); older versions throw `PlaywrightOutdatedException`.
- `npm run build` writes `public/build/manifest.json`. Probes still render without it, but screenshots lose styling.
- PHP 8.5 or newer and Composer dependencies must already be installed.
- The testing environment is `.env.testing`: `APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`. Do not point probes at the Herd site.
- Ready means `bin/doctor.sh` prints `READY`. Each probe is independent; there is no shared instance to collide with.

Teardown is automatic. `RefreshDatabase` rolls back rows, and the browser plugin closes Chromium when the probe ends. If a probe hangs, kill only the process you started by PID; never kill by process name.

## Doctor

Run this read-only check first, and again whenever a probe looks wrong:

```bash
.claude/skills/verify/bin/doctor.sh
```

It checks:

- PHP is 8.5 or newer.
- `public/build/manifest.json` exists.
- `playwright` is installed at 1.63.0 or newer.
- A real Chromium probe loads `/`, sees `ilmu360`, and confirms a built stylesheet from `/build/assets/` is linked.

A non-zero exit prints the failing check and the command that fixes it. Do not drive the app until the doctor says `READY`.

## Drive

Every probe is a single Pest call with the snippet in single outer quotes:

```bash
vendor/bin/pest --agent='require base_path(".claude/skills/verify/bin/boot.php"); visit("/", ["timeout" => 30000])->assertSee("Dekat dengan")->screenshot(fullPage: false, filename: "verify-home");'
```

Rules that keep runs trustworthy:

- **One probe per invocation.** Run probes sequentially. Parallel browser runs interfere with each other.
- **Single quotes outside, double quotes inside.** Nothing is shell-expanded, so `$event`, `\App\Models\...`, and `!` reach PHP literally. Never wrap the snippet in double quotes and never hand-escape `\$`. If the PHP needs a literal apostrophe, write it to a file and run `vendor/bin/pest --agent="$(cat path/to/snippet.php)"`.
- **Always require `bin/boot.php` first.** `Tests\TestCase` calls `withoutVite()`, so unpatched probes render unstyled screenshots. `boot.php` rebinds a real Vite instance and ignores a stale `public/hot`. Do not delete or edit `public/hot`.
- **Give Chromium 30 seconds.** The plugin's default action timeout is 5 seconds, which is too short for first paint and large pages:

  ```php
  \Pest\Browser\Playwright\Playwright::setTimeout(30000);
  visit("/", ["timeout" => 30000]);
  ```

- **Create fixtures inside owner context.** Models are owner-scoped and throw `NoCurrentOwnerException` otherwise:

  ```php
  $event = withGlobalOwnerContext(fn () => \App\Models\Event::factory()->create([...]));
  ```

- **Screenshot the viewport, not the page.** `fullPage: false`. Full-page screenshots of event pages take 30 seconds or more.
- **Assert what the visitor sees.** Prefer visible copy, route paths, and rendered titles over internal state or test-only endpoints. The feature files give the tested selectors.

The scaffold for every probe:

```php
require base_path(".claude/skills/verify/bin/boot.php");
\Pest\Browser\Playwright\Playwright::setTimeout(30000);

$event = withGlobalOwnerContext(fn () => \App\Models\Event::factory()->create([
    "title" => "Verify Event",
    "status" => "approved",
    "visibility" => "public",
    "published_at" => now(),
    "starts_at" => now()->addDay(),
    "ends_at" => now()->addDay()->addHour(),
]));

visit("/majlis/".$event->slug, ["timeout" => 30000])
    ->assertSee("Verify Event")
    ->screenshot(fullPage: false, filename: "verify-event-detail");
```

Use the existing `tests/Browser/PlaywrightSmokeTest.php` and the feature files as working examples. Do not add a new permanent test file just to verify a change; `--agent` is the throwaway runner.

## Evidence

A probe proves behavior through two artifacts:

1. **Assertions.** The `vendor/bin/pest --agent=...` output shows each `assertSee`, `assertPathIs`, and `assertDontSee` that passed. Quote the probe and its result in the report.
2. **A screenshot.** `screenshot(fullPage: false, filename: "verify-<feature>")` writes `tests/Browser/Screenshots/verify-<feature>.png`.

Screenshots are deleted at the start of the next browser run. Copy the shot to the durable evidence directory before running anything else:

```bash
mkdir -p storage/verify/<feature>
cp tests/Browser/Screenshots/verify-<feature>.png "storage/verify/<feature>/$(date +%Y%m%d-%H%M%S).png"
```

Proof standards:

- Exercise the real visitor path: type into the search box, click the submit button, follow the link. Do not call Livewire methods or seed the final page state directly.
- Capture the action and the result. A screenshot of a page without the assertion that the visitor reached it is not proof.
- Check side effects as well as pixels. For sign-in, assert both the redirect to `/dashboard` and the dashboard content. For search, assert the match appears and a non-match does not.
- Report every entry point the feature map lists. A proof through one path does not verify the others.
- Report unreachable paths with the attempted command and the unmet precondition. Never weaken a journey to make it pass, and never report a skipped path as verified.

`storage/verify/` is gitignored and is the only artifact location cleanup must not touch.

## Cleanup

```bash
rm -f tests/Browser/Screenshots/*.png
```

- Rows disappear with the probe's transaction; no database reset is needed.
- Chromium is closed by the probe. If one is stranded, kill the PID you started, not every `chrome` or `node` process.
- Remove scratch files the run created in `storage/verify/` (JSON dumps, HTML captures), but keep every copied screenshot and report.
- Never delete `storage/verify/` itself. Proof must outlive teardown.

## Helpers

- [`bin/boot.php`](./bin/boot.php) — required first line of every probe. Restores built Vite assets so screenshots are styled.
- [`bin/doctor.sh`](./bin/doctor.sh) — read-only readiness check for PHP, assets, Playwright, and a live styled page load. Run it before driving and after changing dependencies or assets.
