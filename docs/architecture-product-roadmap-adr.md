# ADR-014: Product Roadmap Execution Boundaries

- **Status:** Accepted
- **Date:** 2026-07-16
- **Owner:** ilmu360 engineering
- **Decision source:** [`tasks/architecture-product-roadmap.md`](../tasks/architecture-product-roadmap.md)

## Context

The product roadmap groups changes across event discovery, moderation escalation,
event submission, product telemetry, AI cost control, and test feedback. These
areas cross application boundaries and have different failure modes. The roadmap
therefore needs a durable set of implementation boundaries so individual tickets
can be delivered serially without changing public contracts or reintroducing
legacy state.

## Decisions

1. Discovery input is one immutable, app-owned `EventDiscoveryCriteria` object
   containing normalized search intent.
2. Discovery execution is split between `PostgresEventDiscovery` and
   `TypesenseEventDiscovery`, which consume the same criteria. The facade owns
   cache, pagination, eager loads, health checks, and backend selection.
3. When search semantics are unsupported by Typesense, the facade selects
   PostgreSQL. Typesense must never silently approximate an unsupported filter.
4. Escalation history is stored only in a dedicated append-only `EventEscalation`
   model and table. `ModerationReview` is not reused for escalation history.
5. Escalation deduplication persists a unique application-level decision key
   before notifications dispatch. An event may have one record per escalation
   type.
6. Submission input is represented by a readonly `ValidatedEventSubmission`
   created after existing validation. Request keys and validation errors remain
   unchanged.
7. Submission side effects use a named after-commit workflow for notifications,
   Signals, indexing, and cache invalidation.
8. Product Signals properties are allowlisted. Raw search text, notes, IP-derived
   data, referrers, prompts, and upload metadata are omitted by default.
9. AI spend is governed by one budget policy that decides allow, deny, defer, or
   privileged approval before a costed operation starts.

## Non-negotiable implementation rules

- Preserve unrelated working-tree changes and never stage unrelated files.
- Do not add compatibility aliases, dual writes, fallback reads, schema guards, or
  legacy request keys.
- Public API, MCP, Livewire, Filament, and mobile request/response contracts remain
  unchanged unless a ticket explicitly says otherwise.
- Keep prayer, Ramadan, Malaysian geography, moderation vocabulary, and Malay copy
  in `app/`; do not extract them into a package.
- Use only canonical address keys: `country_id`, `state_id`, `city_id`,
  `admin_area_1_id`, and `admin_area_2_id`.
- External data exposure happens after the transaction commits.
- Run ticket-scoped checks; do not run the complete local Pest suite as a default.
- If implementation contradicts a ticket, record the conflict and evidence in
  `tasks/architecture-product-roadmap-blockers.md` instead of choosing a substitute.

## Non-goals

- Introducing microservices.
- Extracting product-specific policy into packages.
- Changing public contracts outside an explicitly scoped ticket.
- Adding a compatibility layer for removed or legacy fields.
- Broad test deletion or weakening assertions to make the roadmap green.

## Consequences

The roadmap delivers smaller, reviewable changes with explicit ownership of
normalization, persistence, side effects, and policy. Existing callers retain
their contracts while internal behavior becomes easier to test and reason about.
The trade-off is that dependent tickets must be merged and verified in sequence;
later work cannot bypass an incomplete prerequisite.

## Link

The ordered tickets and their verification commands live in the
[architecture product roadmap](../tasks/architecture-product-roadmap.md).
