# Share tracking

## Sub-features

Public short links, link attribution, authenticated share dashboards.

## How to get to it (user POV)

Open a generated share link or sign in to **Dakwah** to inspect shared content.

## Driving it with verify-app

Preconditions: A seeded share link and test user are needed for attribution/persistence checks.

```sh
verify/cli/verify-app http GET /dakwah
```

Expected: an anonymous request redirects to login. For link tracking use existing Pest coverage and second-view reads of receipts and attribution.

The CLI repeats every GET as a second independent HTTP read and records both response statuses and SHA-256 digests. For mutation and persistence validation, execute the relevant existing Pest feature tests against the isolated PostgreSQL test database and verify the resulting rows independently.

## Gotchas

Share flows depend on idempotent outcomes and identity claims. Repeated GETs may create attribution receipts for valid tracking links; avoid using links with real traffic or rewards.
