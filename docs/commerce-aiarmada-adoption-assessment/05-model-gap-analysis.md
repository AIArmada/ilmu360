# 05 — Model Gap Analysis

Scope: all `app/Models/*` (42 classes). Package models under `…/packages/*/src/Models/`.

## Inheritance map

5 app models extend a package model (verified by file read):

| App model | File | Extends (package) | Inheritance justified? |
| --- | --- | --- | --- |
| `Event` | `app/Models/Event.php` | `AIArmada\Events\Models\Event` | **Likely TEMPORARY adapter** — see AIA-MODEL-001; app adds media collections, builders, Scout config. Needs thinning. |
| `Venue` | `app/Models/Venue.php` | `AIArmada\Events\Models\Venue` | Same pattern — AIA-MODEL-002. |
| `Reference` | `app/Models/Reference.php` | `AIArmada\References\Models\Reference` | Reasonable — AIA-MODEL-003. |
| `Registration` | `app/Models/Registration.php` | `AIArmada\Events\Models\EventRegistration` | Reasonable, but check if direct usage possible — AIA-MODEL-004. |
| `MemberInvitation` | `app/Models/MemberInvitation.php` | `AIArmada\Membership\Models\MembershipInvitation` | Reasonable (token hashing) — AIA-MODEL-005. |

3 app models use package traits (composition, no inheritance): `Institution`, `Speaker`, `EventSubmission` (Addressing + Contacting traits). `User` uses Affiliates + CommerceSupport relations + Authz facade.

## Model cutover table

| Gap ID | Local model | Package model | Current usage | Decision | Can local be removed? | Reason | Risk | Required tests | Recommended refactor |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| AIA-MODEL-001 | `App\Models\Event` | `AIArmada\Events\Models\Event` | All event flows (admin/public/api/mcp) | `LOCAL_MODEL_TEMPORARY_ADAPTER` | not yet | Adds media collections, `EventBuilder`, Scout `searchable()`, accessors. App-specific extras exist but most could move to config/extension points. | high (central) | event create/show/search tests | Thin the model: move media collections to package config/extension; keep only true app accessors. Target `KEEP_LOCAL_APP_SPECIFIC` slim or `PACKAGE_MODEL_DIRECTLY_USABLE`. |
| AIA-MODEL-002 | `App\Models\Venue` | `AIArmada\Events\Models\Venue` | venue flows + nearby search | `LOCAL_MODEL_TEMPORARY_ADAPTER` | not yet | Same as Event: media, builder, Scout. | med | venue search test | Thin like Event. |
| AIA-MODEL-003 | `App\Models\Reference` | `AIArmada\References\Models\Reference` | reference flows | `LOCAL_MODEL_TEMPORARY_ADAPTER` | not yet | media collection + `ReferenceBuilder` bridge. | med | reference search test | Move media to package extension; evaluate direct usage. |
| AIA-MODEL-004 | `App\Models\Registration` | `AIArmada\Events\Models\EventRegistration` | registration flows | `EXTEND_PACKAGE_WITH_REASON` | no | Adds participant/contact wiring + app accessors. Direct usage insufficient today. | med | registration test | Keep extension; document the reason; ensure it reads `event_registrations` (it does). |
| AIA-MODEL-005 | `App\Models\MemberInvitation` | `AIArmada\Membership\Models\MembershipInvitation` | invitation issue/resolve | `EXTEND_PACKAGE_WITH_REASON` | no | Adds hashed-token storage on issue. Genuine app behavior. | low | invitation token test | Keep; ensure `membership_invitations` table used (it is). |
| AIA-MODEL-006 | `App\Models\Series` | events pkg `Series` concept (`event_series` table) | series grouping | `NEEDS_HUMAN_DECISION` | unclear | Standalone on `series` table; package has `event_series`. Duplicate concept. | med | series test | Decide ownership (AIA-MIG-001). If package, delete local model + migrate. |
| AIA-MODEL-007 | `App\Models\EventSettings` | events pkg (config/settings?) | per-event settings | `KEEP_LOCAL_APP_SPECIFIC` (pending check) | likely no | Package may not have a settings model; app-specific. | low | settings test | Verify no package equivalent; keep if none. |
| AIA-MODEL-008 | `App\Models\EventCheckin` | events pkg `EventAttendance`/`WalkIn` | check-in | `NEEDS_HUMAN_DECISION` | unclear | Package has richer attendance; app check-in is simpler UX. | med | checkin test | Decide (AIA-MIG-006). |
| AIA-MODEL-009 | `App\Models\Institution`, `Speaker` | (no package parent) | directory entities | `KEEP_LOCAL_APP_SPECIFIC` | yes (keep) | No package `Institution`/`Speaker` model exists; these are app-domain. They correctly use package *traits* (Addressing/Contacting) without inheriting. | low | directory tests | Keep as-is; good composition example. |
| AIA-MODEL-010 | `App\Models\MembershipClaim` | `AIArmada\Membership\Models\MembershipApplication` | claim workflow | `LOCAL_MODEL_CAN_BE_REMOVED` (after cutover) | yes, post-cutover | Duplicates package application concept. | med | claim test | Migrate to package application (AIA-MIG-005), then delete. |
| AIA-MODEL-011 | `App\Models\ModerationReview` | `AIArmada\Moderation\Models\*` | moderation queue | `NEEDS_HUMAN_DECISION` | unclear | Package moderation actions exist; app review model may map. | med | moderation test | Map review→package action; decide. |
| AIA-MODEL-012 | `App\Models\Notification*` (Message, Delivery, Destination, Rule, Setting, PendingNotification) | `AIArmada\Communications\Models\*` | notifications | `LOCAL_MODEL_CAN_BE_REMOVED` (after cutover) | yes, post-cutover | Heavy duplication of package communications. | **high** (prod history) | notification delivery tests | Cut over to package communications first (AIA-ACTION-004), migrate data, then delete cluster. |

## Standalone app-specific models (KEEP, no package equivalent)

`AiModelPricing`, `AiUsageLog`, `Audit` (owen-it), `ContributionRequest`, `DonationChannel`, `EventChangeAnnouncement`, `EventKeyPerson`(+pivot), `EventSeries` (pivot), `EventUser` (pivot), `Inspiration`, `MediaLink`, `Membership` (pivot), `PassportUser`, `Report`, `SavedSearch`, `SlugRedirect`, `SocialAccount`, `Space`, `Tag` (Spatie), `Team`(+`TeamInvitation`), `User`.

These are genuinely app-specific and should remain.

## Recommended refactor order

1. **AIA-MODEL-004 / 005** — confirm the two `EXTEND_PACKAGE_WITH_REASON` models are documented & tested (low effort, locks in correct patterns).
2. **AIA-MODEL-010** — cutover `MembershipClaim`→package application (paired with AIA-MIG-005).
3. **AIA-MODEL-006 / 008 / 011** — resolve the 3 `NEEDS_HUMAN_DECISION` items.
4. **AIA-MODEL-001 / 002 / 003** — thin the Event/Venue/Reference adapters (largest payoff, highest care).
5. **AIA-MODEL-012** — notification cluster deletion (last, heaviest, needs data migration).
