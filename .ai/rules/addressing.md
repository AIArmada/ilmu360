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
  `DatabaseSeeder`.
- Never hardcode state, district, or locality scoping. Location features
  resolve through addressing (see `app/Support/Location`) and honor the
  package's grouped subdivision/locality presentation default.
- After pulling package dataset changes, re-run
  `php artisan db:seed --class='AIArmada\Addressing\Database\Seeders\AddressingSeeder'`
  on local databases. Stale databases go silently broad: the symptom is a
  district page listing other districts' towns.
- Seed behavior is set only through the published `config/addressing.php`
  keys `seed.full_city_countries` (city volume) and `seed.postal_codes`
  (`AddressingSeeder` seeds postal codes when true; there is no separate
  postal seeder to call).
