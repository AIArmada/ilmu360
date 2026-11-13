<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Actions\GuestClaims;

use AIArmada\Affiliates\Actions\Identity\ClaimAffiliateIdentity;
use AIArmada\Affiliates\Exceptions\SealedIdentityException;
use AIArmada\Affiliates\Models\AffiliateAttribution;
use AIArmada\Affiliates\Models\AffiliateOwnershipCapability;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\CommerceSupport\Support\OwnerScope;
use AIArmada\Rewards\Jobs\EvaluateRewardEntitlementJob;
use App\Features\Identity\Api\SignupAttributionWorkflow;
use App\Features\ShareTracking\Api\GuestShareClaims;
use App\Features\ShareTracking\Support\ShareTrackingModel;
use App\Shared\ShareTracking\ClaimRecipients;
use App\Shared\ShareTracking\GuestClaims;
use App\Shared\ShareTracking\GuestClaimSnapshot;
use App\Shared\ShareTracking\ShareGuestClaimResult;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Owns the guest-share claim orchestration.
 *
 * Exactly one immutable association per guest profile: the guest affiliate
 * row is never touched (owner stays null), and the future reward
 * recipient is the claimant's distinct member affiliate. A successful
 * claim rewrites no frozen attribution, replays no historical award or
 * notification, and emits no share fact; it only records the association
 * that later evaluation (WP5 policy windows) may consult.
 */
final readonly class ClaimGuestProfileAction implements GuestShareClaims
{
    public function __construct(
        private GuestClaims $guestClaims,
        private ClaimRecipients $claimRecipients,
        private SignupAttributionWorkflow $signupAttribution,
    ) {}

    public function claim(
        string $claimantId,
        string $claimantMorphType,
        string $presentedCapability,
        bool $confirmedConsent,
    ): ShareGuestClaimResult {
        if (! $confirmedConsent) {
            throw new InvalidArgumentException('Linking past anonymous shares needs your explicit confirmation.');
        }

        $capability = trim($presentedCapability);

        if (preg_match('/^[0-9a-f]{64}$/', $capability) !== 1) {
            return ShareGuestClaimResult::ExpiredOrInvalid;
        }

        $verifier = hash('sha256', $capability);

        $result = OwnerContext::withOwner(
            null,
            // Deadlock retry replays read-then-insert: the unique claim key
            // and the single-use consume transition keep retries safe.
            fn (): ShareGuestClaimResult => DB::transaction(
                fn (): ShareGuestClaimResult => $this->attemptClaim(
                    $claimantId,
                    $claimantMorphType,
                    $capability,
                    $verifier,
                ),
                3
            )
        );

        if ($result === ShareGuestClaimResult::Claimed || $result === ShareGuestClaimResult::AlreadyClaimedByYou) {
            $this->dispatchClaimedHistoryEvaluation($verifier);
        }

        return $result;
    }

    private function attemptClaim(
        string $claimantId,
        string $claimantMorphType,
        string $presentedCapability,
        string $verifier,
    ): ShareGuestClaimResult {
        // Fixed lock order inside one transaction: users, then capability,
        // then guest affiliate, then member affiliate, then claim insert.
        if (! $this->guestClaims->lockClaimant($claimantId)) {
            return ShareGuestClaimResult::ExpiredOrInvalid;
        }

        $capability = AffiliateOwnershipCapability::query()
            ->where('verifier_hash', $verifier)
            ->lockForUpdate()
            ->first();

        if (! $capability instanceof AffiliateOwnershipCapability) {
            return ShareGuestClaimResult::ExpiredOrInvalid;
        }

        $guestAffiliateId = (string) $capability->affiliate_id;

        if (! $this->guestClaims->lockMarkedGuestAffiliate($guestAffiliateId)) {
            return ShareGuestClaimResult::ExpiredOrInvalid;
        }

        $existingClaim = $this->guestClaims->claimForAffiliate($guestAffiliateId, true);

        if ($existingClaim instanceof GuestClaimSnapshot) {
            // Idempotent same-claimant retry: success without touching any
            // row or replaying any effect. Every other case refuses
            // generically: revoked history (including the claimant's own
            // deleted history) is never stealable or reassignable.
            if ($existingClaim->active
                && $existingClaim->claimantType === $claimantMorphType
                && $existingClaim->claimantId === $claimantId) {
                return ShareGuestClaimResult::AlreadyClaimedByYou;
            }

            return ShareGuestClaimResult::ClaimedByOther;
        }

        if (! $capability->isUsableAt(now())) {
            return ShareGuestClaimResult::ExpiredOrInvalid;
        }

        try {
            $recipient = $this->claimRecipients->ensureForClaimant($claimantId);
        } catch (InvalidArgumentException) {
            return ShareGuestClaimResult::RecipientIneligible;
        }

        if ($recipient->affiliateId === $guestAffiliateId) {
            throw new RuntimeException('Claim recipient must be distinct from the guest source profile.');
        }

        // The package owns the exactly-one association, single-use consume,
        // and atomic seal (revokes the reusable credential in the same
        // transaction). A racing claimant loses with SealedIdentityException:
        // resolve the committed winner without writing anything further.
        try {
            $claim = app(ClaimAffiliateIdentity::class)->run(
                $presentedCapability,
                $claimantMorphType,
                $claimantId,
                true,
            );
        } catch (SealedIdentityException) {
            $winner = $this->guestClaims->claimForAffiliate($guestAffiliateId);

            if ($winner instanceof GuestClaimSnapshot
                && $winner->active
                && $winner->claimantType === $claimantMorphType
                && $winner->claimantId === $claimantId) {
                return ShareGuestClaimResult::AlreadyClaimedByYou;
            }

            return ShareGuestClaimResult::ClaimedByOther;
        }

        $this->signupAttribution->linkGuestReferralsForClaim(
            (string) $claim->getKey(),
            (string) $claim->affiliate_id,
            (string) $claim->claimant_type,
            (string) $claim->claimant_id,
        );

        return ShareGuestClaimResult::Claimed;
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
        $claim = $this->guestClaims->findClaimByVerifier($verifier);

        if (! $claim instanceof GuestClaimSnapshot || ! $claim->active) {
            return;
        }

        $attributionIds = AffiliateAttribution::query()
            ->withoutGlobalScope(OwnerScope::class)
            ->where('affiliate_id', $claim->affiliateId)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        if ($attributionIds === []) {
            return;
        }

        $keys = ShareTrackingModel::sourceReceiptQuery()
            ->whereIn('attribution_id', $attributionIds)
            ->orderBy('occurred_at')
            ->limit(500)
            ->pluck('business_key');

        foreach ($keys as $key) {
            EvaluateRewardEntitlementJob::dispatch((string) $key)->afterCommit();
        }
    }
}
