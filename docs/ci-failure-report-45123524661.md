# CI Failure Report — Quality run 45123524661

- **Run:** https://github.com/AIArmada/ilmu360/actions/runs/45123524661
- **Workflow:** Quality
- **Branch / commit:** `refactor` @ `d4cf2151` — `refactor(media): move fallback social media icons to public/images`
- **Result:** failure — 17 jobs passed, 8 Pest shards + 1 aggregate job failed
- **Test failures:** 20 across 8 shards
- **Status:** all 8 clusters resolved. Two product fixes (cluster D — unused queries; cluster G — owner context in the relation manager); the rest are test/contract updates. 77 affected tests green (the MCP test is locally blocked by a dirty `commerce` package, see the note at the end).

---

## Resolution log (post-investigation fixes)

| Cluster | Type | Action taken | Files |
|---|---|---|---|
| A — Filament async selects | Test | Tests now assert `Select::getOptions()` instead of rendered HTML; shared helpers `submitEventFieldOptions()` / `assertSubmitEventPrayerOptions()` in `tests/Pest.php` | `tests/Pest.php`, `PrayerTimeConditionalsTest`, `SubmitEventPrayerTimeTest`, `SubmitEventOrganizerAutoSelectTest` |
| B — `domain_tags` multiple | Test | Fixtures now pass arrays, matching the multi-select contract | `SubmitEventMediaTest`, `PublicPagesTest` |
| C — 60s option cache | Test | Form fixtures are built before the component renders | `SubmitEventZeroHttpTest` |
| D — occurrence query budgets | **Product** | Stopped priming the default venue when the event's `venue` relation is already loaded. The perf commit added `default_venue_id` to the prime set, but `Event::resolvedLocationAddress()/Name()` bypass the resolver when `venue` is loaded, so those 7 queries were pure waste (59 → 52 for the single-date page, 62 → 55 for the sibling page) | `PublicScheduleDiscoveryService` |
| E — sort tracking hook | Test | The sort control is intentionally inside the lazily mounted filter form; the package tracker binds via document-level `change` delegation, so tracking still works once the panel opens. The test now requests `?filters_panel=open` before asserting the hook | `SignalsIntegrationTest` |
| F — member directory writes | Test | Test now expects the reviewed-publishing `403 Forbidden.` and asserts no write occurred | `Mcp/SecurityChecklistTest` |
| G — Filament table action | **Product** | The relation manager sets explicit global owner context in `booted()`, because Filament 5.12 inherits the institution table's `addresses` eager load; without context the guard throws on Livewire updates. The original `callTableAction` assertions work unchanged | `InstitutionsRelationManager` |
| H — removed cache key | Test | Test asserts the `SelectionCatalogCache` language contract instead of the deleted `event_filter_languages_v4` key | `Laravel13CacheSerializationTest` |

Verification: 77 affected tests green, `PublicDirectoryPerformanceTest` + `PublicPagesTest` + occurrence/signals suites green, Pint clean.

**Product fixes required:** clusters D and G. Everything else was stale test expectations after the Filament 5.12 upgrade, the multi-select `domain_tags` change, the reviewed-publishing guard, the perf commit's eager-load ordering, and the removed cache key.

---

## 0. Read this first — test environment hazard

**Do not run Pest in this checkout without overriding the database.**

The shell environment (and `.env`) exports:

```
APP_ENV=staging
DB_CONNECTION=pgsql
DB_DATABASE=ilmu360_staging
```

`phpunit.xml` declares `DB_CONNECTION=sqlite` / `DB_DATABASE=:memory:` **without** `force="true"`, so PHPUnit does **not** override the already-present environment variables. The suite uses `RefreshDatabase`, which runs `migrate:fresh`.

Consequence: running `vendor/bin/pest` here drops and recreates the `ilmu360_staging` schema. During this investigation that happened; the DB currently shows `358` migrations in a single batch and `users=0`, `events=0`, `institutions=0`. No `ilmu360_staging` dump was found under `/home/forge/backups`, and the `ilmu360` database is not readable by the app user.

Safe invocation for any further test work:

```bash
DB_CONNECTION=sqlite DB_DATABASE=:memory: APP_ENV=testing \
CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync BROADCAST_CONNECTION=null \
  vendor/bin/pest tests/Feature/SomeTest.php --compact
```

`CACHE_STORE` and `SESSION_DRIVER` matter too: when the shell exports `CACHE_STORE=file`, the 60s taxonomy option caches persist across tests and runs, which produced nondeterministic failures during this investigation. `phpunit.xml` does not override them for the same reason (no `force="true"`).

Verify before running anything:

```bash
DB_CONNECTION=sqlite DB_DATABASE=:memory: APP_ENV=testing \
  vendor/bin/pest --agent='fwrite(STDERR, config("database.default")." / ".config("database.connections.".config("database.default").".database")."\n"); expect(true)->toBeTrue();'
# expect: sqlite / :memory:
```

Longer-term options (pick one): add `force="true"` to the DB `<env>` entries in `phpunit.xml`, or stop exporting production-like DB vars in the dev shell, or add a Pest guard that aborts when `config('database.default') !== 'sqlite'`.

---

## 1. Failure summary

| Shard / job | Job ID | Failing test(s) | Failure |
|---|---|---|---|
| Pest 14/20 | `137522593137` | `EventOccurrencePageTest` ×3 | 59 > 56, 62 > 56, 66 not < 65 queries |
| Pest 14/20 | `137522593137` | `PrayerTimeConditionalsTest` ×6 | Prayer labels absent from rendered HTML |
| Pest 10/20 | `137522593153` | `SignalsIntegrationTest` ×1 | missing `data-signal-change-event="filter.sort_changed"` |
| Pest 11/20 | `137522593168` | `SubmitEventZeroHttpTest` ×1 | `data.domain_tags.0` rejected as invalid |
| Pest 1/20 | `137522593213` | `SubmitEventPrayerTimeTest` ×2 | Prayer labels absent from rendered HTML |
| Pest 16/20 | `137522593218` | `SubmitEventMediaTest` ×2 | Submit does not redirect — validation fails |
| Pest 19/20 | `137522593221` | `SpaceModelRemediationTest` ×1 | CI: `NoCurrentOwnerException` (Address); local: `recordId` required |
| Pest 19/20 | `137522593221` | `SubmitEventOrganizerAutoSelectTest` ×1 | Person name absent from rendered HTML |
| Pest 20/20 | `137522593222` | `Mcp\SecurityChecklistTest` ×1 | `Forbidden.` on member institution update |
| Pest 5/20 | `137522593258` | `Laravel13CacheSerializationTest` ×1 | removed cache key is `null` |
| Pest 5/20 | `137522593258` | `PublicPagesTest` ×1 | `data.domain_tags` must be an array |
| Quality | `137525791164` | — | Aggregate gate only; fails because shards failed |

Everything else was green: PHPStan, Pint (check), Rector (check), Browser smoke (Chromium), Detect changes, and shards 2, 3, 4, 6, 7, 8, 9, 12, 13, 15, 17, 18.

Fetch logs for any job:

```bash
gh run view --repo AIArmada/ilmu360 --job <JOB_ID> --log \
  | perl -pe 's/\Q^[\E\[[0-9;]*m//g; s/\Q^[\E\[[0-9;]*[A-Za-z]//g'
```

Note: GitHub's exported logs contain literal `^[[..m` sequences (already-escaped ANSI), hence the `perl` substitution. `--log-failed` is useless here because the Pest step is reported as `UNKNOWN STEP` and the output contains the Postgres service-container log.

---

## 2. Cluster A — Filament v5.12 async selects (9 tests)

### Failing tests

- `tests/Feature/PrayerTimeConditionalsTest.php` — all 6 tests
- `tests/Feature/SubmitEventPrayerTimeTest.php` — `it shows base prayer time options without selecting a date` (line 24), `it hides conditional prayer time options without a date` (line 38)
- `tests/Feature/SubmitEventOrganizerAutoSelectTest.php` — `it shows formatted person names in submit event selectors` (line 89)

### Symptom

`assertSee('Selepas Subuh')`, `assertSee('Sebelum Maghrib')`, `assertSee('Selepas Zuhur')`, `assertSee($person->formatted_name)` fail even though the form renders successfully (~7.6k lines of HTML).

### Root cause

Filament was upgraded from `v5.11.4` to `v5.12.0` in commit `5083746b` (`Update composer.lock`).

In `vendor/filament/forms/src/Components/Select.php`:

```php
if (! $isNative) {
    $slotHtml = $this->wrapAsyncComponentHtml($slotHtml, key: ...);
}
```

and

```php
public function hasDynamicOptions(): bool
{
    ...
    if ($this->isPreloaded()) {
        return true;
    }

    return $this->options instanceof Closure;
}
```

When a non-native select has `->preload()` (or a closure `options()`), options are now treated as dynamic and fetched on dropdown open, so the initial server HTML contains an `fi-async-component` with a loading placeholder rather than the option labels/JSON. Previously the labels were present in the serialized `x-data` options blob, which is what `assertSee` matched.

The submit-event `prayer_time` select is a closure-options, non-native select (`EventSubmissionFormSchema.php` around line 377). The person/organizer selectors are the same shape.

Verification snippet used during investigation (server HTML contains `Waktu` but no `Selepas …`):

```php
$html = Livewire::test(App\Features\Events\EntryPoints\Livewire\Pages\SubmitEvent\Create::class)->html();
str_contains($html, 'prayer_time');   // true
str_contains($html, 'Selepas');       // false
```

Also note `fi-async-component` keeps the inner markup in the DOM (hidden with `x-show`), but for non-native selects the option list is rendered client-side from Alpine data, so there is nothing textual to assert.

### Options to consider

1. Pin Filament to `5.11.4` until the async-select change is accounted for.
2. Rewrite the assertions to test component state instead of raw HTML, e.g. `assertFormFieldExists('prayer_time', fn (Select $field) => array_key_exists(EventPrayerTime::SelepasSubuh->value, $field->getOptions()))`. This is the most durable fix.
3. Force inline options on the affected fields with `->dynamicOptions(false)` (already used on `domain_tags` and `event_category_ids`) — but this is a product/performance decision, since it re-inlines every option on first paint.

---

## 3. Cluster B — `domain_tags` became a multi-select (3 tests)

### Failing tests

- `tests/Feature/SubmitEventMediaTest.php` — `it stores cover, poster, and gallery uploads when submitting an event` (line 93), `it does not require guest dependency ...` (line 158) — both fail with `Component did not perform a redirect.`
- `tests/Feature/PublicPagesTest.php` — `it records guest submissions without a submitter id` (line 909) — `Component has errors: "data.domain_tags" => ["topik / bidang mestilah dalam bentuk tatasusunan."]`

### Root cause

Commit `b2c0a907` (`feat: complete directory contribution parity and reviewed publishing`) changed `domainTopicField()` in `app/Features/Events/EntryPoints/Livewire/Pages/SubmitEvent/EventSubmissionFormSchema.php`:

```php
Select::make('domain_tags')
    ->required()
+   ->multiple()
+   ->closeOnSelect()
    ...
    ->native(false)
    ->dynamicOptions(false)
```

Filament appends an implicit `array` rule for every multiple field (`CanBeValidated::getValidationRules()`), plus a per-item `in` rule (`dehydrateValidationRules()`).

The tests still use the old scalar canonical:

- `submitEventMediaFormData()` / `submitEventMediaFixtures()` in `SubmitEventMediaTest.php` set `'domain_tags' => $fixtures['domain_tag_ids']` — a single UUID string.
- `PublicPagesTest.php:899` does `->set('data.domain_tags', $domainTag->id)` — scalar.

The submit path normalizes scalars (`EventSubmissionFormSchema::normalizeDomainTagState()`), but the Filament schema validation runs first inside `eventForm()->getState()`, so the error fires before normalization.

### Options to consider

1. Update the two test files to pass `[ $term->id ]` and use the multi-select state shape (matches the new UI contract).
2. If scalar state must stay supported for older API/MCP callers, add a form-level `mutateStateForValidation`/normalization that wraps scalars before Filament validation, and keep a regression test for both shapes.

---

## 4. Cluster C — stale option cache in the zero-HTTP submit test (1 test)

### Failing test

- `tests/Feature/SubmitEventZeroHttpTest.php` — `it submits with zero live HTTP on a cold cache and records hardcoded provenance` (line 29)

```
Component has errors: "data.domain_tags.0" => ["topik / bidang yang dipilih adalah tidak sah."]
```

### Root cause

Commit `f3e0e282` (perf) introduced a 60-second taxonomy-option cache:

`app/Features/Events/Persistence/EventSubmissionOptionsCacheStore.php`

```php
public function rememberTaxonomyTagOptions(string $cachePrefix, string $locale, Closure $resolver): array
{
    return Cache::remember(sprintf('%s_%s_safe_v2', $cachePrefix, $locale), 60, $resolver);
}
```

used by `SubmitEventOptionsProvider::tagOptions()` (`app/Features/Events/Services/SubmitEventOptionsProvider.php:136`).

The test does:

```php
setSubmitEventFormState(
    Livewire::test(Create::class),          // ← rendered first
    submitEventPrayerFormData([...]),        // ← creates the taxonomy term
)
```

PHP evaluates arguments left-to-right, so the component renders **before** `submitEventPrayerFormData()` creates the `EventTerm`. The options cache is warmed with `[]`; the subsequent submit selects an id that is not in the cached option list, and Filament's per-item `in` rule rejects it.

Proof: swapping the order (create `$form` first, then `Livewire::test(...)`) makes the test pass; so does clearing the `submit_tags_domain_ms_safe_v2` key between render and submit.

### Options to consider

1. Fix the test to build fixtures before rendering the component (correct hygiene).
2. Make the cache key taxonomy-version aware or bust it when a term is created, so a cold/empty cache cannot outlive a new term.
3. Consider `->dynamicOptions(false)` on `domain_tags` (it is already set) — the failure is the `in` rule, not the dynamic-options path, so this alone will not fix it.

---

## 5. Cluster D — occurrence page query-budget regression (3 tests)

### Failing tests

`tests/Feature/EventOccurrencePageTest.php`

| Test | Budget | CI actual | Local actual |
|---|---|---|---|
| `it renders the occurrence page with a bounded enriched query budget` (line 84) | ≤ 56 | 59 | 59 |
| `it keeps the occurrence budget stable when sibling dates grow` (line 118) | ≤ 56 | 62 | 65 |
| `it renders the session page ...` (line 324) | < 65 | 66 | 69 |

### Timeline / evidence

- Last fully green Quality run: `44995101064` @ `ecb801eb`.
- Pre-perf run `45115887791` @ `3c480b28`: shard 14 failed **only** `SubmitEventMediaTest`; all three occurrence budget tests passed.
- Perf run `45118592461` @ `f3e0e282`: the same three occurrence budget tests fail with the same counts. **Regression introduced by `f3e0e282`.**
- `tests/Feature/EventOccurrencePageTest.php` budgets are unchanged between `ecb801eb` and HEAD, so the code got heavier, not the assertion.

### Query attribution at HEAD (sqlite in-memory, single-occurrence page, 63 queries)

```
24  Occurrence::mount base graph (line 71)
12  Show::loadSelectedCommerce (line 396)
 8  ShareCardService::ogCard (line 578)
 5  VenueAddressResolver::prime (line 59)
 4  ResolvePublicSlugRedirect middleware (line 85)
 3  SetFilamentTimezone middleware (line 40)
 2  EloquentPlaceCatalog::venueLocationSummariesFor (line 170)
 1  SlugOrUuidResolver, engagement counts, JSON-LD, event_updates, etc.
```

Largest visible block is `ShareCardService` (cover/occurrence/media/venue lookups) followed by the base eager-load graph. Sibling-heavy pages add queries in the middleware/signals and share-card paths.

### Most likely contributor inside `f3e0e282`

`app/Features/Events/Models/Event.php` changed `resolvedLocationAddress()` / `resolvedLocationName()` (around lines 1632–1680) to fall back to `VenueAddressResolver` when the relation is not loaded:

```php
return app(VenueAddressResolver::class)->forVenueId((string) $this->default_venue_id);
...
return app(VenueAddressResolver::class)->nameForVenueId($primaryLocationVenueId);
```

`primeOccurrencePageVenueAddresses()` only primes the current event's locations. Any related/sibling event rendered in "Majlis lain yang berkaitan" that lacks a preloaded `venue` / `primaryLocationVenue` now triggers unprimed resolver lookups. This is consistent with the sibling test growing most (+6) while the single-occurrence test grows least (+3).

`VenueAddressResolver::prime()` also switched from `primaryAddressIdsFor()` + `venueNamesByIds()` to `venueLocationSummariesFor()` in the same commit, and `primeVenueAddresses()` now pushes `default_venue_id` into the venue-id set.

### Options to consider

1. Prime venues for related/sibling events too (batch the `default_venue_id` / location venue ids for the related-event collection before rendering), or eager-load `venue` / `primaryLocationVenue` for those cards.
2. Confirm whether `ShareCardService::ogCard()` can reuse already-loaded media/venue relations instead of re-querying.
3. Only if the extra queries are accepted, raise the budgets deliberately — but do that as a conscious change with the new numbers, not silently.

### Reproduction

```bash
DB_CONNECTION=sqlite DB_DATABASE=:memory: APP_ENV=testing \
  vendor/bin/pest tests/Feature/EventOccurrencePageTest.php --compact
```

A query-attribution harness (not committed) was used; recreate it by registering `DB::listen()` in a throwaway test and walking `debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)` for the first frame under `/app/`, `/resources/`, or `/vendor/aiarmada/`.

---

## 6. Cluster E — lazily mounted filter form removed a tracking hook (1 test)

### Failing test

- `tests/Feature/SignalsIntegrationTest.php` — `it renders the inline signal event hooks used by the package tracker` (line 63)

```
To contain: data-signal-change-event="filter.sort_changed"
```

### Root cause

`f3e0e282` added `Index::$filterFormMounted` and deferred the filter subtree:

`resources/views/partials/event-filter-panel.blade.php:171`

```blade
@if($this->filterFormMounted)
    ... {{ $this->sortForm }} ... {{ $this->form }}
@else
    ... Loading filters ...
@endif
```

`mountFilterForm()` is only called on click (`@click="... $wire.mountFilterForm()"`), so a plain `GET /majlis` (`events.index`) no longer contains the sort select and its `data-signal-change-event="filter.sort_changed"` attribute (`Index.php:491`).

`filter.changed` and `search.nearby_requested` still render, which is why only the sort assertion fails.

### Options to consider

1. Keep a lightweight, always-rendered sort control (outside the lazily mounted subtree) and move the signal attribute there.
2. Mount the form for the initial render whenever the page has a sort-relevant surface, and only defer the expensive location/category form.
3. If the hook is intentionally deferred, update the test to open the panel first (`Livewire::test(...)->call('openFiltersPanel')` for Livewire coverage, or a browser assertion) — but be aware this is also a real tracking regression on first paint.

---

## 7. Cluster F — directory write guard blocks member MCP updates (1 test)

### Failing test

- `tests/Feature/Mcp/SecurityChecklistTest.php` — `it ignores hidden institution slug injections and preserves coordinates across admin and member scopes` (line 94)

```
The response has errors.
-Array []
+Array [ 0 => 'Forbidden.' ]
```

### Root cause

`b2c0a907` added a policy gate to directory mutation hooks, e.g.:

`app/Features/Institutions/EntryPoints/Admin/Resources/InstitutionMutationHooks.php` (same pattern in `Events`, `Persons`, `References` hooks under `EntryPoints/Admin/Resources/`):

```php
abort_unless($actor->canPublishDirectoryUpdates(), 403,
    __('Submit directory updates for review through the contribution form.'));
```

`User::canPublishDirectoryUpdates()` is `hasGlobalAdminAccess()` (global `super_admin` / `admin` only). A member with an `admin` membership on an institution passes `canAccess()` and `canWriteResource()` but is then rejected by the write hook.

Reproduced locally by calling `MemberResourceService::writeSchema()` (ok) followed by `updateRecord()`:

```
updateRecord: HttpException :: Submit directory updates for review through the contribution form.
```

This looks like an **intended** policy change (member directory edits now go through the contribution/review flow), with the test still asserting the pre-change behavior.

### Options to consider

1. Update the test to expect a 403 / `forbidden` for member directory writes and move the coordinate-preservation assertions to an admin-only path (or to the contribution flow).
2. If member MCP writes are supposed to remain available, provide a reviewed-publishing MCP tool/path and let the hook delegate to it instead of aborting.

---

## 8. Cluster G — Filament 5.12 table-action behavior (1 test)

### Failing test

- `tests/Feature/SpaceModelRemediationTest.php` — `it routes space institution relation manager attach and detach through directory actions` (around line 270, 10th test in the file)

CI failure:

```
NoCurrentOwnerException
AIArmada\Addressing\Models\Address requires an owner context or explicit global context.
at .../commerce-support/src/Support/OwnerContext.php:129
.../addressing/src/Support/AddressOwnerGuard.php:29
```

Local reproduction (sqlite):

```
Component has errors: "mountedActions.0.data.recordId" => ["Rekod diperlukan."]
```

### Root cause

The test uses the Filament table-action test helper:

```php
->callTableAction('attach', data: ['recordId' => (string) $institution->getKey()])
```

On `filament/tables` v5.12 the action is an `AttachAction` with `preloadRecordSelect()`. Behavior around preloaded record selects / mounted-action data changed; locally the `recordId` state is not applied before validation, and on CI the option resolution reaches institution/address hydration without an owner context.

Both failure modes are in the same test and are environment-dependent (sqlite vs Postgres container + ordering), so this is one root cause (Filament 5.12 table-action/record-select change), not two.

### Options to consider

1. Update the test to the 5.12 interaction shape (e.g. mount the action then `setTableActionData()` before calling it), or assert through the underlying action (`AttachInstitutionSpaceAction`) plus a separate lightweight UI test.
2. Check whether `AttachAction` needs an explicit record / `recordSelect` configuration in the relation manager after the 5.12 upgrade.
3. Confirm the owner-context part independently: if the preloaded select hydrates institutions through the addressing guard, wrap the test or the option query in `OwnerContext::withOwner(null, ...)`.

---

## 9. Cluster H — stale cache-key assertion (1 test)

### Failing test

- `tests/Feature/Laravel13CacheSerializationTest.php` — `it hydrates the events index language cache into the current safe payload format` (line 27)

```
Failed asserting that null is of type array.
```

### Root cause

`90bf2f98` replaced the ad-hoc cache with the shared selection catalog:

```php
- return cache()->remember('event_filter_languages_v4', 3600, function (): array { ... });
+ $catalogOptions = app(SelectionCatalogCache::class)->languageOptions('code', $preferredLabels);
```

(`app/Features/Events/EntryPoints/Livewire/Pages/Index.php::languageOptions()`)

`event_filter_languages_v4` is no longer written anywhere (grep confirms zero producers). The new storage is `SelectionCatalogCache` under `selection_catalog:languages:*` with a `CacheVersionStore` version key (`LANGUAGES_VERSION_KEY`).

### Options to consider

1. Update the test to assert the new catalog contract: call `languageOptions()` and assert the returned keys, and optionally assert the versioned cache key exists.
2. If the old key is still part of an external contract (unlikely), re-introduce a compatibility write — but prefer deleting the stale assertion.

---

## 10. Suggested fix order for the next agent

1. **Environment safety first** — add the sqlite `force="true"` guard (or a Pest abort) so nobody else wipes a real database, then restore staging if a dump is available.
2. **Decide the two product-level questions:**
   - Are non-native Filament selects allowed to be async on first paint? If yes → migrate the `assertSee` tests to `getOptions()` assertions. If no → pin Filament or set `->dynamicOptions(false)` on affected fields.
   - Are member MCP directory writes intentionally disabled? If yes → update `SecurityChecklistTest`. If no → route them through the reviewed-publishing flow.
3. **Mechanical test updates:** scalar → array `domain_tags` (`SubmitEventMediaTest`, `PublicPagesTest`); fixture-before-render (`SubmitEventZeroHttpTest`); new catalog key (`Laravel13CacheSerializationTest`).
4. **Query budget:** prime related-event venues or relax budgets with an explicit, reviewed number.
5. **Tracking hook:** keep `filter.sort_changed` rendered outside the lazy subtree.
6. **Filament table action:** adapt the relation-manager test and verify owner context.

## 11. Verification commands

Narrow runs, all forced to in-memory SQLite:

```bash
DB_CONNECTION=sqlite DB_DATABASE=:memory: APP_ENV=testing vendor/bin/pest tests/Feature/PrayerTimeConditionalsTest.php --compact
DB_CONNECTION=sqlite DB_DATABASE=:memory: APP_ENV=testing vendor/bin/pest tests/Feature/SubmitEventPrayerTimeTest.php --compact
DB_CONNECTION=sqlite DB_DATABASE=:memory: APP_ENV=testing vendor/bin/pest tests/Feature/SubmitEventMediaTest.php --compact
DB_CONNECTION=sqlite DB_DATABASE=:memory: APP_ENV=testing vendor/bin/pest tests/Feature/SubmitEventZeroHttpTest.php --compact
DB_CONNECTION=sqlite DB_DATABASE=:memory: APP_ENV=testing vendor/bin/pest tests/Feature/SubmitEventOrganizerAutoSelectTest.php --compact
DB_CONNECTION=sqlite DB_DATABASE=:memory: APP_ENV=testing vendor/bin/pest tests/Feature/PublicPagesTest.php --filter="records guest submissions" --compact
DB_CONNECTION=sqlite DB_DATABASE=:memory: APP_ENV=testing vendor/bin/pest tests/Feature/EventOccurrencePageTest.php --compact
DB_CONNECTION=sqlite DB_DATABASE=:memory: APP_ENV=testing vendor/bin/pest tests/Feature/SignalsIntegrationTest.php --compact
DB_CONNECTION=sqlite DB_DATABASE=:memory: APP_ENV=testing vendor/bin/pest tests/Feature/SpaceModelRemediationTest.php --compact
DB_CONNECTION=sqlite DB_DATABASE=:memory: APP_ENV=testing vendor/bin/pest tests/Feature/Mcp/SecurityChecklistTest.php --compact
DB_CONNECTION=sqlite DB_DATABASE=:memory: APP_ENV=testing vendor/bin/pest tests/Feature/Laravel13CacheSerializationTest.php --compact
```

After any change:

```bash
composer arch:check
vendor/bin/pint --dirty --format agent
DB_CONNECTION=sqlite DB_DATABASE=:memory: APP_ENV=testing vendor/bin/pest --parallel --compact
```

## 12. Investigation artifacts

- Raw CI logs (gitignored): `storage/app/ci-logs/`
  - `plain-<job>.log` — ANSI/literal-escape stripped full job logs
  - `all-failures.txt` — extracted failure blocks
  - `perf-shard14.log`, `pre-perf-shard14.log` — the timeline evidence for the query-budget regression
- Temporary debug tests created during investigation were removed.
- Last green commit for comparison: `ecb801eb` (run `44995101064`).

## 13. Local blocker (not part of this CI run)

The sibling `commerce` working tree (`/home/forge/workspaces/aiarmada/commerce`) has uncommitted changes made outside this task. `AIArmada\FilamentOrganizations\RelationManagers\AbstractMemberInvitationsRelationManager::createInvitation()` now requires a sixth `string $channel` argument, while `app/Platform/Admin/Filament/RelationManagers/MemberInvitationsRelationManager.php` still overrides the five-argument signature.

Effect locally: loading that class is a PHP fatal, so `tests/Feature/Mcp/SecurityChecklistTest.php` currently fatals before running, and `composer arch:check` reports the same signature error. It is unrelated to this work and does not affect CI, which clones the committed `commerce` default branch. The MCP test change was verified green before the sibling tree changed. Do not edit the `commerce` package here; it belongs to the concurrent work. Once that lands (with the app-side override updated), the test and `composer arch:check` can be re-run.
