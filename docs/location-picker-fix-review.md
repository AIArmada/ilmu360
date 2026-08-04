# Review Report: Public Institution Submission — Location Picker Fixes

**Date:** 2026-08-05
**Author:** opencode (session: submit-institution e2e + fixes)
**Status:** Second review completed — see §9 for the additional event-flow finding and verification limits.

---

## 1. Background

E2E-tested the public institution submission form (`https://ilmu360.test/sumbangan/institusi/baru`) with Chrome DevTools MCP (real browser input: Google Places autocomplete, Filament selects, media uploads). Three defects were found and fixed. This report documents the evidence, the fixes, the verification, and what remains unverified so a reviewer can independently confirm or refute.

Scope of changes (all in this session):

| File | Change |
|---|---|
| `app/Livewire/Concerns/InteractsWithLocationPickerSelection.php` | Merge 3 default `area_assignments` role keys into the picker-resolved address |
| `app/Forms/SharedFormSchema.php` | `->dehydratedWhenHidden(true)` on the hidden `city_id` select |
| `app/Support/Location/AddressHierarchyFormatter.php` | New `subdivisionOrLocalityName()` (subdivision → postal_locality fallback) |
| `app/Filament/Resources/Institutions/Schemas/InstitutionInfolist.php` | Use it in "Mukim / Kawasan" row (file also contains pre-existing branch WIP) |
| `app/Filament/Resources/Persons/Schemas/PersonInfolist.php` | Same as above |
| `app/Livewire/Pages/SubmitEvent/Create.php` | Reuse the shared picker-selection trait so nested locations receive the same area-assignment defaults |
| `tests/Feature/InstitutionContributionLocationPickerTest.php` | +2 regression tests |
| `tests/Feature/InstitutionAdminViewInfolistTest.php`, `tests/Feature/PersonAdminViewInfolistTest.php` | +1 locality-display test each |
| `tests/Feature/SubmitEventLocationTest.php` | Regression test for nested event-location picker state |

## 2. Defect 1 — Locality select silently drops the user's selection

### Symptom

On the institution form, after choosing a place via the Google Maps picker, the "Locality / Precinct / Kampung" select (e.g. **Titiwangsa** for a Kuala Lumpur place) displayed the selection but it never reached the submitted payload. Verified in the DB: `address_area_assignments` was empty for every submitted institution.

### Evidence

1. **Browser console** (before fix), on selecting a locality:
   `Livewire Entangle Error: Livewire property ['data.address.area_assignments.postal_locality'] cannot be found on component: ['pages.contributions.submit-institution']`
2. **Network trace**: clicking the option fired no Livewire update carrying the value; the submit request body (reqid 423) snapshot showed `area_assignments: []`.
3. UI showed "Titiwangsa" while `$wire.get('data.address.area_assignments')` returned `{}` — client display and Livewire state diverged.
4. Reproduced with both synthetic JS clicks and real CDP clicks (a11y-tree click).

### Root cause

Filament v5 renders the select with `state: $wire.$entangle('data.address.area_assignments.postal_locality', true)` (verified in the rendered DOM — live entangle). Livewire's entangle throws "property cannot be found" when the dotted path does not exist in the component snapshot. The path did not exist because:

- The picker (`applyLocationPickerSelection` → `ResolveGooglePlaceSelectionAction::handle()`) returns `area_assignments` built with `array_filter(...)` — all-null roles are stripped, so when no district/subdistrict/locality resolves, it returns `[]`.
- `data_set($this, 'data.address', array_merge($current, $resolved))` replaced the property's `area_assignments` with `[]` — no `postal_locality` key → entangle fails → the select's `onStateChange` updates only the Alpine-side display.
- The **person** form was not affected because its visible state select's `->afterStateUpdated` writes the three null keys (`SharedFormSchema.php:1451`) whenever the state changes; the institution picker path bypasses that cascade.

### Fix

In `InteractsWithLocationPickerSelection::applyLocationPickerSelection()`:

```php
$resolvedAddress['area_assignments'] = array_merge([
    AddressAssignments::ADMINISTRATIVE_DISTRICT => null,
    AddressAssignments::ADMINISTRATIVE_SUBDIVISION => null,
    AddressAssignments::POSTAL_LOCALITY => null,
], $resolvedAddress['area_assignments'] ?? []);
```

### Why this is the right layer/pattern

- The identical pattern already exists in the codebase for the same purpose (making the entangle keys exist):
  - `app/Filament/Resources/Persons/Pages/EditPerson.php:98` — `array_merge([3 null keys], $address->areaAssignments()->pluck(...)->all() ?? [])`
  - `app/Forms/SharedFormSchema.php:1451` — the state-select cascade sets the same 3-key literal.
- The trait is shared by every picker flow: `SubmitInstitution`, `SubmitPerson`, `SuggestUpdate`, and now `SubmitEvent/Create` (verified `SuggestUpdate::institutionSubjectSchema()` uses `includeLocationPicker: true`, so it had the same latent bug).
- Before the second review, `SubmitEvent/Create.php` had a duplicate picker method that omitted the same defaults. Its nested institution and venue quick-add forms use `SharedFormSchema`, so it was affected as well; see §9.
- Persistence layer is unaffected: `prepareAddressPersistenceData` → `AddressAssignments::normalize()` strips nulls before `SyncAddressAreaAssignmentsAction` runs, so the null keys never create DB rows.

## 3. Defect 2 — Picker-resolved `city_id` never persisted

### Symptom

The picker resolved city "Kuala Lumpur" into `data.address.city_id` (present in every Livewire snapshot), but the submitted payload (`contribution_requests.proposed_data`) and the persisted `addresses.city_id` were `null`.

### Root cause

The `city_id` select (`SharedFormSchema::regionalLocationFields`) is hidden for federal territories / district hierarchies (`cityVisibleClosure`). Filament v5 dehydrates a hidden field only when `dehydratedWhenHidden(true)` is set — verified in vendor source:

```php
// vendor/filament/schemas/src/Components/Concerns/HasState.php
public function isDehydrated(): bool
{
    $isDehydrated = $this->evaluate($this->isDehydrated) ?? $this->isSaved();
    if (! $isDehydrated) return false;
    return ! $this->isHiddenAndNotDehydratedWhenHidden(); // <- drops hidden fields
}
```

`->dehydrated(true)` is not sufficient in v5 when the field is hidden.

### Fix

`->dehydratedWhenHidden(true)` on the `city_id` select (one line in the shared `regionalLocationFields`, so all forms — person, institution, event-adjacent, suggest-update — behave consistently).

### Notes

- `EditPerson.php:90` already reads `city_id` into form state, so round-tripping it is the app's intent.
- Non-picker flows never set `city_id` (hidden select + state cascade sets it to null) → behavior unchanged elsewhere.
- Only `city_id` (a real FK column) got the flag; the hidden free-text `city` fallback was intentionally left as-is.

## 4. Defect 3 — Admin Lokasi tab hid the locality for federal-territory records

The DB stores the deepest area for federal territories under role `postal_locality`, but the admin infolists only read `administrative_subdivision` → "Mukim / Kawasan" showed "-" despite Titiwangsa existing. Fixed with `subdivisionOrLocalityName()` (subdivision first, then postal_locality) in `AddressHierarchyFormatter`, used by both `PersonInfolist` and `InstitutionInfolist`.

## 5. Verification performed

### Automated (results reported by the original fix session)

| Suite | Result |
|---|---|
| `InstitutionContributionLocationPickerTest` | 9 passed (incl. 2 new regression tests) |
| `PersonAdminViewInfolistTest` + `InstitutionAdminViewInfolistTest` | 6 passed (incl. 1 new locality test each) |
| Broad sweep `Contribution|LocationPicker|Infolist|SuggestUpdate|SubmitEvent` | 210 passed, 2 failed (pre-existing, see §6) |
| Admin-edit sweep `EditPerson|EditInstitution|ContributionPages|SubmitEventLocation|AdminResourcesCoverage|PersonForm|InstitutionForm` | 82 passed |
| `vendor/bin/pint --dirty` | reported passed in the original session; current targeted checks are recorded in §9 |
| `vendor/bin/phpstan analyse` | 0 errors |

New regression tests:
1. `keeps the area assignment keys present when a place resolves no areas` — asserts the 3 keys exist after `applyPlaceSelection` (the property the browser entangle needs).
2. `persists the resolved city id when the city field is hidden` — full submit → `addresses.city_id` asserted.
3. `shows the postal locality as the mukim when the address only has a locality` (institution + person variants).

### Browser E2E (post-fix)

- Selected Titiwangsa via real CDP click → `$wire.get()` now returns `postal_locality: 019fc85f-9a81-...` (pre-fix: `{}`).
- Submitted → DB: `addresses.city_id = 019fc85f-7a1f...` (Kuala Lumpur), `address_area_assignments` row Titiwangsa/postal_locality/primary.
- Approval replay (`ApproveContributionRequestAction`) → entity stays `verified`, address keeps city_id + Titiwangsa.
- Admin view (`admin.ilmu360.test/institutions/019fcd81-...`): Lokasi tab now shows **Mukim / Kawasan: Titiwangsa**, Bandar/Kawasan: Kuala Lumpur.

## 6. Pre-existing failures (NOT caused by these changes)

Broad sweep reported 2 failures. Proof they predate this work: with only the 3 tracked fix files stashed (branch WIP fully intact), the identical 2 tests fail with the identical assertion counts (`2 failed, 3 assertions`):

1. `Tests\Feature\ContributionWorkflowActionsTest` — "resolves contribution subject presentation…": expects `__('Speaker')` = `'Penceramah'`, translation now returns `'penceramah'`. Caused by branch WIP in `resources/lang/*.json`.
2. `Tests\Feature\PublicPagesTest` — "renders event contribution links with majlis route segments": the event show page no longer renders the `/sumbangan/majlis/{slug}/kemas-kini` link. The branch WIP removed those links from `resources/views/livewire/pages/events/show.blade.php` and added the replacement component `public-record-feedback.blade.php` only to person/institution pages.

Both are mid-refactor branch state, unrelated to the location-picker work.

## 7. Open questions / gaps for the reviewer

1. **Full suite verification is incomplete.** A fresh full parallel run was attempted, but one worker remained CPU-bound without progress for approximately 27 minutes and was stopped; see §9.
2. **Non-federal-territory picker flow not browser-verified post-fix.** The Selangor path (district + subdistrict selects after picker) is covered by the pre-existing Livewire test asserting `area_assignments.administrative_district/subdivision` in state, and shares the identical entangle mechanism, but no e2e pass was done for it.
3. **`dehydratedWhenHidden(true)` is the app's first usage** of that flag. It is the documented Filament mechanism, but a reviewer may want to confirm the behavior is desired for the person form when a district hierarchy exists (city_id stays null there — no picker — so impact is nil).
4. **Bug-1 reproduction depends on Google Places** (the picker). The Livewire regression test asserts the state shape that fixes the entangle, but the entangle failure itself is browser-only and not covered by a test (no browser test harness in this repo for that page).
5. **Seeders still create addresses without city_id** (146 addresses, 1 with city_id). This fix only affects picker-driven submissions; historical data is untouched.

## 8. Reproduction steps (for the reviewer)

1. `php artisan serve` / Herd site; seed: `php artisan db:seed --class=UserSeeder` (user `user@ilmu360.com` / `password`).
2. Login at `https://ilmu360.test/login`, open `/sumbangan/institusi/baru`.
3. Search "Masjid Wilayah Persekutuan, Kuala Lumpur" in the place picker, select the first suggestion.
4. In "Locality / Precinct / Kampung" pick **Titiwangsa**.
5. Pre-fix: value shows in the UI but `window.Livewire.first().get('data.address.area_assignments')` returns `{administrative_district:null, administrative_subdivision:null, postal_locality:null}` with `postal_locality` missing → console shows the Entangle error → submit loses the selection.
6. Post-fix: `postal_locality` contains the area UUID; submitted address has `city_id` = Kuala Lumpur and the Titiwangsa assignment.
7. Admin: view the institution → Lokasi tab shows "Mukim / Kawasan: Titiwangsa".

## 9. Second review findings (2026-08-05)

The three reported fixes were verified against the current application code, package source, and persistence path:

- `ResolveGooglePlaceSelectionAction` does strip empty area roles with `array_filter`, so the three-key merge in the shared picker trait is required for Livewire/Filament entanglement.
- Filament's `HasState::isDehydrated()` excludes hidden fields unless `dehydratedWhenHidden` is enabled, so the `city_id` change is required for hidden picker-resolved city values to reach form state.
- The admin formatter correctly falls back from `administrative_subdivision` to `postal_locality` for federal-territory records.

One additional defect was found: `SubmitEvent/Create` implemented its own picker handler and did not add the area-assignment keys. Its nested institution and venue quick-add schemas do contain the shared `area_assignments` fields, contrary to the original report. The duplicate handler was removed and the component now uses `InteractsWithLocationPickerSelection`, centralizing the fix.

The new regression test reproduces the previously missing-key state and passes after the fix. Focused verification passed:

- `SubmitEventLocationTest`: 8 tests / 24 assertions.
- `InstitutionContributionLocationPickerTest`: 9 tests / 56 assertions.
- Institution and person admin infolist tests: 7 tests / 29 assertions.
- `ResolveGooglePlaceSelectionActionTest`: 8 tests / 46 assertions.
- `vendor/bin/phpstan analyse --ansi`: no errors.
- Pint passed for all changed location files and the new event regression test; the pre-existing dirty changes in `Create.php` still trigger unrelated Pint fixers when that whole file is checked.
- `php artisan view:cache` and `git diff --check` passed.

A fresh full parallel suite was attempted, but one worker remained CPU-bound with no progress for approximately 27 minutes and no timeout configured. It was stopped to avoid leaving an unbounded process running; the location-specific suites above are the authoritative regression result. Browser E2E was not independently rerun because the available browser context was unauthenticated.
