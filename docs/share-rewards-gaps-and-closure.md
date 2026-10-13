# Share, Rewards and Future Commerce — Gaps and Closure Plan

> Status: current (2026-10-13; updated 2026-10-14 for the referrals
> package landing — see the update block in G2). Scope: the dawah-share attribution system
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
in Loop B); `affiliates` is the attribution outcome ledger and the cash
payout rail; `vouchers` is the benefit burn instrument. MGM/referrals
is **not** a replacement for either loop — it is an optional campaign
layer for addressed invites (G2). As of 2026-10-14 that layer exists
as the standalone `aiarmada/referrals` package in the commerce
monorepo (commit `ef8ca2b5b`); it is not yet installed in ilmu360.

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
- Cash awards settle through a third profile,
  `share_reward_recipient`, via `RecordAffiliateAward`
  (`reward-cash:{award_id}`) and the affiliates payout processors
  (`app/Support/Rewards/AffiliatesRewardPayoutExecutor.php:63`,
  `app/Support/Rewards/RewardCashAwardPolicy.php`).
- Member-facing rewards are complete: portal (enrol, balances,
  statements, appeals, payee, benefit catalog/grants), API controllers,
  gratitude digests, corrections sweep, cohort reporting with 30-day
  maturation (`app/Support/Share/ShareCohortAuthority.php`).
- There is **no** commercial affiliate usage: no
  `RecordAffiliateConversion` call site, no affiliate program, no
  percentage of any paid order anywhere in `app/`.
- The commerce monorepo now ships `aiarmada/referrals`
  (2026-10-14, commerce commit `ef8ca2b5b`): owner-scoped referral
  programmes, addressed invites with hashed tokens, single-claim
  acceptance, host-reported qualification, two-sided frozen rewards
  over manual/voucher/affiliate-cash/reward-points rails, and an
  expiry command (21 Pest tests, PHPStan level 6). It is a
  path-repository sibling like the other AIArmada packages but is
  **not** in ilmu360's `composer.json` yet, and no ilmu360 code
  references it.

## 3. Gap register (summary)

| ID | Gap | Loop | Severity | Effort |
|---|---|---|---|---|
| G1 | Own activity earns nothing unless share-attributed | A | High | M |
| G2 | No addressed invite / MGM lifecycle (package ready 2026-10-14; adoption pending) | B | Medium | S–M (adopt + wire) |
| G3 | No purchase facts, no points burn at checkout | A+B | High (at commerce launch) | L |
| G4 | Future-benefit terms and attribution window undecided | B | High (before any promise) | S (decision) |
| G5 | Three affiliate identities per person; owner tuple as member link | Both | Medium | L (deferrable) |
| G6 | Schema drift: 41 pending migrations, missing `purpose` column | Ops | High | S |
| G7 | Reward programs are operator-created only; economics not in repo | Both | Medium | S–M |
| G8 | Fraud posture not yet monetary-grade for redeemable points | Both | High (before burn opens) | M |
| G9 | Manual-only payout rail; per-program enrolment friction | Both | Low–Medium | S (decision) |

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

### G2 — No addressed invite / MGM lifecycle

**Evidence.** No referral-invite model exists in `app/Models`; share
links are broadcast (tied to the sharer and a subject URL), and signup
attribution is decided by browser cookie at outcome time
(`recordSignup` in `App\Actions\Fortify\CreateNewUser` and Socialite).
The `membership` invitations in use are for inviting members into a
subject/organisation — a different concept.

**Impact.** "Ajak kawan" campaigns cannot be run: no named invitee, no
pending/accepted/expired state, no reminders, no per-member invite
caps, no guarantee an invitee is claimed by exactly one referrer.

**Update (2026-10-14).** The package path was taken first:
`aiarmada/referrals` now exists in commerce
(commit `ef8ca2b5b906c2c9e51c590d33df59877ab5f3f6`) and already
provides most of the closure below — `ReferralProgram` /
`ReferralInvite` / `ReferralReward` with the
`pending → accepted → qualified → rewarded` lifecycle (plus
`expired`/`revoked`), invitee contact hashing with encrypted storage,
hashed one-time tokens (rotatable while pending), single-claim and
self-invite rejection, per-referrer invite and monthly-qualified
caps, exactly-once two-sided rewards, reversal, and owner-scoped
write guards. Closure is therefore now **adoption and wiring**, not
building the layer:

1. Add `aiarmada/referrals` to ilmu360's composer path repositories
   (same pattern as the other AIArmada packages), publish
   `config/referrals.php`, and run its migrations — noting G6: this
   adds three tables to the pending-migration backlog on any
   environment that has not caught up.
2. Invite landing reuses the existing share capture
   (`captureRequest`) so cookie attribution and invite token agree;
   acceptance calls `AcceptReferralInvite` with the presented token
   and the signed-in user's verified contact.
3. Qualification calls `QualifyReferral` from the existing outcome
   call sites on the invitee's **first accepted registration or
   staff-verified check-in**, reusing the canonical
   `ShareOutcomeBusinessKey` for that transition so the referral and
   the share fact share one idempotent identity.
4. Choose the reward rails per programme: referrer points via the
   package's `reward_points` driver requires registering the
   `referral_qualified` outcome in `config/rewards.php` and teaching
   `ShareAttributionRewardSourceFactReader` the
   `referrals:qualified:{inviteId}:{side}` key namespace (today it
   only parses `share-outcome:` keys and would refuse them); the
   invitee welcome reward fits the `voucher` driver. Prefer these
   over `affiliate_cash`: ilmu360 already binds its single
   `AffiliateAwardPolicy` to `RewardCashAwardPolicy`
   (`reward-cash:` keys), so the cash rail would additionally need a
   prefix-dispatching composite policy delegating `referrals:award:`
   keys to the package's `ReferralAffiliateAwardPolicy`.
5. Member surfaces: an "Ajak kawan" entry in the member dashboard
   (create invite, share link, pending/qualified status) next to the
   existing share and rewards surfaces; no package UI ships in v1.

The original host-layer sketch below is retained as the record of
what the package was built from:

**Closure (original sketch, superseded by the package above).** Add a thin host referrals layer (extract to a package
only when a second consumer appears):

1. `ReferralInvite` model: referrer user, invitee phone/email hash,
   token, status (`pending → accepted → qualified → rewarded`, plus
   `expired`/`revoked`), expiry, frozen reward intent per side.
2. Invite landing reuses the existing share capture
   (`captureRequest`) so cookie attribution and invite token agree;
   the token/identity claim is authoritative, the cookie is support.
3. Qualification on the invitee's **first accepted registration or
   staff-verified check-in**, not bare signup, to make fake-account
   farming worthless once points are redeemable.
4. Register a `referral_qualified` outcome; referrer reward issues as
   points via rewards (`Sharer` role), invitee receives a welcome
   benefit/voucher. Cash via affiliates only if a campaign explicitly
   promises withdrawable money.
5. Caps per referrer per month, invitee uniqueness by verified
   phone/email, self-invite exclusion by identity and device.

**Acceptance.** An invitee can be claimed by one referrer only; a
qualified invite pays each side exactly once across retries; expired
and self invites pay nothing; broadcast sharing keeps working
unchanged alongside addressed invites.

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

### G5 — Three affiliate identities per person; owner tuple as member link

**Evidence.** One person can hold `share_member` (sharing),
`share_guest` (pre-claim anonymous history) and
`share_reward_recipient` (cash payout) affiliate rows. The member link
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
   the payout-recipient identity and genuine commercial profiles.
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

1. Run `php artisan migrate` locally; for in-place-evolved tables
   (starting with `affiliate_affiliates.purpose`) sync the schema by
   `migrate:fresh` on disposable environments, or a one-off host
   migration that adds the evolved columns where data must survive.
2. Add a schema smoke check (test or health command) asserting the
   columns/tables the share-rewards stack writes to: affiliates
   `purpose`, conversions `idempotency_key`/`actor_user_id`/
   `sharer_user_id`, and all `reward_*` tables from
   `config/rewards.php`.
3. Deploy checklist: after any commerce package update, run
   `migrate:status` **and** the smoke check — pending count alone
   does not detect in-place evolution.

**Acceptance.** Local DB migrated and smoke check green; the checklist
is referenced from the deployment runbook; verify staging/production
explicitly (not yet done — open verification item).

### G7 — Reward programmes and economics live outside the repo

**Evidence.** No seeder or command creates a reward programme; the
only creation path is the Filament rewards plugin
(`FilamentRewardsPlugin` in `AdminPanelProvider`). Programme rules
(earn per outcome/role, caps, holds, point expiry) therefore exist
only as rows an operator typed, and funding methods are `manual` only
(`config/rewards.php`). Cohort reporting also stays withheld until
`DAWAH_SHARE_REPORTING_START` is declared (`config/dawah-share.php`).

**Impact.** Earn rates are not reviewable or reproducible across
environments; a fresh deploy silently earns nothing (rewards fails
closed with no programme/enrolment); the point liability has no
priced funding commitment behind it.

**Closure.**

1. An idempotent programme publisher (command or seeder) that creates
   the programme family and publishes a version from rules kept in
   `config/share-rewards.php`: components per outcome and role
   (participant vs sharer rates kept deliberately different), caps,
   hold days, point lot expiry, benefit catalog entries.
2. Record the funding source and budget per programme through the
   existing funding actions as part of the same publisher, and set
   `DAWAH_SHARE_REPORTING_START` in every deployed environment.
3. Treat any rate change as a new published version through the same
   path — never a Filament-only edit — so git history is the
   economics history.

**Acceptance.** A fresh environment reaches "member earns points for a
check-in" from repo state alone (`migrate` + publisher), with funding
balances visible in Filament.

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

### G9 — Manual-only payout rail and enrolment friction

**Evidence.** `config/rewards.php` serves only the `manual` payout
method (executed through affiliates processors by staff action).
Members must explicitly enrol per programme in the portal
(`RewardMemberIndex::enroll`) and package presets carry
`require_enrollment`; a member who never visits the portal silently
earns nothing even when their shares convert.

**Impact.** Cash rewards do not scale past staff-processed volumes,
and silent non-earning undermines both loops' feedback cycle.

**Closure (decisions, then small code).**

1. Decide enrolment policy (D5): explicit opt-in with terms
   acceptance (current, legally cleanest) vs auto-enrol on first
   eligible fact with after-the-fact terms notice. If explicit stays,
   surface a one-tap enrol prompt at first share/first registration
   and show "points waiting: enrol to start earning" in the member
   dashboard using banked facts as the teaser (recognition only,
   never promised retroactive points unless terms say so).
2. Keep `manual` until cash volume justifies binding a CHIP send
   processor behind the existing `RewardPayoutProcessorCatalog`;
   points/benefit rewards need no payout rail at all.

**Acceptance.** Enrolment conversion is measurable (enrolled vs
fact-active members); no member with attributed outcomes is more than
one tap from enrolling.

## 5. Open decisions

| ID | Decision | Recommendation |
|---|---|---|
| D1 | Sharer revenue window & grandfathering (G4) | First purchase or 12 months from attributed signup; pre-launch facts inside an explicit lookback only |
| D2 | Sharer benefit form at commerce launch (G3) | Points first; cash % only for a future commercial promoter programme |
| D3 | Point value and funding owner (G4, G7) | Fixed published value (e.g. 100 = RM1); platform-funded pool per programme family |
| D4 | Invite qualification event (G2) | First accepted registration or staff-verified check-in, not signup |
| D5 | Enrolment policy (G9) | Explicit opt-in, prompted at first share/registration |
| D6 | Max points share per order (G8) | Cap (e.g. 30%) to keep cash margin on every order |

## 6. Closure sequence

1. **Phase 0 — make it runnable and reproducible (G6, G7).**
   Migrate/sync schema, smoke check, programme publisher, funding,
   reporting start. Nothing else can be verified end-to-end until
   this is green.
2. **Phase 1 — complete Loop A (G1, G9).** Activity facts and
   participant earning, enrol prompts. This delivers the headline
   promise for existing members with no commerce dependency.
3. **Phase 2 — addressed invites (G2).** ~~Build the referral invite
   lifecycle~~ Adopt `aiarmada/referrals` (landed 2026-10-14) and
   wire it: invite landing, qualification on first real
   participation, points/voucher rails, member dashboard entry.
4. **Phase 3 — commerce payoff (G3, G4, G8).** Decisions D1–D3/D6
   first, then purchase facts, sharer benefit, points tender at
   checkout, monetary-grade fraud gates before burn opens.
5. **Phase 4 — only if needed (G5).** Commercial affiliate programme
   for promoters and/or migration of the share domain into the
   existing referrals package when a second consumer appears.

## 7. Non-goals

- Replacing `affiliates` or `rewards` for Loops A/B — the mapping in
  §1 stands.
- Multi-level/upline earnings on shares or invites — single-level
  only, tied to real participation and purchases.
- Retroactive cash promises on pre-launch shares beyond whatever the
  G4 decision explicitly grandfathers in points.
