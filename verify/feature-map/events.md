# Events

## Sub-features

Public event search, event detail, occurrence and session pages.

## How to get to it (user POV)

Start at the home page, select **Majlis**, then open a published event.

## Driving it with verify-app

Preconditions: The application server is running with a migrated, isolated test database.

```sh
verify/cli/verify-app doctor
verify/cli/verify-app http GET /majlis
```

Expected: the event-list request returns HTTP 200 and neither view produces HTTP 500. Browse a seeded event to verify schedule and ownership independently.

The CLI repeats every GET as a second independent HTTP read and records both response statuses and SHA-256 digests. For mutation and persistence validation, execute the relevant existing Pest feature tests against the isolated PostgreSQL test database and verify the resulting rows independently.

## Gotchas

Search may be empty on an unseeded test database. A public page check cannot prove event creation, approval, authorization or schedule persistence.
