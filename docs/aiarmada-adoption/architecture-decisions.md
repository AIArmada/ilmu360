# Architecture Decisions

## ADR-001 - Fresh Schema Rewrite

Status: `Accepted`

Decision: The rewrite uses a fresh schema with no production data migration, no legacy compatibility tables, and no old model adapters.

Consequences:

- Existing app migrations can be removed or replaced when a package owns the domain.
- Tests should validate the fresh target state, not old/new parity.
- Any one-time seed data must be canonical target data, not migrated legacy rows.

## ADR-002 - Package Standards Win

Status: `Accepted`

Decision: AIArmada package standards win over current app conventions where they conflict.

Consequences:

- `aiarmada/addressing` UUID geography replaces the app's integer geography exception.
- Package model/table contracts should be used directly unless a generic package seam is missing.
- App-level wrappers are temporary only when a package contract does not exist yet.

## ADR-003 - Local Packages Are First-Party Source

Status: `Accepted`

Decision: Packages under `/Users/Saiffil/Herd/commerce/packages/*` are first-party source for this rewrite.

Consequences:

- Composer path repositories are the target development setup.
- Package fixes are allowed.
- Package fixes must remain generic and must not encode ilmu360-specific Islamic domain behavior, copy, or workflows.

## ADR-004 - Do Not Use The Meta Package Initially

Status: `Accepted`

Decision: Do not adopt `aiarmada/commerce` as a meta package during the initial rewrite.

Consequences:

- Packages are installed by capability and dependency need.
- Unused storefront commerce packages remain tracked as `Deferred`.
- Avoid accidental route/provider/migration activation from unrelated packages.

## ADR-005 - Package-Owned Domain Replacement

Status: `Accepted`

Decision: When a package covers a domain, the package becomes the owner and the app implementation is deleted after verification.

Consequences:

- No long-term dual-write, dual-read, or bridge models.
- Public API, MCP, Filament, search, and Livewire surfaces must be rebuilt against package models.
- Deletion waits for tests and runtime proof.

## ADR-006 - App-Owned Product Experience

Status: `Accepted`

Decision: Public UX, MCP servers/tools, donation channels, and highly Islamic presentation remain app-owned unless a package provides a generic capability.

Consequences:

- Packages provide reusable mechanics.
- The app provides product language, page composition, prompts, and domain-specific editorial decisions.
- Tracking is curated through Signals for meaningful workflow outcomes.

## ADR-007 - Verification Before Phase Completion

Status: `Accepted`

Decision: A phase cannot be marked `Verified` without command or runtime evidence in `review-log.md`.

Consequences:

- `status.md` is not enough by itself.
- Each phase has explicit exit criteria.
- If verification fails, mark the phase `Blocked` or `In Progress`, not `Verified`.

## ADR-008 - No Silent Route Activation

Status: `Accepted`

Decision: Route-bearing packages must be reviewed before their routes become part of the app runtime.

Consequences:

- Package providers, middleware, route names, auth, throttling, and docs must be checked.
- Public route changes must be reflected in API/MCP docs where applicable.
- Unexpected public routes are release blockers.

## ADR-009 - Events Are Not Free-Only

Status: `Accepted`

Decision: The package-backed ilmu360 event domain must support free walk-ins, optional RSVP, free ticketed events, paid ticketed events, mixed free/paid ticket types, passes, capacity, waitlists, seating, and check-in.

Consequences:

- Do not recreate the current free-only event abstraction in app code.
- Phase 5 must expose package event participation and ticketing primitives in admin/API/MCP contracts.
- Phase 7 must assess the paid-ticket commerce package chain as an approved workflow.
- The public UX can choose which modes to emphasize first, but persistence and workflows must be ready for all supported event modes.

## ADR-010 - Global Discovery Without Country Switching

Status: `Accepted`

Decision: ilmu360° must be global by default. The target architecture removes app-wide country switching and uses `aiarmada/addressing` countries and hierarchical address areas for global filtering/search.

Consequences:

- Remove `PublicCountryPreference`, `PublicCountryRegistry`, `PreferredCountryResolver`, `/negara/{country}`, and layout country selector behavior during cutover.
- Country remains an optional filter, not a session/application mode.
- Speakers, institutions, venues, and events can participate across multiple countries without changing user context.
- Replace fixed Malaysia-oriented geography columns with package address areas and country-specific administrative area types.
- Search indexes, saved searches, API contracts, MCP docs, and public filters must support global results by default.

## ADR-011 - Package Taxonomy Is Event Classification Source Of Truth

Status: `Accepted` (2026-07-09)

Decision: Event classification uses AIArmada Events package primitives exclusively:

- `EventTaxonomy` (codes: `domain`, `discipline`, `source`, `issue` for ilmu360 seeds)
- `EventTerm` (terms within a taxonomy; `is_active` for moderation)
- `EventClassification` (event/occurrence/session attachment with denormalized `taxonomy_code` / `term_code`)

Spatie Tags are **not** the long-term event classification store. They may remain temporarily only for admin seed/migration tooling until all write paths and indexes use package classifications. Dual-write/dual-index is forbidden at Phase 9 exit.

Why package taxonomy (not Spatie):

1. First-class package model with search document builder, occurrence/session scope, weight/primary flags, and observers.
2. Aligns with ADR-002 package standards and ADR-005 package-owned domains.
3. Avoids polymorphic taggables for the core discovery facet path.
4. App already has `SyncEventTaxonomiesAction` and public filters using `EventTerm`.

App-owned remainder:

- Islamic seed labels and `TagType` metadata map onto taxonomy `code` + seed content (not a second store).
- Filament term moderation UX until filament-events covers it.

Consequences:

- Submit/admin/API write classifications, not `syncTags()` for domain/discipline/source/issue.
- Scout indexes `taxonomy_term_ids` / `taxonomy_codes` (and may drop tag id facets after reindex).
- Catalog endpoints expose taxonomies/terms; tag catalogs become deprecated and are removed at exit.
- One-time migrate: tags → terms → classifications via existing sync command.

## ADR-012 - API Geography Hard Cut (No Legacy Aliases)

Status: `Accepted` (2026-07-09), corrected same day

Decision: Public and admin HTTP APIs use **package-native addressing columns and catalogs only**.

### Real package columns on `addresses`

| Column | Package model / table | App product use |
| --- | --- | --- |
| `country_id` | `AddressCountry` | **Yes** — `/catalogs/countries` |
| `state_id` | `State` / `states` | **Yes** — `/catalogs/states` |
| `city_id` | `City` / `cities` | **Yes** — `/catalogs/cities` |
| `admin_area_1_id` | **District** (AddressArea level 2) | **Yes** — persisted |
| `admin_area_2_id` | **Subdistrict** (AddressArea level 3) | **Yes** — persisted |
| `admin_area_3_id` | — | **No** — always null on app writes |
| `admin_area_4_id` | — | **No** — always null on app writes |

### Product hierarchy (do not store state in admin_area_1)

ilmu360° address UX:

1. `country_id` → AddressCountry
2. `state_id` → package `State` table
3. `city_id` → package `City` table (optional)
4. `admin_area_1_id` → **district** (AddressArea)
5. `admin_area_2_id` → **subdistrict** (AddressArea)

Federal territories (no district): `admin_area_1_id` null, `admin_area_2_id` = local area under the state.

AddressArea level-1 nodes may exist only as **tree parents** for districts (bridge via `AddressAreaStateBridge`). They are not address FKs.

Package leftovers **removed** from `Address`: no `district_id` / `subdistrict_id` accessors, no `district()` / `stateArea()` relation aliases.

| App field | Meaning |
| --- | --- |
| `state_id` | State / WP |
| `city_id` | City |
| `admin_area_1_id` | District |
| `admin_area_2_id` | Subdistrict |

### Hard-cut removals

| Rejected | Reason |
| --- | --- |
| Storing state UUID in `admin_area_1_id` | Use `state_id` |
| Form-only `state_area_id` | Use `state_id` |
| Product use of `admin_area_3_id` | Two area slots only: district + subdistrict |
| Package `district_id` / `subdistrict_id` accessors | Removed from package |

### Zero legacy footprint (enforced)

App and tests reject `state_area_id`, `district_id`, and `subdistrict_id`. Package `Address` no longer provides those accessors/relations. Canonical columns only: `country_id`, `state_id`, `city_id`, `admin_area_1_id` (district), `admin_area_2_id` (subdistrict).

Consequences:

- Forms, contributions, Filament hydrate via `state_id` + `city_id` + district + subdistrict.
- Google Places maps state → `state_id`, district → area_1, subdistrict → area_2.
- Persist always nulls `admin_area_3_id` / `admin_area_4_id`.
- Guideline: `.ai/guidelines/addressing.blade.php`

## ADR-013 - Paid Commerce Productized Cleanly

Status: `Accepted` (2026-07-09)

Decision: Activate the installed events ↔ ticketing ↔ inventory ↔ seating commerce chain as a **productized** capability, not a half-wired experiment.

Required product modes (from ADR-009), all package-native:

1. Free open / walk-in
2. Free RSVP / registration
3. Free ticketed + pass/check-in
4. Paid ticketed + order fulfillment
5. Mixed free/paid ticket types
6. Reserved seating / capacity-managed admission

Implementation rules:

- Use package actions (`AddEventTicketTypeToCartAction`, pass issuance, seating allocation, order paid → registration sync).
- No free-only app abstractions that reject paid pricing modes.
- Do not invent parallel cart/order tables in the app.
- Public UX may phase rollout, but schema, admin Filament plugins, and API contracts expose all modes.
- Payment provider binding (Chip/cashier) is a sub-track under this ADR; until bound, paid modes may be admin-configured with package tables ready and checkout disabled via feature flag.

Consequences:

- Phase 9 includes paid-commerce productization work unit (WU-P) as active, not optional forever.
- Feature flags control **public exposure**, not schema readiness.
