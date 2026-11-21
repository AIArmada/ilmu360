# Laravel Dune Skill — Upstream Defect Report

**Reviewer:** implementation review from a real Laravel 12 app adopting the pack
**Pack version:** `laravel-dune (2).zip` → `.ai/` dated 2026-11-21 (5 skills incl. `laravel-dune-package`)
**Method:** every finding below was reproduced by executing the shipped scripts against synthetic
fixtures. Commands and outputs are quoted verbatim. Two candidate findings were investigated and
**withdrawn** (see §Withdrawn) because they turned out to be review-harness artifacts — included so
you don't spend time chasing them.

---

## Summary

The architectural design is sound, and the generated static analysis genuinely works — the failure
messages name the supported owner, which is exactly the stated goal. The defects cluster in one
place: **`write_files()` in `pkg_scaffold.py` is unconditionally additive, which breaks every
adoption path that touches an existing file.** That single design choice produces findings #1–#3.

| # | Severity | Finding | Area |
|---|----------|---------|------|
| 1 | **High** | `single` → `modular` promotion is documented but impossible | scaffold |
| 2 | **High** | Mode B (convert existing package) fails if `tools/architecture.json` exists | scaffold |
| 3 | **High** | `.ai/rules/index.md` collision blocks any project already using Dune/Boost | scaffold |
| 4 | **Medium** | `$SKILL_DIR` points at a non-existent directory (`laravel-architecture`) | docs |
| 5 | **Medium** | Only `src/` accepted; valid PSR-4 layouts rejected | scaffold |
| 6 | **Medium** | `--diff-base` silently no-ops on a checker that lacks ratchet support | docs + hardening |
| 7 | Low | All-or-nothing abort reports only the first conflict | UX |

---

## Root cause (one function underlies #1–#3)

[`pkg_scaffold.py:47-58`](.ai/skills/laravel-dune-package/scripts/pkg_scaffold.py):

```python
def write_files(root: Path, files: dict, apply: bool) -> None:
    pending = []
    for relative, content in sorted(files.items()):
        target = safe_target(root, relative)
        if target.exists():
            if target.read_bytes() != content:
                raise ValueError(f"Refusing to overwrite differing file: {relative}")
        else:
            pending.append((relative, target, content))
    ...
```

Every `init` emits its **entire** file set, including files whose content is *layout-dependent*, then
refuses the whole run if any one of them differs. Consequences:

- Correct for greenfield `init` (verified: re-running the same layout is idempotent →
  `0 new file(s); existing identical files retained.`).
- Wrong for **upgrade** (`single`→`modular`), **convert** (package already has tooling), and
  **coexistence** (repo already has `.ai/rules/index.md`).

Fixing `write_files` to (a) classify generated files as *managed* vs *user-owned*, (b) report all
conflicts at once, and (c) offer `--force` for managed files, resolves #1, #2, #3 and #7 together.

---

## 1 — HIGH: `single` → `modular` promotion is documented but impossible

**Doc claim** ([`SKILL.md` §Step 1](.ai/skills/laravel-dune-package/SKILL.md)):

> You can start with `single` and promote to `modular` later—`tools/package-check.php` and
> `PackageOwnedWriteRule` support both layouts transparently.

**Reproduced.** `init --layout modular` on an existing `single` package aborts before writing
anything:

```
$ python3 pkg_scaffold.py init --root "$S/prom3" --layout modular --apply
ERROR: Refusing to overwrite differing file: .ai/rules/package-architecture.md
exit=2
```

`src/Platform/` is never created, and `tools/architecture.json` still says `"layout": "single"`.
Exactly three generated files are layout-dependent and therefore always conflict:

```
.ai/rules/package-architecture.md
tests/Arch/PackageArchTest.php
tools/architecture.json
```

(`composer.json` also differs between my two fixtures, but it is read-only input — the scaffold
never writes it. Confirmed: it only ever *reads* `composer.json`.)

**Why the final sentence of the doc claim is wrong.** `package-check.php`/`PackageOwnedWriteRule`
*do* handle both layouts transparently — the *scaffolder* is the only thing that cannot cross the
boundary. The claim is half true, which is worse than wholly false because it reads as supported.

**Fix:** add `pkg_scaffold.py promote --root <path> --to modular [--apply]` that rewrites only the
three layout-dependent files (printing a diff first), or change the doc to "choose the layout up
front; moving between layouts is a manual migration."

---

## 2 — HIGH: Mode B (convert existing package) fails on a pre-existing `architecture.json`

**Doc claim** ([`SKILL.md` §Mode B, step 2](.ai/skills/laravel-dune-package/SKILL.md)):

> Run `pkg_scaffold.py init --layout single` (or `modular` ...) with `--apply`, and start CI with
> ratcheting mode.

**Reproduced** against a package that already carries its own Dune-style tooling (the normal state
for any package being converted — it may have partial or hand-rolled config):

```
$ python3 pkg_scaffold.py init --root "$S/legacy" --layout single --apply
ERROR: Refusing to overwrite differing file: tools/architecture.json
exit=2
```

The run produces **no** files, so the documented Mode B entry point is unusable exactly when it is
meant to be used. Worse, the user's existing config is precious: `architecture.json` holds their
`exceptions` list. A `--force` implementation must **merge** (`exceptions` unioned, layout/namespace
keys updated) rather than overwrite, or converting will silently drop reviewed exceptions.

**Fix:** merge semantics for `architecture.json` (structure fields refreshed, `exceptions`
preserved), and have Mode B state that plainly.

---

## 3 — HIGH: `.ai/rules/index.md` collision blocks non-empty adoption

The scaffold always emits a **fixed, 5-line** index file
([`build_package_ai_rules`](.ai/skills/laravel-dune-package/scripts/pkg_scaffold.py)):

```markdown
| Applies to | Rule file |
| --- | --- |
| src/**, config/**, tests/** | .ai/rules/package-architecture.md |
```

Nothing in it is package-specific, yet it is treated as a conflicting generated artifact. Any
package inside a repo that already uses `laravel-dune` or Laravel Boost project rules hits:

```
$ python3 pkg_scaffold.py init --root "$S/basic" --layout single --apply
ERROR: Refusing to overwrite differing file: .ai/rules/index.md
exit=2
```

Verified all-or-nothing: `find abort -type f` shows only the two pre-existing files, no generated
output.

**Fix:** never hard-fail on `index.md`. Either idempotently append the row if absent, or skip it and
print `SKIPPED .ai/rules/index.md (exists) — add this row manually:` plus the row. Reserve the hard
refusal for genuinely package-specific generated code.

---

## 4 — MEDIUM: `$SKILL_DIR` points at a directory that does not exist

**Doc claim** ([`laravel-dune/SKILL.md:19`](.ai/skills/laravel-dune/SKILL.md), verbatim in the zip):

```markdown
Resolve `$SKILL_DIR` to `.ai/skills/laravel-architecture` ...
```

The shipped directory is `.ai/skills/laravel-dune`. `laravel-architecture` occurs **exactly once in
the whole pack** (verified: `grep -rn "laravel-architecture" | wc -l` → `1`), so it is a rename
residue, not a deliberate alias. Following the bootstrap instructions verbatim gives:

```
python3: can't open file '.../.ai/skills/laravel-architecture/scripts/arch_scaffold.py':
[Errno 2] No such file or directory
```

**Fix:** `.ai/skills/laravel-dune`. Worth a release-blocking grep for stale skill names across the
pack — this class of error is only discoverable by execution.

---

## 5 — MEDIUM: only `src/` accepted; valid PSR-4 layouts rejected

**Reproduced.** A package mapping its namespace to `lib/` is refused:

```
$ # composer.json: "psr-4": {"Acme\\NoPsr\\": "lib/"}
$ python3 pkg_scaffold.py init --root "$S/nopsr" --layout single
ERROR: Expected a PSR-4 autoload entry mapping a namespace to 'src/' in composer.json.
exit=2
```

`src/` is the dominant convention and matches Spatie's skeleton, so requiring it by default is
defensible. The problem is that the *machinery already supports* another directory —
`architecture.json` carries `"sourceDir": "src"`, and `package-check.php` iterates
`foreach ([$sourceDir, 'config', 'routes'])`. Only the front-door validation hardcodes the literal.

**Fix:** `--source-dir=lib` (default `src`), validated against a matching PSR-4 entry and persisted
into `architecture.json`. At minimum, split the error into "no PSR-4 found" vs "PSR-4 found but not
`src/`" so users know which requirement failed.

---

## 6 — MEDIUM: `--diff-base` silently no-ops on a checker that lacks ratchet support

**Important scoping:** the pack's **own** checkers are correct. Both support the flag and fail loudly
on a bad base — verified:

```
$ php tools/package-check.php --diff-base=main      # outside a git repo
exit=2
Unable to compute git diff against 'main'.
```

So this is **not** a bug in the shipped scripts. It is a documentation-robustness issue that bites
adopters who *customize* the checker, which the pack explicitly invites (the scripts are "starter"
assets copied into the target repo). I hit this in practice: an app with a hand-written checker using
`getopt('', ['root:', 'json'])` silently discarded the flag and reported success:

```
$ php tools/architecture-check.php --diff-base=main
0 architecture violation(s); 28 feature(s) checked.
exit=0
```

Fail-closed (over-enforcement), so not a safety hole — but during a migration the entire purpose of
the flag is "don't fail on untouched legacy files," so silently not having it invalidates the plan
while appearing to work.

**Fix (two parts):**
1. **Doc note** in `tooling.md` next to the script block: `arch:boundaries:diff` requires a checker
   that implements ratchet mode; if you maintain a custom checker, omit this script rather than
   passing a flag it ignores.
2. **Harden the shipped checkers to reject unknown options** (~5 lines), which converts every
   flag/version mismatch into a loud failure instead of silent degradation:

```php
$argv = $_SERVER['argv'];
$unknown = array_filter(array_slice($argv, 1), fn ($a) => str_starts_with($a, '--')
    && ! str_starts_with($a, '--root=') && $a !== '--json' && ! str_starts_with($a, '--diff-base='));
if ($unknown !== []) { fwrite(STDERR, 'Unknown option(s): '.implode(' ', $unknown).PHP_EOL); exit(2); }
```

Part 2 is the higher-value half and protects every future flag addition.

---

## 7 — LOW: all-or-nothing abort reports only the first conflict

`write_files()` validates the batch, then raises on the **first** differing file. With several
conflicts the user fixes one, re-runs, and discovers the next — serially, with no idea how many
remain:

```
ERROR: Refusing to overwrite differing file: .ai/rules/package-architecture.md
```

**Fix:** collect and report together:

```
Refusing to overwrite 3 differing file(s):
  .ai/rules/package-architecture.md
  tests/Arch/PackageArchTest.php
  tools/architecture.json
Re-run with --dry-run to inspect diffs, or --force to overwrite generated files.
```

---

## What works well (no action needed)

Stating these explicitly because they are the parts worth keeping intact:

- **`package-check.php` genuinely detects violations.** A package importing `App\*` and calling
  `sleep()` returned `exit=1` with precise messages:
  > `A reusable Laravel package must NEVER import host application classes (App\*). Depend on a
  > package Contract or config binding instead.`
- **`PackageOwnedWriteRule` works under PHPStan** and names the supported owner:
  > `Storage mutation in 'src/Services/BadService.php' is outside a write owner. Move
  > DB/Cache/Storage writes into src/Actions/ ... (Single-Writer Law).`
  — emitted with the `packageArchitecture.writeLocation` identifier.
- **`env()` placement is honoured:** allowed in `config/`, flagged `banned-footgun` in `src/`.
- **Both layouts generate correctly from scratch.** `modular` detects features
  (`"features": ["Billing"]`); `driver` and `boost-docs` work in both layouts.
- **`feature` correctly refuses on `single`** and names the alternative:
  > `feature` is only for layout='modular' packages. For focused packages, add classes directly
  > under src/Actions/, src/Contracts/, src/Drivers/.
- **Idempotency** holds for repeated `init` (same layout) and repeated `feature` adds.
- **Namespace injection is correct** throughout generated code, including `tests/Arch/PackageArchTest.php`.
- **`package_inventory.py`** produces a genuinely useful read-only report (public surface, Spatie/
  Testbench detection, suggested layout, anti-slop hotspots).

---

## Withdrawn (investigated, not defects)

Recording these so the report is not padded and you don't chase them:

1. **"Generated `PackageArchTest` cannot pass."** My first run failed with
   `Call to undefined method PHPUnit\Framework\TestCase::group()`. That was a review-harness
   artifact: I executed Pest via a symlinked `vendor/` from the host app, with the fixture declaring
   Pest 3 while Pest 5.3 was resolved. `orchestra/testbench` is not installed in the host app either.
   **The test content is correct**; a properly bootstrapped package with
   `pest-plugin-arch`/`pest-plugin-laravel` should pass. *Suggestion only:* emit a minimal
   `phpunit.xml` + `tests/Pest.php` in `init` so the skill's own
   "Verify Before Declaring Done" block works on the first try.
2. **"`architecture.neon` vs `architecture-phpstan.neon` inconsistency."** I suspected the prose and
   the JSON block disagreed. They do not — the zip is self-consistent on
   `tools/architecture-phpstan.neon` in both places. Retracted.

---

## Suggested priority

1. **Fix `write_files` once** (managed-vs-user-owned + batch reporting + `--force`/`--dry-run`) —
   resolves #1, #2, #3, #7 and is contained to one function.
2. **#4** (one-token doc fix, release-blocking) and **#6 part 2** (reject unknown flags) — both tiny.
3. **#2 merge semantics** for `architecture.json` `exceptions` — must land with #1 or conversions
   will silently drop reviewed exceptions.
4. **#5** as a small feature.
