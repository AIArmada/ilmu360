# Registration

## Sub-features

Event checkout, passes, attendee records, refunds and organizer check-in.

## How to get to it (user POV)

Open a public event and choose its registration or checkout flow; organizers use the event participants and check-in workspace.

## Driving it with verify-app

Preconditions: A published event with an active admission configuration and test account are needed for mutations.

```sh
verify/cli/verify-app http GET /majlis
```

Expected: HTTP 200. Run the existing registration and refund Pest suites to validate paid/free admission, idempotency, and persisted attendee/refund records.

The CLI repeats every GET as a second independent HTTP read and records both response statuses and SHA-256 digests. For mutation and persistence validation, execute the relevant existing Pest feature tests against the isolated PostgreSQL test database and verify the resulting rows independently.

## Gotchas

Checkout redirects through external payment services; never use real payments as a smoke test. Anonymous GET requests cannot verify an authenticated checkout.
