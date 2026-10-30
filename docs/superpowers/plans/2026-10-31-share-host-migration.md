# Host share migration to package identity primitives Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Cut ilmu360 guest-share storage from 3 host sidecars to the merged package primitives, keeping host vocabulary (`share_member`/`share_guest`, `ShareGuestClaimResult`) and all observable behavior.

**Architecture:** Thin-host over package: `AffiliatesShareTrackingService` issuance/validation calls package issue actions; `ClaimGuestShareProfileAction` keeps its contract (consent check, format check, lock order, recipient check, reward dispatch, attribution backfill) but stores via `ClaimAffiliateIdentity`; read paths query package `AffiliateClaim`; host tables then drop with no backfill (fresh/test DBs per project convention; see Rulings).

**Tech Stack:** PHP 8.5, Laravel, Pest 5 (`vendor/bin/pest --parallel`, TIA via `./pest`), package `aiarmada/affiliates` @ merged `share/affiliates-generic-primitives`.

**Spec:** Arena synthesis + 5 GPT interrogations (package plan, `docs/superpowers/plans/2026-10-30-share-affiliates-base.md`) + host scope map 2026-10-31 (17 app files, 8 test files, 3 migrations).

## Global Constraints

- ilmu360 DB rules: `uuid('id')->primary()`, `foreignUuid`, no DB constraints/cascades, safe idempotent migrations, no `down()` required.
- Package stays generic: no `share_*`, no `User` references, no consent wording in package calls — host asserts consent pre-call and passes `true`.
- No backward compatibility, no legacy, no leftovers: direct cutover, no dual-write/dual-read transition, no adapter layer, no deprecated aliases or shims. Host models/enums/tables are deleted, not deprecated. Old create-table migrations stay as history; exactly one new migration drops the tables. `ShareGuestClaimResult`, cookie/header transport names, and TTL config keys stay — they are live host contracts, not leftovers.
- `ShareGuestClaimResult` enum and controller/API shapes are unchanged (host vocabulary).
- Presentation secrets become package plaintext (64 hex): update format validation; cookie/header transport names unchanged.
- Verification: `vendor/bin/pest --parallel` (or `./pest --parallel --tia` for affected scope), `vendor/bin/phpstan analyse --ansi`, `vendor/bin/pint --dirty`.

## Review Focus

- A claim replay by the same claimant creating a second row — expect idempotent `AlreadyClaimedByYou`, one row.
- A revoked claim re-used by anyone including the original claimant — expect `ClaimedByOther` (sealed), never a new row.
- An in-flight anonymous write racing a committing claim — expect sealed via `RevalidateIdentitySeal`, then a genuinely new guest identity, never accrual into claimed history.
- A revoked/unclaimed guest row read as a referrer — expect `null` referrer; only active claims confer.
- A commercial affiliate landing in share reads — expect exclusion via existing `ShareTrackingProfile::isMarked` gates, unchanged.

## Rulings

- No data backfill when dropping host tables: local DBs rebuild via `migrate:fresh`, tests use fresh DBs, pre-launch has no prod history worth migrating. Cost if wrong: a backfill migration in the follow-up if prod history appears.
- Claimant mapping: `claimant_user_id` (string user id) → `claimant_type = User::morphClass`, `claimant_id = (string) id`. Cost if wrong: referrer resolution breaks; covered by attribution tests.
- `RevalidateIdentitySeal` callers hold affiliate lock, then credential lock, in the same transaction as the write — mirrors host's existing lock order. Cost if wrong: race test proves it.

---

### Task 1: Claim path cutover

**Files:**
- Modify: `app/Actions/ShareTracking/ClaimGuestShareProfileAction.php` (storage calls only; contract, lock order, result mapping unchanged)
- Modify: `app/Actions/Auth/LinkGuestSignupAttributionsAction.php` (accept package `AffiliateClaim`)
- Modify: `app/Actions/Auth/RecordSignupAttributionAction.php:88-89` (package claim lookup)
- Test: `tests/Feature/SignupAttributionTest.php`, `tests/Feature/SignupAttributionSourceResolverTest.php`, `tests/Feature/ShareTracking/Wp3GuestClaimTest.php` (swap host models for package models; same assertions)

**Interfaces:**
- Consumes: package `ClaimAffiliateIdentity::run`, `AffiliateClaim::query`, `SealedIdentityException` (merged, commerce `main`).
- Produces: unchanged `ShareGuestClaimResult` outcomes; `LinkGuestSignupAttributionsAction::handle(AffiliateClaim $claim)`.

- [ ] **Step 1: Write the failing tests** — update the three test files to package models/columns (`affiliate_id`, `claimant_type`/`claimant_id`, `capability_verifier_hash`, status strings); keep every behavior assertion. New coverage: same-claimant replay returns `AlreadyClaimedByYou` with one row (package idempotency path).
- [ ] **Step 2: Run to verify they fail** — `vendor/bin/pest --parallel <files> --compact`. Expected: FAIL (host models still written by production code / column mismatch).
- [ ] **Step 3: Rewire `ClaimGuestShareProfileAction::attemptClaim`** — capability lookup via package `AffiliateOwnershipCapability` verifier hash (locked); guest affiliate locked; `isMarkedGuest` gate unchanged; pre-check existing package claim for `AlreadyClaimedByYou`/`ClaimedByOther` mapping; create via `ClaimAffiliateIdentity::run` with `authorization_assertion: true` (consent already checked); map `SealedIdentityException` from a lost race to winner re-read; keep recipient check, `distinct` guard, reward dispatch, attribution backfill. Keep `capability_verifier` format gate but accept 64-hex package secrets.
- [ ] **Step 4: Rewire link + referrer readers** — `LinkGuestSignupAttributionsAction::handle(AffiliateClaim $claim)`; referrer lookup via package claim `isActive()` + claimant morph.
- [ ] **Step 5: Run tests to verify pass** — same command. Expected: PASS.
- [ ] **Step 6: Commit** — `git commit -m "feat(share): claim guest history through package identity"`.

### Task 2: Issuance + validation cutover

**Files:**
- Modify: `app/Services/ShareTracking/AffiliatesShareTrackingService.php` (`issueGuestAffiliate` ~:1987, `findGuestAffiliateForCredential` ~:2032, `revalidateGuestCredentialSeal` ~:1919, `presentedGuestCredential`/`presentedClaimCapability` transports, `ensureAffiliateForGuest` reuse path)
- Test: `tests/Feature/ShareTracking/Wp1TrustBoundaryTest.php` (package credential rows/statuses)

**Interfaces:**
- Consumes: package `IssueActivityCredential`, `IssueOwnershipCapability`, `RevalidateIdentitySeal`.
- Produces: unchanged share payload shape (link + credential + capability plaintexts for cookies); TTLs still from `config('dawah-share.guest_credential.ttl_days')` / `claim_capability.ttl_days`.

- [ ] **Step 1: Write the failing tests** — Wp1 assertions against `AffiliateCredential` (hashed verifier stored, plaintext never stored, active/revoked transitions, reuse never reissues capability, unknown-verifier + revoked + expired paths).
- [ ] **Step 2: Run to verify they fail.** Expected: FAIL (package issuers not called).
- [ ] **Step 3: Rewire issuance** — one DB transaction creating the guest `Affiliate` + package credential + package capability; TTLs from existing config; cookie/header transport names unchanged; reuse path touches `last_used_at` and never reissues capability.
- [ ] **Step 4: Rewire validation + seal** — credential lookup on package table (verifier hash, active, unexpired, unrevoked, `isMarkedGuest` affiliate); seal check delegates to `RevalidateIdentitySeal` under the existing lock order.
- [ ] **Step 5: Run tests to verify pass.** Expected: PASS.
- [ ] **Step 6: Commit** — `git commit -m "feat(share): issue guest identity through package primitives"`.

### Task 3: Read + lifecycle paths

**Files:**
- Modify: `app/Support/Share/ShareProfileScope.php` (`affiliateIdsForClaimant`), `app/Services/ShareTracking/AffiliatesShareTrackingAnalyticsService.php:417`, `app/Support/Auth/AffiliateSignupAttributionSourceResolver.php` (guest referrer), `app/Support/Share/ShareCohortAuthority.php`, `app/Support/Rewards/ShareAttributionRewardSourceFactReader.php` (claim lookups), `AffiliatesShareTrackingService.php` delete/prune paths (`deleteUserTracking` ~:1145, `revokeGuestClaimsForClaimant` ~:1537, `pruneAbandonedGuestProfiles` ~:1216)
- Test: `tests/Feature/ShareTracking/PruneGuestShareProfilesTest.php`, `tests/Feature/Rewards/Host/RewardEventWiringTest.php`, `tests/Feature/Rewards/Host/RewardHostAdapterTest.php`

**Interfaces:**
- Consumes: package `AffiliateClaim::query` (claimant morph + active), `RevokeAffiliateClaim`, package credential/capability revocation.
- Produces: unchanged `affiliateIdsForClaimant()` list semantics; delete = revoke package rows (never delete history); prune deletes only unclaimed, activity-free guest profiles + package sidecars.

- [ ] **Step 1: Write the failing tests** — scope/analytics/cohort/reader assertions against package claim rows; delete preserves revoked claim rows + deactivates guest links; prune removes only never-claimed, conversion-free guests with their package sidecars.
- [ ] **Step 2: Run to verify they fail.** Expected: FAIL (readers hit dropped host tables after Task 4? No — this task runs before drop; fail on package-row assertions).
- [ ] **Step 3: Rewire readers + delete/prune** to package models via `RevokeAffiliateClaim` and direct credential/capability revocation; guest affiliate rows themselves untouched (owner stays null).
- [ ] **Step 4: Run tests to verify pass.** Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat(share): read and lifecycle paths on package identity"`.

### Task 4: Drop host sidecars + remaining tests

**Files:**
- Delete: `app/Models/ShareGuestClaim.php`, `ShareGuestClaimCapability.php`, `ShareGuestCredential.php`; `app/Enums/ShareGuestClaimStatus.php`, `ShareGuestClaimCapabilityStatus.php`, `ShareGuestCredentialStatus.php` (keep `ShareGuestClaimResult.php`)
- Modify: delete the original `2026_10_07_00000*_create_share_guest_*.php` migration files outright (no replacement migration; fresh `migrate:fresh` rebuilds clean)
- Test: `tests/Feature/ShareTracking/Wp3RealDriverRaceTest.php` (real-driver claim races against package semantics), remaining references in any suite file

**Interfaces:**
- Consumes: Tasks 1-3 (no production code may reference host models after this task).
- Produces: zero `ShareGuestClaim|Credential|Capability` model references outside tests slated for rewrite; tables gone.

- [ ] **Step 1: Port Wp3 race tests** — same race shapes (claim-vs-claim, claim-vs-creation, delete-vs-claim) asserting package outcomes: one claim row, `consumed` capability, sealed losers, revoked-not-readable, fresh identity after seal.
- [ ] **Step 2: Run to verify they fail.** Expected: FAIL (host tables gone from code paths / model classes deleted).
- [ ] **Step 3: Delete models/enums, remove the original create-table migrations, fix remaining references.** Delete (no deprecation): the 3 models + 3 status enums, and the 3 original `2026_10_07_00000*_create_share_guest_*.php` migration files outright — no replacement drop migration (fresh `migrate:fresh` workflow rebuilds clean; no backfill per Rulings). Keep `ShareGuestClaimResult` (live API contract). No adapter, alias, or dual path anywhere.
- [ ] **Step 4: Full share + attribution + rewards sweep** — `vendor/bin/pest --parallel tests/Feature/ShareTracking tests/Feature/SignupAttributionSourceResolverTest.php tests/Feature/SignupAttributionTest.php tests/Feature/Rewards --compact`. Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat(share)!: drop host guest sidecars for package identity"`.

### Task 5: Verification close-out

**Files:** none (verification only; docs only if behavior changed beyond storage)

- [ ] **Step 1: Full host suite** — `./pest --parallel --tia --compact` (or `vendor/bin/pest --parallel --compact` if TIA cache cold). Expected: PASS.
- [ ] **Step 2: Static + style** — `vendor/bin/phpstan analyse --ansi`; `vendor/bin/pint --dirty`. Expected: clean.
- [ ] **Step 3: Record outcome** — no commit (or docs commit if warranted).
