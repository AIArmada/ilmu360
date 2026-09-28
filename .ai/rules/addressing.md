---
paths:
  - 'database/seeders/**'
  - 'config/addressing.php'
---

# Addressing

Geography truth lives in the `aiarmada/addressing` package. This app
consumes it; it never authors it.

- Never create an addressing seeder or seed orchestration in
  `database/seeders`. Call the bundled
  `AIArmada\Addressing\Database\Seeders\AddressingSeeder` from
  `DatabaseSeeder`, or run `php artisan address:seed`.
- Never hardcode state, district, or locality scoping. Location features
  resolve through addressing (see `app/Support/Location`) and honor the
  package's grouped subdivision/locality presentation default.
- After pulling package dataset changes, re-run `php artisan address:seed`
  on local databases. Stale databases go silently broad: the symptom is a
  district page listing other districts' towns.
- City seed volume is set only through the published
  `config/addressing.php` key `seed.full_city_countries`.
