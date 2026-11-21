<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Actions\GuestProfiles;

use AIArmada\Affiliates\Models\Affiliate;
use AIArmada\Affiliates\Models\AffiliateAttribution;
use AIArmada\Affiliates\Models\AffiliateClaim;
use AIArmada\Affiliates\Models\AffiliateConversion;
use AIArmada\Affiliates\Models\AffiliateCredential;
use AIArmada\Affiliates\Models\AffiliateLink;
use AIArmada\Affiliates\Models\AffiliateOwnershipCapability;
use AIArmada\Affiliates\Models\AffiliateTouchpoint;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\CommerceSupport\Support\OwnerScope;
use App\Features\ShareTracking\Api\FinancialObligationReader;
use App\Features\ShareTracking\Api\GuestProfilePruner;
use App\Features\ShareTracking\Api\TrackedShareLinkDetacher;
use App\Features\ShareTracking\Support\ShareTrackingProfile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class PruneAbandonedGuestProfilesAction implements GuestProfilePruner
{
    public function __construct(
        private FinancialObligationReader $financialObligations,
        private TrackedShareLinkDetacher $detachTrackedShareLink,
    ) {}

    /**
     * @return array{scanned: int, pruned: int, skipped: int}
     */
    public function prune(int $retentionDays, int $limit = 1000, bool $dryRun = false): array
    {
        if ($retentionDays < 1) {
            throw new InvalidArgumentException('Guest profile retention must be at least one day.');
        }

        $cutoff = now()->subDays($retentionDays);

        $candidates = Affiliate::query()
            ->withoutGlobalScope(OwnerScope::class)
            ->whereNull('owner_type')
            ->whereNull('owner_id')
            ->where('purpose', ShareTrackingProfile::PURPOSE_SHARE_GUEST)
            ->where('created_at', '<=', $cutoff)
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();

        $pruned = 0;
        $skipped = 0;

        foreach ($candidates as $candidate) {
            $deletable = OwnerContext::withOwner(null, fn (): ?Affiliate => DB::transaction(function () use ($candidate, $cutoff, $dryRun): ?Affiliate {
                $affiliate = Affiliate::query()
                    ->withoutGlobalScope(OwnerScope::class)
                    ->whereKey($candidate->getKey())
                    ->lockForUpdate()
                    ->first();

                if (! $affiliate instanceof Affiliate
                    || ! ShareTrackingProfile::isMarkedGuest($affiliate)
                    || $affiliate->created_at === null
                    || $affiliate->created_at->greaterThan($cutoff)
                    || ! $this->isAbandonedGuestProfile($affiliate)) {
                    return null;
                }

                if (! $dryRun) {
                    $this->deleteAbandonedGuestProfile($affiliate);
                }

                return $affiliate;
            }, 3));

            $deletable instanceof Affiliate ? $pruned++ : $skipped++;
        }

        return [
            'scanned' => $candidates->count(),
            'pruned' => $pruned,
            'skipped' => $skipped,
        ];
    }

    private function isAbandonedGuestProfile(Affiliate $affiliate): bool
    {
        if (AffiliateClaim::query()->where('affiliate_id', $affiliate->getKey())->exists()) {
            return false;
        }

        if (AffiliateConversion::query()
            ->withoutGlobalScope(OwnerScope::class)
            ->where('affiliate_id', $affiliate->getKey())
            ->exists()) {
            return false;
        }

        if ($affiliate->fraudSignals()->exists()) {
            return false;
        }

        return ! $this->financialObligations->hasNonConversionFinancialRecords((string) $affiliate->getKey());
    }

    private function deleteAbandonedGuestProfile(Affiliate $affiliate): void
    {
        AffiliateCredential::query()->where('affiliate_id', $affiliate->getKey())->delete();
        AffiliateOwnershipCapability::query()->where('affiliate_id', $affiliate->getKey())->delete();

        $affiliate->links()
            ->get()
            ->each(function (AffiliateLink $link): void {
                $this->detachTrackedShareLink->detachTrackedShareLink((string) $link->getKey());
                $link->delete();
            });

        $affiliate->attributions()
            ->get()
            ->each(static fn (AffiliateAttribution $attribution): bool => (bool) $attribution->delete());

        AffiliateTouchpoint::query()->where('affiliate_id', $affiliate->getKey())->delete();
        $affiliate->balances()->delete();
        $affiliate->delete();
    }
}
