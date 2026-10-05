# SWOT — Malaysian geography labelling in the location filter

**Subject:** `CountryAddressProfileResolver::levelLabel()` and the field labels it produces on `/institusi?state=johor`
**Repos:** app `ilmu360` · package `commerce/packages/addressing` (symlinked into the app via composer path repo `../commerce/packages/*` — provider and resolver diff clean, so the package as read here *is* what runs)
**Date:** 2026-10-02 · read-only investigation, no files changed

---

## How the label is actually produced

```
$areaFilters()[i]['label']
  └─ SharedFormSchema::locationLevelLabel(country, role, fallback, stateId, areaIds)   app/Forms/SharedFormSchema.php:1780
       └─ SharedFormSchema::levelLabel()                                              app/Forms/SharedFormSchema.php:1739
            └─ CountryAddressProfileResolver::levelLabel()                            packages/addressing/src/Support/CountryAddressProfileResolver.php:536
            └─ __($label)                                                            resources/lang/ms.json
placeholder: __('All :level', ['level' => $label])                                    ms.json:1336 → "Semua :level"
```

`levelLabel()` has two branches:

| Branch | Trigger | Returns |
|---|---|---|
| **Static** | no state, unresolvable parent, or empty scope (`:547`, `:553`, `:559`) | the provider's hardcoded level label |
| **Scoped** | state + resolvable parent (`:556-574`) | `' / '`-joined `Str::headline()` of the distinct `address_areas.type` values in scope |

**Every area label in the screenshots came from the scoped branch.** `areaQueryForRole()` bails when `parentKey !== null && parentId === null`, so with no state selected there are no options to attach a label to. The static branch's rich labels are translated in `ms.json:1585-1587` and unreachable from this state-gated cascade.

The Malaysia provider supplies 7 hardcoded strings and delegates everything else to `Str::headline()`:

| Provider line | Label | Reachable in filter? |
|---|---|---|
| `:65` | `State / Federal Territory` | ✅ **yes** — `state_id` short-circuits at `:552` |
| `:73` | `Division / Bahagian` | ❌ static branch only |
| `:83` | `District / Jajahan / Jajahan Kecil` | ❌ static branch only |
| `:93` | `Mukim / Subdistrict / Bandar / Pekan` | ❌ static branch only |
| `:117` | `Locality / Precinct / Kampung` | ❌ static branch only |
| `:131` | `areaTypeLabels(): []` | deliberately empty |
| `:139` | Kelantan `03` / Pahang `06` overrides | ✅ reachable, 2 states only |

Verified with `jq` against `resources/lang/ms.json`:

| Rendered | Source | `ms` key |
|---|---|---|
| NEGERI / WP | static `State / Federal Territory` | ✅ `:1576` |
| DAERAH | `Str::headline('district')` | ✅ `:215` |
| BAHAGIAN | `Str::headline('division')` | ✅ `:1621` |
| PRESINT | `Str::headline('precinct')` | ✅ `:1620` |
| LOKALITI | `Str::headline('locality')` | ✅ `:1619` |
| MUKIM | `Str::headline('mukim')` | ❌ **no key** |
| MUKIM / LOKALITI | blade concat `$subdivision['label'].' / '.$this->localityLabel()` at `⚡index.blade.php:175` | mixed |

`Mukim / Lokaliti` is assembled in the **app blade**, not the package — the one place geography strings are hand-concatenated.

---

## Strengths

- **One seam, three call sites.** `filament-addressing/AddressFormSchema.php:117`, `SharedFormSchema.php:1452`, `SharedFormSchema.php:1491`. Every label in every surface — Filament form, four Livewire cascades, admin infolists via `AddressHierarchyFormatter::roleAreaLabel()` — resolves through it. Retiring terminology is a one-file change.
- **Proper terms live upstream, where they belong.** Kelantan calls districts `Jajahan`; Pahang calls `minor_district` `Daerah Kecil`. That is geography knowledge, not app config, and `docs/05-country-data.md:153-160` shows the reasoning behind the Genting Highlands case.
- **No per-country branching in the app.** SG and ID ride the same code path with zero MY special-casing (`tasks/todo.md:3459-3493`). The pilot that replaced hardcoded MY roles shipped without per-country `if`s.
- **The package stops at the right boundary.** No `resources/lang`, no `__()` on labels, `docs/03-configuration.md:171-174` states locale-aware selection is deliberately unimplemented. Consumers own translation. A package that imposed a locale would have been wrong.
- **Intent is explicit, not accidental.** Documented at `:525-532`, pinned by `MalaysiaGroupingMatrixTest.php:24-46` and `CountryAddressLabelTest.php:73-95`, app-side by `LocationLevelLabelTest.php:16-18`. The compound label is intentional and deduplicated (`:569`) and ordered by declared `areaTypes` order (`:803-805`).
- **It matches the data's real shape.** Peninsular has no division tier; Sabah/Sarawak do; WP have mukims at L2; Putrajaya has precincts at L2. One role-based cascade renders all four correctly with no country branches.

## Weaknesses

- **`levelLabel()` queries the database.** `scopeTypes()` (`:787-808`) is an uncached `SELECT DISTINCT type`, and the app invokes it from Filament `->label()` closures — once per area-role field, per render. Only the *profile* is request-cached (`:32-73`). `tests/Unit/SharedFormSchemaPerformanceTest.php` asserts only that the `countries` query doesn't repeat; it never covers `scopeTypes`. The guard is pointed at the cheap half.
- **The label is a function of the selection, so it moves mid-interaction.** Johor reads `Mukim`; narrowing to one district reads `Mukim / Bandar / Pekan`; a district with only mukims reads `Mukim` again. Accurate each time, unstable each time — and the package made that call for the app without the app choosing it.
- **Most Malaysian labels render by accident.** `ms.json` has no key for `Mukim`, `Bandar`, `Pekan`, `Minor District`, or `Municipality`. They read Malay only because the CSV `type` slug is already a Malay word. Of 1,806 MY area rows, **1,159 (64%)** — every `mukim` (1,003), `bandar` (109), and `pekan` (47) row — get their label from an untranslated `Str::headline()` passthrough.
- **The best copy is the copy nobody sees.** `ms.json:1585-1587` carefully translate `Division / Bahagian`, `District / Jajahan / Jajahan Kecil`, and `Mukim / Subdistrict / Bandar / Pekan`. All three are dead in the filter.
- **i18n is exact-string luck.** The `__()` wrapper at `SharedFormSchema.php:1759` is a lookup with no fallback and no miss warning. One upstream rewording silently reverts to English, and the joined multi-type forms have no key at any width.
- **Failure is indistinguishable from success.** Three early-returns fall through to the widest static label. A broken scope and a genuinely wide scope render identically.
- **No data-level i18n seam.** All 1,806 rows have an empty `native_name`. `addressing.defaults.locale` exists in config and is never read.

## Opportunities

- **`areaTypeLabels()` is already the right hook, returning `[]` by choice** (`:131-137`). Populating it makes the passthrough intentional and translatable, and removes the dependency on slug spelling. Cost: two lines, one file, **no app change**.
- **`scopeTypes()` is trivially cacheable** per `(role, parentId)` in the request attribute bag the resolver already uses at `:32-73` and `:836`. Behaviour-preserving.
- **The dataset already encodes the stable distinction.** State-level type set differs by state (no division tier in the peninsula; `subdistrict` in Sabah/Sarawak; `precinct` in Putrajaya). Labelling from the *state* level instead of the selected-parent level uses the same data and stops the relabeling.
- **The grouped-label concat belongs upstream.** Moving `⚡index.blade.php:175` behind the package gives grouping and labelling one owner. `ms.json:1587` already contains the target string `Mukim / Bandar / Pekan` as a *value* — the vocabulary exists, it is just never routed to.
- **The matrix test is the asset.** `MalaysiaGroupingMatrixTest.php:24-46` already encodes the full 16-state expected label set. Any stability proposal should be validated against it, and it is the natural place to add the joined-form cases nothing currently covers.

## Threats

- **Dataset drift is silent.** Rename a `type` slug or reseed and labels change or vanish with no failure. `assertSee('Daerah')` in `InstitutionLocationCascadeTest.php:221` is a substring check and passes for `Daerah / Jajahan`. `.ai/rules/addressing.md` already warns that stale databases "go silently broad".
- **The Malay layer is hand-maintained JSON with no key checking.** `docs/location-picker-fix-review.md:131-138` records `__("Speaker")` returning `'penceramah'` from a bulk edit. Adding ~6 new keys per change compounds that exposure.
- **Growth is by accretion.** 11 types over 1,806 rows today; each new type or state override is another untranslated passthrough, and each is a new case in the matrix.
- **Two front-ends, one method.** `CONTEXT.md:27` names `filament-addressing` as the paired UI adapter, but the app does not use it — it has its own `SharedFormSchema`. A package change lands on both.
- **Terminology lives in prose.** `docs/05-country-data.md` carries ~100 "Types are labelled X" statements. Country-specific language expressed as documentation cannot fail loudly or be diffed.

---

## Proposal

Four changes, ranked. **P1 and P2 are behaviour-preserving and need no app change.**

### P1 — Declare the type labels (package, 2 lines)

Populate `MalaysiaGeographyProvider::areaTypeLabels()` (`:131-137`):

```php
public function areaTypeLabels(): array
{
    return [
        'state' => 'Negeri',                    // 'wilayah_persekutuan' → 'Wilayah Persekutuan' already fine
        'division' => 'Division',
        'district' => 'District',
        'minor_district' => 'Minor District',
        'mukim' => 'Mukim',
        'bandar' => 'Bandar',
        'pekan' => 'Pekan',
        'subdistrict' => 'Subdistrict',
        'locality' => 'Locality',
        'precinct' => 'Precinct',
    ];
}
```

The values are the current `Str::headline()` output, so **rendered strings do not change** — but the label now flows through `__()` and stops depending on slug spelling. Add the 6 missing `ms` keys.

- **Why first:** highest value per line. Converts an accidental rendering into a declared, translatable contract.
- **Risk:** low. Output-identical by construction; a `Str::headline($type)` parity test pins it.
- **Reject:** declaring Malay values here (`'district' => 'Daerah'`). That duplicates `ms.json:215` and makes the package locale-specific, which its no-`resources/lang` design deliberately avoids.

### P2 — Cache `scopeTypes()` (package, behaviour-preserving)

Memoise per `(role, parentId)` in the request attribute bag, keyed like the existing profile cache at `:32-73` and read at `:836`. Kills the per-render `DISTINCT` query in Filament label closures.

- **Risk:** none behaviourally. Behind Octane the bag is per-request, so no cross-request bleed.
- **Add:** extend `SharedFormSchemaPerformanceTest` to assert `scopeTypes` queries do not repeat. Today's test only counts `countries`.

### P3 — Label from the state level, not the selection (package, behaviour change)

Resolve the type set against the **state** rather than the role's currently-resolved parent, so the label is stable while the user narrows.

- **Effect:** Johor shows `Mukim / Bandar / Pekan` throughout; Putrajaya shows `Presint` throughout; KL shows `Mukim / LOKALITI` throughout. Labels stop moving.
- **Cost:** loses some real precision — a mukim-only district currently reads `Mukim`, which is more accurate than `Mukim / Bandar / Pekan`.
- **This is a UX trade, not a bug fix.** It needs your call.
- **Tests that will fail and must be updated:** `MalaysiaGroupingMatrixTest.php:24-46`, `InstitutionLocationCascadeTest.php:221/228/236-299`, `LocationLevelLabelTest.php:16-18`.

### P4 — Own the grouped label (app → package, 1 line)

Move the concat at `⚡index.blade.php:175` behind the package so grouping and labelling share one owner, and `ms.json:1587`'s `Mukim / Bandar / Pekan` becomes reachable.

- **Do last.** Only worth it after P3 settles what the label is.

---

### Explicitly not proposed

| Rejected | Why |
|---|---|
| A `resources/lang/` in the package | Contradicts its locale-agnostic design; the `__()`-at-the-consumer seam is correct |
| Singular/plural label pairs | No UI in this app needs them; would be speculative |
| Label overrides in app config | `.ai/rules/addressing.md` forbids app-side geography authoring |
| Filling `native_name` on 1,806 CSV rows | Fixes nothing visible — the labels come from `type`, not `name` |
| A new key-checking CI step for `ms.json` | Real exposure, but a separate concern from labelling |

### Rollout

P1 + P2 together, one commit in `commerce`, no app change, no user-visible diff. Then run `php artisan address:seed` on local databases per `.ai/rules/addressing.md`. P3 is a separate conversation with the user, not a package commit.