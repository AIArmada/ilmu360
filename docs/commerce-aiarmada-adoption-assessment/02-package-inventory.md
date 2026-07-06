# 02 — Package Inventory

Source of truth: `composer.json` `require` + path repo `/Users/Saiffil/Herd/commerce/packages/*`.
"App uses it?" verified by grepping `app/` for `AIArmada\<Pkg>\` imports and checking Filament plugin registration in `app/Providers/Filament/AdminPanelProvider.php`.

## Required packages (22)

| Package | Source Path | Version | Migrations | Models | Actions/Services | Filament | Config | Tests | App uses it? | Local duplication? |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `aiarmada/addressing` | `…/packages/addressing` | dev-main | yes (`address_*`, `addressables`, `addresses`, `address_snapshots`) | yes | yes (Actions, Casts, Commands) | via `filament-addressing` | `config/addressing.php` ✓ | yes | **YES** — resolver, seeders, traits, observers | NO (legacy geo models deleted) |
| `aiarmada/affiliates` | `…/packages/affiliates` | dev-main | yes (`affiliate_*`, ~31 tables) | yes | yes | n/a (no filament-affiliates required) | — | yes | **YES** — `ShareTrackingService`, 5 analytics svcs | wrapped (app services bridge package) |
| `aiarmada/authz` | `…/packages/authz` | dev-main | yes (`authz_scopes`) | yes | yes | via `filament-authz` | `config/authz.php` ✓ | yes | **YES** — `Authz` facade, gates, UserResource extends | NO |
| `aiarmada/commerce-support` | `…/packages/commerce-support` | dev-main | yes (`notification_preferences`, `reports`, `saved_searches`, `webhook_calls`) | yes (`Permission`/`Role`) | yes (Concerns, Webhooks) | n/a | — | yes | **PARTIAL** — Permission/Role models + `OwnerContext` | `reports`/`saved_searches` table overlap (AIA-MIG-007) |
| `aiarmada/communications` | `…/packages/communications` | dev-main | yes (`communication_*` 16 tables, `notification_inboxes`) | yes | yes | via `filament-communications` | `config/communications.php` ✓ | yes | **NO app-code usage** (plugin registered, tables exist) | **YES** — app `Notification*` cluster (AIA-ACTION-004) |
| `aiarmada/contacting` | `…/packages/contacting` | dev-main | yes (`contact_methods`, `contact_snapshots`, `social_profiles`) | yes | yes | via `filament-contacting` | `config/contacting.php` ✓ | yes | **YES** — traits on Institution/Speaker/EventSubmission | NO |
| `aiarmada/engagement` | `…/packages/engagement` | dev-main | yes (`engagement_*` 10 tables) | yes | yes | via `filament-engagement` | `config/engagement.php` ✓ | yes | **NO app-code usage** (plugin registered) | **YES** — app `followings` table (AIA-MIG-003) |
| `aiarmada/events` | `…/packages/events` | dev-main | yes (`events`, `venues`, `event_*` ~60 tables) | yes | **yes (rich Actions/Services/States)** | via `filament-events` (NOT registered) | **`config/events.php` NOT published** | yes | **MODEL-ONLY** — Event/Venue/Registration extend pkg models; zero pkg actions called | **YES** — app `app/Actions/Events/*` (AIA-ACTION-001) |
| `aiarmada/inventory` | `…/packages/inventory` | dev-main | yes (`inventory_*` 16 tables) | yes | yes | `filament-inventory` (NOT required) | `config/inventory.php` ✓ | yes | **NO** — zero `AIArmada\Inventory` imports | schema-only install (AIA-MIG-008) |
| `aiarmada/membership` | `…/packages/membership` | dev-main | yes (`membership_applications`, `membership_invitations`) | yes | yes | n/a (filament-membership does not exist) | `config/membership.php` ✓ | yes | **PARTIAL** — `MemberInvitation` extends pkg; claim flow local | **YES** — `membership_claims` table (AIA-MIG-005) |
| `aiarmada/moderation` | `…/packages/moderation` | dev-main | yes (`moderation_actions`, `moderation_blocks`) | yes | yes | n/a | `config/moderation.php` ✓ | yes | **NO app-code usage** | **YES** — app `ModerationReview` + queue (AIA-ACTION-005) |
| `aiarmada/references` | `…/packages/references` | dev-main | yes (`ref_references` default) | yes | yes | n/a | `config/references.php` ✓ | yes | **PARTIAL** — `Reference` extends pkg + builder bridge | local Resource/UI remains (AIA-MODEL-003) |
| `aiarmada/seating` | `…/packages/seating` | dev-main | yes (`seat_*`, `seat_maps`) | yes | yes | `filament-seating` (NOT required) | **`config/seating.php` NOT published** | yes | **NO** — zero imports | schema-only install (AIA-MIG-008) |
| `aiarmada/signals` | `…/packages/signals` | dev-main | yes (`signal_*` 11 tables) | yes | yes | via `filament-signals` | `config/signals.php` ✓, `product-signals.php` ✓ | yes | **YES** — full tracking + analytics | NO |
| `aiarmada/ticketing` | `…/packages/ticketing` | dev-main | yes (`ticket_*` 7 tables) | yes | yes | `filament-ticketing` (NOT required) | **`config/ticketing.php` NOT published** | yes | **NO** — zero imports | schema-only install (AIA-MIG-008) |
| `aiarmada/filament-addressing` | `…/packages/filament-addressing` | dev-main | — | — | — | Resources/RM/Schemas | — | — | **YES** — registered | NO |
| `aiarmada/filament-authz` | `…/packages/filament-authz` | dev-main | — | — | — | Resources/Forms/Tables | `config/filament-authz.php` ✓ | — | **YES** — registered, UserResource extends | NO |
| `aiarmada/filament-communications` | `…/packages/filament-communications` | dev-main | — | — | — | Resources/Pages/RM | — | — | registered but app comms not cut over | duplicate admin surface |
| `aiarmada/filament-contacting` | `…/packages/filament-contacting` | dev-main | — | — | — | Resources/RM/Schemas | — | — | **YES** — registered | NO |
| `aiarmada/filament-engagement` | `…/packages/filament-engagement` | dev-main | — | — | — | Resources/RM/Widgets | — | — | registered but engagement not cut over | duplicate admin surface |
| `aiarmada/filament-events` | `…/packages/filament-events` | dev-main | — | — | — | Resources/Pages/RM/Widgets | — | — | **NOT registered** (required in composer only) | AIA-FILAMENT-002 |
| `aiarmada/filament-signals` | `…/packages/filament-signals` | dev-main | — | — | — | Resources/Pages/Widgets | `config/filament-signals.php` ✓ | — | **YES** — registered, pages extended | NO |

## Packages in the path repo but NOT required by ilmu360 (intentional)

`cart`, `checkout`, `orders`, `products`, `pricing`, `promotions`, `vouchers`, `shipping`, `customers`, `cashier`, `cashier-chip`, `chip`, `jnt`, `tax`, `growth`, `docs`, `affiliate-network`, `feedback`, `csuite`, plus their `filament-*` adapters, plus `filament-cart`, `filament-checkout`, `filament-orders`, `filament-vouchers`, `filament-promotions`, `filament-pricing`, `filament-products`, `filament-cashier`, `filament-cashier-chip`, `filament-chip`, `filament-tax`, `filament-shipping`, `filament-ticketing`, `filament-seating`, `filament-inventory`, `filament-jnt`, `filament-docs`, `filament-feedback`, `filament-growth`, `filament-commerce-support`, `filament-customers`, `filament-affiliate-network`.

**Reason:** ilmu360 has no commerce/payment/ticketing-purchase features yet (verified: zero local cart/order/voucher/checkout/payment/chip code). These become relevant only if/when paid event ticketing is built. They should NOT be adopted speculatively.

## Key inventory findings

1. **22 packages required, ~6 genuinely driving runtime** (addressing, contacting, signals, affiliates, authz, commerce-support foundation).
2. **5 packages are schema/plugin-installed but have ZERO app-code usage**: `communications`, `engagement`, `inventory`, `moderation`, `ticketing`, `seating` (6 counting events-actions).
3. **`filament-events` is required but its plugin is never registered** — dead composer dependency today.
4. **3 package configs are NOT published** despite migrations running: `events.php`, `ticketing.php`, `seating.php` → those packages run on default table names.
