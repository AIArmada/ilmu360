# Agent Work Queue

This file decomposes the rewrite into packets that can be assigned to multiple agents. Each packet has a bounded ownership area. Agents must update `status.md` before starting and `review-log.md` after completing work.

## Work Packet Template

```markdown
## WP-XX - Name

Owner: Unassigned
State: `Not Started`
Phase: N
Owned paths:
- path

Inputs:
- file or decision

Outputs:
- expected artifact/change

Verification:
- command

Dependencies:
- WP-YY
```

## Coordination Rules

- Do not edit another packet's owned paths without updating this file and `status.md`.
- Prefer separate agents for package families with disjoint paths.
- Keep package changes generic.
- Every packet must leave enough notes for another agent to resume after interruption.

## Parallel Lanes

| Lane | Focus | Can run in parallel with |
| --- | --- | --- |
| A | Composer/dependency source strategy | Package inventory, docs, static audits |
| B | Package migration/PHPStan/test readiness | Domain mapping, UI planning |
| C | Foundation packages | Communications and engagement planning after Composer resolves |
| D | Domain replacement | App rebuild planning after package readiness |
| E | Public/API/MCP rebuild | Package implementation after contracts settle |

## Packet Backlog

| ID | State | Phase | Owner | Packet | Owned paths |
| --- | --- | --- | --- | --- | --- |
| WP-00 | `In Progress` | 0 | Main agent | Create docs hub and tracking structure | `docs/aiarmada-adoption/*`, `tasks/todo.md` |
| WP-01 | `Not Started` | 0 | Unassigned | Reconcile package inventory with live commands and update `package-inventory.md` | `docs/aiarmada-adoption/package-inventory.md` |
| WP-02 | `Not Started` | 1 | Unassigned | Add Composer path repository strategy and dry-run dependency resolution | `composer.json`, `composer.lock`, `docs/aiarmada-adoption/phase-01-source-dependencies.md` |
| WP-03 | `Blocked` | 1 | Unassigned | Resolve authz/permission v8 conflict generically | `/Users/Saiffil/Herd/commerce/packages/authz`, `/Users/Saiffil/Herd/commerce/packages/filament-authz` |
| WP-04 | `Blocked` | 1 | Unassigned | Update `references` package for Sluggable v4 compatibility | `/Users/Saiffil/Herd/commerce/packages/references` |
| WP-05 | `Not Started` | 2 | Unassigned | Add or document package test harness strategy | `/Users/Saiffil/Herd/commerce/packages/*`, `docs/aiarmada-adoption/phase-02-package-readiness.md` |
| WP-06 | `Not Started` | 2 | Unassigned | Audit package migrations for UUID/no-constraint/no-SoftDeletes compliance | `/Users/Saiffil/Herd/commerce/packages/*/database` |
| WP-07 | `Not Started` | 2 | Unassigned | Fix `commerce-support` migration stub behavior generically | `/Users/Saiffil/Herd/commerce/packages/commerce-support` |
| WP-08 | `Not Started` | 3 | Unassigned | Move existing installed AIArmada packages to local path source | `composer.json`, `config/*`, `app/Providers/*`, tests touching affiliates/signals/authz |
| WP-09 | `Not Started` | 4 | Unassigned | Replace addressing/geography model plan, global discovery, country-switch removal, and seed/import strategy | `app/Models/*`, `database/*`, routes/layout country switcher, search filters, `/Users/Saiffil/Herd/commerce/packages/addressing` |
| WP-10 | `Not Started` | 4 | Unassigned | Replace contacts/social profiles with `contacting` | `app/Models/Contact.php`, `app/Models/SocialMedia.php`, form schemas/resources |
| WP-11 | `Not Started` | 4 | Unassigned | Replace membership claims/invitations/members with `membership` | `app/Actions/Membership`, membership models/resources/routes |
| WP-12 | `Not Started` | 5 | Unassigned | Replace event core with `events` package | event models/actions/resources/controllers/livewire/tests |
| WP-13 | `Not Started` | 5 | Unassigned | Replace engagement behavior with `engagement` | follows/saves/going/share/reminder code paths |
| WP-14 | `Not Started` | 5 | Unassigned | Replace references with `references` package | reference model/actions/resources/controllers/livewire/tests |
| WP-15 | `Not Started` | 5 | Unassigned | Replace moderation/report/block flows with `moderation`, `events`, and maybe `feedback` | moderation/report models/actions/resources |
| WP-16 | `Not Started` | 6 | Unassigned | Replace notification engine with `communications` | notification models/jobs/controllers/listeners/views |
| WP-17 | `Not Started` | 7 | Unassigned | Evaluate paid tickets/event products/donation checkout commerce path | cart/checkout/orders/products/pricing/payment packages |
| WP-18 | `Not Started` | 8 | Unassigned | Rebuild Filament resources against package models | `app/Filament`, `filament-*` package configs |
| WP-19 | `Not Started` | 8 | Unassigned | Rebuild public Livewire/API/MCP surfaces | `app/Livewire`, `app/Http/Controllers/Api`, `app/Mcp`, docs |
| WP-20 | `Not Started` | 8 | Unassigned | Final deletion, docs regeneration, and full verification | app legacy paths, generated docs, tests |

## Agent Completion Note Format

Use this exact format in `review-log.md`:

```markdown
## YYYY-MM-DD - WP-XX Title

State: `In Review`
Owner: name/agent

Changed:
- path

Verified:
- command => result

Decisions:
- decision

Blockers:
- blocker or None

Next:
- next packet/action
```
