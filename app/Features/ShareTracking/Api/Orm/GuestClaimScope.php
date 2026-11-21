<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Api\Orm;

use AIArmada\Affiliates\Models\AffiliateClaim;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read scope for guest share profiles that were actively claimed.
 *
 * Revoked claims are excluded so detached history is never readable.
 *
 * @internal Explicit ORM integration port for framework callbacks, hydrated page state and builder composition.
 *
 * Consumers: Providers/AppServiceProvider.php, Rewards/Queries, ShareTracking/Services
 */
interface GuestClaimScope
{
    /**
     * @return Builder<AffiliateClaim>
     */
    public function guestClaims(): Builder;

    /**
     * The active user claimant for a guest share profile, or null when the
     * affiliate is unclaimed, revoked, or claimed by another subject type.
     */
    public function activeClaimantIdForAffiliate(string $affiliateId): ?string;
}
