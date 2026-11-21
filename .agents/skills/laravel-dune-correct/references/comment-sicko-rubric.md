# Comment-Sicko Audit Rubric (Laravel Edition)

When auditing a diff or file set, assume every comment is guilty until proven protected by one of the **five keep clauses** below. When in doubt, delete.

## The 5-Item Keep Leash (~1% Survivors)

1. **Legal or license headers** (SPDX / copyright notices).
2. **Non-obvious behavior forced by an external dependency, vendor API, database engine, or Laravel quirk we cannot reshape** — must be proven true on a live code path today. Surprises in *our own application code* are NEVER excused: kill the comment and refactor the symbol (`MUST KILL`).
3. **Pure formatter ignores** (e.g., `// prettier-ignore` in Blade/TS assets). Correctness or static-analysis suppressions (`@phpstan-ignore*`, `phpcs:disable`, `phpcs:ignore`, `@psalm-suppress`) are **MUST KILL** unless proven to be a faulty style-only rule.
4. **Public API contract docblocks** (`app/Features/*/Api/**`, `app/Shared/Contracts/**`, or PHPStan `@template` / `@return` generic annotations required by static analysis).
5. **Issue, ADR, or RFC links** explaining an external constraint that code cannot express.

## Mandatory Actions on Kills

| Pattern Found | Action |
| --- | --- |
| Narrating what the next line obviously does (`// Save the order`) | Delete immediately. |
| Commented-out code blocks | Delete immediately (git preserves history). |
| Over-rotated PR feedback (`// Lauren said never do X`) | Delete immediately; if real, promote via `/correct`. |
| Workaround justification (`// quick fix for race`, `// fine for now`) | Delete comment + mark symbol `MUST KILL` for root-cause fix. |
| Claimed invariant (`// DO NOT REMOVE`, `// Must run after hydration`) | Trace symbol → encode in types, `platform-check.php`, `OwnedWriteRule`, or Pest test → delete comment. |
