# Phase 4 - Identity, Geography, Contacts, And Membership

State: `Not Started`

## Objective

Replace app-owned support domains with package-owned UUID addressing, contact/social profiles, and membership workflows. Geography must become global by default: no app-wide country switcher, no preferred-country resolver as a product mode, and no Malaysia-only area tables in the fresh target schema.

## Target Packages

- `addressing`
- `filament-addressing`
- `contacting`
- `filament-contacting`
- `membership`
- `filament-membership` if available and ready

## Checklist

- [ ] Adopt `addressing` UUID countries/areas/addresses.
- [ ] Remove current integer geography target from the fresh rewrite.
- [ ] Seed canonical package geography data.
- [ ] Build or configure address-area import sources for country-specific administrative terms and hierarchies.
- [ ] Represent Malaysia-specific terms such as `district`, `wilayah_persekutuan`, and `small_district` as generic `address_areas.type` values.
- [ ] Replace fixed `state_id` / `district_id` / `subdistrict_id` filtering with `AddressArea` hierarchy filtering and address component search.
- [ ] Remove global country switching from the target UX and API contracts; country is an optional filter, not an application mode.
- [ ] Remove or replace `PublicCountryPreference`, `PublicCountryRegistry`, `PreferredCountryResolver`, `/negara/{country}`, and layout country selector behavior during cutover.
- [ ] Replace app address traits, actions, resources, and form schema dependencies.
- [ ] Replace app contact/social profile models and normalizers with `contacting`.
- [ ] Replace membership claim/invitation/member logic with `membership`.
- [ ] Bind package membership roles to `authz` scopes.
- [ ] Keep public membership/claim/dashboard UX app-owned.
- [ ] Update API/MCP docs for changed model contracts.

## Verification

```bash
vendor/bin/pest --parallel --compact --filter=Address
vendor/bin/pest --parallel --compact --filter=Country
vendor/bin/pest --parallel --compact --filter=Location
vendor/bin/pest --parallel --compact --filter=Contact
vendor/bin/pest --parallel --compact --filter=Membership
vendor/bin/phpstan analyse --ansi
```

## Exit Criteria

- Fresh database no longer needs old integer geography tables.
- Discovery and search are global by default and do not depend on country switching.
- Institution, venue, speaker, and event-location workflows use package UUID countries and address areas.
- Contact/social data writes through package actions/models.
- Membership workflows are package-backed and authorized through package authz contracts.

## Stop And Re-plan Triggers

- `addressing` package seed data cannot support required public geography filters.
- A public search/listing/API/MCP path still depends on a selected global country.
- Membership roles require ilmu360-specific package code.
- Contact normalization loses current supported platform or phone behavior without a generic package fix.
