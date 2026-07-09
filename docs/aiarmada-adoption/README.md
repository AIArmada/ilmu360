# AIArmada Adoption Program

This directory is the execution hub for the ilmu360° fresh-schema rewrite onto local AIArmada packages.

## Objective

Rebuild ilmu360° on first-party packages from `/Users/Saiffil/Herd/commerce/packages/*` with no backward compatibility, no legacy schema preservation, and no compatibility adapters for old application models. Package standards win when they conflict with current app conventions, including using the `aiarmada/addressing` UUID geography model instead of the current integer geography model.

## Source Of Truth

| File | Purpose |
| --- | --- |
| **Live code + Composer** | Absolute truth when docs disagree. |
| `status.md` | Current phase (Phase 9 native purity), blockers, model ownership, next actions. |
| `gap-closure-report.html` | Fresh open-gap dashboard: what blocks package-native exit. |
| `cutover-plan.html` | Phase 9 work units (no BC policy — hard cuts). |
| `phase-08-cutover.md` | Phase 8 checklist (mostly complete) + Phase 9 handoff. |
| `../commerce-package-readiness-reassessment.md` | Readiness closure audit. |
| `package-inventory.md` | Package inventory and tiers (historical inventory may lag install list). |
| `domain-mapping.md` | Domain → package ownership targets. |
| `architecture-decisions.md` | Accepted rewrite decisions and consequences. |
| `agent-work-queue.md` | Parallelizable packets for multiple agents. |
| `phase-00-readiness.md` through `phase-08-cutover.md` | Executable phase checklists. |
| `review-log.md` | Verification evidence and completed review entries. |

### North star (2026-07-09)

Close all remaining dual paths and cutover shims. Use AIArmada packages natively. Custom code only when intentional by product design. Feature development is frozen until Phase 9 exit criteria in `gap-closure-report.html` pass.

## Status Vocabulary

Use only these states:

- `Not Started`
- `Assessing`
- `Blocked`
- `Ready`
- `In Progress`
- `In Review`
- `Verified`
- `Deferred`
- `Removed`

## Operating Rules

- Start every work session by reading `status.md`, the current phase file, `agent-work-queue.md`, and the relevant architecture decisions.
- Update `status.md` before and after each material work packet.
- Keep `tasks/todo.md` short: it mirrors only the active phase and points back here.
- If work changes UI behavior, navigation, forms, filters, tabs, table actions, buttons, or stateful interactions, explicitly decide whether Signals tracking should be added or updated.
- Package edits are allowed only when they remain generic package improvements. Do not put ilmu360-specific Islamic presentation, copy, workflows, or data assumptions into packages.
- Prefer package contracts, config seams, and extension points over app-local wrappers.
- Delete superseded app code after its package replacement is verified. Do not keep legacy compatibility shims.

## Stop And Re-plan Triggers

Stop implementation and update `review-log.md` before proceeding when any of these happen:

- Composer cannot resolve local path packages without changing broad dependency strategy.
- A package migration would require legacy table compatibility or data preservation.
- A package API needs ilmu360-specific behavior to function.
- A package introduces DB constraints, cascades, non-UUID primary keys, or `SoftDeletes`.
- A public API, MCP contract, or mobile contract would change without corresponding docs and tests.
- A phase exit criterion cannot be proven with commands or runtime evidence.

## Agent Handoff Protocol

Every agent packet must end with:

- Files changed.
- Decisions made.
- Tests and commands run.
- Known blockers.
- Next suggested packet.

Agents must not modify another packet's owned files unless `status.md` is updated first and the collision is intentional.

## Verification Baseline

Use these gates throughout the program:

```bash
composer validate
composer show aiarmada/*
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse --ansi
vendor/bin/pest --parallel --compact
rg -n -- "constrained\(|cascadeOnDelete\(" /Users/Saiffil/Herd/commerce/packages/*/database
rg -n -- "softDeletes\(\)|SoftDeletes" app database /Users/Saiffil/Herd/commerce/packages
```

Run narrower commands during a packet, then record the final proof in `review-log.md`.
