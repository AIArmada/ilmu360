# Notification settings simplification

## Goal

Replace the 7-family / 24-trigger / 239-control notification settings screen with a 3+1 model:
Mandatory messages, channels (in-app/email/push), content groups (discovery, my events, submissions),
quiet hours, and push devices. Remove WhatsApp from notifications entirely. No backward compatibility,
legacy options, or leftover UI controls.

## Work

- [x] Remove WhatsApp notification channel, sender, config, destinations, translations, tests.
- [x] Simplify NotificationChannel, NotificationCatalog, ResolvedNotificationPolicy.
- [x] Rewrite NotificationSettingsManager around: global channels, family enabled/cadence, trigger enabled.
- [x] Update preference/consent resolvers for the new `channels` metadata.
- [x] Rewrite the Livewire notifications tab and Blade view.
- [x] Update API docs/examples and tests.
- [x] Run Pint, targeted tests, PHPStan, browser verification.

## Verification

- `AccountSettingsPageTest` — 22 passed.
- `NotificationPreferencesTest` — 4 passed.
- `NotificationCenterApiTest` — 4 passed.
- `NotificationDeliveryFlowTest` — 3 passed.
- `RewardDigestTest` — 18 passed.
- PHPStan full — 0 errors.
- Browser: 3,060px desktop / 4,035px mobile; 25 controls (was 11,588px / 25,729px and 239 controls).
