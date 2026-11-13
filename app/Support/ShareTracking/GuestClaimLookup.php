<?php

declare(strict_types=1);

namespace App\Support\ShareTracking;

use AIArmada\Affiliates\Models\Affiliate;
use AIArmada\Affiliates\Models\AffiliateClaim;
use AIArmada\CommerceSupport\Support\OwnerScope;
use App\Models\User;
use App\Shared\Auth\UserMorphType;
use App\Shared\ShareTracking\GuestClaims;
use App\Shared\ShareTracking\GuestClaimSnapshot;
use App\Support\Share\ShareProfileScope;
use DateTimeImmutable;
use DateTimeInterface;

final readonly class GuestClaimLookup implements GuestClaims
{
    public function __construct(private UserMorphType $userMorphType) {}

    public function activeClaimantId(string $affiliateId, bool $lockAffiliate = false): ?string
    {
        if ($lockAffiliate) {
            Affiliate::query()->withoutGlobalScope(OwnerScope::class)
                ->whereKey($affiliateId)->lockForUpdate()->first();
        }

        $claim = ShareProfileScope::guestClaims()
            ->where('affiliate_id', $affiliateId)
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->first();

        if (! $claim instanceof AffiliateClaim
            || $claim->claimant_type !== $this->userMorphType->morphClass()
            || ! User::query()->whereKey($claim->claimant_id)->exists()) {
            return null;
        }

        return (string) $claim->claimant_id;
    }

    public function lockClaim(string $claimId): ?GuestClaimSnapshot
    {
        $claim = ShareProfileScope::guestClaims()
            ->whereKey($claimId)
            ->lockForUpdate()
            ->first();

        return $claim instanceof AffiliateClaim ? $this->snapshot($claim) : null;
    }

    public function lockClaimant(string $claimantId): bool
    {
        return User::query()->whereKey($claimantId)->lockForUpdate()->first() instanceof User;
    }

    public function lockMarkedGuestAffiliate(string $affiliateId): bool
    {
        $affiliate = Affiliate::query()
            ->withoutGlobalScope(OwnerScope::class)
            ->whereKey($affiliateId)
            ->lockForUpdate()
            ->first();

        return ShareTrackingProfile::isMarkedGuest($affiliate);
    }

    public function claimForAffiliate(string $affiliateId, bool $lock = false): ?GuestClaimSnapshot
    {
        $query = ShareProfileScope::guestClaims()
            ->where('affiliate_id', $affiliateId);

        if ($lock) {
            $query->lockForUpdate();
        }

        $claim = $query->first();

        return $claim instanceof AffiliateClaim ? $this->snapshot($claim) : null;
    }

    public function findClaimByVerifier(string $verifier): ?GuestClaimSnapshot
    {
        $claim = ShareProfileScope::guestClaims()
            ->where('capability_verifier_hash', $verifier)
            ->first();

        return $claim instanceof AffiliateClaim ? $this->snapshot($claim) : null;
    }

    private function snapshot(AffiliateClaim $claim): GuestClaimSnapshot
    {
        $createdAt = $claim->getAttribute('created_at');

        return new GuestClaimSnapshot(
            claimId: (string) $claim->getKey(),
            affiliateId: (string) $claim->affiliate_id,
            claimantType: (string) $claim->claimant_type,
            claimantId: (string) $claim->claimant_id,
            active: $claim->isActive(),
            createdAt: $createdAt instanceof DateTimeInterface
                ? DateTimeImmutable::createFromInterface($createdAt)
                : new DateTimeImmutable,
        );
    }
}
