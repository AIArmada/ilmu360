# Dawah impact operations

This runbook covers the local Ilmu360 Dawah impact example and the staff
workflow for operating member share profiles. Share-profile affiliates are
impact-only: they can produce points and community measurements, but never
commercial payouts. Paid ticket commission uses a separate commercial
affiliate link and attribution.

## Measurement start and local examples

`config('dawah-share.reporting.reporting_start')` reads
`DAWAH_SHARE_REPORTING_START`. Without a start date, the admin Dawah analytics
page reports measurement as **not configured** and withholds impact totals.
Set the baseline in the local, gitignored `.env`, then clear cached config:

```dotenv
DAWAH_SHARE_REPORTING_START=2026-10-16
```

```bash
php artisan config:clear
php artisan app:dawah:activate-local-examples
```

The activation command refuses to run unless `APP_ENV=local`. It idempotently
publishes and activates the points family `dawah-points-v1`, activates the
referral program `ilmu360-dawah-community`, and provisions the benefit item
`community-learning-pack` plus local sharer and visitor demo accounts. The v1
amounts are 3 points for the first accepted signup, 5 points for an accepted
event registration, and 15 points for a staff-verified check-in. Daily and
monthly caps are 3 and 20 awards per member; a source/component can credit a
member once. Check-ins require staff verification. Bintang 1–5 milestones
count accepted registrations and staff-verified check-ins at 1, 3, 5, 10,
and 20 contributions and grant points. The benefit costs 500 points. There is
no cash component or public ranking.

Members enroll through the member portal or API and accept the current
published terms. On enrollment or reacceptance, Ilmu360 evaluates eligible
share outcomes from the version's 30-day preclaim window: attributed outcomes
can earn for the sharer, and a member's own activity can earn as a participant.
The accepted version still controls verification, first-only variants,
self-credit exclusion, and source, daily, and monthly caps. Outcomes after
enrollment continue through the normal live evaluation path.

Demo credentials (local only):

| Role | Login | Password |
| --- | --- | --- |
| Sharer | `sharer.demo@ilmu360.test` | `Ilmu360Demo!2026` |
| Visitor | `visitor.demo@ilmu360.test` | `Ilmu360Demo!2026` |

Re-running the command refreshes these local passwords and leaves active
programs active. It never provisions or activates examples in another
environment.

## Staff controls and analytics

Use **Admin → Share Analytics** to review measurement status, outbound
shares, visits, signup and event outcomes, and suppression notes. `Ready`
means the reporting baseline is configured; it does not promise that every
metric has non-zero evidence.

In Filament **Affiliate Links**, deactivate an individual Dawah
`AffiliateLink` to stop Dawah credit through that link. Deactivating its
tracked row in the generic **Links** resource does not stop Dawah credit;
the `/k` flow checks `AffiliateLink.deactivated_at`. Use **Affiliate Links**
for this operation, and preserve the Dawah purpose marker and identity
fields. A tracked `Link` whose subject is an `AffiliateLink` is managed
through the affiliate link resource, so staff should not change its slug or
destination in the generic Links resource.

## Monthly community release

The monthly community release is a manual, immutable report publication. It
requires at least 10 distinct evidenced sharers and 10 distinct participants,
excludes staff, paid purchasers, commercial affiliates, and fraud-tainted
evidence, and publishes only after the month has ended for 7 days. A
super-admin or admin publishes it once per month:

```bash
php artisan rewards:publish-community-release 2026-10 --actor=<staff-user-uuid>
```

Replace the month and actor ID for the period being reviewed. The command
returns the existing immutable release if the month was already published.
It does not move money or release affiliate payouts; v1 uses points and
impact reporting only.

## Scheduled and manual commands

The scheduler runs these operational tasks:

- `share:dispatch-pending-effects` every five minutes, with overlap protection.
- `affiliates:process-maturity` daily, with overlap protection.
- `affiliates:process-payouts` every fifteen minutes, with overlap protection.

The affiliate maturity and payout commands are safe when multi-level
commissions are disabled and there are no payout programs; they process no
eligible work in that state. Rank recalculation, bonus evaluation, and
aggregate rebuilds are intentionally not scheduled.

Other package commands remain manual by design: `affiliates:process-ranks`,
`affiliates:aggregate-performance`, and `affiliates:process-bonuses` are not
part of Dawah activation. Do not run them to repair a Dawah member profile.

## Commercial ticket commission separation

Paid-ticket commission is recorded only from frozen checkout attribution for
a commercial-purpose affiliate with active program membership. It is one
pending conversion per paid registration, based on that order line's unit
price times quantity minus the line discount, excluding tax. Dawah
share-member links earn impact and points only. Keep commercial affiliate
links separate from Dawah share links so a shared Dawah link can never also
pay a ticket commission.

## Reserved payout reversal follow-up

If a registration is refunded while its ticket commission is reserved by an
open payout, the refund completes and the reversal is queued as a pending
`ticket_affiliate_reversal` share-effect receipt. The pending receipt remains
visible until the payout reaches a terminal state. After the payout is
cancelled, failed, or completed through its normal workflow, run the existing
dispatcher if the scheduled pass has not applied the reversal yet:

```bash
php artisan share:dispatch-pending-effects
```

Confirm the receipt is `succeeded` and the commission is reversed before
closing the operator follow-up. Do not edit the conversion or receipt
directly.

## Payout and bonus switches

The package exposes its reward-award payout seam at
`config/affiliates.php` → `payouts.reward_award_settlement.enabled`; the
package default supports consumers that deliberately settle those awards.
Ilmu360 pins this switch to `false`, and also keeps
`config/referrals.php` → `fulfillment.drivers.affiliate_cash.enabled` false.
Da'wah-purpose affiliates therefore cannot receive cash payouts. All four
Ilmu360 affiliate bonus programs (`top_performer`, `recruitment`,
`consistency`, and `growth`) are also pinned to `enabled => false` in
`config/affiliates.php`.
