# Phase 5 - Core Domain Rewrite

State: `Not Started`

## Objective

Replace event, venue, series, registration, check-in, submission, reference, taxonomy, engagement, and moderation persistence with package-owned domains.

The target event domain must support more than the current free-only app model: free walk-ins, optional RSVP, free ticketed events, paid tickets, mixed free/paid ticket types, passes, capacity, waitlists, seating, and check-in.

## Target Packages

- `events`
- `filament-events`
- `engagement`
- `filament-engagement`
- `moderation`
- `references`
- `filament-feedback` / `feedback` only if chosen for reports

## Checklist

- [ ] Make `events` the canonical event domain package.
- [ ] Map app event concepts to package event attributes, audiences, time expressions, roles, organizations, taxonomies, classifications, and involvements.
- [ ] Replace app venues/spaces/series/occurrences with package primitives.
- [ ] Replace registrations, RSVP, attendance, walk-ins, and check-ins.
- [ ] Model event participation modes explicitly: free walk-in, free optional RSVP, free ticketed/pass, paid ticketed, mixed free/paid ticket types, reserved seating, and capacity/waitlist.
- [ ] Ensure admin event creation can choose pricing mode, registration/ticket requirements, walk-in allowance, capacity, ticket types, pass issuance, and check-in behavior.
- [ ] Ensure public/API/MCP contracts do not assume events are free-only.
- [ ] Replace event submissions and approval logs with package submission primitives.
- [ ] Replace tags with package taxonomies/terms/classifications.
- [ ] Replace references with `references` and package event references.
- [ ] Replace follows/saves/going/reminders/shares with `engagement`.
- [ ] Replace blocks/moderation actions with `moderation`.
- [ ] Decide whether `feedback` owns reports or reports remain app-owned.
- [ ] Keep donation channels, public pages, MCP tools, and Islamic presentation in app.
- [ ] Delete superseded app models/actions/migrations/tests after replacement is verified.

## Verification

```bash
vendor/bin/pest --parallel --compact --filter=Event
vendor/bin/pest --parallel --compact --filter=Registration
vendor/bin/pest --parallel --compact --filter=Ticket
vendor/bin/pest --parallel --compact --filter=WalkIn
vendor/bin/pest --parallel --compact --filter=Engagement
vendor/bin/pest --parallel --compact --filter=Reference
vendor/bin/pest --parallel --compact --filter=Moderation
vendor/bin/phpstan analyse --ansi
```

## Exit Criteria

- Package models own core event/reference/engagement/moderation data.
- Event workflows cover free walk-ins, free registration/tickets, paid tickets, mixed ticket types, passes, capacity, and check-in.
- Public and admin workflows pass against package-backed models.
- No legacy app domain models remain for replaced areas.

## Stop And Re-plan Triggers

- Package event model needs ilmu360-only fields instead of generic attributes/seams.
- The app begins rebuilding a free-only event abstraction instead of exposing package participation/ticketing primitives.
- Public search/API/MCP contracts cannot be rebuilt without compatibility shims.
- Donation or Islamic presentation logic starts leaking into packages.
