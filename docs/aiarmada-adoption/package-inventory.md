# Package Inventory

Baseline date: 2026-06-28 · **Install list last fully accurate for early path-repo work — verify against Composer**

> **Live install truth (2026-07-10):** **25** direct `aiarmada/*` requires — see [`status.md`](status.md).  
> This file remains useful as a **catalog of the monorepo** (tiers, deferred packages).  
> WP-01 still open if a full line-by-line reconcile of the tables below is desired.

Source path: `/Users/Saiffil/Herd/commerce/packages/*`

## Summary

| Category | Count | Notes |
| --- | ---: | --- |
| Composer packages | 59 | 32 backend/meta packages and 27 Filament adapter packages. |
| Migration files | 247 | Package migrations generally use UUIDs and avoid DB constraints/cascades. |
| Eloquent models | 257 | Curated baseline classification. A raw `src/Models/*.php` file count is broader because it includes concerns/support files. |
| Route files | 14 | Route-bearing packages need route-name and middleware review. |
| Resource files | 116 | Curated Filament/resource baseline classification. A raw `*Resources*/*.php` count is broader because it includes non-Filament resource paths and nested support files. |
| Package-local tests | 0 | No package-local tests/testbench setup found in baseline scan. |

## Current App Adoption Truth

The app currently requires these AIArmada packages:

- `aiarmada/affiliates`
- `aiarmada/commerce-support`
- `aiarmada/filament-authz`
- `aiarmada/filament-signals`
- `aiarmada/signals`

The app now has Composer path repositories pointing to `/Users/Saiffil/Herd/commerce/packages/*`. All installed AIArmada packages resolve from local source via symlinks.

## Adoption Tiers

| Tier | Meaning |
| --- | --- |
| Foundation | Must be adopted early because many package families depend on it. |
| Domain Replacement | Directly replaces an ilmu360 app domain. |
| Admin UI | Filament adapter for an adopted backend package. |
| Commerce Capability | Adopt only when a concrete ilmu360 workflow needs it. |
| Deferred | Keep inventoried, but do not install in the initial rewrite. |
| Meta | Do not install directly unless explicitly chosen later. |

## Backend And Meta Packages

| Package | Migrations | Models | Tier | Readiness | Notes |
| --- | ---: | ---: | --- | --- | --- |
| `addressing` | 5 | 4 | Domain Replacement | `Ready` after dependency resolve | Target UUID geography replacement; must support global discovery and country-specific address-area hierarchies without app-wide country switching. |
| `affiliate-network` | 6 | 6 | Commerce Capability | `Deferred` | Relevant only if real affiliate network workflows are introduced. |
| `affiliates` | 26 | 27 | Foundation | `Ready` | Local path source active. Symlinked at vendor/aiarmada/affiliates. |
| `authz` | 2 | 0 | Foundation | `Ready` | Permission v8 resolved. Installed automatically as dependency. |
| `cart` | 2 | 2 | Commerce Capability | `Assessing` | Needed for approved paid event ticket workflow. |
| `cashier` | 0 | 2 | Commerce Capability | `Deferred` | Payment/billing capability only. |
| `cashier-chip` | 3 | 3 | Commerce Capability | `Deferred` | Requires payment workflow decision. |
| `checkout` | 1 | 1 | Commerce Capability | `Assessing` | Needed for approved paid event ticket workflow; pulls cart, customers, docs, orders, pricing, products, shipping. |
| `chip` | 10 | 2 | Commerce Capability | `Deferred` | Route-bearing payment provider package. |
| `commerce-support` | 7 | 4 | Foundation | `Ready` | Local path source active. Migration stubs deferred to WP-07. |
| `communications` | 16 | 16 | Domain Replacement | `Ready` after dependency resolve | Replaces app notification engine; custom FCM/WhatsApp/digest remain app-owned. |
| `contacting` | 3 | 3 | Domain Replacement | `Ready` after dependency resolve | Replaces contact/social profile storage and normalization. |
| `csuite` / `aiarmada/commerce` | 0 | 0 | Meta | `Deferred` | Do not use for initial adoption. |
| `customers` | 7 | 5 | Commerce Capability | `Deferred` | Needed only if checkout/order workflows are adopted. |
| `docs` | 4 | 14 | Commerce Capability | `Assessing` | Useful for invoice/docs workflows; depends on browser/PDF tooling. |
| `engagement` | 10 | 10 | Domain Replacement | `Ready` after dependency resolve | Replaces follows, bookmarks, responses, reactions, reminders, subscriptions, shares. |
| `events` | 70 | 70 | Domain Replacement | `Assessing` | Core replacement; must support free walk-ins, free ticketed events, paid tickets, mixed tickets, passes, seating, capacity, and check-in. |
| `feedback` | 1 | 9 | Commerce Capability | `Assessing` | Could support reports/feedback if generic enough. |
| `growth` | 3 | 3 | Foundation | `Assessing` | Depends on Signals; adopt with telemetry/growth workflows. |
| `inventory` | 14 | 15 | Commerce Capability | `Assessing` | `events` depends on it; verify ticket capacity/inventory role for paid event tickets. |
| `jnt` | 5 | 5 | Commerce Capability | `Deferred` | Logistics only. |
| `membership` | 2 | 2 | Domain Replacement | `Ready` after authz resolve | Replaces membership claims, invitations, and roles where generic. |
| `moderation` | 2 | 2 | Domain Replacement | `Ready` after dependency resolve | Replaces blocks and generic moderation actions. |
| `orders` | 6 | 6 | Commerce Capability | `Assessing` | Needed for approved paid event ticket workflow. |
| `pricing` | 3 | 3 | Commerce Capability | `Assessing` | Needed for approved paid event ticket workflow. |
| `products` | 14 | 10 | Commerce Capability | `Assessing` | Assess for event ticket/product bundling and donation products. |
| `promotions` | 1 | 1 | Commerce Capability | `Deferred` | Needed for vouchers/promotions. |
| `references` | 1 | 1 | Domain Replacement | `Ready` | Sluggable v4 constraint resolved; API compatible. |
| `shipping` | 7 | 8 | Commerce Capability | `Deferred` | Needed only if physical delivery/logistics enter scope. |
| `signals` | 11 | 11 | Foundation | `Assessing` | Already installed; move to local path and keep privacy-first event curation. |
| `tax` | 4 | 4 | Commerce Capability | `Deferred` | Needed only for taxable commerce workflows. |
| `vouchers` | 5 | 5 | Commerce Capability | `Deferred` | Needed for paid workflow discounts or credits. |

## Filament Adapter Packages

| Package | Tier | Readiness | Notes |
| --- | --- | --- | --- |
| `filament-addressing` | Admin UI | `Ready` after backend adoption | Admin UI for addressing. |
| `filament-affiliate-network` | Admin UI | `Deferred` | Depends on affiliate network adoption. |
| `filament-affiliates` | Admin UI | `Assessing` | Useful with local affiliates. |
| `filament-authz` | Foundation | `Blocked` | Depends on authz/permission alignment. |
| `filament-cart` | Admin UI | `Deferred` | Only if cart is adopted. Has its own migrations/models. |
| `filament-cashier` | Admin UI | `Deferred` | Only if cashier is adopted. |
| `filament-cashier-chip` | Admin UI | `Deferred` | Only if CHIP billing is adopted. |
| `filament-chip` | Admin UI | `Deferred` | Only if CHIP provider is adopted. |
| `filament-commerce-support` | Admin UI | `Assessing` | Useful for package settings/support surfaces. |
| `filament-communications` | Admin UI | `Ready` after communications adoption | Admin logs and templates. |
| `filament-contacting` | Admin UI | `Ready` after contacting adoption | Admin contact management. |
| `filament-customers` | Admin UI | `Deferred` | Only if customers is adopted. |
| `filament-docs` | Admin UI | `Deferred` | Only if docs is adopted. |
| `filament-engagement` | Admin UI | `Ready` after engagement adoption | Admin engagement views. |
| `filament-events` | Admin UI | `Assessing` | Replace app event resources after events ownership decision. |
| `filament-feedback` | Admin UI | `Assessing` | Depends on feedback/report decision. |
| `filament-growth` | Admin UI | `Assessing` | Depends on growth adoption. |
| `filament-inventory` | Admin UI | `Deferred` | Only if inventory UX is needed. |
| `filament-jnt` | Admin UI | `Deferred` | Logistics only. |
| `filament-orders` | Admin UI | `Deferred` | Only if orders are adopted. |
| `filament-pricing` | Admin UI | `Deferred` | Only if pricing is adopted. |
| `filament-products` | Admin UI | `Deferred` | Only if products are adopted. |
| `filament-promotions` | Admin UI | `Deferred` | Only if promotions are adopted. |
| `filament-shipping` | Admin UI | `Deferred` | Shipping only. |
| `filament-signals` | Foundation | `Assessing` | Already installed; move to local path. |
| `filament-tax` | Admin UI | `Deferred` | Depends on tax and filament-authz. |
| `filament-vouchers` | Admin UI | `Deferred` | Only if vouchers are adopted. |

## Route-Bearing Packages

Audit middleware, route names, auth, throttling, and docs before enabling routes from:

- `affiliate-network`
- `affiliates`
- `cashier`
- `checkout`
- `chip`
- `communications`
- `docs`
- `filament-authz`
- `filament-docs`
- `jnt`
- `shipping`
- `signals`

## Dependency Chains

- `commerce-support` is the hub dependency for most packages.
- `checkout` pulls `cart`, `customers`, `docs`, `orders`, `pricing`, `products`, and `shipping`.
- `events` pulls `commerce-support`, `contacting`, and `inventory`.
- `growth` pulls `signals`.
- Most `filament-*` packages pull the matching backend package.
- `filament-tax` pulls `filament-authz`.
- `filament-affiliate-network` pulls `affiliate-network` and `filament-affiliates`.

## Readiness Risks

- Local path package strategy is not configured yet.
- Dependency drift exists for Laravel, Filament, `spatie/laravel-permission`, and `spatie/laravel-sluggable`.
- Fresh schema removes table-collision concerns as compatibility blockers, but package table ownership must still be explicit.
- Package-local tests are missing and must be added or replaced with app-level acceptance coverage.
- Package route discovery can introduce public routes unexpectedly unless reviewed package by package.
