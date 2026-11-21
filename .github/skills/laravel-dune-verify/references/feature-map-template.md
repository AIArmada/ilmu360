# `<Feature Name>`

`<One-paragraph description of the user-visible behavior, independent of internal PHP classes.>`

## Sub-features

- `<slug>-create` — `<one-line observable behavior>`
- `<slug>-update` — `<one-line observable behavior>`
- `<slug>-auth-guard` — `<unauthorized actor is rejected without mutating state>`

## How to get to it (user POV)

- Navigate via `<Menu / URL path>` (`<visible button or link name>`).
- Keyboard / CLI entry (if applicable): `<shortcut or artisan command>`.

## Driving it with verify-app

Preconditions:

- `verify/cli/verify-app doctor` exits `0` against disposable verification DB and target git revision.
- Seeded fixture `<fixture name>` exists and target test user is authenticated.

- **Open flow.** Run `verify/cli/verify-app open "<path>"`. Expect status `200` and visible heading `<Heading>`.
- **Execute mutation.** Run `verify/cli/verify-app submit ...`. Expect confirmation state `<State>` and zero Boost `last-error` / `browser-logs` entries.
- **Cross-check persistence (second view).** Reload or query via Boost `database-query` read-only check. Expect stored row matches submitted values.
- **Capture proof.** Save screenshot/ARIA snapshot or JSON transcript under `storage/verify-artifacts/<slug>/`.

## Gotchas

- `<Known wrong surface, CSRF/session requirement, or state reset trap>`
- Do not bypass the UI/HTTP boundary by calling internal Actions or injecting DB rows to fabricate a passing state.
- Cleanup restores fixtures while retaining `storage/verify-artifacts/<slug>/`.
