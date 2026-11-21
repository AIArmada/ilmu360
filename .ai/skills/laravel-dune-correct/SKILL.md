---
name: laravel-dune-correct
description: Turn repeated agent mistakes and comment slop in a Laravel repo into permanent mechanical checks (Architecture -> Types/AST/PHPStan -> Pest Test -> Boost record-rule last), prove each check fails on a real past mistake, and audit comments using the Comment-Sicko 5-item keep leash.
---

# Laravel Dune — `/correct` & Comment-Sicko Hardening

Whenever you correct coding agents for the same mistake twice, stop fixing it in chat and fix the repository so no future agent can repeat it (*Code Is Memory*).

## Part A — Find Mistake Classes (Rule of Two)

1. Inspect recent git commits, reverts, PR review comments, `.ai/rules/*.md`, and workaround comments in `app/` (or `src/` in Composer packages).
2. Group failures into mistake classes. **A class counts once it has happened twice.**

## Part B — Escalate to the Highest Level That Works

Fix the highest-frequency classes one atomic commit each:

1. **Level 1 · Architecture (`app/Platform/`, `feature.json`, `Api/`, `app/Host/` — or `src/Contracts/`, `src/Drivers/`, `src/Actions/` in packages):** Give state one owner, give each task one supported way, hide internals, replace hand-synced lists with `ExtensionRegistry`, delete legacy paths agents would copy.
2. **Level 2 · Types, AST & Static Analysis (`tools/architecture-check.php` / `tools/package-check.php`, `OwnedWriteRule.php`, PHPStan, Pest `arch()`):** Make the bad state unrepresentable with branded/readonly types or add a mechanical check whose error message **explicitly names the supported file, Action, or contract to use instead**. If legacy instances are widespread, enforce in `--diff-base=main` ratchet mode while sweeping old code.
3. **Level 3 · Behavioral Pest Test:** Assert observable behavior (delete tests pinned to internal helper calls or that still pass when behavior breaks).
4. **Level 4 · Boost Project Rule (`record-rule` MCP tool — LAST RESORT):** Only for genuine judgment calls that cannot be checked mechanically. Always call Laravel Boost's `record-rule` MCP tool (so `.ai/rules/index.md` stays synchronized).

### Mandatory Check-Proof & Cleanup Rules

- **Prove every new check can fail:** Demonstrate the new AST rule, PHPStan rule, or Pest test failing on a real past mistake (or deliberate scratch revert) before committing.
- **Maintain the Rule → Enforcement Table** in `.ai/rules/verification-and-hygiene.md`. If an unenforced prose rule is broken again, promote it up the ladder in the same PR. **Delete the prose rule once the mistake is mechanically impossible.**
- **Expiry-dated exceptions:** Any temporary exception in `tools/architecture.json` MUST include exact file, exact target, reason, owner, and expiry date (`YYYY-MM-DD`).

## Part C — Independent Comment-Sicko Pass

Audit the current diff (or target files) using the strict 5-item keep leash in [references/comment-sicko-rubric.md](references/comment-sicko-rubric.md):

1. Delete narration, section banners, commented-out code, workaround sermons, and over-rotated PR feedback (`// X said never do this`).
2. Flag surprises in *our own code* and inline suppressions (`@phpstan-ignore`, `phpcs:ignore`) as **`MUST KILL`**—fix the root cause via rename, extraction, type narrowing, or architecture change.
3. For every claimed constraint (`IMPORTANT`, `DO NOT REMOVE`, `must stay in sync`), trace the symbol, promote the real invariant to a type/lint/test, and delete the comment.
4. Re-run `composer arch:check` and affected tests to verify everything stays green.
