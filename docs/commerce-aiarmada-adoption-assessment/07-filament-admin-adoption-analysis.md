# 07 — Filament / Admin Adoption Analysis

Panels: `AdminPanelProvider` (registers 6 AIArmada plugins), `AhliPanelProvider` (none).
Plugins registered in admin: `FilamentCommunicationsPlugin`, `FilamentAddressingPlugin`, `FilamentAuthzPlugin`, `FilamentContactingPlugin`, `FilamentEngagementPlugin`, `FilamentSignalsPlugin`.
**NOT registered:** `FilamentEventsPlugin` (despite being required in composer.json).

## Filament cutover table

| Gap ID | Local admin class | Package equivalent | Current usage | Decision | Can local be removed? | Risk | Next step | Verification |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| AIA-FILAMENT-001 | `app/Filament/Resources/{States,Districts,Subdistricts}/` (empty Pages/Schemas stubs) | `FilamentAddressingPlugin` resources | stub dirs, empty | `READY_TO_REMOVE_AFTER_REFERENCES_UPDATED` | yes | low | Delete empty stub directories; geography admin is fully via the registered plugin. | `php artisan filament:optimize`; admin geography CRUD still works. |
| AIA-FILAMENT-002 | (none — `filament-events` not registered) | `AIArmada\Filament\Events\FilamentEventsPlugin` | required in composer, plugin NOT registered | `NEEDS_HUMAN_DECISION` | n/a | med | Decide: register plugin (then migrate local Event/Venue Resources to it) OR drop the composer requirement. Current state = dead dependency. | `php artisan route:list --path=admin | grep events`. |
| AIA-FILAMENT-003 | `app/Filament/Resources/Events/*`, `app/Filament/Resources/Venues/*`, `app/Filament/Resources/References/*`, `app/Filament/Ahli/*/Resource` | package `filament-events` Resources, `filament-*` (none for references) | local Resources own event/venue/reference admin UI | `KEEP_LOCAL_APP_SPECIFIC` (until AIA-FILAMENT-002 decided) | not yet | med | If `filament-events` registered: evaluate migrating local Resources to package Resources. References has no package Filament adapter → stays local. | admin event/venue CRUD tests. |
| AIA-FILAMENT-004 | (none) | `filament-ticketing`, `filament-seating`, `filament-inventory` adapters | NOT required, NOT registered | `KEEP_LOCAL_APP_SPECIFIC` (n/a) | n/a | low | Defer until paid-ticket commerce. | none. |
| AIA-FILAMENT-005 | `app/Filament/Resources/Authz/UserResource.php` extends `AIArmada\FilamentAuthz\Resources\UserResource` | package UserResource | extended | `EXTEND_PACKAGE_WITH_REASON` | no (correct) | low | Keep — correct pattern. | auth user admin test. |
| AIA-FILAMENT-006 | `app/Filament/Pages/ProductSignals.php` extends `…\FilamentSignals\Pages\LiveActivityReport`; `ShareAnalytics.php` extends `…PageViewsReport` | package signal pages | extended | `EXTEND_PACKAGE_WITH_REASON` | no (correct) | low | Keep — correct pattern. | signals page renders. |
| AIA-FILAMENT-007 | `app/Filament/Resources/MembershipClaims/*`, `app/Filament/RelationManagers/MemberInvitationsRelationManager.php` | (no filament-membership exists) | local | `KEEP_LOCAL_APP_SPECIFIC` | yes (keep) | low | No package Filament adapter for membership → stays local until upstream exists. | membership claim admin test. |
| AIA-FILAMENT-008 | `app/Filament/Pages/ModerationQueue.php` | `filament-moderation`? (not required) | local | `KEEP_LOCAL_APP_SPECIFIC` | yes (keep) | low | No package adapter required; stays local. | moderation queue renders. |
| AIA-FILAMENT-009 | `app/Filament/Widgets/EventInventoryOverview.php`, `StatsOverview.php` | (n/a) | local widgets | `KEEP_LOCAL_APP_SPECIFIC` | yes (keep) | low | App dashboard widgets. | dashboard renders. |

## Duplicate admin surfaces to watch

- `filament-communications` + `filament-engagement` plugins are registered, but because the underlying domains aren't cut over (AIA-ACTION-002/004), the admin UI for follow/notification may double up against any local management pages. Verify no two resources claim the same model/table once cutover proceeds.

## Recommended order

1. AIA-FILAMENT-001 — delete empty geography stubs (trivial, safe).
2. AIA-FILAMENT-002 — decide `filament-events` fate (blocks AIA-FILAMENT-003).
3. AIA-FILAMENT-003 — only after events flow cutover (AIA-ACTION-001) and the filament-events decision.
