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
| WP-00 | `Verified` | 0 | Main agent | Create docs hub and tracking structure | `docs/aiarmada-adoption/*`, `tasks/todo.md` |
| WP-01 | `Deferred` | 0 | Unassigned | Reconcile package inventory with live commands and update `package-inventory.md` | `docs/aiarmada-adoption/package-inventory.md` |
| WP-02 | `Verified` | 1 | Main agent | Add Composer path repository strategy and dry-run dependency resolution | `composer.json`, `composer.lock`, `docs/aiarmada-adoption/phase-01-source-dependencies.md` |
| WP-03 | `Verified` | 1 | Main agent | Resolve authz/permission v8 conflict generically | Resolved — no package changes needed. App upgraded to permission v8; authz/filament-authz already v8-compatible. |
| WP-04 | `Verified` | 1 | Main agent | Update `references` package for Sluggable v4 compatibility | Completed — constraint bumped to `^4.0.2`; Sluggable v4 has same API as v3, no code changes. |
| WP-05 | `Verified` | 2 | Main agent | Document package test harness strategy | `docs/aiarmada-adoption/phase-02-package-readiness.md` |
| WP-06 | `Verified` | 2 | Main agent | Audit package migrations for UUID/no-constraint/no-SoftDeletes compliance | `/Users/Saiffil/Herd/commerce/packages/*/database` |
| WP-07 | `Verified` | 2 | Main agent | Fix `commerce-support` migration stub behavior generically | `/Users/Saiffil/Herd/commerce/packages/commerce-support` |
| WP-08 | `Verified` | 3 | Main agent | Fix stale model refs, remove stale monorepo overrides, publish authz config | `config/permission.php`, `config/authz.php`, `app/Providers/AppServiceProvider.php`, tests touching authz/permissions |
| WP-09 | `In Progress` | 4/8 | Main agent | Replace addressing/geography model plan, global discovery, country-switch removal, and seed/import strategy | `app/Models/*`, `database/*`, routes/layout country switcher, search filters, `/Users/Saiffil/Herd/commerce/packages/addressing` |
| WP-10 | `Assessed` | 4 | Main agent | Replace contacts/social profiles with `contacting` | `app/Models/Contact.php`, `app/Models/SocialMedia.php`, form schemas/resources |
| WP-11 | `Assessed` | 4 | Main agent | Replace membership claims/invitations/members with `membership` | `app/Actions/Membership`, membership models/resources/routes |
| WP-12 | `Assessed` | 5 | Main agent | Replace event core with `events` package | event models/actions/resources/controllers/livewire/tests |
| WP-13 | `Assessed` | 5 | Main agent | Replace engagement behavior with `engagement` | follows/saves/going/share/reminder code paths |
| WP-14 | `Assessed` | 5 | Main agent | Replace references with `references` package | reference model/actions/resources/controllers/livewire/tests |
| WP-15 | `Assessed` | 5 | Main agent | Replace moderation/report/block flows with `moderation`, `events`, and maybe `feedback` | moderation/report models/actions/resources |
| WP-16 | `Assessed` | 6 | Main agent | Replace notification engine with `communications` — assessed. FCM/WhatsApp/digest bound through contracts. Detailed plan in phase-06.md. | `docs/aiarmada-adoption/phase-06-communications.md` |
| WP-17 | `Assessed` | 7 | Main agent | Evaluate paid tickets/event products/donation checkout commerce path — assessed. App has zero commerce. Ticketing via events package, payments via CHIP, donations via Chip Collect. No inventory/shipping/tax needed. | `docs/aiarmada-adoption/phase-07-commerce.md` |
| WP-18 | `Not Started` | 8 | Unassigned | Rebuild Filament resources against package models | `app/Filament`, `filament-*` package configs |
| WP-19 | `Not Started` | 8 | Unassigned | Rebuild public Livewire/API/MCP surfaces | `app/Livewire`, `app/Http/Controllers/Api`, `app/Mcp`, docs |
| WP-20 | `Not Started` | 8 | Unassigned | Final deletion, docs regeneration, and full verification | app legacy paths, generated docs, tests |

## Active Phase 8 Sub-Packets

| ID | State | Owner | Packet | Owned paths | Verification |
| --- | --- | --- | --- | --- | --- |
| WP-09A | `Verified` | Main agent | Remove public country switch route/controller/shell selector and public discovery defaults | `routes/web.php`, `resources/views/layouts/app.blade.php`, public event/institution/venue listing filters | `rg` for `country.switch` in code => no matches; `php artisan route:list` => no `/negara` route |
| WP-09B | `Verified` | Main agent | Seed package countries and Malaysia address areas | `database/seeders/AddressingSeeder.php`, `database/seeders/data/malaysia-address-areas.csv`, `config/addressing.php` | `php artisan migrate:fresh --ansi && php artisan db:seed --class=AddressingSeeder --ansi` |
| WP-09C | `Verified` | Main agent | Move submit-event to package `AddressCountry` UUIDs and remove admin/MCP preferred-country defaults. Full package address field replacement remains in WP-09E/address schema rebuild. | `resources/views/components/pages/submit-event/*`, `app/Actions/Events/SubmitFrontendEventAction.php`, `app/Support/Api/Admin/AdminResourceMutationService.php`, MCP tools/tests | `view:cache`, `pint`, `git diff --check`, no preferred-country refs in code/tests |
| WP-09D | `Verified` | Main agent | Delete legacy country-mode services/tests after remaining callers are removed | `app/Support/Location/PublicCountryPreference.php`, `app/Support/Location/PublicCountryRegistry.php`, `app/Support/Location/PreferredCountryResolver.php`, related tests | `rg "PublicCountryPreference|PublicCountryRegistry|PreferredCountryResolver"` => docs only |
| WP-09E | `Not Started` | Unassigned | Delete old integer geography models/migrations after forms/search/indexing use package addressing | `app/Models/Country.php`, `app/Models/State.php`, `app/Models/City.php`, `app/Models/District.php`, `app/Models/Subdistrict.php`, legacy geography migrations/seeders | `php artisan migrate:fresh --seed` after app seeders are rebuilt |

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
