# Addressing & Geography Guidelines

This application uses `aiarmada/addressing` natively. Treat the package's current migrations, models, country profiles, and actions as canonical. The package stores direct country/state/city IDs and role-based address-area assignments; do not invent fixed admin-area columns, aliases, or backward-compatibility shims.

## Canonical address data

| Data | Storage |
|------|---------|
| Country | `addresses.country_id` (UUID) |
| State or federal territory | `addresses.state_id` (UUID) |
| City | `addresses.city_id` (UUID, optional) |
| Administrative and postal areas | `address_area_assignments` rows (`address_id`, `address_area_id`, `role`, `is_primary`, `metadata`) |

Use `Address::areaAssignments()`, `AddressAreaAssignment`, `AddressLocationData::areaAssignments`, `SyncAddressAreaAssignmentsAction`, and the configured `CountryAddressProfile`. Address-area roles and hierarchy levels are country-profile data; never assume that one country maps to the same fixed columns as another.

For Malaysia, the profile defines roles such as `administrative_division`, `administrative_district`, `administrative_subdivision`, and `postal_locality`. The selected state remains `state_id`; an address-area assignment is not a substitute for the state relation.

Denormalized text and provider/navigation fields may also be stored when useful: `line1`, `line2`, `postcode`, `state`, `city`, `country`, `country_code`, and the package's geo/provider fields.

## Form and validation

Use the package's country → state → optional city flow, followed by the area roles defined by the selected country's profile. Resolve options through the package profile/provider APIs and persist them through `SyncAddressAreaAssignmentsAction`; do not duplicate hierarchy rules in application callers.

## Forbidden legacy keys

- Fixed `admin_area_1_id` through `admin_area_4_id` columns.
- Form-only aliases such as `state_area_id`.
- Removed geography keys or relations such as `district_id`, `subdistrict_id`, `district()`, `subdistrict()`, `stateArea()`, `districtArea()`, and `subdistrictArea()`.
- Integer geography tables or integer geography foreign keys.
- Dual API keys or caller-side remapping of obsolete keys.

`area_assignments`, `areaAssignments`, `AddressAreaAssignment`, and configured role names are current package APIs, not legacy consumers.

## Seed and import

- Countries: `php artisan address:seed-countries` or the package country seeder.
- Malaysia states and cities: `AIArmada\Addressing\Database\Seeders\MalaysiaGeographySeeder`.
- Administrative and postal areas: import `address_areas` through the package/import actions and sync role-based assignments to addresses.

## Snapshots

Historical moments use `AddressSnapshot` or event-location snapshots; do not point historical records at mutable live address state.

## Verification

```bash
# Application code must not use removed fixed-column or legacy geography APIs.
rg -n "admin_area_[1-4]_id|state_area_id|\\bdistrict_id\\b|\\bsubdistrict_id\\b|stateArea\\(|districtArea\\(|subdistrictArea\\(" app/ tests/ database/ resources/ --glob '!**/storage/**'
```
