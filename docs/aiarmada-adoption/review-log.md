# Review Log

## 2026-06-28 - WP-00 Documentation Hub Creation

State: `Verified`
Owner: Main agent

Changed:

- `docs/aiarmada-adoption/README.md`
- `docs/aiarmada-adoption/status.md`
- `docs/aiarmada-adoption/package-inventory.md`
- `docs/aiarmada-adoption/domain-mapping.md`
- `docs/aiarmada-adoption/architecture-decisions.md`
- `docs/aiarmada-adoption/agent-work-queue.md`
- `docs/aiarmada-adoption/phase-00-readiness.md`
- `docs/aiarmada-adoption/phase-01-source-dependencies.md`
- `docs/aiarmada-adoption/phase-02-package-readiness.md`
- `docs/aiarmada-adoption/phase-03-foundation.md`
- `docs/aiarmada-adoption/phase-04-identity-geography-contacts-membership.md`
- `docs/aiarmada-adoption/phase-05-core-domain.md`
- `docs/aiarmada-adoption/phase-06-communications.md`
- `docs/aiarmada-adoption/phase-07-commerce-capabilities.md`
- `docs/aiarmada-adoption/phase-08-cutover.md`
- `tasks/todo.md`

Verified:

- `test -d docs/aiarmada-adoption && find docs/aiarmada-adoption -maxdepth 1 -type f | sort` => 16 hub files present.
- `find /Users/Saiffil/Herd/commerce/packages -maxdepth 2 -name composer.json -print | wc -l` => 59.
- `find /Users/Saiffil/Herd/commerce/packages -path '*/database/migrations/*.php' -print | wc -l` => 247.
- `find /Users/Saiffil/Herd/commerce/packages -path '*/src/Models/*.php' -print | wc -l` => 289 raw files; documented curated Eloquent-model baseline remains 257.
- `find /Users/Saiffil/Herd/commerce/packages -path '*/routes/*.php' -print | wc -l` => 14.
- `find /Users/Saiffil/Herd/commerce/packages -path '*Resources*/*.php' -print | wc -l` => 673 raw files; documented curated resource baseline remains 116.
- `rg -n -- "aiarmada/" composer.json` => app currently requires `affiliates`, `commerce-support`, `filament-authz`, `filament-signals`, and `signals`.
- `rg -n -- "constrained\(|cascadeOnDelete\(" /Users/Saiffil/Herd/commerce/packages/*/database` => no matches.
- `rg -n -- "softDeletes\(\)|SoftDeletes" app database /Users/Saiffil/Herd/commerce/packages` => existing app `Team` SoftDeletes usage and package docs examples found; tracked as B009.
- `find /Users/Saiffil/Herd/commerce/packages -maxdepth 3 \( -name tests -o -name Testbench.php -o -name phpunit.xml -o -name package.json \) -print | sort` => no package-local tests/testbench/package.json found at that depth.
- `composer validate --no-check-publish` => pass.
- `git diff --check` => pass.

Decisions:

- Dedicated docs hub is the durable source of truth.
- `tasks/todo.md` mirrors only the active phase.
- AIArmada package standards win over current app conventions in the rewrite target.
- UUID geography from `aiarmada/addressing` replaces the current integer geography design in the fresh schema.

Blockers:

- Dependency blockers B001-B005 remain open for phases 1-2.
- B009 records existing app SoftDeletes usage and package docs examples for later cleanup.

Next:

- Begin Phase 1 package source and dependency alignment.

## 2026-06-28 - Event Ticketing Requirement Captured

State: `Verified`
Owner: Main agent

Changed:

- `docs/aiarmada-adoption/domain-mapping.md`
- `docs/aiarmada-adoption/phase-05-core-domain.md`
- `docs/aiarmada-adoption/phase-07-commerce-capabilities.md`
- `docs/aiarmada-adoption/architecture-decisions.md`
- `docs/aiarmada-adoption/package-inventory.md`
- `docs/aiarmada-adoption/status.md`

Verified:

- Read `AIArmada\Events\Enums\PricingMode` and confirmed package supports `paid`, `free`, and `mixed`.
- Read `EventAccessPolicy` and confirmed flags for registration, approval, payment, ticket, seating, walk-in, capacity, waitlist, and open/close windows.
- Read event ticket type migration and confirmed ticket type price/currency, access type, seating mode, sale window, status, visibility, and quantity fields.
- Read `RegisterForFreeAction` and `CreateRegistrationsFromOrderAction` and confirmed separate free and paid registration paths.

Decisions:

- ilmu360 rewrite target must not remain free-only.
- Paid event tickets are an approved commerce workflow and must be assessed in Phase 7.
- Public UI may phase exposure, but schema/admin/API/MCP contracts must be ready for free walk-ins, free tickets, paid tickets, mixed ticketing, passes, seating, capacity, waitlists, and check-in.

Blockers:

- Paid-ticket package chain still depends on Phase 1 dependency resolution and Phase 7 commerce assessment.

Next:

- During Phase 5, expose the package participation/ticketing primitives instead of adding app-specific free-only event state.

## 2026-06-28 - Global Addressing Requirement Captured

State: `Verified`
Owner: Main agent

Changed:

- `docs/aiarmada-adoption/domain-mapping.md`
- `docs/aiarmada-adoption/phase-04-identity-geography-contacts-membership.md`
- `docs/aiarmada-adoption/architecture-decisions.md`
- `docs/aiarmada-adoption/package-inventory.md`
- `docs/aiarmada-adoption/status.md`
- `docs/aiarmada-adoption/agent-work-queue.md`
- `docs/aiarmada-adoption/phase-08-cutover.md`

Verified:

- Read `address_areas` migration and confirmed UUID IDs, `country_id`, `parent_id`, `country_code`, `type`, `level`, `name`, source fields, metadata, and country/type/name indexing.
- Read `addresses` migration and confirmed `country_id` plus `admin_area_1_id` through `admin_area_4_id`, raw/formatted address fields, geospatial fields, and country/city/postcode indexes.
- Read `AddressAreaData` and confirmed import shape supports per-country source/type/level/parent metadata.
- Searched current app and confirmed existing country-switching and preferred-country assumptions in `/negara/{country}`, layout selector, `PublicCountryPreference`, `PublicCountryRegistry`, `PreferredCountryResolver`, search filters, API contracts, and docs.

Decisions:

- ilmu360° target product is global by default.
- Country is an optional search/filter dimension, not a session or application mode.
- Country-specific administrative terms such as Malaysia `district`, `wilayah_persekutuan`, and `small_district` belong in generic `address_areas.type` values.
- Institution, venue, speaker, and event workflows must support cross-country discovery without users switching countries.

Blockers:

- B010 tracks removal of existing country-switching behavior and global-search contract updates.

Next:

- During Phase 4, replace current integer geography and preferred-country services with package addressing and global filters.
