# CI Failures — Compilation

## Run: 30160176993

### 1. PHPStan (1 error)
- `app/Actions/Events/SyncEventResourceRelationsAction.php:72` — passes `array<int, int>` to `Event::syncLanguages()`, which now expects `array<int, string>|string`. Line 68 has `->map(fn (mixed $id): int => (int) $id)` — change to `(string) $id`.

### 2. Pest (column "name_native" does not exist)
The old nnjeim/world `languages` table had `name_native`. The new commerce-support table has `native`. All references need updating:

**database/seeders/LanguageSeeder.php** — uses old integer IDs + `name_native`. Replace entirely (commerce-support's `SeedLanguagesAction` handles this).
**tests/Pest.php:146-152** — `name_native` → `native`
**tests/Feature/Laravel13CacheSerializationTest.php:23** — `name_native` → `native`
**tests/Unit/EventTest.php:161,166** — `name_native` → `native`
**tests/Feature/Api/Admin/AdminApiTest.php:1352,1893,1900,3159** — `name_native` → `native`
**tests/Feature/AdminAuditFollowUpTest.php:77,83** — `name_native` → `native`
**tests/Feature/EventSearchTest.php:1142,1143,1175,1176** — `name_native` → `native`

### 3. Pint (12 style issues)

Fix with: `vendor/bin/pint --format agent`
