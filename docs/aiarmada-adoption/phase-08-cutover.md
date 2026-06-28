# Phase 8 - App Rebuild And Cutover

State: `Not Started`

## Objective

Rebuild app surfaces on package-owned domains, delete superseded app code, regenerate docs, and prove the fresh-schema application works end to end.

## Checklist

- [ ] Rebuild Filament resources against package models.
- [ ] Rebuild public Livewire pages against package models.
- [ ] Rebuild API controllers/resources against package models.
- [ ] Rebuild MCP tools/resources/prompts against package models.
- [ ] Rebuild search indexes and indexing commands.
- [ ] Remove global country-switching route, layout selector, preference services, and preferred-country defaults.
- [ ] Regenerate public/API/MCP search contracts so country and address areas are optional filters over global results.
- [ ] Rebuild policies and authorization gates.
- [ ] Rebuild queue jobs/listeners/schedules.
- [ ] Rebuild seeders for canonical package data.
- [ ] Regenerate API documentation.
- [ ] Regenerate MCP documentation.
- [ ] Delete superseded app models/actions/migrations/tests.
- [ ] Run full fresh migrate/seed verification.
- [ ] Run full test/static/build verification.

## Verification

```bash
php artisan migrate:fresh --seed
vendor/bin/pest --parallel --compact
vendor/bin/phpstan analyse --ansi
vendor/bin/pint --dirty --format agent
npm run build
php artisan route:list
```

Runtime smoke checks:

- Filament boots.
- Public event discovery, detail, registration/check-in, and membership flows work.
- Public discovery works globally by default without selecting or switching a country.
- Notification inbox and delivery flows work.
- Signals events record expected outcomes.
- MCP admin/member tools work against package-backed models.
- Media uploads and conversions work.

## Exit Criteria

- Fresh app passes all verification.
- No deleted legacy surface remains referenced by routes, docs, tests, or providers.
- `review-log.md` contains proof for the final cutover.
- `status.md` marks phases 0-8 `Verified`, `Deferred`, or `Removed` with no ambiguous work left.

## Stop And Re-plan Triggers

- Any public API/MCP generated documentation points at removed legacy shapes.
- A package-backed model cannot support a critical public workflow through generic seams.
- Full fresh migrate/seed cannot complete deterministically.
