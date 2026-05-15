# Ilmu360 Branding Refactor Plan

**Status:** Ready for execution  
**Prepared:** May 11, 2026  
**Source of truth:** `docs/ilmu360-brand-name-standard.md`  
**Execution directive:** for this refactor, active repo materials should read as if `ilmu360` / `ilmu360°` were the original brand, with legacy-name references removed unless a hard legal, archival, or protocol reason requires them.

## Target outcome

Bring every product, documentation, and system touchpoint into compliance with the approved brand hierarchy, while making the repository read as if `ilmu360` / `ilmu360°` were the pioneer brand from day one:

- `ilmu360°` for visual and public-facing brand moments
- `ilmu360` for functional, searchable, typed, and technical contexts
- `Ilmu360` for formal and institutional prose
- `MajlisIlmu` only for rare exception cases that cannot be rewritten safely

## Current repo snapshot

The current repository is already substantially aligned, but the surface is still mixed:

- Public UI already uses `ilmu360°` in several high-visibility places such as the main layout footer, homepage titles, about content, and event CTAs.
- Technical identifiers are already mostly aligned to `ilmu360`, including `window.ilmu360`, MCP server names such as `ilmu360-admin`, and repository/config defaults such as `ilmu360`.
- Documentation filenames recently had naming drift across legacy uppercase prefixes and lowercase `ilmu360-*` conventions.
- Some user-facing copy still needs contextual review instead of blind replacement, especially strings such as `Download Ilmu360` that may belong to app-store or CTA contexts.
- Some protocol and integration surfaces are effectively public contracts and must be handled carefully rather than mass-renamed.
- Several docs and review artifacts still carry transition framing such as `rebrand`, `historical snapshot`, or legacy-prefixed filenames, which keeps the old brand alive in active repository reading paths.

## Brand rules to enforce

| Context | Approved form | Notes |
| --- | --- | --- |
| Logo, hero, splash, public visual brand | `ilmu360°` | Degree symbol required |
| URLs, email, handles, hashtags, code, filenames, ids | `ilmu360` | No degree symbol |
| Formal documents and institutional prose | `Ilmu360` | Use selectively, not as visual lockup |
| Historical, instructional, and audit narratives | `ilmu360°` / `ilmu360` / `Ilmu360` by context | Rewrite to current-brand framing by default |
| Truly unavoidable provenance/legal exceptions | `MajlisIlmu` | Keep only when removal would distort required history |

## Zero-legacy presentation rule

The cleanup target is not just brand consistency. It is brand primacy.

- Active product copy, docs, instructions, onboarding guides, architecture notes, audit writeups, and internal reference material should read as though `ilmu360` has always been the canonical product name.
- Remove routine transition phrasing such as `formerly`, `rebrand`, `legacy name`, `historical snapshot`, and similar origin-story framing from active materials.
- Preserve prior-name mentions only when they are legally required, materially necessary for forensic provenance, or needed to explain an immutable external contract.
- When provenance must remain, compress it into the smallest possible metadata or archive note instead of making it part of the reader's main narrative.

## Guardrails

- Do not introduce the degree symbol into URLs, filenames, headers, package names, env keys, analytics events, or code identifiers.
- Do not blindly replace every `Ilmu360`; some formal-document contexts are correct and should remain.
- Treat every `MajlisIlmu`, `formerly`, `rebrand`, and transition-era phrase as a removal candidate unless a concrete exception applies.
- Do not rename public protocol surfaces without a compatibility plan.
- Do not treat docs filename cleanup as a pure search-and-replace exercise; several PHP classes and MCP resources point at specific file paths.

## Compatibility and exception map

| Surface | Desired state | Strategy |
| --- | --- | --- |
| `window.ilmu360` | Keep | Already correct functional spelling |
| MCP server names such as `ilmu360-admin` and `ilmu360-member` | Keep | Functional/system identifier |
| GitHub repo/config defaults using `ilmu360` | Keep | Functional/system identifier |
| `X-Ilmu360-*` headers | Freeze for now | Treat as client-facing contract; only change with versioning or aliases |
| Existing MCP docs resource ids and PHP path references | Phase carefully | Canonicalize only when all references are updated together or compatibility stubs exist |
| Domains and email addresses | `ilmu360` only | Never use `°` in transport strings |
| Audit, onboarding, and instruction docs | Rewrite to current-brand narrative | Preserve findings, not rebrand storytelling |

## Repo hotspots discovered

These are the highest-value surfaces to review first:

- `resources/views/layouts/app.blade.php` — global header/footer brand lockup and shared JS namespace bootstrap
- `resources/views/components/pages/⚡home.blade.php` — homepage title, hero, and SEO-facing brand text
- `resources/views/livewire/pages/events/show.blade.php` and `resources/views/components/join-ilmu360-cta.blade.php` — public CTA copy
- `resources/lang/en.json`, `resources/lang/ms.json`, `resources/lang/ms_MY.json`, and `resources/lang/*/about.php` — large copy surface with mixed usage contexts
- `config/horizon.php` and any app-name metadata sources — operational naming and fallback labels
- `app/Mcp/Servers/*.php`, `app/Mcp/Resources/Docs/*.php`, and `app/Support/Mcp/*` — functional identifiers and hard-coded docs path references
- `app/Http/Requests/Api/StoreMobileTelemetryRequest.php`, `app/Support/Signals/*`, and `app/Support/Api/Frontend/FrontendFormContractService.php` — header and protocol names like `X-Ilmu360-*`
- legacy-uppercase docs plus `docs/ilmu360-brand-name-standard.md` — documentation filename drift, duplication, and naming cleanup
- Historical-seeming docs such as review plans, visitor guides, MCP guides, and repo-analysis material — many still preserve transition language that should be rewritten into current-brand framing

## Execution phases

### Phase 0 — Freeze decisions and classify all usage

- [ ] Build a brand inventory by file and classify each occurrence as Visual, Functional, Formal, Removal Candidate, or Frozen Contract.
- [ ] Create an exceptions allowlist for identifiers that intentionally remain functional or contract-stable.
- [ ] Create a rename map for documentation files before changing any file paths.

**Exit criteria:** every discovered occurrence has an explicit classification, and every legacy-origin reference is either queued for removal or explicitly justified.

### Phase 1 — Refactor highest-visibility public surfaces

- [ ] Standardize brand lockups across header, footer, hero, splash, and page titles to `ilmu360°`.
- [ ] Review CTA copy and change user-facing product moments to the correct visual or functional spelling.
- [ ] Resolve app-download and promotional CTAs so app-store/distribution naming follows the approved functional spelling where appropriate.
- [ ] Keep translated copy aligned with the same decision rules.

**Exit criteria:** a user browsing core public pages sees `ilmu360°` in visual brand moments and never sees unapproved casing or spacing.

### Phase 2 — Sweep locale files and long-form content

- [ ] Review locale JSON and language PHP files for mixed brand variants.
- [ ] Preserve `Ilmu360` only where the copy is formal/institutional.
- [ ] Remove accidental all-caps, spaced, hyphenated, or underscored variants.
- [ ] Remove transition-era wording such as `formerly`, `rebrand`, `legacy name`, and `historical snapshot` from active content unless an exception applies.
- [ ] Check about pages, submission flows, emails, guides, descriptive text, and review writeups for current-brand narrative consistency.

**Exit criteria:** translation files and long-form copy reflect the same context rules as English and Malay source copy and no longer narrate the old brand as active history.

### Phase 3 — Stabilize technical and protocol surfaces

- [ ] Keep technical identifiers on `ilmu360` unless a compatibility decision explicitly says otherwise.
- [ ] Audit `APP_NAME`, manifest labels, Horizon naming, repo/package names, analytics namespaces, and code identifiers.
- [ ] Explicitly mark frozen client contracts such as `X-Ilmu360-*` headers and MCP server/tool identifiers.
- [ ] Avoid changing public integrations without an alias, migration, or versioning plan.

**Exit criteria:** code and system identifiers use the approved functional spelling and no decorative brand usage leaks into technical strings.

### Phase 4 — Normalize documentation names and path references

- [ ] Define a canonical docs filename convention using functional lowercase `ilmu360` naming.
- [ ] Decide which legacy uppercase or `MAJLISILMU_*` files should be renamed, stubbed, redirected, or archived.
- [ ] Update all PHP, MCP, and internal references to canonical paths in one controlled pass.
- [ ] Remove duplicated documents only after confirming there is no runtime or workflow dependency on the old path.

**Exit criteria:** docs filenames follow one naming strategy and every code reference resolves cleanly.

### Phase 5 — Remove legacy-origin narrative

- [ ] Remove `MajlisIlmu`, `formerly`, `rebrand`, and transition-story wording from active docs, instructional material, audits, onboarding, and reference content.
- [ ] Rewrite historical and review documents so they preserve dates and findings without centering a previous brand identity.
- [ ] Keep any unavoidable provenance notes tiny, metadata-like, and out of the main reader narrative.

**Exit criteria:** the repository reads as though `ilmu360` was the pioneer brand, and any remaining prior-name references are rare, justified exceptions.

### Phase 6 — Verify, sign off, and harden against regressions

- [ ] Run grep-based sweeps for prohibited variants and review all remaining hits.
- [ ] Spot-check top user journeys: homepage, event detail, dashboard CTA, contribution pages, docs entry points, and MCP docs references.
- [ ] Add or update focused tests when code paths, docs references, or public contracts change.
- [ ] Run formatting, diff hygiene, and focused regression checks for touched areas.

**Exit criteria:** all remaining matches are intentional exceptions, contract-sensitive surfaces are explicitly documented, and focused verification passes.

## Risk notes

- Renaming docs files without updating `app/Mcp/Resources/Docs/*` and `app/Support/Mcp/*` references can break MCP documentation fetch flows.
- Renaming `X-Ilmu360-*` headers without aliases can break native/mobile telemetry and Signals ingestion.
- Renaming `window.ilmu360` or analytics namespaces can break browser-side integrations and event tracking.
- Blanket replacement can incorrectly overwrite legitimate formal `Ilmu360` usage or break genuinely necessary provenance notes, so exceptions still need explicit review.

## Verification checklist

Use these checks during implementation, not only at the end:

1. Search for prohibited public variants in UI and content surfaces.
2. Search for remaining `MajlisIlmu`, `formerly`, `rebrand`, `legacy name`, and `historical snapshot` hits and classify every result.
3. Search for `ilmu360°` inside filenames, ids, URIs, headers, env keys, and code identifiers to catch decorative leakage into technical contexts.
4. Review any docs filename changes against PHP/MCP references before removing old paths.
5. Run `git diff --check` after each pass.
6. When PHP, Blade, config, or MCP paths change, run focused verification such as `vendor/bin/pint --dirty --format agent`, `vendor/bin/phpstan analyse --ansi`, and the smallest relevant `vendor/bin/pest --parallel` slice.

## Definition of done

The refactor is complete when all of the following are true:

- Public visual brand moments use `ilmu360°` consistently.
- Functional and technical surfaces use `ilmu360` consistently.
- Formal institutional copy uses `Ilmu360` only where appropriate.
- The repository no longer tells a routine origin story about a previous brand.
- `MajlisIlmu` appears only in explicitly justified legal, archival, or immutable-contract exceptions.
- Docs filenames and references follow one canonical strategy without broken links or broken MCP resource paths.
- Grep-based review returns only intentional, documented exceptions.
- Focused verification passes for every touched surface.

## Recommended implementation order

1. Freeze the classification matrix and exceptions list.
2. Update highest-traffic public UI surfaces.
3. Sweep translation and long-form copy.
4. Normalize technical and contract-sensitive identifiers.
5. Consolidate docs filenames and path references.
6. Run the zero-legacy narrative cleanup across active and historical-seeming materials.
7. Run final grep, formatting, static analysis, focused Pest, and stakeholder sign-off.