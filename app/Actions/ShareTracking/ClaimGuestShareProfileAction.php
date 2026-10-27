<?php

declare(strict_types=1);

namespace App\Actions\ShareTracking;

use AIArmada\Affiliates\Models\Affiliate;
use AIArmada\Affiliates\Models\AffiliateAttribution;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\CommerceSupport\Support\OwnerScope;
use AIArmada\Rewards\Jobs\EvaluateRewardEntitlementJob;
use App\Actions\Auth\LinkGuestSignupAttributionsAction;
use App\Enums\ShareGuestClaimResult;
use App\Enums\ShareGuestClaimStatus;
use App\Enums\ShareGuestCredentialStatus;
use App\Models\ShareGuestClaim;
use App\Models\ShareGuestClaimCapability;
use App\Models\ShareGuestCredential;
use App\Models\ShareSourceAttributionReceipt;
use App\Models\User;
use App\Services\ShareTracking\AffiliatesShareTrackingService;
use App\Support\ShareTracking\ShareTrackingProfile;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Explicit authenticated guest-share claim.
 *
 * Proves ownership of an anonymous share history with the private
 * single-purpose claim capability issued when guest sharing began, plus
 * the claimant's explicit confirmation. Public share tokens, email
 * addresses, IP similarity, and user-agent matching can never prove
 * ownership and are never read here.
 *
 * Exactly one immutable association per guest profile: the guest affiliate
 * row is never touched (owner stays null), and the future reward
 * recipient is the claimant's distinct member affiliate. A successful
 * claim rewrites no frozen attribution, replays no historical award or
 * notification, and emits no share fact; it only records the association
 * that later evaluation (WP5 policy windows) may consult.
 */
final class ClaimGuestShareProfileAction
{
    use AsAction;

    public function __construct(
        private readonly AffiliatesShareTrackingService $shareTrackingService,
        private readonly LinkGuestSignupAttributionsAction $linkGuestSignupAttributions,
    ) {}

    public function handle(User $claimant, string $presentedCapability, bool $confirmedConsent): ShareGuestClaimResult
    {
        if (! $confirmedConsent) {
            throw new InvalidArgumentException('Linking past anonymous shares needs your explicit confirmation.');
        }

        $capability = trim($presentedCapability);

        if (preg_match('/^[A-Za-z0-9]{32}$/', $capability) !== 1) {
            return ShareGuestClaimResult::ExpiredOrInvalid;
        }

        $verifier = ShareGuestClaimCapability::verifierFor($capability);

        $result = OwnerContext::withOwner(
            $claimant,
            // Deadlock retry replays read-then-insert: the unique claim key
            // and the single-use consume transition keep retries safe.
            fn (): ShareGuestClaimResult => DB::transaction(
                fn (): ShareGuestClaimResult => $this->attemptClaim($claimant, $verifier),
                3
            )
        );

        if ($result === ShareGuestClaimResult::Claimed || $result === ShareGuestClaimResult::AlreadyClaimedByYou) {
            $this->dispatchClaimedHistoryEvaluation($verifier);
        }

        return $result;
    }

    private function attemptClaim(User $claimant, string $verifier): ShareGuestClaimResult
    {
        $claimantId = (string) $claimant->getKey();

        // Fixed lock order inside one transaction: users, then capability,
        // then guest affiliate, then member affiliate, then claim insert.
        $lockedUser = User::query()->whereKey($claimantId)->lockForUpdate()->first();

        if (! $lockedUser instanceof User) {
            return ShareGuestClaimResult::ExpiredOrInvalid;
        }

        $capability = ShareGuestClaimCapability::query()
            ->where('capability_verifier', $verifier)
            ->lockForUpdate()
            ->first();

        if (! $capability instanceof ShareGuestClaimCapability) {
            return ShareGuestClaimResult::ExpiredOrInvalid;
        }

        $guestAffiliateId = (string) $capability->guest_affiliate_id;

        $guestAffiliate = Affiliate::query()
            ->withoutGlobalScope(OwnerScope::class)
            ->whereKey($guestAffiliateId)
            ->lockForUpdate()
            ->first();

        if (! ShareTrackingProfile::isMarkedGuest($guestAffiliate)) {
            return ShareGuestClaimResult::ExpiredOrInvalid;
        }

        $existingClaim = ShareGuestClaim::query()
            ->where('guest_affiliate_id', $guestAffiliateId)
            ->lockForUpdate()
            ->first();

        if ($existingClaim instanceof ShareGuestClaim) {
            // Idempotent same-claimant retry: success without touching any
            // row or replaying any effect. Every other case refuses
            // generically: revoked history (including the claimant's own
            // deleted history) is never stealable or reassignable.
            if ($existingClaim->isActive() && (string) $existingClaim->claimant_user_id === $claimantId) {
                return ShareGuestClaimResult::AlreadyClaimedByYou;
            }

            return ShareGuestClaimResult::ClaimedByOther;
        }

        if (! $capability->isUsable()) {
            return ShareGuestClaimResult::ExpiredOrInvalid;
        }

        try {
            $recipient = $this->shareTrackingService->ensureClaimRecipientAffiliate($claimant);
        } catch (InvalidArgumentException) {
            return ShareGuestClaimResult::RecipientIneligible;
        }

        if ((string) $recipient->getKey() === $guestAffiliateId) {
            throw new RuntimeException('Claim recipient must be distinct from the guest source profile.');
        }

        try {
            $claim = DB::transaction(static fn (): ShareGuestClaim => ShareGuestClaim::query()->create([
                'guest_affiliate_id' => $guestAffiliateId,
                'claimant_user_id' => $claimantId,
                'capability_verifier' => $verifier,
                'status' => ShareGuestClaimStatus::Active,
                'claimed_at' => now(),
            ]), 1);
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            // A racing claimant won the exactly-one association: resolve
            // the committed winner without writing anything further.
            $winner = ShareGuestClaim::query()
                ->where('guest_affiliate_id', $guestAffiliateId)
                ->first();

            if ($winner instanceof ShareGuestClaim
                && $winner->isActive()
                && (string) $winner->claimant_user_id === $claimantId) {
                return ShareGuestClaimResult::AlreadyClaimedByYou;
            }

            return ShareGuestClaimResult::ClaimedByOther;
        }

        // Single-use: the held row lock makes this consume exclusive, so a
        // failure here is a bug, never a second claimant. Throwing rolls
        // the claim insert back with it.
        if (! $capability->consume()) {
            throw new RuntimeException('Claim capability could not be consumed exactly once.');
        }

        // Claimed history is sealed: the reusable share credential that
        // minted it is revoked in the same transaction, so later anonymous
        // activity on the same browser cannot accrue into the claimant's
        // owned history. A subsequent anonymous share presents a revoked
        // credential and receives a genuinely new guest identity with its
        // own fresh credential and capability; the claimed rows keep their
        // original guest source, owner null, and frozen attribution.
        $this->revokeHistoricalGuestCredentials($guestAffiliateId);
        $this->linkGuestSignupAttributions->handle($claim);

        return ShareGuestClaimResult::Claimed;
    }

    private function revokeHistoricalGuestCredentials(string $guestAffiliateId): void
    {
        ShareGuestCredential::query()
            ->where('affiliate_id', $guestAffiliateId)
            ->where('status', ShareGuestCredentialStatus::Active->value)
            ->lockForUpdate()
            ->get()
            ->each(static fn (ShareGuestCredential $credential): bool => $credential->revoke());
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return in_array((string) ($exception->errorInfo[0] ?? $exception->getCode()), ['23000', '23505'], true);
    }

    /**
     * Re-evaluates the claimed guest history under the claimant's identity.
     *
     * Claim rewrites nothing: it only records the association. Each
     * attributed receipt behind the guest affiliate is dispatched for
     * evaluation, where the source reader resolves the sharer through the
     * active claim and program preclaim windows decide eligibility — never
     * a bulk historical replay of awards. Bounded to the 500 oldest
     * receipts; older history stays readable but unevaluated.
     */
    private function dispatchClaimedHistoryEvaluation(string $verifier): void
    {
        $claim = ShareGuestClaim::query()
            ->where('capability_verifier', $verifier)
            ->first();

        if (! $claim instanceof ShareGuestClaim || ! $claim->isActive()) {
            return;
        }

        $attributionIds = AffiliateAttribution::query()
            ->withoutGlobalScope(OwnerScope::class)
            ->where('affiliate_id', (string) $claim->guest_affiliate_id)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        if ($attributionIds === []) {
            return;
        }

        $keys = ShareSourceAttributionReceipt::query()
            ->whereIn('attribution_id', $attributionIds)
            ->orderBy('occurred_at')
            ->limit(500)
            ->pluck('business_key');

        foreach ($keys as $key) {
            EvaluateRewardEntitlementJob::dispatch((string) $key)->afterCommit();
        }
    }
}
