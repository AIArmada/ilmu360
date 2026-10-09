<?php

declare(strict_types=1);

namespace App\Support\Share;

use AIArmada\Affiliates\Models\Affiliate;
use AIArmada\CommerceSupport\Support\OwnerScope;
use App\Models\ShareGuestClaim;
use App\Models\User;
use App\Support\ShareTracking\ShareTrackingProfile;

/**
 * Canonical share read scope: primary member profile plus actively
 * claimed guest profiles. Revoked claims (deleted history) are excluded:
 * detached history is never readable.
 *
 * Every share reader (metrics, cohorts, admin analytics, community
 * releases) resolves ownership through this scope so member and global
 * surfaces can never disagree about whose facts they count. Marker plus
 * owner association is the only adoption signal: unmarked commercial
 * profiles are never adopted, and code/name prefixes are never read.
 */
final class ShareProfileScope
{
    /**
     * @return list<string>
     */
    public static function affiliateIdsForUser(User $user): array
    {
        $primary = self::primaryAffiliateId($user);

        $ids = $primary === null ? [] : [$primary];

        return array_values(array_unique([
            ...$ids,
            ...ShareGuestClaim::activeGuestAffiliateIdsForClaimant((string) $user->getKey()),
        ]));
    }

    public static function primaryAffiliateId(User $user): ?string
    {
        $affiliate = Affiliate::query()
            ->withoutGlobalScope(OwnerScope::class)
            ->where('owner_type', $user->getMorphClass())
            ->where('owner_id', $user->getKey())
            ->get()
            ->first(fn (Affiliate $candidate): bool => ShareTrackingProfile::isMarkedMemberFor($candidate, $user));

        return $affiliate instanceof Affiliate ? (string) $affiliate->getKey() : null;
    }

    /**
     * Global staff scope: every marked share profile. Commercial facts
     * never enter share reporting through this scope.
     *
     * @return list<string>
     */
    public static function markedAffiliateIds(): array
    {
        return Affiliate::query()
            ->withoutGlobalScope(OwnerScope::class)
            ->get()
            ->filter(fn (Affiliate $affiliate): bool => ShareTrackingProfile::isMarked($affiliate))
            ->map(fn (Affiliate $affiliate): string => (string) $affiliate->getKey())
            ->values()
            ->all();
    }
}
