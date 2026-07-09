# Agent Work Queue

> **Stale for live backlog (2026-07-10).**  
> Do **not** start work from “Not Started” / “Assessed” rows without cross-checking:  
> 1. [`status.md`](status.md)  
> 2. [`phase-reconciliation.md`](phase-reconciliation.md) (§ agent-work-queue + §9)  
> 3. [`phase-08-cutover.md`](phase-08-cutover.md) Phase 9 workstreams  
> Phase 4–8 packets below are **historical**. Active implementation IDs are **P9-A…P9-I** and **G11**.

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
| WP-00 | `Verified` | 0 | Main agent | Create docs hub and tracking structure | `docs/aiarmada-adoption/*`, `tasks/todo.md` |
| WP-01 | `Deferred` | 0 | Unassigned | Reconcile package inventory with live commands and update `package-inventory.md` | `docs/aiarmada-adoption/package-inventory.md` |
| WP-02 | `Verified` | 1 | Main agent | Add Composer path repository strategy and dry-run dependency resolution | `composer.json`, `composer.lock`, `docs/aiarmada-adoption/phase-01-source-dependencies.md` |
| WP-03 | `Verified` | 1 | Main agent | Resolve authz/permission v8 conflict generically | Resolved — no package changes needed. App upgraded to permission v8; authz/filament-authz already v8-compatible. |
| WP-04 | `Verified` | 1 | Main agent | Update `references` package for Sluggable v4 compatibility | Completed — constraint bumped to `^4.0.2`; Sluggable v4 has same API as v3, no code changes. |
| WP-05 | `Verified` | 2 | Main agent | Document package test harness strategy | `docs/aiarmada-adoption/phase-02-package-readiness.md` |
| WP-06 | `Verified` | 2 | Main agent | Audit package migrations for UUID/no-constraint/no-SoftDeletes compliance | `/Users/Saiffil/Herd/commerce/packages/*/database` |
| WP-07 | `Verified` | 2 | Main agent | Fix `commerce-support` migration stub behavior generically | `/Users/Saiffil/Herd/commerce/packages/commerce-support` |
| WP-08 | `Verified` | 3 | Main agent | Fix stale model refs, remove stale monorepo overrides, publish authz config | `config/permission.php`, `config/authz.php`, `app/Providers/AppServiceProvider.php`, tests touching authz/permissions |
| WP-09 | `Verified` | 4/8 | Main agent | Addressing/geography cutover + product FK hard-cut | see phase-reconciliation §4; residual aliases = P9-D only |
| WP-10 | `Verified` | 4/8 | Main agent | Contacts/social → contacting (models deleted; alias traits = P9-D) | `contacting` package + app traits residual |
| WP-11 | `Verified` | 4/8 | Main agent | Membership → package applications/invitations | residual polish only |
| WP-12 | `Mostly complete` | 5/8 | Main agent | Events package ownership | residuals P9-A/C/E/G |
| WP-13 | `Verified` | 5/8 | Main agent | Engagement package contracts | — |
| WP-14 | `Mostly complete` | 5/8 | Main agent | References package model | thick product = P9-G |
| WP-15 | `Mostly complete` | 5/8 | Main agent | Moderation/report | P9-E accessors; G12 Block optional |
| WP-16 | `Mostly complete` | 6/8 | Main agent | Communications cutover | P9-B residual factories/commands |
| WP-17 | `Superseded` | 7→9 | — | Paid commerce assessment | **G11 / ADR-013** |
| WP-18 | `Mostly complete` | 8 | — | Filament against packages | residual Tag resource = P9-A |
| WP-19 | `Mostly complete` | 8 | — | Public Livewire/API/MCP on package models | dual-path residuals only |
| WP-20 | `In Progress` | 9 | — | Final deletion + verification | **Phase 9 P9-*** + P9-I |

## Active Phase 8 Sub-Packets

| ID | State | Owner | Packet | Owned paths | Verification |
| --- | --- | --- | --- | --- | --- |
| WP-09A | `Verified` | Main agent | Remove public country switch route/controller/shell selector and public discovery defaults | `routes/web.php`, `resources/views/layouts/app.blade.php`, public event/institution/venue listing filters | `rg` for `country.switch` in code => no matches; `php artisan route:list` => no `/negara` route |
| WP-09B | `Verified` | Main agent | Seed package countries and Malaysia address areas | `database/seeders/AddressingSeeder.php`, `database/seeders/data/malaysia-address-areas.csv`, `config/addressing.php` | `php artisan migrate:fresh --ansi && php artisan db:seed --class=AddressingSeeder --ansi` |
| WP-09C | `Verified` | Main agent | Move submit-event to package `AddressCountry` UUIDs and remove admin/MCP preferred-country defaults. Full package address field replacement remains in WP-09E/address schema rebuild. | `resources/views/components/pages/submit-event/*`, `app/Actions/Events/SubmitFrontendEventAction.php`, `app/Support/Api/Admin/AdminResourceMutationService.php`, MCP tools/tests | `view:cache`, `pint`, `git diff --check`, no preferred-country refs in code/tests |
| WP-09D | `Verified` | Main agent | Delete legacy country-mode services/tests after remaining callers are removed | `app/Support/Location/PublicCountryPreference.php`, `app/Support/Location/PublicCountryRegistry.php`, `app/Support/Location/PreferredCountryResolver.php`, related tests | `rg "PublicCountryPreference|PublicCountryRegistry|PreferredCountryResolver"` => docs only |
| WP-09E | `Verified` | Main agent | Delete old integer geography models — **done**; product uses State/City FKs + admin_area_1/2. Zero alias footprint. | (deleted models) | `rg state_area_id\|district_id\|subdistrict_id` only reject-guards |

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
