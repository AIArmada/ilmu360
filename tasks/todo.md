# Todo — Production Reference + Author Seeder

- [x] Inspect prod `ilmu360-prod` references (83 rows, free-text `author`, 1 part) + local schema
- [x] Create `database/seeders/ProductionReferenceSeeder.php` (source `ilmu360-prod`, preserve IDs, parse authors, sync contributors)
- [x] Verify: `vendor/bin/pint --dirty --format agent`, run seeder on local copy, run `ReferenceSeederTest`
- [ ] Document result in review section

## Review

- Replaced demo `ReferenceSeeder` with embedded production data (83 books, prod IDs preserved, 1 part relinked) — no `ilmu360-prod` connection, same pattern as `SpeakerSeeder`.
- Prod mapping: free-text `author` split on commas → `Person` (inactive, non-submittable) + `syncContributors(Author)` on works only (parts inherit); `kitab`→`book`; verified status; slug preserved or regenerated.
- Verified: fresh seed 83 refs; rerun 0 new / 83 updated (idempotent on pgsql + sqlite); `Al-Fiqh Al-Manhaji` got 3 author links; part kept `parent_id`; `ReferenceSeederTest` 2 passed (17 assertions), `InstitutionSeederTest` 2 passed; pint + phpstan clean.
- `db:seed` wiring: `DatabaseSeeder.php:69` `SpeakerSeeder` (guarded, runs first) + `ReferenceSeeder` in the main call list — both run on every non-production `db:seed`.
- Reference covers: embedded 51 prod media rows (48 front_cover, 1 back_cover, 2 gallery, disk s3) in `ReferenceSeeder`; S3 keys already match the local path generator so no bucket copy needed. Verified real S3 thumb URLs + rerun idempotent; `ReferenceSeederTest` now 3 tests. Full set (7 tests) green; pint + phpstan clean.
- Admin 403 on `admin.ilmu360.test/`: panel home `/` served the signals plugin dashboard (Filament Dashboard defaults to route `/`), whose `signal-report.view` ability is held by nobody — every admin got 403 while `/dashboard` worked. Fixed in the package (`commerce/packages/filament-signals/.../SignalsDashboard.php` + explicit `$routePath = '/signals'` so a plugin dashboard can never squat the panel home); reverted the temporary app-side flag. Verified `/` → 302 → `/dashboard` → 200, `/signals` correctly 403s as a gated page; 45 panel/dashboard tests green.
