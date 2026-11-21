# Institutions

## Sub-features

Public institution directory, venue links, and institution dashboards.

## How to get to it (user POV)

From public navigation choose **Institusi**; authenticated institution managers access the institution dashboard.

## Driving it with verify-app

Preconditions: A test database is migrated; the public institution directory is accessible without an account.

```sh
verify/cli/verify-app http GET /institusi
```

Expected: HTTP 200. For an institution update verify persisted name/address and refreshed public listing through separate reads.

The CLI repeats every GET as a second independent HTTP read and records both response statuses and SHA-256 digests. For mutation and persistence validation, execute the relevant existing Pest feature tests against the isolated PostgreSQL test database and verify the resulting rows independently.

## Gotchas

Managing institution profiles needs authentication and authority. No account data should be changed using the anonymous GET harness.
