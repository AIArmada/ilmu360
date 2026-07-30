# Simplify public person update form

## Plan

- [x] Trace the shared person contribution schema and public update state.
- [x] Remove public alternative-name editing and title date inputs.
- [x] Replace public title repeaters with a multi-select and sync active assignments.
- [x] Run focused tests, formatting, static analysis, and diff checks.

## Review

The public person contribution/update form no longer exposes alternative-name
editing or title date/status metadata. Titles are selected through `title_ids`
and synchronized as active assignments while preserving existing assignment
records where possible. Admin/create-option forms retain the detailed repeater.

Verification:

- `ContributionPagesTest`: 55 passed / 393 assertions.
- Pint: passed.
- PHPStan for changed application files: passed.
- `git diff --check`: passed.
