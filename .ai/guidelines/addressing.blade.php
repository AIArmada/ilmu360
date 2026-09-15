# Addressing & Geography Guidelines

This application uses `aiarmada/addressing` natively. Treat the package's current migrations, models, country profiles, and actions as canonical: direct country/state/city IDs plus role-based address-area assignments. Do not invent fixed admin-area columns, aliases, or backward-compatibility shims, and never assume one country's profile maps to another's fixed columns.

## Canonical address data

| Data | Storage |
|------|---------|
| Country | `addresses.country_id` (UUID) |
| State or federal territory | `addresses.state_id` (UUID) |
| City | `addresses.city_id` (UUID, optional) |
| Administrative and postal areas | `address_area_assignments` rows (`address_id`, `address_area_id`, `role`, `is_primary`, `metadata`) |

Use `Address::areaAssignments()`, `AddressAreaAssignment`, `AddressLocationData::areaAssignments`, `SyncAddressAreaAssignmentsAction`, and the configured `CountryAddressProfile`.

For Malaysia, the profile defines roles such as `administrative_division`, `administrative_district`, `administrative_subdivision`, and `postal_locality`. The selected state remains `state_id`; an address-area assignment is not a substitute for the state relation.

Denormalized text and provider/navigation fields may also be stored when useful: `line1`, `line2`, `postcode`, `state`, `city`, `country`, `country_code`, and the package's geo/provider fields.

## Form and validation

Use the package's country → state → optional city flow, then the area roles from the selected country's profile. Resolve options via package profile/provider APIs and persist via `SyncAddressAreaAssignmentsAction`; do not duplicate hierarchy rules in callers.

## Forbidden legacy keys

- Fixed `admin_area_1_id` through `admin_area_4_id` columns.
- Form-only aliases such as `state_area_id`.
- Removed geography keys/relations: `district_id`, `subdistrict_id`, `district()`, `subdistrict()`, `stateArea()`, `districtArea()`, `subdistrictArea()`.
- Integer geography tables or integer geography foreign keys.
- Dual API keys or caller-side remapping of obsolete keys.

`area_assignments`, `areaAssignments`, `AddressAreaAssignment`, and configured role names are current package APIs, not legacy consumers.

## Seed and import

- Countries: `php artisan address:seed-countries` or the package country seeder.
- Malaysia states/cities: `AIArmada\Addressing\Database\Seeders\MalaysiaGeographySeeder`.
- Administrative/postal areas: import `address_areas` through package/import actions, then sync role-based assignments.

## Snapshots

Historical moments use `AddressSnapshot` or event-location snapshots; do not point historical records at mutable live address state.

## Verification

```bash
# Application code must not use removed fixed-column or legacy geography APIs.
rg -n "admin_area_[1-4]_id|state_area_id|\\bdistrict_id\\b|\\bsubdistrict_id\\b|stateArea\\(|districtArea\\(|subdistrictArea\\(" app/ tests/ database/ resources/ --glob '!**/storage/**'
```
