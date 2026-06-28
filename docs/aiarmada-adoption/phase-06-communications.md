# Phase 6 - Communications And Notifications

State: `Not Started`

## Objective

Replace the app notification engine with `communications` while keeping provider-specific channels and digest scheduling app-owned.

## Target Packages

- `communications`
- `filament-communications`

## Checklist

- [ ] Adopt communication batches, threads, communications, recipients, contents, deliveries, attempts, events, templates, preferences, suppressions, attachments, references, tracking tokens, and inboxes.
- [ ] Replace app notification messages/rules/settings/deliveries/jobs where package coverage exists.
- [ ] Bind app FCM channel through package contracts.
- [ ] Bind app WhatsApp channel through package contracts.
- [ ] Build digest scheduling on top of package preferences and deliveries.
- [ ] Preserve viewer timezone handling with `UserTimezoneResolver` and `UserDateTimeFormatter`.
- [ ] Add Signals tracking only for meaningful communication workflow outcomes.
- [ ] Expose package admin UI where useful.

## Verification

```bash
vendor/bin/pest --parallel --compact --filter=Notification
vendor/bin/pest --parallel --compact --filter=Communication
vendor/bin/phpstan analyse --ansi
```

## Exit Criteria

- Notification persistence and delivery state are package-backed.
- App-specific channels are contract-bound, tested, and retry-safe.
- In-app inbox works from package data.

## Stop And Re-plan Triggers

- Package eligibility cannot represent app quiet-hours/timezone requirements through generic contracts.
- Provider channels require package-specific hardcoding.
- Delivery events cannot be recorded reliably after queue retries.
