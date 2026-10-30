<?php

declare(strict_types=1);

namespace App\Actions\ShareTracking;

use AIArmada\Affiliates\Actions\Identity\ClaimAffiliateIdentity;
use AIArmada\Affiliates\Exceptions\SealedIdentityException;
use AIArmada\Affiliates\Models\Affiliate;
use AIArmada\Affiliates\Models\AffiliateAttribution;
use AIArmada\Affiliates\Models\AffiliateClaim;
use AIArmada\Affiliates\Models\AffiliateOwnershipCapability;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\CommerceSupport\Support\OwnerScope;
use AIArmada\Rewards\Jobs\EvaluateRewardEntitlementJob;
use App\Actions\Auth\LinkGuestSignupAttributionsAction;
use App\Enums\ShareGuestClaimResult;
use App\Models\ShareSourceAttributionReceipt;
use App\Models\User;
use App\Services\ShareTracking\AffiliatesShareTrackingService;
use App\Support\Share\ShareProfileScope;
use App\Support\ShareTracking\ShareTrackingProfile;
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

        if (preg_match('/^[0-9a-f]{64}$/', $capability) !== 1) {
            return ShareGuestClaimResult::ExpiredOrInvalid;
        }

        $verifier = hash('sha256', $capability);

        $result = OwnerContext::withOwner(
            null,
            // Deadlock retry replays read-then-insert: the unique claim key
            // and the single-use consume transition keep retries safe.
            fn (): ShareGuestClaimResult => DB::transaction(
                fn (): ShareGuestClaimResult => $this->attemptClaim($claimant, $capability, $verifier),
                3
            )
        );

        if ($result === ShareGuestClaimResult::Claimed || $result === ShareGuestClaimResult::AlreadyClaimedByYou) {
            $this->dispatchClaimedHistoryEvaluation($verifier);
        }

        return $result;
    }

    private function attemptClaim(User $claimant, string $presentedCapability, string $verifier): ShareGuestClaimResult
    {
        $claimantId = (string) $claimant->getKey();

        // Fixed lock order inside one transaction: users, then capability,
        // then guest affiliate, then member affiliate, then claim insert.
        $lockedUser = User::query()->whereKey($claimantId)->lockForUpdate()->first();

        if (! $lockedUser instanceof User) {
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

        $guestAffiliate = Affiliate::query()
            ->withoutGlobalScope(OwnerScope::class)
            ->whereKey($guestAffiliateId)
            ->lockForUpdate()
            ->first();

        if (! ShareTrackingProfile::isMarkedGuest($guestAffiliate)) {
            return ShareGuestClaimResult::ExpiredOrInvalid;
        }

        $existingClaim = ShareProfileScope::guestClaims()
            ->where('affiliate_id', $guestAffiliateId)
            ->lockForUpdate()
            ->first();

        if ($existingClaim instanceof AffiliateClaim) {
            // Idempotent same-claimant retry: success without touching any
            // row or replaying any effect. Every other case refuses
            // generically: revoked history (including the claimant's own
            // deleted history) is never stealable or reassignable.
            if ($existingClaim->isActive()
                && $existingClaim->claimant_type === $claimant->getMorphClass()
                && (string) $existingClaim->claimant_id === $claimantId) {
                return ShareGuestClaimResult::AlreadyClaimedByYou;
            }

            return ShareGuestClaimResult::ClaimedByOther;
        }

        if (! $capability->isUsableAt(now())) {
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

        // The package owns the exactly-one association, single-use consume,
        // and atomic seal (revokes the reusable credential in the same
        // transaction). A racing claimant loses with SealedIdentityException:
        // resolve the committed winner without writing anything further.
        try {
            $claim = app(ClaimAffiliateIdentity::class)->run(
                $presentedCapability,
                $claimant->getMorphClass(),
                $claimantId,
                true,
            );
        } catch (SealedIdentityException) {
            $winner = ShareProfileScope::guestClaims()
                ->where('affiliate_id', $guestAffiliateId)
                ->first();

            if ($winner instanceof AffiliateClaim
                && $winner->isActive()
                && $winner->claimant_type === $claimant->getMorphClass()
                && (string) $winner->claimant_id === $claimantId) {
                return ShareGuestClaimResult::AlreadyClaimedByYou;
            }

            return ShareGuestClaimResult::ClaimedByOther;
        }

        $this->linkGuestSignupAttributions->handle($claim);

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
        $claim = ShareProfileScope::guestClaims()
            ->where('capability_verifier_hash', $verifier)
            ->first();

        if (! $claim instanceof AffiliateClaim || ! $claim->isActive()) {
            return;
        }

        $attributionIds = AffiliateAttribution::query()
            ->withoutGlobalScope(OwnerScope::class)
            ->where('affiliate_id', (string) $claim->affiliate_id)
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
