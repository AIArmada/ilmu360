# Dune runtime feature map

These are user-facing journey handles for runtime verification, not a PHP architecture map. Each feature description has exactly four H2 sections. The checked-in CLI runs read-only two-view HTTP checks; authenticated mutations and database persistence require existing Pest integration coverage and independent database assertions.

## Launch and evidence

With an isolated PostgreSQL test database configured:

```sh
APP_ENV=testing DB_CONNECTION=pgsql DB_DATABASE=test verify/cli/verify-app launch
verify/cli/verify-app doctor
verify/cli/verify-app http GET /majlis
verify/cli/verify-app cleanup
```

The harness preserves HTTP request bodies and JSON evidence in `storage/verify-artifacts/`. It never deletes the test database. Do not run it against production or staging.

## Core user journeys

- [Events](events.md)
- [Institutions](institutions.md)
- [Persons](persons.md)
- [Registration](registration.md)
- [Contributions](contributions.md)
- [Share tracking](share-tracking.md)
