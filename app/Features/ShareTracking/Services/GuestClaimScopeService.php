<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Services;

use AIArmada\Affiliates\Models\AffiliateClaim;
use App\Features\ShareTracking\Api\Orm\GuestClaimScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Boundary adapter over the member/guest share-profile scope.
 */
final readonly class GuestClaimScopeService implements GuestClaimScope
{
    public function guestClaims(): Builder
    {
        return ShareProfileScope::guestClaims();
    }

    public function activeClaimantIdForAffiliate(string $affiliateId): ?string
    {
        if ($affiliateId === '') {
            return null;
        }

        $claim = ShareProfileScope::guestClaims()
            ->where('affiliate_id', $affiliateId)
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->first();

        if (! $claim instanceof AffiliateClaim || ! $claim->isActive()) {
            return null;
        }

        if ($claim->claimant_type !== (new User)->getMorphClass()) {
            return null;
        }

        $claimantId = $claim->getAttribute('claimant_id');

        return is_string($claimantId) && preg_match('/^[0-9a-fA-F-]{32,45}$/', $claimantId) === 1
            ? $claimantId
            : null;
    }
}
