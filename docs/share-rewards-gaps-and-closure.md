# Share, Rewards and Future Commerce — Gaps and Closure Plan

> Status: current (2026-10-15; G2 and G7 landed — see dated status
> blocks). Scope: the dawah-share attribution system
> (`app/Services/ShareTracking`, `app/Support/Share*`), its rewards
> integration (`app/Support/Rewards`, member portal, rewards API), and the
> planned ticket/merchandise monetisation of banked share provenance.
>
> Method: every gap below was verified against ilmu360 source on
> 2026-10-13, with file/line evidence. Live row volumes were **not**
> verified: the local PostgreSQL database is behind the packages
> (see G6), so counts per outcome remain unmeasured.

## 1. The product idea, restated as two loops

- **Loop A — participation.** Every member activity or interaction
  (register, check in, save, follow, submit) earns points / internal
  rewards, spendable later on tickets and merchandise.
- **Loop B — propagation.** Sharing to another user banks attributed
  provenance now, and the sharer benefits in the future when tickets are
  sold and merchandise is offered — including purchases made by the
  people they brought in.

Package mapping that already fits: `rewards` is the entitlement and
points bank (member as `Participant`/`Purchaser` in Loop A, as `Sharer`
in Loop B); `affiliates` is the attribution outcome ledger;
`vouchers` is the benefit burn instrument. MGM/referrals
is **not** a replacement for either loop — it is the campaign layer
for addressed invites (G2). `aiarmada/referrals` is installed and
integrated in ilmu360; programme records and their economics are
staff-managed in Filament.

## 2. What exists today (verified)

- Every user gets an `Affiliate` profile with `purpose = share_member`
  and `commission_rate = 0`, owned by the user; guests get
  `share_guest` profiles (owner null) plus a guest credential and a
  single-purpose claim capability
  (`app/Support/ShareTracking/ShareTrackingProfile.php`,
  `app/Services/ShareTracking/AffiliatesShareTrackingService.php:1638`).
- Eleven outcomes (`signup`, `event_registration`, `event_checkin`,
  `event_submission`, `event_save`, `event_going`, four follows,
  `saved_search_created`) are recorded as zero-value affiliate
  conversions via `RecordAffiliateOutcome`, frozen behind
  `ShareSourceAttributionReceipt` / `ShareEffectReceipt`
  (`app/Enums/DawahShareOutcomeType.php`, `config/rewards.php` outcomes).
- `ShareAttributionRewardSourceFactReader` turns a frozen receipt +
  conversion into a reward source fact (sharer/actor, verification,
  first-time, fraud, corrections); `EvaluateRewardEntitlementJob`
  issues points/badges/benefits/cash under funded, capped programs.
- Host cash payouts are explicitly disabled: `rewards.payouts.methods`
  is empty and `RewardPayoutExecutor` resolves to
  `NullRewardPayoutExecutor`. Affiliate reward-award settlement remains
  disabled. The host does not create affiliate backing conversions for
  reward payouts.
- Member-facing rewards are complete: portal (enrol, balances,
  statements, appeals, payee, benefit catalog/grants), API controllers,
  gratitude digests, corrections sweep, cohort reporting with 30-day
  maturation (`app/Support/Share/ShareCohortAuthority.php`).
- There is **no** commercial affiliate usage: no
  `RecordAffiliateConversion` call site, no affiliate program, no
  percentage of any paid order anywhere in `app/`.
- The commerce monorepo ships `aiarmada/referrals` with owner-scoped
  programmes, addressed hashed-token invites, single-claim acceptance,
  host-reported qualification, two-sided frozen rewards, reversal,
  and expiry. ilmu360 integrates the global-programme flow through
  Filament, the member dashboard, and verified participation outcomes.

## 3. Gap register (summary)

| ID | Gap | Loop | Severity | Effort |
|---|---|---|---|---|
| G1 | Own activity earns nothing unless share-attributed | A | High | M |
| G2 | Addressed invite / MGM lifecycle | B | Medium | Landed 2026-10-15 |
| G3 | No purchase facts, no points burn at checkout | A+B | High (at commerce launch) | L |
| G4 | Future-benefit terms and attribution window undecided | B | High (before any promise) | S (decision) |
| G5 | Two affiliate identities per person; owner tuple as member link | Both | Medium | L (deferrable) |
| G6 | Schema drift: 41 pending migrations, missing `purpose` column | Ops | High | S |
| G7 | Reward programme publication and economics | Both | Medium | Landed 2026-10-15 |
| G8 | Fraud posture not yet monetary-grade for redeemable points | Both | High (before burn opens) | M |
| G9 | Disabled host cash payouts; per-program enrolment friction | Both | Low–Medium | S (decision) |

## 4. Gaps in detail

### G1 — Loop A incomplete: unattributed activity earns nothing

**Evidence.** Reward evaluation is dispatched from exactly two places:
`ShareSourceAttributionReceiptObserver` (on receipt creation) and
`ClaimGuestShareProfileAction` (claimed history). The source-fact
reader only accepts `share-outcome:{type}:{transition}` keys and
returns `null` when no `AffiliateConversion` exists for the key
(`ShareAttributionRewardSourceFactReader::read`). Unattributed
outcomes freeze a receipt **without** a conversion, so they can never
become facts. A member who registers for an event directly (no share
link) therefore earns zero, while the same action via a share link can
award both actor and sharer.

**Impact.** The stated Loop A promise ("every activity gains points")
is not met; earning depends on arriving through someone's link.

**Closure.**

1. Introduce actor-only activity facts with their own namespace, e.g.
   `activity:{outcome}:{transition}`, reusing the same outcome
   vocabulary and source records (registration, check-in, follow,
   bookmark, saved search, submission).
2. Add an `ActivityRewardSourceFactReader` (or a composite reader keyed
   by namespace in `AppServiceProvider`, keeping the single
   `RewardSourceFactReader` binding) that resolves actor, verification
   and validity directly from the source record — no receipt, no
   affiliate conversion, `isAttributed = false`, `sharerId = null`.
3. Dispatch `EvaluateRewardEntitlementJob` for activity facts from the
   same call sites that already call `recordOutcome` (they already
   fire for attributed and unattributed activity alike).
4. Program rules separate the loops by role: `Participant` components
   fire on activity facts; `Sharer` components keep firing only on
   attributed share facts. Caps (`recipient_program_day`, etc.) bound
   total daily earn across both.

**Acceptance.** A direct (unattributed) registration and check-in earn
the configured participant points; the same actions via a share link
earn participant points once (activity fact) plus sharer points once
(share fact), with no double participant credit; fraud/correction of
the source record reverses activity awards through the existing
corrections sweep.

**Status (2026-10-14).** Loop A backend landed: canonical `activity:`
keys, direct source facts, namespace routing through the single rewards
reader, unattributed-only after-commit dispatch, and activity-source
discovery in the existing corrections sweep are covered by host feature
tests. Programme rates remain operator-defined; the enrolment prompt is
still the separate G9 decision, so the full Phase 1 sequence is not closed.
The G9 dashboard enrolment prompt landed on 2026-10-15 (see G9 status).

### G2 — Addressed invite / MGM lifecycle

**Evidence.** `aiarmada/referrals` supplies the invite/program/reward
state machine, hashed one-time tokens, contact matching, caps,
fulfilment, reversal, and expiry. ilmu360 integrates it with a public
invite landing, member dashboard, Filament admin, the existing share
capture middleware, and the canonical registration/check-in outcome
path.

**Closure.** Landed on 2026-10-15. Staff create and activate global
referral programmes in Filament. A member creates a contact-bound
invite from **Ajak kawan**, then shares its one-time link. The landing
captures attribution; acceptance occurs only after the invitee signs in
and presents a verified email or phone matching the invitation. The
first accepted event registration or staff-verified check-in qualifies
the accepted invite, using the canonical `ShareOutcomeBusinessKey`.
The package fulfils the referrer's `reward_points` and invitee's
single-use, beneficiary-bound `voucher` rewards exactly once. Source
corrections reverse fulfilled rewards and the referral fact reader
marks the source invalid so a sweep cannot restore the award. A reversal
job that arrives while the source is still valid leaves both rewards
intact. The member page presents the configured rewards and effective
invite expiry in plain language. Invite expiry is scheduled; curated signals record invite creation and
acceptance.

**Acceptance.** Host feature coverage exercises both qualifying
outcomes, no double reward on later activity, invite landing and
verified-contact outcomes, member create/list/revoke, signals, source
reversal, and reward-source namespace separation. Broadcast share
flows retain their existing attribution path.

**Residual operator decisions.** Programme terms remain database
records managed in Filament. The referrer points amount must match the
active rewards programme rate. Operators choose voucher type/value,
invite caps, and expiry when publishing the referral programme. No
programme economics are invented in application configuration.

### G3 — No purchase facts and no points burn at checkout

**Evidence.** Paid registrations are recorded as zero-value
`event_registration` outcomes through
`RecordCheckoutShareProvenanceStep`; the reader maps a paid
registration's actor to `Purchaser`, but no purchase-valued fact or
conversion exists. Points can be spent only by redeeming benefit
grants in the member portal/API (`RedeemRewardBenefitAction`); there
is no `ReserveRewardPoints`/`DebitRewardPoints` usage in cart or
checkout, and no product/merchandise catalogue models in `app/Models`.

**Impact.** At ticket/merchandise launch there is no mechanism for
(a) the sharer to benefit from a brought-in user's purchase, or
(b) any member to pay (partly) with points — the two payoffs both
loops promise.

**Closure.**

1. Add purchase outcomes (`ticket_purchase`, `merch_purchase`) emitted
   from paid orders with business keys on the order/registration ID,
   distinct from the existing registration outcome keys — never
   revalue a banked zero-value fact.
2. Extend the source-fact reader: purchase facts carry order value,
   purchaser, and the frozen sharer from the underlying attribution
   (checkout provenance already persists the attribution ID).
3. Choose the sharer benefit per programme (decision D2): points
   (rewards only), benefit/status (rewards catalog), or cash %
   (a real `RecordAffiliateConversion` — the first commercial
   affiliate flow — kept on a separate conversion type and payout
   path from zero-value share facts).
4. Points tender at checkout: reserve points against the cart total
   (cart condition in minor units), debit on payment success, release
   on abandonment/refund reversal; the merchant/programme funding leg
   settles through rewards budget movements.

**Acceptance.** A brought-in user's paid order credits the sharer
exactly once per the published terms; a member can part-pay with
points and a refund restores them; order, award and point ledgers
reconcile per order reference.

### G4 — Future-benefit terms and attribution window undecided

**Evidence.** Attribution mechanics exist (30-day share cookie and
`ttl_days` in `config/dawah-share.php`, registration → check-in
inheritance, 30-day cohort maturation), and the codebase already
states the honesty rule — "fact counts are never cash, points, or paid
value" (`app/Support/Share/ShareMetricDefinitions.php`). What does
not exist is any published rule for how banked provenance converts to
future commerce benefit: for how long after signup the original
sharer earns on a brought-in user's purchases, whether pre-launch
shares are grandfathered, and at what point value.

**Impact.** Any public "share now, benefit later" messaging today is
an unpriced, unbounded promise. Deciding later, retroactively, is how
programmes lose trust.

**Closure (decision record, then code).**

1. ADR for: revenue-share window (recommended: first purchase only,
   or purchases within 12 months of the attributed signup — not
   lifetime), grandfathering lookback for pre-launch facts (explicit
   include/exclude with an effective date), and point value
   (e.g. 100 points = RM1) with the funding owner per programme
   (platform, organiser, or sponsor).
2. Publish these as a new reward program version effective at
   commerce launch; banked facts older than the lookback stay
   recognition/reporting only.
3. Member-facing copy states accrual in points under published terms,
   never implied future cash.

**Acceptance.** The ADR is accepted and the program version is
published before commerce launch; support copy can answer "what do my
2026 shares earn me?" from the versioned terms alone.

### G5 — Two affiliate identities per person; owner tuple as member link

**Evidence.** One person can hold `share_member` (sharing) and
`share_guest` (pre-claim anonymous history) affiliate rows. The member link
is the `owner_type`/`owner_id` tuple — a tenancy boundary — because
`Affiliate` has no member reference; guests squat on `owner = null`,
which everywhere else means "global". Every reporting surface must
remember `ShareProfileScope` filtering, and monetary vs non-monetary
rows share one conversions table (hence `ShareTrackingFinance`).

**Impact.** Manageable today, but it blocks a clean commercial
affiliate launch tomorrow (real promoters would share tables, admin
surfaces and the portal with share profiles) and taxes every new
surface with purpose filtering.

**Closure (staged, deferrable).**

1. Now: no new purpose values; all new queries go through
   `ShareProfileScope`; keep `filament-affiliates` uninstalled until a
   commercial programme exists.
2. When commercial affiliates or a second MGM consumer arrives:
   extract the share profile / guest-claim / receipt domain into the
   referrals layer (G2's package path), leaving `affiliates` with only
   genuine commercial profiles.
   Historical conversions stay frozen and readable through the same
   business keys — this is an identity move, not a history rewrite.
   *Update (2026-10-14): the target package now exists
   (`aiarmada/referrals`), so this step is a migration into a ready
   home rather than a package-creation project; its trigger conditions
   are unchanged.*

**Acceptance.** Until extraction: zero new unscoped affiliate queries
(guarded by an architecture test grepping for `Affiliate::query`
outside the share/reward support namespaces).

### G6 — Schema drift between packages and the local database

**Evidence (2026-10-13, local PostgreSQL).** `php artisan
migrate:status` shows 41 pending migrations, including **all**
`reward_*` tables and the tracked-links tables; `affiliate_affiliates`
exists but has **no `purpose` column**, while current code writes
`purpose` on every profile. Vendor packages are symlinked to
`~/Herd/commerce/packages/*`, and commerce evolves package migrations
in place — so a migration already marked "Ran" silently gains columns
the local schema never receives. Tests use fresh databases, which
masks the drift.

**Impact.** The local environment cannot exercise rewards flows at
all, and the same failure mode in staging/production would break
sharing writes outright (insert with unknown column).

**Closure.**

1. Package migrations are the single schema source. In development,
   a drifted database is rebuilt with `migrate:fresh`; no host sync
   migrations or schema-patch commands are kept.
2. After any commerce package update, run `php artisan migrate`
   (or rebuild) — pending count alone does not detect in-place
   evolution, so a stale development schema is recreated, not patched.

**Acceptance.** Local DB migrated from package migrations alone.

**Status (2026-10-15).** Resolved for development without backward
compatibility: the schema-check command and host sync migration were
removed. Package migrations are the single schema source; a drifted
development database is rebuilt (`migrate:fresh`) rather than patched.
Local PostgreSQL is migrated (`migrate:status` reports 0 pending).

### G7 — Reward programmes and economics live outside the repo

**Evidence.** The rewards and referrals Filament plugins are registered
in `AdminPanelProvider`. Their package actions publish programme
versions, terms, status, funding, and referral reward specifications.
There are no host seeders or environment configs that invent earning
rates or voucher values.

**Impact.** A fresh environment has no reward programme and awards no
points until staff create, publish, fund, and activate a programme in
Filament. This keeps programme liability explicit and reviewable in
its database records.

**Closure.** Programmes are FILAMENT-MANAGED. Staff use the rewards
admin to create a global programme, publish its rules and terms, record
a funding source/allocation where required by the configured rewards,
and activate it. Staff use the referrals admin to create and activate
a global referral programme with its two reward specifications. The
member enrolment prompt selects the first Active global reward
programme deterministically and appears only when its applicable
published version requires enrolment. Point rates, voucher type/value,
caps, expiry, terms, and funding are entered by staff in the database;
none are defaults in code or config.

**Acceptance.** A host lifecycle test drives the same core programme
creation, version publication, readiness, funding, activation, and
explicit-enrolment actions used by Filament, then verifies that a direct
unattributed staff-verified check-in earns the configured test points.
On a fresh environment earning starts only after staff create and
activate a programme in admin.

**Status (2026-10-15).** The user decision makes rewards and referrals
programmes Filament-managed and their economics database-managed. This
supersedes and deletes the slice-2 config publisher and its command,
config, and acceptance test. No production rates, budgets, voucher
values, caps, or expiry periods are seeded in application code/config.
The package readiness checker allows structurally complete programmes
to become Ready. Cash components require confirmed funding before
activation; points-only programmes do not require cash funding under
the installed checker.

### G8 — Fraud posture is not yet monetary-grade

**Evidence.** Guest profiles can accrue attributable credit before
claiming; identity is browser-key based with 30-day cookies;
first-time checks are per conversion type; confirmed/suspect fraud
already propagates from `AffiliateFraudSignal` into source facts, and
corrections reverse awards. All of that is proportionate while points
buy nothing.

**Impact.** The day points redeem against tickets/merchandise (G3),
self-sharing, multi-account and guest-claim farming become direct
monetary attacks on programme funding.

**Closure (before burn opens, in order).**

1. Redeemable points only from verified outcomes: staff-verified
   check-in, accepted registration, moderated-approved submission,
   paid purchase. Soft outcomes (save/follow/going) earn recognition
   or badges, not spendable points.
2. Hold days on point lots from share facts (e.g. 7–14 days) so the
   corrections sweep can claw back before spend; guest-earned value
   unlocks only after claim **and** a verified first participation.
3. Programme caps enforced as money limits: per-recipient daily/
   monthly earn caps, per-actor sharer caps, `exclude_self_credit`
   verified on in published rules; velocity review queue (existing
   fraud signals) must clear before payout requests, as today.
4. Burn-side limits: max points share per order (e.g. ≤ 30% of cart),
   no points-on-points (orders paid with points earn at the cash-paid
   portion only).

**Acceptance.** A scripted self-share farm (same device, fresh
accounts, guest claims) nets zero spendable points in a feature test;
a reversed registration claws back unspent points and opens a
recovery for spent ones.

### G9 — Disabled cash payouts and enrolment friction

**Evidence.** Host cash payouts are disabled through an empty method
catalog and the null payout executor. Points and benefit rewards do not
require a cash payout rail. Members must explicitly enrol per programme
in the portal (`RewardMemberIndex::enroll`) and package presets carry
`require_enrollment`; a member who never visits the portal silently
earns nothing even when their shares convert.

**Impact.** Host cash payouts are unavailable pending a separate
enablement decision, and silent non-earning undermines both loops'
feedback cycle.

**Closure (decisions, then small code).**

1. D5 is settled: keep explicit opt-in with terms acceptance. The
   dashboard now shows a one-tap enrol prompt only for an unenrolled
   member with banked eligible share/activity outcomes and an active
   programme with published terms. The prompt says activity is waiting
   and that enrolling does not award points for past activity.
2. Host cash payouts stay disabled; the rewards package's native manual
   lifecycle is complete and verified. Enabling host manual cash is a
   separate operational decision. If automated cash payouts are later
   justified, implement CHIP locally in the host against
   `RewardPayoutExecutor` and `RewardPayoutMethodCatalog`; do not route
   reward payouts through the affiliates catalog or enable affiliate
   reward-award settlement.

**Acceptance.** Enrolment conversion has a curated server-side
`rewards.program_enrolled` signal after successful enrolment; no member
with eligible outcomes is more than one tap from the explicit opt-in.

**Status (2026-10-15).** The dashboard prompt resolves the first Active
global reward programme deterministically and uses the applicable
published version plus `EnrollRewardMemberAction`, the same path as the
rewards portal. It appears only for an unenrolled member when the rules
require enrolment and the member has eligible activity; it disappears
after enrolment. The curated enrolment signal distinguishes the
`dashboard-prompt` surface while package `acceptedVia` remains
`member-portal`.

## 5. Decisions

| ID | Decision | Recommendation |
|---|---|---|
| D1 | Sharer revenue window & grandfathering (G4) | First purchase or 12 months from attributed signup; pre-launch facts inside an explicit lookback only |
| D2 | Sharer benefit form at commerce launch (G3) | Points first; cash % only for a future commercial promoter programme |
| D3 | Programme economics and funding owner (G4, G7) | Settled 2026-10-15: staff manage rates, terms, and funding in Filament/database records; the referral points amount matches the active rewards programme rate, with no code/config defaults |
| D4 | Invite qualification event (G2) | Settled and implemented 2026-10-15: the first accepted registration or staff-verified check-in, never signup |
| D5 | Enrolment policy (G9) | Explicit opt-in, prompted at first share/registration |
| D6 | Max points share per order (G8) | Operator decision; no redemption percentage is seeded |

## 6. Closure sequence

1. **Phase 0 — programme operations (G6, G7).** Apply package
   migrations with the host migration command. Staff create, publish,
   fund as required, and activate programmes in Filament before any
   earning is available in a fresh environment.
2. **Phase 1 — complete Loop A (G1, G9).** Activity facts,
   participant earning, and the enrolment prompt are landed; members
   enrol explicitly when the published rules require it.
3. **Phase 2 — addressed invites (G2).** Landed 2026-10-15:
   staff-managed global referral programmes, invite landing and verified
   acceptance, first-participation qualification, points/voucher rails,
   member dashboard, expiry, signals, and source reversal.
4. **Phase 3 — commerce payoff (G3, G4, G8).** Decide the future
   purchase-attribution window and redemption limits, then add purchase
   facts, any sharer benefit, points tender, and monetary-grade fraud
   gates before burn opens.
5. **Phase 4 — only if needed (G5).** Commercial affiliate programme
   for promoters and/or migration of the share domain into the existing
   referrals package when a second consumer appears.

## 7. Non-goals

- Replacing `affiliates` or `rewards` for Loops A/B — the mapping in
  §1 stands.
- Multi-level/upline earnings on shares or invites — single-level
  only, tied to real participation and purchases.
- Retroactive cash promises on pre-launch shares beyond whatever the
  G4 decision explicitly grandfathers in points.
