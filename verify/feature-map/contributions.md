# Contributions

## Sub-features

Suggest new institutions, speakers and references, propose changes, moderator review.

## How to get to it (user POV)

Sign in, select **Sumbangan**, and choose the relevant new-entry or correction form.

## Driving it with verify-app

Preconditions: An authenticated test account is required for editor workflows.

```sh
verify/cli/verify-app http GET /sumbangan
```

Expected: an unauthenticated request redirects to login rather than returning a server error. Use authenticated Pest cases for staging and approval persistence.

The CLI repeats every GET as a second independent HTTP read and records both response statuses and SHA-256 digests. For mutation and persistence validation, execute the relevant existing Pest feature tests against the isolated PostgreSQL test database and verify the resulting rows independently.

## Gotchas

Unprivileged contributions must stage changes rather than mutate live entities; media staging and role permissions require dedicated regression tests.
