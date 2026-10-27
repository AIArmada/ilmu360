<?php

declare(strict_types=1);

namespace App\Support\Auth;

use AIArmada\Affiliates\Contracts\AffiliateLookup;
use AIArmada\Affiliates\Models\Affiliate;
use AIArmada\Affiliates\Models\AffiliateAttribution;
use AIArmada\Affiliates\Models\AffiliateLink;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\CommerceSupport\Support\OwnerScope;
use App\Contracts\SignupAttributionSourceResolver;
use App\Enums\ShareGuestClaimStatus;
use App\Enums\SignupAttributionSourceType;
use App\Models\ShareBrowserSelection;
use App\Models\ShareGuestClaim;
use App\Models\User;
use App\Services\ShareTrackingService;
use App\Support\ShareTracking\ShareTrackingProfile;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use InvalidArgumentException;

final readonly class AffiliateSignupAttributionSourceResolver implements SignupAttributionSourceResolver
{
    public function __construct(
        private ShareTrackingService $shareTrackingService,
        private AffiliateLookup $affiliateLookup,
    ) {}

    public function resolve(?Request $request): ?SignupAttributionSource
    {
        if (! $request instanceof Request) {
            return null;
        }

        $share = $this->resolveShare($request);
        $commercial = $this->resolveCommercialAffiliate($request);

        if ($share === null) {
            return $commercial['source'] ?? null;
        }

        if ($commercial === null) {
            return $share['source'];
        }

        return $commercial['referred_at']->greaterThan($share['referred_at'])
            ? $commercial['source']
            : $share['source'];
    }

    /** @return array{source: SignupAttributionSource, referred_at: CarbonInterface}|null */
    private function resolveShare(Request $request): ?array
    {
        $selection = $this->shareTrackingService->resolveActiveAttribution($request);

        if ($selection === null) {
            return null;
        }

        $attribution = AffiliateAttribution::query()->withoutGlobalScope(OwnerScope::class)
            ->whereKey($selection->id)->first();

        if (! $attribution instanceof AffiliateAttribution
            || $attribution->attribution_type !== 'landing'
            || (string) $attribution->affiliate_link_id !== $selection->linkId) {
            return null;
        }

        $affiliate = $this->affiliateFor($attribution);
        $link = $this->linkFor($attribution);

        if (! $affiliate instanceof Affiliate
            || ! ShareTrackingProfile::isEligibleForNewCredit($affiliate)
            || ! $link instanceof AffiliateLink
            || $attribution->expires_at?->isPast()) {
            return null;
        }

        $browserSelection = ShareBrowserSelection::query()
            ->where('browser_key', $attribution->visitor_key)
            ->where('current_attribution_id', $attribution->getKey())
            ->first();
        $referredAt = $browserSelection?->getAttribute('accepted_at');

        if (! $referredAt instanceof CarbonInterface) {
            return null;
        }

        $isGuest = ShareTrackingProfile::isMarkedGuest($affiliate);
        $owner = $affiliate->owner;
        $referrerId = $isGuest
            ? $this->activeGuestClaimantId((string) $affiliate->getKey())
            : ($owner instanceof User ? (string) $owner->getKey() : null);

        return [
            'source' => new SignupAttributionSource(
                type: $isGuest ? SignupAttributionSourceType::ShareGuest : SignupAttributionSourceType::ShareMember,
                referrerUserId: $referrerId,
                affiliateId: (string) $affiliate->getKey(),
                affiliateLinkId: (string) $link->getKey(),
                affiliateAttributionId: (string) $attribution->getKey(),
            ),
            'referred_at' => $referredAt,
        ];
    }

    /** @return array{source: SignupAttributionSource, referred_at: CarbonInterface}|null */
    private function resolveCommercialAffiliate(Request $request): ?array
    {
        if (! $this->canReadCommercialCookie($request)) {
            return null;
        }

        $cookie = $request->cookie((string) config('affiliates.cookies.name', 'affiliate_session'));

        if (! is_string($cookie) || $cookie === '' || mb_strlen($cookie) > 120 || str_contains($cookie, "\0")) {
            return null;
        }

        // The private browser cookie identifies a source across account owner scopes.
        // Re-enter its verified owner before the package applies its normal lookup policy.
        $candidate = AffiliateAttribution::query()->withoutGlobalScope(OwnerScope::class)
            ->where('cookie_value', $cookie)
            ->active()
            ->orderByDesc('last_cookie_seen_at')
            ->orderByDesc('id')
            ->first();

        if (! $candidate instanceof AffiliateAttribution) {
            return null;
        }

        $affiliate = $this->affiliateFor($candidate);

        if (! $affiliate instanceof Affiliate
            || ShareTrackingProfile::isMarked($affiliate)
            || ! in_array($affiliate->purpose, [null, 'general'], true)
            || ! $affiliate->canBeAttributed()
            || $affiliate->deactivated_at !== null
            || $affiliate->paused_at !== null) {
            return null;
        }

        try {
            $owner = OwnerContext::fromTypeAndIdOrFail($affiliate->owner_type, $affiliate->owner_id);
        } catch (InvalidArgumentException|ModelNotFoundException) {
            return null;
        }

        $attribution = OwnerContext::withOwner(
            $owner,
            fn (): ?AffiliateAttribution => $this->affiliateLookup->findActiveAttributionByCookie($cookie),
        );

        if (! $attribution instanceof AffiliateAttribution
            || (string) $attribution->getKey() !== (string) $candidate->getKey()
            || (string) $attribution->affiliate_id !== (string) $affiliate->getKey()) {
            return null;
        }

        $referredAt = $attribution->last_referred_at;

        if (! $referredAt instanceof CarbonInterface) {
            return null;
        }

        $link = $this->linkFor($attribution);

        if ($attribution->affiliate_link_id !== null && ! $link instanceof AffiliateLink) {
            return null;
        }

        return [
            'source' => new SignupAttributionSource(
                type: SignupAttributionSourceType::CommercialAffiliate,
                referrerUserId: $owner instanceof User ? (string) $owner->getKey() : null,
                affiliateId: (string) $affiliate->getKey(),
                affiliateLinkId: $link instanceof AffiliateLink ? (string) $link->getKey() : null,
                affiliateAttributionId: (string) $attribution->getKey(),
            ),
            'referred_at' => $referredAt,
        ];
    }

    private function affiliateFor(AffiliateAttribution $attribution): ?Affiliate
    {
        $affiliate = Affiliate::query()->withoutGlobalScope(OwnerScope::class)
            ->whereKey($attribution->affiliate_id)->first();

        if (! $affiliate instanceof Affiliate
            || $attribution->owner_type !== $affiliate->owner_type
            || (string) $attribution->owner_id !== (string) $affiliate->owner_id) {
            return null;
        }

        return $affiliate;
    }

    private function linkFor(AffiliateAttribution $attribution): ?AffiliateLink
    {
        if ($attribution->affiliate_link_id === null) {
            return null;
        }

        $link = AffiliateLink::query()->withoutGlobalScope('affiliate_owner')
            ->whereKey($attribution->affiliate_link_id)
            ->where('affiliate_id', $attribution->affiliate_id)
            ->first();

        return $link instanceof AffiliateLink && $link->isEnabled() ? $link : null;
    }

    private function activeGuestClaimantId(string $affiliateId): ?string
    {
        $claim = ShareGuestClaim::query()
            ->where('guest_affiliate_id', $affiliateId)
            ->where('status', ShareGuestClaimStatus::Active->value)
            ->whereNull('revoked_at')
            ->first();

        $claimant = $claim instanceof ShareGuestClaim
            ? User::query()->whereKey($claim->claimant_user_id)->first()
            : null;

        return $claimant instanceof User ? (string) $claimant->getKey() : null;
    }

    private function canReadCommercialCookie(Request $request): bool
    {
        if (! (bool) config('affiliates.cookies.enabled', true)
            || ((bool) config('affiliates.cookies.respect_dnt', false) && $request->header('DNT') === '1')) {
            return false;
        }

        return ! (bool) config('affiliates.cookies.require_consent', false)
            || $request->boolean('affiliate_consent')
            || (bool) $request->cookie((string) config('affiliates.cookies.consent_cookie', 'affiliate_consent'));
    }
}
