# Handoff: Bring addressing package docs up to provider scale-up standard

## Suggested skills

Before writing, read this repo's `AGENTS.md` and `packages/addressing/CONTEXT.md`, then invoke what's available: a PHP/Laravel standards skill and a technical-writing skill if one exists. CONTEXT.md conventions (docs updated in the same pass as code, direction guards, no consumer rules in core) override generic advice.

---

# Task: Document provider authoring, registration, and the unregistered provider backlog

## Audit verdict (verified 2026-09-18 — re-verify, do not trust blindly)

Consumer-facing docs are strong: installation, configuration, usage, adoption levels (06–09), contracts/examples (10), agent checklists (11), troubleshooting (99), and the per-country catalog (05) accurately cover the **37 registered providers**. The geography README documents all 37 registered CSVs with sources and data decisions.

What is missing is everything the **provider scale-up** needs (59 providers shipped, 37 registered, trajectory toward 100s):

1. **No provider-authoring guide.** Nowhere is it explained how to add a new country provider. Scattered fragments exist (a `providerKey()` paragraph in 03-configuration, the numeric-key gotcha in 05's tail); the rest lives only in code.
2. **22 shipped providers are undocumented**: AF AO AR AU BR CA CM CO GH IQ KR MG MM MX MZ PE PH TH TW UA UZ VN exist in `src/Geography/` with CSVs but have no 05 section, no geography-README line, and no troubleshooting mention. It is unknown whether they are staged/unreviewed or ready-but-unregistered.
3. **Registration vs shipping is unexplained.** Nothing documents what it means for a provider to exist in `src/Geography/` but be absent from a consumer's `addressing.geography.providers`, how to enable one, or the consequences (e.g. empty hierarchies → Sync rejects area roles — verify this claim against `CountryAddressProfileResolver` + `SyncAddressAreaAssignmentsAction`).
4. **No role/type vocabulary reference.** Roles and area types are documented per-country inline only. There is no consolidated catalog (`regency` vs `district` vs `mukim` vs `province` vs `planning_area` vs `postal_sector` …) with level conventions — provider authors choosing names and consumers building generic UIs both need it.
5. **Rotting enumerations + stubs.** The 37-country list is spelled out in 01-overview and 02-installation ("Fifteen more… Eighteen more…") and will rot with every batch. `database/seeders/README.md` is a 5-line leftover task note ("should create AddressCountrySeeder") — do it or delete it. There is no package-level README quickstart (`README.md` is the adoption-pack index).

## Deliverables (all in `packages/addressing/`)

1. **New provider-authoring guide** (new doc file, numbered per existing sequence — check what numbers are free): contracts to implement (`CountryGeographyProvider`, `CountryAddressAreaMetadataProvider`, `CountryHierarchyProvider`, formatter) and what each contributes; designing `addressHierarchies()` (hierarchy keys incl. the `administrative` convention, `kind: state|area`, `areaTypes` vs `areaType`, levels, `parentKey`, `assignmentRole`); `stateDefinitions` / `areaNames` / `areaRoles` / `areaRelationships` shapes; `stateAreaMappings` semantics and the providerKey-vs-sourceKey stability rule; CSV format + `source_id` conventions (country prefix, level numbering, `parent_source_id`); naming policy as evidenced in code (official-language names, English exonyms as aliases — state it explicitly); when to bundle deep levels vs state-only; the numeric-ISO int-key gotcha; how to test a provider (seed command + Sync a sample address + what to assert); and the registration step in the consuming app.
2. **Triage the 22 unregistered providers**: for each, determine from code/data whether it is complete-but-unregistered or staged/incomplete. Document the complete ones exactly like the registered set (05 section + geography-README line + troubleshooting entry only if it has quirks); list the incomplete ones with what is missing. Document the pipeline explicitly: CSV → provider → consumer registration → docs, and what each stage requires.
3. **Registration semantics section** (in 02-installation or 03-configuration): what registration does, how to enable a provider, and behavior when data exists without registration (verify against code, document precisely).
4. **Role/type vocabulary catalog** (new section or doc): every assignment role and area type in use across shipped providers, with level conventions and guidance for naming new ones consistently.
5. **Hygiene**: enumeration of providers lives in exactly one place (05-country-data); 01-overview and 02-installation link to it instead of listing countries. Resolve the seeders-README stub. Add a minimal package README quickstart (install → configure → seed one country → link to docs) only if the repo has no objection to a second README role — check first.

## First step: verify before writing

Read the contracts, the seed action (`SeedCountryGeographiesAction`), `CountryAddressProfileResolver`, `SyncAddressAreaAssignmentsAction`, and at least four representative providers (one deep MY/ID, one state-only, one alias-heavy DE/IT, one unregistered from the 22). Confirm every factual claim above (counts, behaviors, gaps) against the current tree — several may have shifted — and note corrections in the final summary.

## Out of scope

- Code changes of any kind (docs only), except fixes to factually wrong doc statements discovered during verification.
- Registering the 22 providers or completing their data — triage and document only; implementation is a separate task.
- Filament adapter docs (separate package).

## Acceptance criteria

- A provider author can add country #60 following only the new guide, without reading provider source for conventions (spot-check: the guide answers contracts, hierarchy design, CSV format, naming policy, mappings, testing, registration).
- Zero country enumerations outside 05-country-data (grep for stale lists).
- Every shipped provider is either documented like the registered set or listed as incomplete-with-reason; no silent gaps (reconcile 59 providers vs docs 1:1 and show the reconciliation).
- Docs build/lint passes if the repo has any; touched-doc consistency (no contradictions between 01/02/03/05/99 on provider counts or behavior).
