# Share affiliates-base package upgrade Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add generic identity + outcome primitives to `aiarmada/affiliates` so the host can delete its marker + 3 sidecar tables without the package learning share vocabulary.

**Architecture:** Generic primitives only (no share strings, no purpose enum, no kind column in package): close the commission boundary in generic recording (overrides + weighted recipients), add dual activity/ownership capabilities with seal-on-success, extend the existing outcome pipeline attribution-free. Host keeps `share_member`/`share_guest` strings and `ShareProfileScope`; referrals stays untouched as MGM consumer via business keys. Fixed-award settlement (`reward_award` via `AffiliateAwardPolicy`) preserved as-is.

**Tech Stack:** PHP 8.5, Laravel, Pest 5 (`./vendor/bin/pest --parallel` from commerce root), PHPStan level 6 per package.

**Spec:** Arena synthesis 2026-10-30 (affiliates-base) + GPT-6.1-sol interrogations ×3 (7 findings, then 5, then 4 — all folded in). Kill-shot: `ClaimGuestShareProfileAction.php:150` (sharer claims history) vs `AcceptReferralInvite.php:48` (invitee accepts). Work executes in the commerce repo — vendor symlink resolves there; plan paths below are commerce-relative.

## Global Constraints

- PHP 8.5+ only; immutable dates (`CarbonImmutable`) where touched.
- Commerce DB rules: `uuid('id')->primary()`, `foreignUuid()` for FK cols, no DB constraints/cascades (`constrained`/`cascadeOnDelete` forbidden), enforce in models/Actions; evolve schema by editing the owning migration in place; no `down()` required.
- Package docs updated in same pass when public behavior/config changes (`packages/affiliates/docs/*.md`).
- No backward-compat shims; breaking changes allowed, remove old path.
- Claimant is always a morph, never `User`; consent wording and collection stay host-side — package takes an explicit caller authorization assertion.
- Never overload `beneficiary` (weighted commission recipient, `RecordAffiliateConversion.php:147-163`); claimant resolution is a separate concern.
- Verification from commerce root: `./vendor/bin/pest --parallel tests/src/Affiliates`, `./vendor/bin/phpstan analyse packages/affiliates/src --level=6`, `rg -n "constrained\(|cascadeOnDelete\(" packages/affiliates/database`.

## Review Focus

- An opaque-purpose affiliate minting commission via generic `record()` — expect 0 commission for non-commercial through overrides, vouchers, programs, and weighted recipients.
- A `reward_award` settlement blocked by the new guard — expect fixed-award flow preserved via `AffiliateAwardPolicy`.
- A guest row readable as global-only (`owner=null`) — expect claim-state-gated scopes, never bare owner-null reads.
- A revoked claim replayed as re-claimable — expect revoke-only, unique active claim, history preserved, seal-on-success atomic with credential revocation.
- A concurrent credential/claim race creating two identities — expect unique verifiers + row locks + seal-on-success atomic with credential revocation + write-time revalidation seam so in-flight activity cannot append to sealed history; real-driver harness defined in-task.

---

### Task 1: Commission boundary in generic recording (package, generic)

**Files:**
- Modify: `packages/affiliates/src/Actions/Conversions/RecordAffiliateConversion.php` (`resolveCommission()`, weighted-recipient loop `:143-179`)
- Modify: `packages/affiliates/src/Actions/Conversions/RecordAffiliateOutcome.php` (`commission_minor` intake `:170`, accounting `:227`)
- Test: `tests/src/Affiliates/` (new Pest file, opaque fixture tags only — never `share_*`)

**Interfaces:**
- Consumes: existing `isCommercialPurpose()`, `scopeWhereCommercialPurpose()`, `AffiliateAwardPolicy`.
- Produces: rule — non-commercial purpose yields 0 commission on every path (explicit `commission` payload, `commission_override` voucher, program rules, calculator, weighted recipients, outcome `commission_minor` override); `reward_award` settlement untouched.

- [ ] **Step 1: Write the failing tests** — opaque-purpose affiliate via generic `record()`: explicit commission payload → 0; voucher override → 0; program rule → 0; mixed-purpose touchpoints (commercial primary, opaque weighted recipient) → recipient portion 0; opaque-purpose outcome with positive `commission_minor` payload → 0 and no balance movement; `reward_award` via award policy still settles.
- [ ] **Step 2: Run test to verify it fails** — Run: `./vendor/bin/pest --parallel tests/src/Affiliates/<new-file>` Expected: FAIL (commission minted).
- [ ] **Step 3: Implement the gate** in `resolveCommission()` + recipient eligibility in the weights loop + outcome `commission_minor` boundary; preserve `reward_award` path.
- [ ] **Step 4: Run test to verify it passes** — same command. Expected: PASS.
- [ ] **Step 5: Existing exclusion suite** — `./vendor/bin/pest --parallel tests/src/Affiliates/Feature/AffiliatePurposeExclusionTest.php`. Expected: PASS (no regression).
- [ ] **Step 6: Commit** — `git commit -m "feat(affiliates): zero commission for non-commercial purposes"`.

### Task 2: Dual capabilities + claim lifecycle (package, generic)

**Files:**
- Modify: `packages/affiliates/config/affiliates.php` (table-map entries for the new storage)
- Modify: owning migrations (add `affiliate_credentials`, `affiliate_claims`, `affiliate_ownership_capabilities` — exact table names locked here; if no owning migration exists for a new table, add per commerce convention and record the filename in this task before writing code)
- Create: `packages/affiliates/src/Models/AffiliateCredential.php`, `AffiliateClaim.php`, `AffiliateOwnershipCapability.php`
- Create: `packages/affiliates/src/Actions/Identity/IssueActivityCredential.php`, `IssueOwnershipCapability.php`, `ClaimAffiliateIdentity.php`, `RevokeAffiliateClaim.php`
- Test: `tests/src/Affiliates/` (lifecycle + real-driver race)

**Interfaces:**
- Consumes: Task 1 opaque-purpose rule (any non-commercial purpose may use credentials/claims; package never names which).
- Produces: `IssueActivityCredential::run(Affiliate, array $options): array{credential, plaintext_secret}` (reusable, expirable; reuse never reissues ownership proof) + `IssueOwnershipCapability::run(Affiliate, array $options): array{capability, plaintext_secret}` (one-time delivery, expirable, consumable) + `ClaimAffiliateIdentity::run(capability_verifier, claimant_morph, authorization_assertion): AffiliateClaim` + `RevokeAffiliateClaim::run(claim): void` (revoke-only, history preserved, atomic seal-on-success revoking activity credentials in the same transaction) + `RevalidateIdentitySeal::run(locked_affiliate, credential): void` (throws sealed-identity failure when any claim row — active or revoked — exists; callers hold the affiliate row lock first, then lock the credential row; on failure the caller issues a fresh identity).

- [ ] **Step 1: Define the race harness first** — the suite forces sqlite `:memory:` (`AffiliatesTestCase.php:81-96`), which cannot run row-lock races. Record the chosen harness (testcase override, env-driven connection, isolated database, independent workers, sync barriers, invocation) in this task before writing any test.
- [ ] **Step 2: Write failing tests** — issue creates unique verifiers with expiry; reuse of activity credential never reissues capability; claim with verifier + caller assertion creates single active claim; double-claim by other claimant rejected; revoke preserves row, blocks re-claim; post-claim activity gets a new identity; in-flight activity racing a committing claim is sealed (write-time revalidation via `RevalidateIdentitySeal`); concurrent claims seal to one identity.
- [ ] **Step 3: Run to verify fail** — `./vendor/bin/pest --parallel <file>`. Expected: FAIL.
- [ ] **Step 4: Implement models + actions + seam** with the locked transaction ownership, affiliate-then-credential lock order, and permanent seal covering revoked claims; `claimed_at`/`revoked_at`/`expires_at` `timestampTz`, no delete path, polymorphic claimant.
- [ ] **Step 5: Run to verify pass** — same command. Expected: PASS.
- [ ] **Step 6: PHPStan package** — `./vendor/bin/phpstan analyse packages/affiliates/src --level=6`. Expected: no new errors.
- [ ] **Step 7: Commit.**

### Task 3: Attribution-free outcomes via existing pipeline (package, generic)

**Files:**
- Modify: `packages/affiliates/src/Actions/Conversions/RecordAffiliateOutcome.php` (accept explicit target affiliate when attribution absent; explicit provenance + outcome-type inputs against existing `conversion_type`)
- Modify: owning conversion migration only if a column is genuinely missing — expected **no-op**: attribution FK already nullable, provenance + `sharer_user_id` + `conversion_type` already exist (verify first, record the outcome here)
- Test: `tests/src/Affiliates/` (attribution-free outcome + replay)

**Interfaces:**
- Consumes: Task 1-2.
- Produces: extended `RecordAffiliateOutcome` with signature `handle(?AffiliateAttribution $attribution, string $conversionType, string $businessKey, array $payload = [], ?Affiliate $affiliate = null, array $provenance = [])` (exact order locked in-task during implementation if this shape proves unworkable — record the final signature here); attribution optional, target affiliate resolved within the owner boundary, link/program references validated without attribution. Provenance contract: keys `subject_key` (required for attribution-free calls), `origin` (required), `sharer_user_id`/`actor_user_id`/`affiliate_link_id`/`affiliate_program_id` (optional); precedence explicit `$provenance` > `$payload` > attribution-derived defaults (existing payload-over-attribution behavior preserved); a provenance-vs-payload conflict on the same key resolves to provenance. Normalize once into the existing pipeline; link/program integrity checks validate the merged values (precedence never bypasses them). Derive owner/code from the owner-scoped affiliate; any link must belong to it, any program within its allowed owner scope. Existing named + positional callers migrated (cutover scope listed in-task); business-key idempotency, frozen-winner replay, fraud/accounting integration, after-commit events preserved; claimant access to preserved history resolved separately from commission recipients (reuse award-policy seam where applicable).

- [ ] **Step 1: Write failing tests** — attribution-free outcome with explicit affiliate + valid provenance records zero-value; missing `subject_key` or `origin` rejected; invalid affiliate rejected; provenance-vs-payload conflict resolves to provenance and persists `actor_user_id`; replay returns stored record, never a second promise; commission recipients unaffected.
- [ ] **Step 2: Run to verify fail.** Expected: FAIL (mandatory attribution).
- [ ] **Step 3: Implement pipeline extension.**
- [ ] **Step 4: Run to verify pass.** Expected: PASS.
- [ ] **Step 5: Commit.**

### Task 4: Package verification + docs close-out

**Files:**
- Modify: `packages/affiliates/docs/01-overview.md`, `04-usage.md`, `05-models.md`, `99-troubleshooting.md`

- [ ] **Step 1: Full affiliate suite** — `./vendor/bin/pest --parallel tests/src/Affiliates`. Expected: PASS.
- [ ] **Step 2: Constraint grep** — `rg -n "constrained\(|cascadeOnDelete\(" packages/affiliates/database`. Expected: empty.
- [ ] **Step 3: Commit docs.**

## Out of scope (separate plan)

Host migration in `ilmu360` (delete `share_guest_credentials/capabilities/claims`, receipts wrapper, `ShareTrackingProfile` marker; rewire `ShareProfileScope`, resolver, analytics; port `Wp3*` race tests). Proposed as follow-up plan after package lands.
