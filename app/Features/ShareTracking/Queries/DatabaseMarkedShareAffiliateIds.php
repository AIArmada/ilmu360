<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Queries;

use AIArmada\Affiliates\Models\Affiliate;
use AIArmada\CommerceSupport\Support\OwnerScope;
use App\Features\ShareTracking\Api\MarkedShareAffiliateIds;
use App\Shared\ShareTracking\ShareTrackingProfile;

final readonly class DatabaseMarkedShareAffiliateIds implements MarkedShareAffiliateIds
{
    /** @return list<string> */
    public function allMarkedAffiliateIds(): array
    {
        return Affiliate::query()
            ->withoutGlobalScope(OwnerScope::class)
            ->get()
            ->filter(static fn (Affiliate $affiliate): bool => ShareTrackingProfile::isMarked($affiliate))
            ->map(static fn (Affiliate $affiliate): string => (string) $affiliate->getKey())
            ->values()
            ->all();
    }
}
