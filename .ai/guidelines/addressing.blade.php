# Addressing & Geography Guidelines

This application uses `aiarmada/addressing` natively. Do not invent alias columns, dual storage, or BC shims.

## Canonical Address columns (persist these)

| Column | Model / meaning (Malaysia product) |
|--------|--------------------------------------|
| `country_id` | `AddressCountry` (UUID) |
| `state_id` | `State` table (UUID) — negeri / federal territory |
| `city_id` | `City` table (UUID) — major city under state |
| `admin_area_1_id` | `AddressArea` — **district** (daerah) |
| `admin_area_2_id` | `AddressArea` — **subdistrict** (mukim / local area) |
| `admin_area_3_id` | Always **null** on app writes |
| `admin_area_4_id` | Always **null** on app writes |

Also store denormalized text when useful: `line1`, `line2`, `postcode`, `state`, `city`, `country`, `country_code`, geo/provider/navigation fields.

## Package dual model (do not confuse them)

1. **First-class tables**: `states` + `cities` — use for `state_id` / `city_id`. Seed MY via package `MalaysiaGeographySeeder`.
2. **Generic tree**: `address_areas` (`type`, `level`, `parent_id`) — import hierarchy for districts/subdistricts (and optional level-1 state *nodes* only as parents of districts). Level-1 area rows are **not** written to `admin_area_*` and are **not** a substitute for `states.state_id`.

## Forbidden (zero legacy footprint)

- Do **not** use removed package leftovers: `district_id`, `subdistrict_id`, `district()`, `subdistrict()`, `stateArea()`, `districtArea()`, `subdistrictArea()`.
- Do **not** invent form-only aliases (`state_area_id` is forbidden in app code).
- Do **not** store state UUID in `admin_area_1_id`.
- Do **not** store subdistrict in `admin_area_3_id` (always null).
- Do **not** use integer geography tables or integer FKs for country/state/city/district.
- Do **not** remap legacy keys in app or tests — fix callers instead.
- Do **not** reintroduce dual API keys (`district_id` / `subdistrict_id` as aliases).

## Form cascade (MY)

```
country_id → state_id → city_id (optional)
          ↘ admin_area_1_id (district; hidden for federal territories)
            → admin_area_2_id (subdistrict / local area)
```

- District options: AddressArea level 2 whose parent is the AddressArea level-1 node that matches the selected `State` (by name/label/code within country).
- Subdistrict options: AddressArea children of the selected district; for federal territories, children of the matching level-1 area under the state.
- Federal territories (KL, Putrajaya, Labuan): no district field; `admin_area_1_id` null; local areas in `admin_area_2_id`.

## Seed / import

- Countries: `php artisan address:seed-countries` / package seeder.
- MY states + cities: `AIArmada\Addressing\Database\Seeders\MalaysiaGeographySeeder`.
- Districts/subdistricts: import into `address_areas` (CSV / `ImportAddressAreasAction` / app poskod seeders). Keep level-1 state *area nodes* only as hierarchy parents for districts when needed.

## Snapshots & DTO

- `AddressData` is flat text + geo/provider — not a substitute for FK columns on `addresses`.
- Historical moments use `AddressSnapshot` or event location snapshots — do not point live mutable addresses from historical records.

## Verification

```bash
# Must be empty (except this guideline / intentional null writes of admin_area_3/4):
rg -n "state_area_id|\\bdistrict_id\\b|\\bsubdistrict_id\\b|stateArea\\(|districtArea\\(|subdistrictArea\\(" app/ tests/ database/ resources/ --glob '!**/storage/**'
```
