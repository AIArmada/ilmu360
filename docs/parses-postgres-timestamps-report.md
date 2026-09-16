# Technical Report: Why `ParsesPostgresTimestamps` Exists

**File:** `commerce/packages/commerce-support/src/Concerns/ParsesPostgresTimestamps.php`
**Date:** 2026-09-16
**Trigger:** `/majlis` page load measured at 6.5s TTFB / 6.8s LCP in headless Chrome (cold)
**Status:** Implemented, tested, verified. Uncommitted in the `commerce` repo.

---

## 1. Executive summary

Every read of an affected `timestamptz` attribute using Laravel's stock parsing path
threw and caught a `Carbon\Exceptions\InvalidFormatException`, costing ~1ms per read.
On the `/majlis` listing this single defect accounted for roughly half a second of
server time (hundreds of date reads across hydration, filtering, sorting, and Blade
rendering), and it taxed every other date-touching page in the application.

The trait overrides one method, `Model::asDateTime()`, to route values that already
carry an explicit UTC offset (or fractional seconds) straight to `Date::parse()` —
which is exactly what Laravel's own fallback does after the exception. Parsed instants
are byte-identical; only the doomed format attempt is skipped. Measured speedup on the
hot path: **192.7ms → 5.4ms per 200 reads (~35×)**.

---

## 2. How the defect was found (evidence chain)

Profiling proceeded top-down on `/majlis` (115 events, 115 occurrences, 10 sessions):

| Step | Measurement |
|---|---|
| Browser TTFB/LCP (Chrome DevTools MCP, cold) | 6,524ms / 6,771ms |
| Server wall / DB time (in-app probe) | 5,178ms / 200ms → defect is PHP, not SQL |
| `PublicScheduleDiscoveryService::search()` alone | 4,174ms |
| After fixing the state-config hotspot (§6) | discovery 1,333ms |
| Stage split: `get` / `flatten` / `filter` / `sort` | 525 / 137 / 216 / **455ms** |
| Sort of ~40–100 leaves costing 455ms → `startsAt()` per comparison | suspect: date cast |
| `$occurrence->starts_at` × 93 reads | **768ms for `status`, 91ms for `starts_at`** (attribute bisect) |
| Plain `CarbonImmutable::parse()` × 200 | 1ms (Carbon itself is fine) |
| `Date::parse($raw)` × 200 | 2ms (facade is fine) |
| `Model::asDateTime($raw)` × 200 | **206ms** |
| `Date::createFromFormat('Y-m-d H:i:s', $raw)` | **throws** `InvalidFormatException`; throw+catch × 200 = **182ms** |

The bisect isolates the cost precisely: not Carbon, not the facade, not the query —
the exception round-trip inside stock `asDateTime()`.

Raw value from the driver:

```
string(22) "2026-01-05 02:30:00+00"
```

Model date format (from `PostgresGrammar::getDateFormat()`):

```
Y-m-d H:i:s
```

`createFromFormat('Y-m-d H:i:s', '2026-01-05 02:30:00+00')` fails on trailing data
(`+00`), the `catch (InvalidArgumentException)` in `HasAttributes::asDateTime()`
swallows it, and the fallback `Date::parse($value)` produces the answer. Correct
result, ~1ms of exception overhead, on every read, on every model, on every page.

---

## 3. Root cause

Three facts combine:

1. **Project convention is `timestampTz` columns** (see `.ai/database` rules: store UTC).
   The pgsql driver therefore returns timestamps with a numeric offset suffix
   (`+00`, `+08`, …) — never bare `Y-m-d H:i:s`.
2. **Laravel's Postgres grammar declares its date format as `Y-m-d H:i:s`**
   (no offset, no fractional part). This format is correct for plain `timestamp`
   columns but matches no `timestamptz` value.
3. **`HasAttributes::asDateTime()` tries `createFromFormat()` before `parse()`**
   and uses exceptions for control flow when the format does not match.

No application or package code is at fault; it is a framework/driver format mismatch
that only manifests (as a performance defect) when a codebase standardizes on
`timestamptz`. It is invisible in query logs and APM traces because no query is
involved — pure CPU burn in exception handling.

---

## 4. Alternatives considered and rejected

| # | Alternative | Verdict | Reason |
|---|---|---|---|
| 1 | Override `getDateFormat()` to `'Y-m-d H:i:sP'` per model | Rejected | `P` requires a colon (`+00:00`); pgsql emits short `+00`. Worse, models mix `timestamptz` columns with plain `timestamp` columns (`created_at`/`updated_at` have no offset) — any single format string breaks one group or the other. |
| 2 | Override to `'Y-m-d H:i:sO'` | Rejected | `O` requires 4 digits (`+0000`); pgsql emits 2 (`+00`), and half-hour zones emit `+05:30`. No single `date()` format token matches all pgsql outputs. |
| 3 | Change the grammar / connection `DateStyle` | Rejected | Grammar is vendor code; `DateStyle` cannot remove the offset from `timestamptz` output. |
| 4 | Strip the offset, then call `parent::asDateTime()` | Rejected | **Behavior change.** The offset carries timezone information; stripping `+08` reinterprets the instant in the app timezone. Only correct if every value is UTC, which the cast layer must not assume. |
| 5 | Cache parsed dates per model instance (memoize casts) | Rejected | Fights the framework: attribute caching interacts with `setRawAttributes()`, `refresh()`, `replicate()`, and Octane long-lived workers. Fixes the symptom at one call site instead of the cause. |
| 6 | Fix only the sort (Schwartzian transform in `compareByTime`) | Rejected as the fix (kept in mind) | Would hide ~400ms on `/majlis` while leaving the identical 1ms tax on every date read everywhere else (policies, Blade, formatters, API resources). The defect is systemic; the fix belongs at the layer where it occurs. |
| 7 | Per-model `asDateTime()` overrides scattered across packages | Rejected | Same logic copy-pasted into N models across N packages. A shared trait in the package every other package already depends on is strictly better. |

The chosen fix — detect-then-parse in one shared trait — is the lowest-risk local
fix among the alternatives evaluated: (a) behavior-identical, (b) driver-agnostic,
(c) written once, and (d) placed at the layer that owns the defect.

---

## 5. Why a trait in `commerce-support`

**Ownership.** Per the project's package-friendliness rules, reusable model behavior
belongs in a shared package, not in application models. `commerce-support` is the
foundation package: both `events` and `persons` already declare
`"aiarmada/commerce-support": "self.version"`, so the trait introduces **zero new
dependencies** in either direction.

**Why not the application?** The hot models (`EventOccurrence`, `EventSession`,
`Event`, `Person`) live in commerce packages; the app subclasses some of them.
Putting the fix in app subclasses would leave package-internal reads (policies,
scopes, resources used by other consumers) slow, and would need repeating for every
future consumer of these packages.

**Why a trait and not a base model?** The packages have no shared base model —
each model extends Eloquent's `Model` (directly or via one package parent) with its
own trait stack. A trait composes into the existing structure without forcing an
inheritance redesign, and `parent::asDateTime()` inside the trait resolves to
whatever the using model extends, so the override is always anchored to stock
Laravel behavior.

**Why these five models?** They are exactly the models whose date attributes are
read on the six audited pages (measured, not guessed):
`EventOccurrence`, `EventSession`, package `Event` (→ inherited by app `Event`),
package `Person` (→ inherited by app `Person`), and app `Institution` (extends
Eloquent directly). Other models keep stock behavior until profiling says otherwise.

---

## 6. The fix

```php
trait ParsesPostgresTimestamps
{
    private const OFFSET_OR_FRACTIONAL_SUFFIX =
        '/(?:\.\d+|Z|[+-]\d{2}(?::?\d{2})?)\z/';

    protected function asDateTime($value)
    {
        if (
            is_string($value)
            && preg_match(self::OFFSET_OR_FRACTIONAL_SUFFIX, $value) === 1
        ) {
            return Date::parse($value);
        }

        return parent::asDateTime($value);
    }
}
```

Design notes:

- **Same expression as the fallback.** `Date::parse($value)` is literally what
  `HasAttributes::asDateTime()` returns after catching the exception, so the
  success value is definitionally identical — same class, same instant, same tz.
- **Regex matches exactly the shapes the grammar format cannot** (single `\z`
  end-anchor, named `OFFSET_OR_FRACTIONAL_SUFFIX` constant):
  `+HH`, `+HHMM`, `+HH:MM`, `-…` equivalents, `Z`, and fractional seconds
  (`.u`, which also defeat `Y-m-d H:i:s`). Everything else — bare datetimes,
  `Y-m-d` dates, numerics, `DateTime` objects — falls through to `parent`
  untouched, so SQLite tests and plain `timestamp` columns behave exactly as before.
- **No signature change.** The override mirrors the parent signature precisely
  (no native return type, matching Laravel's source). The `@phpstan-ignore` is
  required only because Larastan's vendor stub hard-codes a mutable-Carbon return
  while this application runs the immutable date factory globally — the stub, not
  the code, is inaccurate. The ignore is single-line, identifier-scoped, and
  reasoned (see §8).
- **Octane/test safe.** No static state, no caches, no container writes — a pure
  function of its input. Nothing to reset between requests or tests.

Companion fix (same session, separate defect): the three directory-scanning Spatie
state configs (`OccurrenceStatus`, `RegistrationStatus`, `EventModerationStatus`)
were memoized, because Spatie rebuilds `config()` on every state instantiation
(8ms per `$occurrence->status` read). That fix is orthogonal and documented by its
own regression test.

---

## 7. Correctness argument

1. **Value parity (measured):** raw `2026-01-05 02:30:00+00` → `2026-01-05T02:30:00.000000Z`,
   `tz=+00:00` — identical before and after (verified in tinker against the live DB).
2. **Path coverage:** the regex routes a strict subset of inputs (offset/fractional
   strings) to the parent's own fallback expression; all other inputs execute the
   parent method verbatim. There is no input whose result can differ — only inputs
   whose *route* to an identical result is shorter.
3. **Exhaustive page-level proof:** `/majlis` SSR HTML is byte-identical before and
   after (623.6KB), across events, occurrences, sessions, time expressions,
   locations, and formatted person names — the largest date-rendering surface in
   the app.
4. **Regression tests** (two files, one defect each):
   - `tests/Unit/ParsesPostgresTimestampsTest.php` compares the trait against
     native Laravel parsing directly across 10 timestamp shapes (bare, date-only,
     `+HH`/`-HH`/`+HHMM`/`+HH:MM` offsets, `Z`, fractional with and without
     offset), asserting identical class, instant (`Y-m-d H:i:s.uP`), Unix
     timestamp, and timezone name. 10 tests, 40 assertions, green.
   - `tests/Unit/StateConfigMemoizationTest.php` covers config memoization and
     transition integrity for the separate state-config fix. 4 tests, green.

---

## 8. Verification record

| Check | Result |
|---|---|
| `/majlis` browser TTFB / LCP, full session incl. state-config + N+1 fixes (Chrome DevTools MCP, cold) | 6,524/6,771ms → **1,562/1,861ms** |
| `/majlis` server wall / queries, full session | 5,178ms/90 → **~900ms/67** |
| Person show / institusi / penceramah | all faster or equal; zero console errors; request counts unchanged |
| Date-cast micro-benchmark (×200) | 192.7ms → **5.4ms**, same instants |
| Feature suites (discovery, person/institution index+show, engagement, lifecycle, admissions, checkout) | all green (119 tests total across files) |
| New `ParsesPostgresTimestampsTest` + `StateConfigMemoizationTest` | 10 + 4 passed |
| Pint (ilmu360 + commerce) | clean |
| PHPStan level 6, changed files, both repos | clean (one scoped, reasoned ignore) |
| Commerce diff | **+38/−0 across 7 files, plus this 1 new trait file** |

---

## 9. Known non-goals and follow-ups

- **Other models** still pay the exception tax on `timestamptz` reads (e.g. `Venue`,
  `EventLocation`, media/address models). Deliberately left untouched: apply the
  trait only where profiling justifies it, one measured step at a time. No global
  base model — targeted application keeps the blast radius tiny until `timestampTz`
  is near-universal.
- **Leaf sorting** (`compareByTime` re-reads `startsAt()` per comparison) was
  re-profiled after this fix: 40 leaves sort in **7ms** (was 455ms, when every
  comparison paid the exception tax). A Schwartzian transform is not justified at
  this cost; revisit if leaf counts grow ~10×.
- **`/majlis` HTML weight (624KB, 370KB of filter-select JSON)** and the
  **person-page font-swap CLS (0.26)** are separate, UX-touching optimizations
  proposed as follow-ups, not bundled into this behavior-preserving fix.
- Upstream, Laravel could avoid the throw by attempting the grammar format only
  when the value lacks an offset — worth a framework PR, but out of scope here.

---

## 10. One-paragraph version

Postgres returns `timestamptz` values with an offset suffix (`…+00`) that never
matches Laravel's `Y-m-d H:i:s` grammar format, so stock Eloquent threw and caught
an exception on every affected timestamptz read using Laravel's stock parsing path
(~1ms each). `ParsesPostgresTimestamps`
is a dependency-free trait in `commerce-support` — the one package all model-owning
packages already require — that detects offset/fractional values with a single regex
and sends them straight to `Date::parse()`, the exact expression Laravel falls back
to after the exception. Results are provably identical (byte-identical page output,
parity tests); the only thing removed is the doomed format attempt. It reduced the
isolated datetime-casting benchmark from 192.7ms to 5.4ms per 200 reads and
contributed to the broader `/majlis` optimization that reduced cold TTFB from 6.5s
to 1.6s in a real browser. It speeds up every affected timestamptz read using
Laravel's stock parsing path, present and future, for any model that uses it.
