# Reassessment Closure: ilmu360° on AIArmada Packages

The original version of this document asked whether ilmu360° could be rebuilt on top of AIArmada packages. That is no longer a hypothetical planning exercise.

The current codebase already consumes local AIArmada packages through the Composer path repository at `/Users/Saiffil/Herd/commerce/packages/*`. The remaining work is not package-readiness discovery. It is cutover cleanup: deleting app-owned wrappers, finishing package-first surface rebuilds, and closing final verification debt.

## 1. Current Adoption Truth

### 1.1 Installed directly in this app

`composer show 'aiarmada/*' --direct` currently reports **22 installed AIArmada packages**:

- `addressing`
- `affiliates`
- `authz`
- `commerce-support`
- `communications`
- `contacting`
- `engagement`
- `events`
- `filament-addressing`
- `filament-authz`
- `filament-communications`
- `filament-contacting`
- `filament-engagement`
- `filament-events`
- `filament-signals`
- `inventory`
- `membership`
- `moderation`
- `references`
- `seating`
- `signals`
- `ticketing`

This supersedes the old “5 installed, 54 source-only” conclusion.

### 1.2 Durable evidence in the repo

- `composer.json` now requires the package set above directly.
- `docs/aiarmada-adoption/status.md` tracks the active cutover state.
- `docs/aiarmada-adoption/domain-mapping.md` records package ownership targets.
- `config/addressing.php`, `config/contacting.php`, `config/communications.php`, `config/membership.php`, `config/moderation.php`, and `config/references.php` exist locally, which means these packages are no longer theoretical inputs.

## 2. Assessment-By-Assessment Closure

| Original assessment area | Old conclusion | Current codebase reality | Status |
| --- | --- | --- | --- |
| Package inventory | 5 installed, most relevant packages source-only | 22 AIArmada packages are installed from the local monorepo path repository | `Closed` |
| Addressing policy | “Do not adopt addressing” | `aiarmada/addressing` is installed, seeded, and live in runtime flows; country switching has been removed | `Closed` |
| Contact and social models | Package coverage only in source | `contacting` and `filament-contacting` are installed; legacy `Contact` and `SocialMedia` classes are already gone from `app/` | `Closed` |
| Events package feasibility | Theoretical replacement only | `events`, `seating`, `ticketing`, and `filament-events` are installed; runtime search/show/venue behavior already bridges package-owned occurrences and metadata | `In Progress` |
| Engagement feasibility | Theoretical replacement only | `engagement` and `filament-engagement` are installed; public event engagement already uses package actions | `In Progress` |
| References feasibility | Source-only package | `references` is installed; reference query/runtime bridges exist, but the app still keeps a local `Reference` wrapper model and UI | `In Progress` |
| Membership feasibility | Source-only package | `membership` is installed; `App\Models\MemberInvitation` now sits on the installed package model and invitation token storage/resolution follows the package contract, but app-owned `MembershipClaim` and broader invitation workflows still exist | `In Progress` |
| Communications feasibility | Source-only package | `communications` and `filament-communications` are installed, but app-owned notification models/jobs still exist | `In Progress` |
| Moderation / bans / blocks | Source-only package | `moderation` is installed, but app-owned moderation/report workflows still exist beside the package | `In Progress` |
| Filament adapter availability | Most relevant UIs source-only | Relevant Filament adapters are installed directly; the remaining issue is surface migration, not package availability | `Closed` |
| Public Livewire, MCP, donation channels | No package coverage | Still intentionally app-owned under ADR-006 | `Closed (Intentional)` |
| Source vs vendor reconciliation | Vendor/source drift blocked adoption | Composer path repositories make local package source the installed runtime dependency; the live problem is app cutover, not vendor publishing drift | `Closed` |

## 3. Domain Reality Against The Current Codebase

| Domain | Current reality in the repo | Remaining live gap |
| --- | --- | --- |
| Geography and global discovery | Package `AddressCountry` / `AddressArea` data is seeded by `database/seeders/AddressingSeeder.php`. Country switching is removed. Legacy `Country` / `State` / `District` / `Subdistrict` wrappers and their dead admin/test surfaces are now deleted, and cache/deletion behavior lives on package observers. | Keep collapsing the last legacy address alias shapes only where broader app wrappers still need them during the remaining domain cutovers. |
| Contacts and social profiles | Package-backed contact/social aliases are live. The old app contact/social classes are already removed. | Finish deleting any remaining app-owned compatibility accessors after all UI/API surfaces fully stop speaking the old shape. |
| Events, seating, ticketing | The app now depends on `events`, `seating`, and `ticketing`. Package tables and occurrence-backed behavior are live in runtime slices. Focused organizer regressions now pass without legacy organizer field reads. | Delete remaining app event wrappers (`App\Models\Event`, `EventSettings`, `Registration`, `EventCheckin`) once all public/API/MCP/admin paths are rebuilt package-first. |
| Institutions, speakers, venues, series | These domains already speak to package-backed addressing/contacting/event relations in multiple runtime paths. | App models and resources still own persistence and UX contracts; those wrappers remain to be deleted or thinned further. |
| References | Package-backed reference querying is live, and the package is installed. | Local `App\Models\Reference`, local resource flows, and legacy contract shapes still remain. |
| Membership | Package is installed and package ownership is the target architecture. `App\Models\MemberInvitation` now wraps the installed package model, invitation rows store hashed tokens, and raw accept links resolve through package-compatible matching. | Claim/invitation workflows still run through local app models/actions/resources, and full package action replacement is blocked by event-specific organizer/co-organizer role semantics. |
| Communications | Package and Filament adapter are installed. | App still carries notification models, delivery rules, and app-specific channels/digest scheduling. |
| Moderation and contributions | Package is installed and moderation ownership is accepted in the rewrite docs. | App still carries `ModerationReview`, report handling, and entity mutation application logic. |
| Tags and taxonomy | Package taxonomy exists through `events`. | The app still uses local Spatie tag ownership and has not fully cut over taxonomy storage/querying. |
| Public Livewire UX, MCP, donation channels | These remain app-owned by design. | No gap to close here unless a future generic package is introduced. |

## 4. What The Original Document Got Wrong Now

These conclusions are no longer true and should not be used for planning:

- “Actual status: planning analysis only.”
- “None of the packages identified here (beyond the original 5) have been adopted.”
- “Do not adopt addressing.”
- “Source-only package availability is the main blocker.”
- “Filament package UIs are unavailable in the app.”

The blocker class has changed. Package installation and source availability are no longer the issue. The remaining work is deletion, surface migration, and verification.

## 5. Remaining Live Gaps To Cutover Exit

This is the actionable remainder after closing the stale assumptions above:

1. Delete remaining app wrappers for package-owned domains once their public/API/MCP/admin surfaces no longer depend on legacy shapes.
2. Finish the communications cutover so package-owned communication records replace the app notification engine, leaving only app-specific FCM, WhatsApp, and digest orchestration.
3. Finish the membership cutover so package-owned membership models replace app claim/invitation ownership.
4. Decide whether Spatie tags remain an intentional app boundary or complete the taxonomy cutover to package `EventTaxonomy` / `EventTerm`.
5. Finish the broader package-first domain deletions that still sit outside geography: event wrappers, references, membership, communications, and taxonomy.
6. Keep the package-first verification debt closed as each remaining domain packet lands.

## 6. Closure Summary

The readiness question is answered: **AIArmada package adoption is real and already in flight inside this repo.**

What is closed:

- Package availability and installation uncertainty
- Addressing adoption uncertainty
- Contact/social package availability uncertainty
- Filament adapter availability uncertainty
- Source-vs-vendor publishing drift as the main blocker

What remains:

- Final package-first cutover work inside the app
- Deletion of compatibility wrappers
- Broader verification and static-analysis cleanup

This document should now be read as a **closure audit** for the old reassessment, not as a speculative readiness study.
