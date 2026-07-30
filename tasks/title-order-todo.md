# Order person title options by category

## Plan

- [x] Reuse the configured title-category ordering for title options.
- [x] Run focused formatting, static analysis, and regression checks.

## Review

Title options now sort by title category `sort_order`, then title
`sort_order`, then name. Category labels are not displayed.

Verification: the focused public title sync test passed (1 test / 11
assertions), Pint passed, PHPStan passed, and `git diff --check` passed.
