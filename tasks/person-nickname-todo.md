# Add nickname person-name type

## Plan

- [x] Trace the person-name type enum, persistence, forms, serializers, and tests.
- [x] Add `nickname` consistently to the domain and user-facing paths.
- [x] Add or update focused regression coverage.
- [x] Run formatting, static analysis, focused tests, and diff checks.

## Review

`PersonNameType` is owned by `aiarmada/persons`; the application consumes the
enum generically for form options and validation, so no app-level enum or
migration change is required.

Verification:

- Commerce Persons tests: 14 passed / 81 assertions.
- Commerce Pint: passed.
- Commerce PHPStan for `packages/persons/src`: passed.
- App `PersonIndexTest`: 21 passed / 73 assertions.
- `git diff --check`: passed in both repositories.
