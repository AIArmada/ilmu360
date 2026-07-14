# 03 — Adoption Matrix

Per-package, per-area adoption with evidence. "Evidence" = concrete file path or grep result.

| Package | Area | Installed | Configured | Migrations used | Models used directly | Models extended | Actions/Services used | Local dups removed? | Tests prove adoption | Adoption | Cutover | Evidence |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| addressing | geo | ✓ | ✓ `config/addressing.php` | ✓ (tables in DB) | indirect | n/a | ✓ package resolver+area bridge+seeders+observers | ✓ legacy models deleted | partial | 5 | 5 | `AIArmada\Addressing\Support\AddressCountryResolver`; `AIArmada\Addressing\Support\AddressAreaStateBridge`; no `app/Models/Country.php` |
| affiliates | share/analytics | ✓ | — | ✓ | no | no | ✓ via app bridge svcs | n/a (wrapped) | partial | 4 | 4 | `app/Services/ShareTracking/*`, `app/Services/Signals/AffiliateSignalsBridge.php` |
| authz | perms | ✓ | ✓ `config/authz.php` | ✓ `authz_scopes` | no | `UserResource` extends pkg | ✓ `Authz` facade + gates | partial | partial | 4 | 4 | `app/Filament/Resources/Authz/UserResource.php`; `User` uses `Authz` facade |
| commerce-support | foundation | ✓ | — | ✓ | ✓ `Permission`/`Role` models | no | ✓ `OwnerContext` | partial | no | 3 | 3 | `config/permission.php` → `AIArmada\CommerceSupport\Models\*` |
| communications | notifications | ✓ | ✓ `config/communications.php` | ✓ 16 tables | no | no | **NO** | **NO** — app `Notification*` runs | no | 2 | 1 | zero `AIArmada\Communications` in `app/`; `notification_*` tables present |
| contacting | contacts/social | ✓ | ✓ `config/contacting.php` | ✓ | indirect (traits) | n/a | ✓ via traits | ✓ `Contact`/`SocialMedia` deleted | partial | 5 | 5 | `HasContactMethods`/`HasSocialProfiles` on Institution/Speaker/EventSubmission |
| engagement | follows/reactions | ✓ | ✓ `config/engagement.php` | ✓ 10 tables | no | no | **NO** | **NO** — `followings` local | no | 2 | 2 | zero `AIArmada\Engagement` in `app/`; `followings` table used |
| events | event domain | ✓ | **NO config** | ✓ ~60 tables | no | Event/Venue/Registration extend | **NO** pkg actions | **NO** — `app/Actions/Events/*` | no | 3 | 2 | `app/Models/Event.php extends AIArmada\Events\Models\Event`; zero `AIArmada\Events\Actions` imports |
| inventory | stock | ✓ | ✓ `config/inventory.php` | ✓ 16 tables | no | no | **NO** | n/a | no | 1 | 0 | zero `AIArmada\Inventory` in `app/` |
| membership | membership | ✓ | ✓ `config/membership.php` | ✓ | no | `MemberInvitation` extends | partial (claim action imports pkg) | **NO** — `MembershipClaim` local | partial | 2 | 2 | `app/Models/MemberInvitation.php extends …MembershipInvitation`; `membership_claims` vs `membership_applications` |
| moderation | moderation | ✓ | ✓ `config/moderation.php` | ✓ | no | no | **NO** | **NO** — `ModerationReview` local | no | 1 | 1 | zero `AIArmada\Moderation` in `app/`; `moderation_reviews` table |
| references | references | ✓ | ✓ `config/references.php` | ✓ (`references` table) | no | `Reference` extends | partial (builder bridge) | partial | partial | 3 | 3 | `app/Models/Reference.php extends …Reference`; `app/Models/Builders/ReferenceBuilder.php` |
| seating | seating | ✓ | **NO config** | ✓ | no | no | **NO** | n/a | no | 1 | 0 | zero `AIArmada\Seating` in `app/` |
| signals | analytics | ✓ | ✓ `config/signals.php`+`product-signals.php` | ✓ 11 tables | indirect | pages extend pkg | ✓ tracking+recorder | n/a | partial | 5 | 5 | `app/Services/Signals/*`; `ProductSignals`/`ShareAnalytics` pages extend pkg |
| ticketing | tickets | ✓ | **NO config** | ✓ | no | no | **NO** | n/a | no | 1 | 0 | zero `AIArmada\Ticketing` in `app/` |
| filament-addressing | admin UI | ✓ | — | — | n/a | — | plugin registered | ✓ | partial | 5 | 5 | `AdminPanelProvider` registers `FilamentAddressingPlugin` |
| filament-authz | admin UI | ✓ | ✓ | — | n/a | UserResource extends | plugin registered | partial | partial | 4 | 4 | `AdminPanelProvider` registers `FilamentAuthzPlugin` |
| filament-communications | admin UI | ✓ | — | — | n/a | — | plugin registered | **NO** (duplicate surface) | no | 2 | 1 | registered but app comms not cut over |
| filament-contacting | admin UI | ✓ | — | — | n/a | — | plugin registered | ✓ | partial | 5 | 5 | registered |
| filament-engagement | admin UI | ✓ | — | — | n/a | — | plugin registered | **NO** | no | 2 | 2 | registered but engagement not cut over |
| filament-events | admin UI | ✓ (required) | — | — | n/a | — | **NOT registered** | n/a | no | 1 | 0 | required in composer; no plugin() call in AdminPanelProvider |
| filament-signals | admin UI | ✓ | ✓ | — | n/a | pages extend | plugin registered | ✓ | partial | 5 | 5 | registered; `ProductSignals`/`ShareAnalytics` extend pkg pages |

## Reading the matrix

- **Level-5 (fully adopted):** addressing, contacting, signals (+ their filament adapters). These are the proven template for how the rest should look.
- **Level-3 "models only":** events, references. Schema + inheritance done; flows + admin UI still local. This is the immediate cutover zone.
- **Level-2 "plugin registered, logic local":** engagement, membership, communications. Plugin surface exists; runtime still app-owned. Parallel-storage risk.
- **Level-1 "schema installed, nothing used":** inventory, ticketing, seating, filament-events. Pure dead weight until a feature demands them.
