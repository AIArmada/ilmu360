# Community releases ownership map

## Ownership

- `App\Features\Community` owns `community_releases`, its immutable monthly release model, publication lifecycle, publication eligibility, suppression policy, latest-release query, policy, CLI entrypoint, Filament resource, and public API route.
- The only application writer for `community_releases` is `Community\Actions\PublishCommunityReleaseAction`. Publication is immutable and idempotent by fixed month key.
- ShareTracking keeps the affiliate source analytics, metric definitions, attribution/fraud evidence, and `share_cohort_projections`. That projection is a Signals drain/idempotency store and does not represent Community release evidence.
- Community consumes a narrow ShareTracking API that returns immutable aggregate evidence (overall counts and per-channel/per-outcome counts with distinct sharer/participant counts). It does not receive individual records, Eloquent models, or query builders. Community owns release thresholds, delay, cell suppression, and persistence policy.
- The existing shared migration continues to create `community_releases` beside `reward_digest_slots` and `share_cohort_projections`; that migration remains historical schema creation and Community owns the table through its feature manifest.

## Current behavior to preserve

- Publication accepts a strict `YYYY-MM` month and requires the month end plus seven days to have elapsed.
- Publisher must be a real admin or super-admin user. Filament visibility and execution checks remain, and the CLI continues requiring an explicit `--actor`.
- Evidence includes marked share profiles, valid share outcome kinds/statuses, existing sharer and participant users, no confirmed fraud on the conversion or either user's related share funnel, no staff participant, and no paid-registration purchaser. Free registrations remain eligible.
- Overall counts and all cells use one qualified/proven evidence definition. The release requires at least 10 distinct sharers and 10 distinct participants. Each cell in each cut must independently meet both thresholds; a sparse cell suppresses that entire cut. Empty channels use the `unknown` cell. Totals never include excluded or complementary evidence.
- A month key can be published once. Concurrent publishers converge on the winning row. Existing releases reject updates and deletes.
- The public route remains anonymous at `GET /api/v1/community/release`, with route name `community.release.show` and unchanged payload. It returns the latest period, ordered by period end then publication time, or an honest `insufficient-data` state.
- The existing `rewards:publish-community-release` command signature stays as the current operator contract; its implementation moves under Community. The admin Filament resource remains in the admin panel and generic admin API/MCP resource registry.
- The release resource has no public per-month/history endpoint. Late publication of an older month remains supported; latest-period selection prevents it displacing a newer period.

## Cutover and removal

- Moved the model, publisher, reader, policy, CLI command, Filament resource/pages/schema/table, and public API entrypoint into Community.
- Moved focused release tests into `tests/Feature/Community`. Rewards delivery tests now cover digest delivery and the active public endpoint; Community-specific release coverage is in Community tests.
- Removed the root action/model/reader/policy/command/resource/controller method/route registration and the old ShareTracking metric/identity paths. No aliases, wrappers, or old-path readers remain.
- Kept the shared migration's other two table definitions unchanged. `community_releases` is declared only in Community's ownership manifest.

## Verification

- Community behavior passed 16 tests / 131 assertions; Rewards delivery passed 6 / 73; ShareTracking hold tests passed 10 / 34.
- Uncached route method/URI/name/middleware/order matched the pre-cutover snapshot. Route cache preserved the Community route; config cache/show/clear passed.
- The operator command is registered once, Filament/admin API/MCP resource coverage passed, and the architecture scanner reports zero violations.
