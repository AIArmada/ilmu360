# Persons

## Sub-features

Speaker directory, public biographies, institution affiliation and profile management.

## How to get to it (user POV)

From public navigation select **Penceramah**.

## Driving it with verify-app

Preconditions: The public speaker directory is accessible and a test database is initialized.

```sh
verify/cli/verify-app http GET /penceramah
```

Expected: HTTP 200. A seeded speaker slug can be used to inspect the detail page.

The CLI repeats every GET as a second independent HTTP read and records both response statuses and SHA-256 digests. For mutation and persistence validation, execute the relevant existing Pest feature tests against the isolated PostgreSQL test database and verify the resulting rows independently.

## Gotchas

Public browsing is not proof of profile edits, credentials or linked institution records; use persistence-focused Pest assertions.
