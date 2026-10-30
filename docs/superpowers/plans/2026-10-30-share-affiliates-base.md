# Share affiliates-base package upgrade Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give `share_member`/`share_guest` first-class package support in `aiarmada/affiliates` so the host can delete its marker + 3 sidecar tables.

**Architecture:** Extend the affiliates package with generic primitives only (no share vocabulary in package): opaque non-commercial purposes stay host-defined, plus package-owned credential/claim lifecycle, nullable-attribution outcomes with provenance, and beneficiary resolution. Host keeps `share_member`/`share_guest` strings and `ShareProfileScope`; it deletes its 3 sidecar tables in favor of package tables. Referrals stays untouched as MGM consumer via business keys.

**Tech Stack:** PHP 8.5, Laravel, Pest 5 (`./pest --parallel`), PHPStan level 6 per package.

**Spec:** Arena synthesis 2026-10-30 (referrals-base overturned by GPT-6.1-sol high): affiliates-base wins on cardinality (`AffiliateLink->attributions/touchpoints/conversions` 1→N), role direction (sharer claims history vs invitee accepts), no duplication. Kill-shot: `ClaimGuestShareProfileAction.php:150` vs `AcceptReferralInvite.php:48`.

## Global Constraints

- PHP 8.5+ only; immutable dates (`CarbonImmutable`) where touched.
- Commerce DB rules: `uuid('id')->primary()`, `foreignUuid()` for FK cols, no DB constraints/cascades (`constrained`/`cascadeOnDelete` forbidden), enforce in models/Actions; evolve schema by editing the owning migration in place; no `down()` required.
- Package docs updated in same pass when public behavior/config changes (`packages/affiliates/docs/*.md`).
- No backward-compat shims; breaking changes allowed, remove old path.
- Verification per package: `./vendor/bin/pest --parallel`, `./vendor/bin/phpstan analyse packages/affiliates/src --level=6`, `rg -n "constrained\(|cascadeOnDelete\(" packages/affiliates/database`.

## Review Focus

- A commercial query omitting the share exclusion still blends share traffic into payouts/ranks — expect package-level guard, not caller discipline.
- A guest row readable as global-only (`owner=null`) — expect kind-gated scopes, never bare owner-null reads.
- A revoked claim replayed as re-claimable — expect revoke-only, unique active claim, history preserved.
- A purchase benefit minted as commission on a share row — expect explicit eligibility + beneficiary resolution, never purpose flip.
- A concurrent credential/claim race creating two identities — expect unique verifiers + row locks + sealed-identity on conflict.

---

### Task 1: Non-commercial purpose contract (package, generic)

**Files:**
- Modify: `packages/affiliates/src/Models/Affiliate.php` (document opaque-purpose rule; no share enum, no kind column)
- Modify: `packages/affiliates/docs/01-overview.md`, `05-models.md` (non-commercial purpose contract)
- Test: `packages/affiliates/tests/` (new Pest file for opaque purposes)

**Interfaces:**
- Consumes: existing `purpose` column, `OwnerScope`, `HasOwner`.
- Produces: documented rule — package treats `null`/`general` as commercial, any other string as opaque non-commercial host tag; `isCommercialPurpose()` unchanged; no `Share` case, no `Kind` enum (exact names locked here; Task 2-3 consume them).

- [ ] **Step 1: Write the failing test** — commercial (`null`, `general`) commissions/pays; opaque purposes (`share_member`, `share_guest`, any future host tag) never commission, never enter payouts/ranks, without the package naming any of them.
- [ ] **Step 2: Run test to verify it fails** — Run: `./vendor/bin/pest --parallel packages/affiliates/tests/<new-file>` Expected: FAIL (guard missing).
- [ ] **Step 3: Implement the opaque-purpose guard** in package selectors/payout path (no new enum, no new column).
- [ ] **Step 4: Run test to verify it passes** — same command. Expected: PASS.
- [ ] **Step 5: Docs + Pint scoped** — update `01-overview.md`/`05-models.md`; run Pint on changed files only.
- [ ] **Step 6: Commit** — `git add <files>` + `git commit -m "feat(affiliates): opaque non-commercial purpose guard"`.

### Task 2: Credentials + claims (package, generic)

**Files:**
- Modify: owning migrations (add `affiliate_credentials`, `affiliate_claims` — exact table names locked here)
- Create: `packages/affiliates/src/Models/AffiliateCredential.php`, `AffiliateClaim.php`
- Create: `packages/affiliates/src/Actions/Identity/IssueAffiliateCredential.php`, `ClaimAffiliateIdentity.php`, `RevokeAffiliateClaim.php`
- Test: `packages/affiliates/tests/` (issue/claim/revoke lifecycle)

**Interfaces:**
- Consumes: Task 1 opaque-purpose rule (any non-commercial purpose may use credentials/claims; package never names which).
- Produces: `IssueAffiliateCredential::run(Affiliate): AffiliateCredential`, `ClaimAffiliateIdentity::run(credential_verifier, claimant): AffiliateClaim`, `RevokeAffiliateClaim::run(claim): void` (revoke-only, history preserved).

- [ ] **Step 1: Write failing tests** — issue creates unique verifier credential; claim with verifier+consent creates single active claim; double-claim by other user rejected; revoke preserves row, blocks re-claim; race on unique verifier seals to one identity.
- [ ] **Step 2: Run to verify fail** — `./vendor/bin/pest --parallel <file>`. Expected: FAIL.
- [ ] **Step 3: Implement models + actions** with row locks, unique verifiers, `claimed_at`/`revoked_at` `timestampTz`, no delete path.
- [ ] **Step 4: Run to verify pass** — same command. Expected: PASS.
- [ ] **Step 5: PHPStan package** — `./vendor/bin/phpstan analyse packages/affiliates/src --level=6`. Expected: no new errors.
- [ ] **Step 6: Commit.**

### Task 3: Nullable-attribution outcomes + beneficiary (package)

**Files:**
- Modify: `Actions/Conversions/RecordAffiliateConversion.php:70` area (eligibility gate)
- Modify: owning conversion migration (nullable attribution + provenance) in place
- Test: `packages/affiliates/tests/` (zero-value share outcome + future purchase beneficiary)

**Interfaces:**
- Consumes: Task 1-2.
- Produces: `RecordNonCommercialOutcome::run(business_key, affiliate): Conversion` (attribution optional, provenance frozen) + purchase-benefit eligibility check consumed by host later.

- [ ] **Step 1: Write failing tests** — non-commercial outcome without attribution records zero-value with frozen provenance; `RecordAffiliateConversion` still refuses non-commercial-purpose rows as commission; purchase benefit resolves beneficiary from preserved source, never by purpose flip.
- [ ] **Step 2: Run to verify fail.** Expected: FAIL.
- [ ] **Step 3: Implement gate + outcome path.**
- [ ] **Step 4: Run to verify pass.** Expected: PASS.
- [ ] **Step 5: Commit.**

### Task 4: Package verification + docs close-out

**Files:**
- Modify: `packages/affiliates/docs/04-usage.md`, `99-troubleshooting.md`

- [ ] **Step 1: Full package suite** — `./vendor/bin/pest --parallel packages/affiliates`. Expected: PASS.
- [ ] **Step 2: Constraint grep** — `rg -n "constrained\(|cascadeOnDelete\(" packages/affiliates/database`. Expected: empty.
- [ ] **Step 3: Commit docs.**

## Out of scope (separate plan)

Host migration in `ilmu360` (delete `share_guest_credentials/capabilities/claims`, receipts wrapper, `ShareTrackingProfile` marker; rewire `ShareProfileScope`, resolver, analytics; port `Wp3*` race tests). Proposed as follow-up plan after package lands.
