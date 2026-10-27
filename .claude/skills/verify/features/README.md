# ilmu360° verification map

This directory is the maintained source for verifying the visitor-facing behavior of ilmu360°, the public events and knowledge-discovery web app at `https://ilmu360.test`. The primary surface is the public Laravel + Livewire site. The Filament admin panel at `admin.ilmu360.test` and the member panel at `ahli.ilmu360.test` are secondary surfaces that are not mapped yet.

Read this index before driving, then open the feature file that matches the journey.

## Baseline preconditions

- Work from the repository root.
- Run `.claude/skills/verify/bin/doctor.sh` and require `READY`.
- Drive through `vendor/bin/pest --agent='...'`; each probe boots a disposable app with `.env.testing` (`APP_ENV=testing`, SQLite `:memory:`, `RefreshDatabase`).
- Require `.claude/skills/verify/bin/boot.php` first in every probe so built CSS is linked.
- Create fixtures inside `withGlobalOwnerContext(...)`, or owner-scoped models throw `NoCurrentOwnerException`.
- Give Chromium `\Pest\Browser\Playwright\Playwright::setTimeout(30000)` and pass `["timeout" => 30000]` to `visit()`.
- The Herd site `https://ilmu360.test` uses PostgreSQL and persistent data. Never drive it; it is read-only reference.

## Driving conventions

- Start every recipe from the baseline state and its own `Preconditions`.
- Run one probe per invocation, sequentially. Do not add `--parallel`; concurrent Chromium runs interfere.
- Pass the PHP snippet in single outer quotes. Use double quotes for PHP strings so the shell does not expand `$variables` or backslashes.
- Prefer stable handles: input `name` attributes, visible button text, route paths, and rendered headings.
- Treat every command as literal. Keep titles, emails, and selectors unchanged.
- Capture the action and the destination, not only the final screen.
- A probe that creates rows leaves them to the transaction; no manual database reset is required.
- Do not edit `public/hot`. `bin/boot.php` ignores it because a stale Vite URL produces unstyled screenshots.

## Proof and skip reporting

- Every proof includes the probe command, the assertion output, and a viewport screenshot.
- Screenshots land in `tests/Browser/Screenshots/` and are deleted at the start of the next browser run. Copy them to `storage/verify/<feature>/<timestamp>.png` immediately:

  ```bash
  mkdir -p storage/verify/<feature>
  cp tests/Browser/Screenshots/verify-<feature>.png "storage/verify/<feature>/$(date +%Y%m%d-%H%M%S).png"
  ```

- Record the feature ID and the entry point used with every artifact.
- Confirm both the visible result and the route or data change that proves the action took effect.
- Report an unreachable path with the attempted command and the unmet precondition.
- Do not report a skipped entry point as verified through a different path.
- Do not weaken a journey to make it pass. Fix the fixture or record the gap under `Gotchas`.

## Feature entry contract

Each feature file starts with an H1 title and one paragraph describing the visitor-visible behavior. It then uses exactly four H2 sections in this order.

1. `Sub-features` lists short IDs with one line for each behavior.
2. `How to get to it (user POV)` lists every visitor entry point.
3. `Driving it with Pest agent` starts with `Preconditions:` and pairs each action with an exact command and observable result.
4. `Gotchas` lists traps that can waste or invalidate a run.

Keep implementation details out of the map. Name only visitor paths, stable handles, required state, commands, and observable proof.

## Features

- [Discover events](./discover-events.md) covers the home page and its hero search into the event directory.
- [Browse events](./browse-events.md) covers directory search, matching, and non-matching results at `/majlis`.
- [Event detail](./event-detail.md) covers opening an event from the directory and its primary actions.
- [Sign in](./sign-in.md) covers the sign-in form, redirect, and authenticated dashboard.

## Not yet mapped

These visitor surfaces exist and were reachable during the initial interview, but do not have a proven recipe yet:

- Event submission: `/tambah-majlis` landing and `/hantar-majlis` form (first step renders; full submission is unproven).
- Ticketing and checkout: `/majlis/{event}/checkout/{ticket?}` and `/checkout/...`.
- Institutions, speakers, and venues directories and detail pages.
- Registration, pass, calendar, and share flows.
- Filament admin (`admin.ilmu360.test`) and member (`ahli.ilmu360.test`) panels.
- API surface at `api.ilmu360.test`.

Do not claim any of these verified until a feature file exists and its journey passes.
