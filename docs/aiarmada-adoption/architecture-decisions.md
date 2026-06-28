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
