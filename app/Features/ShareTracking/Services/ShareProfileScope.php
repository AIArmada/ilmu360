<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Services;

use AIArmada\Affiliates\Models\Affiliate;
use AIArmada\Affiliates\Models\AffiliateClaim;
use AIArmada\CommerceSupport\Support\OwnerScope;
use App\Models\User;
use App\Shared\ShareTracking\ShareTrackingProfile;
use Illuminate\Database\Eloquent\Builder;

/**
 * Member share scope: primary profile plus actively claimed guest profiles.
 * Revoked claims are excluded, so detached history is never readable.
 *
 * Global readers resolve explicitly marked profiles through ShareTracking's
 * public identifier API. Both paths use the same marker and owner rules:
 * unmarked commercial profiles and code/name prefixes never establish
 * share ownership.
 */
final class ShareProfileScope
{
    /**
     * @return Builder<AffiliateClaim>
     */
    public static function guestClaims(): Builder
    {
        $claimTable = (new AffiliateClaim)->getTable();

        return AffiliateClaim::query()
            ->withoutGlobalScope(OwnerScope::class)
            ->whereNull($claimTable.'.owner_type')
            ->whereNull($claimTable.'.owner_id')
            ->whereHas('affiliate', static function (Builder $query): void {
                $affiliateTable = $query->getModel()->getTable();

                $query->withoutGlobalScope(OwnerScope::class)
                    ->whereNull($affiliateTable.'.owner_type')
                    ->whereNull($affiliateTable.'.owner_id')
                    ->whereJsonContains($affiliateTable.'.metadata->'.ShareTrackingProfile::MARKER_KEY, true)
                    ->where($affiliateTable.'.metadata->'.ShareTrackingProfile::KIND_KEY, ShareTrackingProfile::KIND_GUEST);
            });
    }

    /**
     * @return list<string>
     */
    public static function affiliateIdsForUser(User $user): array
    {
        $primary = self::primaryAffiliateId($user);

        $ids = $primary === null ? [] : [$primary];

        return array_values(array_unique([
            ...$ids,
            ...self::activeGuestAffiliateIdsForClaimant($user),
        ]));
    }

    /**
     * @return list<string>
     */
    private static function activeGuestAffiliateIdsForClaimant(User $user): array
    {
        return self::guestClaims()
            ->where('claimant_type', $user->getMorphClass())
            ->where('claimant_id', (string) $user->getKey())
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->pluck('affiliate_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->unique()
            ->values()
            ->all();
    }

    public static function primaryAffiliateId(User $user): ?string
    {
        $affiliate = Affiliate::query()
            ->withoutGlobalScope(OwnerScope::class)
            ->where('owner_type', $user->getMorphClass())
            ->where('owner_id', $user->getKey())
            ->get()
            ->first(fn (Affiliate $candidate): bool => ShareTrackingProfile::isMarkedMemberForOwner(
                $candidate,
                $user->getMorphClass(),
                (string) $user->getKey(),
            ));

        return $affiliate instanceof Affiliate ? (string) $affiliate->getKey() : null;
    }
}
