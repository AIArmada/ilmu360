# Task: Optimize penceramah edit loading

## Current Task: Speaker profile repeaters

- [x] Add alternate-name repeater to the speaker update form.
- [x] Replace single affiliated institution editing with an optimized repeater.
- [x] Update quick-add institution language and address fields.
- [x] Run focused regression tests, formatting, and static analysis.

## Review

Verification: `vendor/bin/pest --parallel tests/Feature/ContributionPagesTest.php` (59 tests / 403 assertions), Pint, PHPStan on all changed PHP files, and `git diff --check` passed.

## Current Task: Improve speaker contact section

- [x] Locate the public “Hubungi Penceramah” section.
- [x] Add contact-type icons and improve contact card hierarchy.
- [x] Verify Blade rendering and focused UI coverage.

## Review

The public speaker contact cards now show type-specific icons for phone,
WhatsApp, and email, with a neutral link fallback for other contact types.
Each card keeps its existing destination/value while gaining clearer hierarchy
and hover feedback.

Verification: `php artisan view:cache`, `git diff --check`, live response check
for `/penceramah/idris-ahmad`, and `vendor/bin/pest --parallel
tests/Feature/PersonShowSocialPlacementTest.php` (6 tests / 25 assertions)
passed.

- [x] Trace the speaker edit route/component, query dependencies, and family-name field.
- [x] Establish a reproducible baseline for request timing/query count and add regression coverage.
- [x] Implement query optimization and fix family-name loading.
- [x] Run focused tests, PHPStan, and targeted verification.

## Review

The person update form now hydrates `family_name`. Initial page load no longer
preloads every institution and title record; both catalogs use bounded,
search-backed queries and selected-value label lookups. The initial profile
query inventory was 34 queries and included an unbounded institution catalog;
the catalog path is now deferred to user search/selection.

Verification: `vendor/bin/pest --parallel tests/Feature/ContributionPagesTest.php`
passed 56 tests / 394 assertions; Pint passed; PHPStan passed all 955 files;
all modified PHP files pass syntax checks.

## Media hydration follow-up

- [x] Trace repeated Spatie media relationship hydration in direct-edit fields.
- [x] Override the upload relationship loader to use `loadMissing('media')`.
- [x] Avoid forced media reloads while comparing direct-edit changes on save.
- [x] Re-run person media functional coverage and static checks.

Result: the direct-edit upload fields retain the vendor behavior while reusing
the loaded media relationship and keeping preview URL resolution safe during
Livewire hydration. Focused media coverage passed 4 tests / 28 assertions;
Pint and PHPStan passed for the changed implementation files.

## Admin and shared media audit

- [x] Verify the admin person edit form uses the shared optimized loader.
- [x] Inventory all application `SpatieMediaLibraryFileUpload` usages.
- [x] Confirm the admin person form hydrates five media collections with one media query in isolation.

The same provider-level optimization covers person, institution, reference,
event, venue, report, inspiration, series, donation-channel, membership
evidence, and submission forms. Admin person tests passed 5 tests / 21
assertions; the person tab test passed; Pint and PHPStan passed.

## Institution label hydration

- [x] Reproduce the missing selected institution on the speaker update form.
- [x] Separate institution search visibility from selected-value label lookup.
- [x] Add regression coverage for non-public existing affiliations.

The form now displays an existing affiliated institution even when it is not
currently in the public verified/pending search catalog. Full contribution
coverage passed 58 tests / 405 assertions.

## Institution dropdown search

- [x] Reproduce the dropdown and search behavior through Chrome.
- [x] Fix case-sensitive institution and alternate-name matching.
- [x] Verify lowercase `masjid` search returns live institution options.

Chrome verification returned institution options for lowercase `masjid` with
no console errors. Institution-focused contribution coverage passed 21 tests /
126 assertions; Pint, PHPStan, and diff checks passed.

## Institution dropdown initial options

- [x] Reproduce the empty list when opening the institution field without typing.
- [x] Add a bounded initial list while preserving server-side search.
- [x] Verify opening and selecting an institution directly in Chrome.

Chrome now shows the first 50 institutions immediately on click and successfully
selects an institution without requiring prior text entry.
