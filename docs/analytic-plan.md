# Analytics Plan

## Current Share Analytics Baseline

- Share tracking now tracks provider outbound clicks as `affiliate_touchpoints` rows with `touchpoint_type=outbound_share`.
- Recipient landings store the originating share provider in the canonical attribution `channel` field.
- Dashboard provider breakdowns should use both sources together:
  - outbound clicks answer which provider button a sharer used
  - attributed visits and outcomes answer which provider actually produced downstream impact

## Provider Impact Semantics

- `outbound_shares`: the sharer clicked a provider button such as WhatsApp, Telegram, LINE, Facebook, X, Instagram, TikTok, or Email.
- `visits`: downstream attributed visits created from landings that carried the provider marker.
- `outcomes`: downstream attributed actions created after those provider-marked landings.

## Current Dashboard Coverage

- The Dakwah dashboard exposes provider and channel impact at the user level.
- The per-link Dakwah detail page also exposes provider and channel impact.
- If future work adds sorting or filtering by provider, reuse:
  - `ShareTrackingAnalyticsService::providerBreakdownForUser()`
  - `ShareTrackingAnalyticsService::providerBreakdownForLink()`

## Legacy Cleanup Note

- The old local share tables and legacy share services have been removed.
- ilmu360° share attribution now runs through the affiliate-backed `ShareTrackingService` and `ShareTrackingAnalyticsService` only.

## High-Value Next Analytics Events

- Login and login method attribution.
- Report submission and moderation-triggering user reports.
- Notification open and notification click-through tracking.
- Search execution and search result click tracking.
- Moderation funnel analytics.
- AI extraction lifecycle analytics.
- Profile completion and onboarding progression.
- Donation intent analytics.
- Outbound share activity by provider across surfaces outside share tracking.

## Architecture Direction

- Keep share attribution analytics separate from the future general product analytics module.
- Share attribution should answer: "Who influenced this action?"
- Product analytics should answer: "What are users doing across the product overall?"
- Do not force all product events into share outcomes, because attribution and product telemetry answer different questions.

## Channel Caveat

- Instagram and TikTok deep attribution is only exact when the shared URL itself carries the provider marker.
- Redirect-based outbound clicks are tracked for all providers.
- For non-URL-native channels, some cases may contribute reliable outbound-share counts without equally reliable downstream provider attribution unless the final shared URL preserves the provider marker.
