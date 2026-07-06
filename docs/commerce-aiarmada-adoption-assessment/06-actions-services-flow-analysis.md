# 06 — Actions / Services / Flow Analysis

Method: for each commerce/domain flow, traced the runtime path through `app/Actions/`, `app/Services/`, `app/Livewire/`, controllers; checked whether the package `Actions`/`Services` namespace is imported.

**Verified grep baseline:** `rg "AIArmada\\\(Events|Engagement|Inventory|Communications|Moderation|Ticketing|Seating)\\\(Actions|Services)" app/` → **ZERO matches across all six domains.** This is the single most important finding: the packages ship rich Actions/Services/States, but ilmu360 calls none of them. Adoption is model+schema only for these domains.

## Flow cutover table

| Gap ID | Flow | Local entry point | Package equivalent | Current adoption | Decision | Can local be removed? | Gap | Risk | Next step | Verification |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| AIA-ACTION-001 | Event create/save (admin) | `app/Actions/Events/SaveAdminEventAction.php` | `AIArmada\Events\Actions\*` (create/update/submit) | local only | `REPLACE_WITH_PACKAGE_CALL` | after pilot | pkg actions unused | high | Pilot: call package action from SaveAdminEventAction (or replace it); add test. | event create test |
| AIA-ACTION-001b | Event submit (public) | `app/Actions/Events/SubmitFrontendEventAction.php` | pkg submit action | local only | `REPLACE_WITH_PACKAGE_CALL` | after pilot | pkg actions unused | high | same pilot pattern | submit-event test |
| AIA-ACTION-001c | Event register | `app/Actions/Events/RegisterForEventAction.php` | pkg `EventRegistration` action | local only | `REPLACE_WITH_PACKAGE_CALL` | after pilot | pkg actions unused | high | pilot | registration test |
| AIA-ACTION-001d | Parent program / sync | `app/Actions/Events/CreateAdvancedParentProgramAction.php`, `SyncEventResourceRelationsAction.php` | pkg actions | local only | `NEEDS_HUMAN_DECISION` | unclear | may be app-specific | med | evaluate if pkg covers parent-program + relation sync | — |
| AIA-ACTION-002 | Follow / unfollow | `app/Models/Concerns/HasFollowers` + `followings` table | `AIArmada\Engagement\Actions\*` (follow/react) | local only | `REPLACE_WITH_PACKAGE_CALL` | yes, post-cutover | parallel storage (AIA-MIG-003) | med | switch follow to package action; backfill `followings`→`engagement_follows` | follow test |
| AIA-ACTION-003 | Inventory / capacity | none (unused) | `AIArmada\Inventory\Actions\*` | installed, unused | `KEEP_LOCAL_APP_SPECIFIC` (n/a) | n/a | pkg unused | low | defer until paid tickets | `rg AIArmada\\Inventory app/` → none |
| AIA-ACTION-004 | Notifications / delivery | `app/Services/Notifications/*`, `Notification*` models, jobs | `AIArmada\Communications\Actions/Services/Jobs` | local only | `REPLACE_WITH_PACKAGE_CALL` (staged) | yes, post-cutover | heaviest parallel storage (AIA-MIG-004) | **high** | stage: (1) route new deliveries through package, (2) backfill history, (3) drop local | notification delivery tests |
| AIA-ACTION-005 | Moderation / reports | `app/Services/ModerationService.php`, `ModerationReview`, `ModerationQueue` page | `AIArmada\Moderation\Actions\*` | local only | `NEEDS_HUMAN_DECISION` | unclear | pkg unused | med | map review→action; decide | moderation test |
| AIA-ACTION-006 | Membership claim | `app/Actions/Membership/SubmitMembershipClaimAction.php` (imports pkg), `ChangeSubjectMemberRole.php` | `AIArmada\Membership\Actions\*` | partial | `REPLACE_WITH_PACKAGE_CALL` | yes, post-cutover | claim table dup (AIA-MIG-005) | med | finish cutover; delete `MembershipClaim` | claim test |
| AIA-ACTION-007 | Ticketing / seating | none | `AIArmada\Ticketing\*`, `Seating\*` | installed, unused | `KEEP_LOCAL_APP_SPECIFIC` (n/a) | n/a | pkg unused | low | defer until paid tickets | none |
| AIA-ACTION-008 | Signals tracking + affiliates analytics | `app/Services/Signals/*`, `app/Services/ShareTracking/*` | `AIArmada\Signals\*`, `Affiliates\*` | **genuinely adopted** | `EXTEND_PACKAGE_WITH_REASON` / direct | no (legit bridge) | none | low | none — keep | signals tests |
| AIA-ACTION-009 | Reference search/bridge | `app/Models/Builders/ReferenceBuilder.php`, `EventSearchService` | pkg references/events query | partial bridge | `REPLACE_WITH_PACKAGE_CALL` (finish) | partial | builder bridges metadata | low | move remaining local query logic to package | reference/event search tests |
| AIA-ACTION-010 | Slug sync | `app/Actions/Slugs/SyncSlugRedirectAction.php` | none (app-specific) | local | `KEEP_LOCAL_APP_SPECIFIC` | yes (keep) | genuine app feature | low | keep | slug test |
| AIA-ACTION-011 | Event key people sync | `app/Services/EventKeyPersonSyncService.php` | none clear | local | `KEEP_LOCAL_APP_SPECIFIC` (pending) | likely keep | app-specific relation | low | verify no pkg equivalent | — |
| AIA-ACTION-012 | Contribution entity mutation | `app/Services/ContributionEntityMutationService.php` (imports CommerceSupport) | pkg `CommerceSupport` helpers | partial | `KEEP_LOCAL_APP_SPECIFIC` | yes (keep) | app orchestration over pkg helpers | low | keep | contribution test |

## Flow-by-flow reality

- **Event lifecycle (create/submit/register):** entirely app-owned in `app/Actions/Events/*`. The package's Actions/Services/States (the richest part of the events package) are unused. This is the biggest unrealized adoption.
- **Engagement:** the package is registered as a Filament plugin and its tables exist, but the actual follow/reaction behavior runs on the app's own `followings` table via a local concern. Two storage locations.
- **Communications:** the most duplicated domain. 6 app notification models + jobs + a notifications service layer, alongside 16 package communication tables. Plugin registered but no package action called.
- **Signals/Affiliates:** the correct template — app services wrap/bridge the package and the package is the source of truth for tracking + analytics data.

## Recommended cutover sequence

1. **Pilot AIA-ACTION-001c (register flow)** through a package action behind a feature test — proves the pattern with the smallest blast radius.
2. Roll the pattern across AIA-ACTION-001/001b.
3. AIA-ACTION-002 (engagement follow) — small, self-contained.
4. AIA-ACTION-006 (membership claim) — already partial.
5. AIA-ACTION-004 (communications) — last, with data migration.
