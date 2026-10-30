<?php

declare(strict_types=1);

use AIArmada\Affiliates\Actions\Identity\ClaimAffiliateIdentity;
use AIArmada\Affiliates\Actions\Identity\IssueOwnershipCapability;
use AIArmada\Affiliates\Models\Affiliate;
use AIArmada\Affiliates\Models\AffiliateClaim;
use AIArmada\Affiliates\Models\AffiliateCredential;
use AIArmada\Affiliates\Models\AffiliateLink;
use AIArmada\Affiliates\States\Active;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\CommerceSupport\Support\OwnerScope;
use App\Actions\Auth\RecordSignupAttributionAction;
use App\Actions\ShareTracking\ClaimGuestShareProfileAction;
use App\Enums\ShareGuestClaimResult;
use App\Enums\SignupAttributionSourceType;
use App\Models\User;
use App\Services\ShareTracking\AffiliatesShareTrackingAnalyticsService;
use App\Services\ShareTracking\AffiliatesShareTrackingService;
use App\Services\ShareTrackingService;
use App\Support\Auth\SignupAttributionSource;
use App\Support\Share\ShareProfileScope;
use App\Support\ShareTracking\ShareTrackingProfile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

it('excludes unrelated package claims from share history and cleanup', function (array $metadata, bool $owned, string $purpose): void {
    config(['affiliates.owner.enabled' => false]);
    $user = User::factory()->create();
    $affiliate = new Affiliate([
        'code' => 'COMMERCIAL-BOUNDARY',
        'name' => 'Commercial identity',
        'purpose' => $purpose,
        'status' => Active::class,
        'commission_type' => 'percentage',
        'commission_rate' => 1000,
        'currency' => 'MYR',
        'metadata' => $metadata,
    ]);

    if ($owned) {
        $affiliate->assignOwner($user);
    }

    $affiliate->save();
    $capability = IssueOwnershipCapability::run($affiliate);
    $claim = ClaimAffiliateIdentity::run($capability['plaintext_secret'], $user->getMorphClass(), $user->getKey(), true);
    $link = AffiliateLink::query()->create([
        'affiliate_id' => $affiliate->getKey(),
        'destination_url' => route('events.index'),
        'tracking_url' => route('events.index').'?aff=COMMERCIAL-BOUNDARY',
    ]);
    $analytics = app(AffiliatesShareTrackingAnalyticsService::class);

    expect(ShareProfileScope::affiliateIdsForUser($user))->toBe([])
        ->and($analytics->linksForUser($user))->toBeEmpty()
        ->and($analytics->findLinkForUser($user, (string) $link->getKey()))->toBeNull();

    app(ShareTrackingService::class)->deleteUserTracking($user);

    expect($claim->fresh()->isActive())->toBeTrue()
        ->and($link->fresh()->deactivated_at)->toBeNull()
        ->and($affiliate->fresh())->not->toBeNull();
})->with([
    'commercial identity' => [[], false, 'general'],
    'purpose without marker' => [[], false, ShareTrackingProfile::PURPOSE_SHARE_GUEST],
    'string marker' => [[ShareTrackingProfile::MARKER_KEY => 'true', ShareTrackingProfile::KIND_KEY => ShareTrackingProfile::KIND_GUEST], false, 'general'],
    'owned guest marker' => [ShareTrackingProfile::guestMetadata(), true, 'general'],
]);

it('claims reads and revokes guest history with ownership enabled', function (bool $includeGlobal): void {
    config([
        'affiliates.owner.enabled' => true,
        'affiliates.owner.include_global' => $includeGlobal,
        'dawah-share.reporting.reporting_start' => '2026-10-01',
    ]);
    Model::clearBootedModels();
    $claimant = User::factory()->create();
    $unrelated = User::factory()->create();
    $signupBefore = User::factory()->create();
    $service = app(ShareTrackingService::class);
    $identityService = app(AffiliatesShareTrackingService::class);
    $payload = $service->sharePayload(null, route('events.index'), 'Share', 'Page', 'web', Request::create(route('events.index')));
    $link = AffiliateLink::query()->withoutGlobalScope(OwnerScope::class)->where('custom_slug', $payload['tracking_token'])->firstOrFail();
    $request = Request::create(route('share.claim.show'));
    $request->headers->set('X-Dawah-Claim-Capability', $payload['guest_claim_capability']);
    $context = [
        'referral_invite_id' => null,
        'affiliate_source' => (new SignupAttributionSource(
            SignupAttributionSourceType::ShareGuest,
            affiliateId: (string) $link->affiliate_id,
            affiliateLinkId: (string) $link->getKey(),
        ))->toArray(),
    ];
    $beforeAttribution = RecordSignupAttributionAction::run($signupBefore, context: $context);
    expect($beforeAttribution->referrer_user_id)->toBeNull();

    OwnerContext::withOwner($unrelated, function () use ($claimant, $payload, $identityService, $request, $link, $unrelated): void {
        expect($identityService->claimPageState($claimant, $request)['state'])->toBe('available');
        expect(ClaimGuestShareProfileAction::run($claimant, $payload['guest_claim_capability'], true))->toBe(ShareGuestClaimResult::Claimed)
            ->and(ClaimGuestShareProfileAction::run($claimant, $payload['guest_claim_capability'], true))->toBe(ShareGuestClaimResult::AlreadyClaimedByYou)
            ->and($identityService->claimPageState($claimant, $request)['state'])->toBe('claimed_by_you')
            ->and(ShareProfileScope::affiliateIdsForUser($claimant))->toContain((string) $link->affiliate_id);

        $analytics = app(AffiliatesShareTrackingAnalyticsService::class);
        expect($analytics->findLinkForUser($claimant, (string) $link->getKey()))->not->toBeNull()
            ->and($analytics->linksForUser($claimant))->toHaveCount(1)
            ->and($analytics->findLinkForUser($unrelated, (string) $link->getKey()))->toBeNull()
            ->and((string) OwnerContext::resolve()?->getKey())->toBe((string) $unrelated->getKey());
    });

    expect((string) $beforeAttribution->fresh()->referrer_user_id)->toBe((string) $claimant->getKey());
    $signupAfter = User::factory()->create();
    $afterAttribution = OwnerContext::withOwner($unrelated, fn () => RecordSignupAttributionAction::run($signupAfter, context: $context));
    expect((string) $afterAttribution->referrer_user_id)->toBe((string) $claimant->getKey());

    $claim = ShareProfileScope::guestClaims()->where('affiliate_id', $link->affiliate_id)->firstOrFail();
    $credential = AffiliateCredential::query()->withoutGlobalScope(OwnerScope::class)->where('affiliate_id', $link->affiliate_id)->firstOrFail();
    expect($credential->status)->toBe('revoked')
        ->and($claim->owner_id)->toBeNull();

    OwnerContext::withOwner($unrelated, fn () => $service->deleteUserTracking($claimant));

    expect($claim->fresh()->status)->toBe('revoked')
        ->and($claim->fresh()->revoked_at)->not->toBeNull()
        ->and($link->fresh()->deactivated_at)->not->toBeNull()
        ->and(ShareProfileScope::affiliateIdsForUser($claimant))->toBe([])
        ->and(ClaimGuestShareProfileAction::run($claimant, $payload['guest_claim_capability'], true))->toBe(ShareGuestClaimResult::ClaimedByOther)
        ->and(AffiliateClaim::query()->withoutGlobalScope(OwnerScope::class)->where('affiliate_id', $link->affiliate_id)->count())->toBe(1);
})->with([
    'global records excluded from owner scope' => false,
    'global records included in owner scope' => true,
]);
