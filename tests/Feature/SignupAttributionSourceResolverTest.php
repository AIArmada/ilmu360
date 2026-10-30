<?php

declare(strict_types=1);

use AIArmada\Affiliates\Actions\Identity\RevokeAffiliateClaim;
use AIArmada\Affiliates\Enums\CommissionType;
use AIArmada\Affiliates\Models\Affiliate;
use AIArmada\Affiliates\Models\AffiliateAttribution;
use AIArmada\Affiliates\Models\AffiliateClaim;
use AIArmada\Affiliates\Models\AffiliateLink;
use AIArmada\Affiliates\States\Active;
use AIArmada\Affiliates\States\Disabled;
use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Actions\ShareTracking\ClaimGuestShareProfileAction;
use App\Enums\ShareGuestClaimResult;
use App\Enums\SignupAttributionSourceType;
use App\Models\ShareBrowserSelection;
use App\Models\User;
use App\Services\ShareTrackingService;
use App\Support\Auth\AffiliateSignupAttributionSourceResolver;
use App\Support\Auth\SignupAttributionSource;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'affiliates.owner.enabled' => false,
        'affiliates.cookies.enabled' => true,
        'affiliates.cookies.respect_dnt' => false,
        'affiliates.cookies.require_consent' => false,
        'dawah-share.reporting.reporting_start' => '2026-10-01',
    ]);
});

/**
 * @return array{request: Request, affiliate: Affiliate, link: AffiliateLink, attribution: AffiliateAttribution, capability: ?string}
 */
function signupResolverShareFixture(?User $sharer = null): array
{
    $service = app(ShareTrackingService::class);
    $pageUrl = route('events.index');
    $payload = $service->sharePayload(
        $sharer,
        $pageUrl,
        'Share this page',
        'Shared Page',
        'web',
        Request::create($pageUrl, 'GET'),
    );
    $link = AffiliateLink::query()->where('custom_slug', $payload['tracking_token'])->firstOrFail();
    $landing = Request::create($pageUrl.'?share='.$payload['tracking_token'], 'GET');
    $route = app('router')->getRoutes()->match($landing);
    $landing->setRouteResolver(static fn (): mixed => $route);
    $cookie = $service->captureRequest($landing);
    $request = Request::create(route('register'), 'POST');
    $request->cookies->set((string) config('dawah-share.cookie.name', 'mi_dawah_share'), $cookie);

    return [
        'request' => $request,
        'affiliate' => Affiliate::query()->whereKey($link->affiliate_id)->firstOrFail(),
        'link' => $link,
        'attribution' => AffiliateAttribution::query()->where('affiliate_link_id', $link->getKey())->firstOrFail(),
        'capability' => is_string($payload['guest_claim_capability'] ?? null) ? $payload['guest_claim_capability'] : null,
    ];
}

function signupResolverCommercialAffiliate(User $owner, ?string $purpose = null): Affiliate
{
    $affiliate = new Affiliate([
        'code' => 'signup-commercial-'.Str::lower(Str::random(12)),
        'name' => 'Signup commercial affiliate',
        'purpose' => $purpose,
        'status' => Active::class,
        'commission_type' => CommissionType::Percentage->value,
        'commission_rate' => 1000,
        'currency' => 'MYR',
        'activated_at' => now(),
    ]);
    $affiliate->assignOwner($owner);
    $affiliate->save();

    return $affiliate;
}

/**
 * @return array{request: Request, affiliate: Affiliate, link: AffiliateLink, attribution: AffiliateAttribution}
 */
function signupResolverCommercialFixture(User $owner, ?string $purpose = null): array
{
    $affiliate = signupResolverCommercialAffiliate($owner, $purpose);
    $link = AffiliateLink::query()->create([
        'affiliate_id' => $affiliate->getKey(),
        'destination_url' => route('events.index'),
        'tracking_url' => route('events.index').'?aff='.$affiliate->code,
    ]);
    $cookie = (string) Str::uuid();
    $attribution = new AffiliateAttribution([
        'affiliate_id' => $affiliate->getKey(),
        'affiliate_code' => $affiliate->code,
        'affiliate_link_id' => $link->getKey(),
        'cookie_value' => $cookie,
        'cart_instance' => 'default',
        'first_seen_at' => now(),
        'last_referred_at' => now(),
        'last_seen_at' => now(),
        'last_cookie_seen_at' => now(),
        'expires_at' => now()->addDay(),
    ]);
    $attribution->forceFill([
        'owner_type' => $owner->getMorphClass(),
        'owner_id' => $owner->getKey(),
    ])->save();
    $request = Request::create(route('register'), 'POST');
    $request->cookies->set((string) config('affiliates.cookies.name', 'affiliate_session'), $cookie);

    return compact('request', 'affiliate', 'link', 'attribution');
}

it('captures the referring member from a real tracked share landing', function (): void {
    $sharer = User::factory()->create();
    $fixture = signupResolverShareFixture($sharer);

    $source = app(AffiliateSignupAttributionSourceResolver::class)->resolve($fixture['request']);

    expect($source?->toArray())->toBe([
        'type' => 'share_member',
        'referrer_user_id' => (string) $sharer->getKey(),
        'affiliate_id' => (string) $fixture['affiliate']->getKey(),
        'affiliate_link_id' => (string) $fixture['link']->getKey(),
        'affiliate_attribution_id' => (string) $fixture['attribution']->getKey(),
    ]);
});

it('resolves a tracked share across owner scopes without changing the ambient owner', function (): void {
    config(['affiliates.owner.enabled' => true]);
    $sharer = User::factory()->create();
    $unrelatedOwner = User::factory()->create();
    $fixture = OwnerContext::withOwner($sharer, fn (): array => signupResolverShareFixture($sharer));

    $source = OwnerContext::withOwner($unrelatedOwner, function () use ($fixture, $unrelatedOwner): ?SignupAttributionSource {
        $source = app(AffiliateSignupAttributionSourceResolver::class)->resolve($fixture['request']);
        expect((string) OwnerContext::resolve()?->getKey())->toBe((string) $unrelatedOwner->getKey());

        return $source;
    });

    expect($source?->type)->toBe(SignupAttributionSourceType::ShareMember);
    expect($source?->referrerUserId)->toBe((string) $sharer->getKey());
});

it('preserves an unclaimed guest share without inventing a referring account', function (): void {
    $fixture = signupResolverShareFixture();

    $source = app(AffiliateSignupAttributionSourceResolver::class)->resolve($fixture['request']);

    expect($source?->type)->toBe(SignupAttributionSourceType::ShareGuest);
    expect($source?->referrerUserId)->toBeNull();
    expect($source?->affiliateId)->toBe((string) $fixture['affiliate']->getKey());
    $this->assertDatabaseCount('affiliate_claims', 0);
});

it('resolves the existing explicit guest claimant without changing the guest profile', function (): void {
    $fixture = signupResolverShareFixture();
    $claimant = User::factory()->create();
    $result = ClaimGuestShareProfileAction::run($claimant, (string) $fixture['capability'], true);
    expect($result)->toBe(ShareGuestClaimResult::Claimed);

    $source = app(AffiliateSignupAttributionSourceResolver::class)->resolve($fixture['request']);

    expect($source?->type)->toBe(SignupAttributionSourceType::ShareGuest);
    expect($source?->referrerUserId)->toBe((string) $claimant->getKey());
    expect($fixture['affiliate']->fresh()?->owner_id)->toBeNull();
    $this->assertDatabaseCount('affiliate_claims', 1);
});

it('does not resolve a revoked guest claimant', function (): void {
    $fixture = signupResolverShareFixture();
    $claimant = User::factory()->create();
    ClaimGuestShareProfileAction::run($claimant, (string) $fixture['capability'], true);
    $hostClaim = AffiliateClaim::query()->where('affiliate_id', $fixture['affiliate']->getKey())->firstOrFail();
    app(RevokeAffiliateClaim::class)->handle($hostClaim);

    $source = app(AffiliateSignupAttributionSourceResolver::class)->resolve($fixture['request']);

    expect($source?->type)->toBe(SignupAttributionSourceType::ShareGuest);
    expect($source?->referrerUserId)->toBeNull();
});

it('captures a commercial affiliate owner through a valid cookie', function (?string $purpose): void {
    $owner = User::factory()->create();
    $fixture = signupResolverCommercialFixture($owner, $purpose);

    $source = app(AffiliateSignupAttributionSourceResolver::class)->resolve($fixture['request']);

    expect($source?->toArray())->toBe([
        'type' => 'commercial_affiliate',
        'referrer_user_id' => (string) $owner->getKey(),
        'affiliate_id' => (string) $fixture['affiliate']->getKey(),
        'affiliate_link_id' => (string) $fixture['link']->getKey(),
        'affiliate_attribution_id' => (string) $fixture['attribution']->getKey(),
    ]);
})->with(['default commercial purpose' => [null], 'explicit commercial purpose' => ['general']]);

it('resolves a commercial source in its verified owner scope and restores the ambient owner', function (): void {
    config(['affiliates.owner.enabled' => true]);
    $owner = User::factory()->create();
    $unrelatedOwner = User::factory()->create();
    $fixture = OwnerContext::withOwner($owner, fn (): array => signupResolverCommercialFixture($owner));

    $source = OwnerContext::withOwner($unrelatedOwner, function () use ($fixture, $unrelatedOwner): ?SignupAttributionSource {
        $source = app(AffiliateSignupAttributionSourceResolver::class)->resolve($fixture['request']);
        expect((string) OwnerContext::resolve()?->getKey())->toBe((string) $unrelatedOwner->getKey());

        return $source;
    });

    expect($source?->referrerUserId)->toBe((string) $owner->getKey());
    expect($source?->affiliateId)->toBe((string) $fixture['affiliate']->getKey());
});

it('accepts a commercial code attribution that has no campaign link', function (): void {
    $fixture = signupResolverCommercialFixture(User::factory()->create());
    $fixture['attribution']->forceFill(['affiliate_link_id' => null])->save();

    $source = app(AffiliateSignupAttributionSourceResolver::class)->resolve($fixture['request']);

    expect($source?->type)->toBe(SignupAttributionSourceType::CommercialAffiliate);
    expect($source?->affiliateLinkId)->toBeNull();
});

it('prefers the current tracked share when explicit referral timestamps tie', function (): void {
    $this->freezeTime();
    $sharer = User::factory()->create();
    $share = signupResolverShareFixture($sharer);
    $commercial = signupResolverCommercialFixture(User::factory()->create());
    $cookieName = (string) config('affiliates.cookies.name', 'affiliate_session');
    $share['request']->cookies->set($cookieName, $commercial['request']->cookie($cookieName));

    $source = app(AffiliateSignupAttributionSourceResolver::class)->resolve($share['request']);

    expect($source?->type)->toBe(SignupAttributionSourceType::ShareMember);
    expect($source?->referrerUserId)->toBe((string) $sharer->getKey());
    expect($source?->affiliateId)->toBe((string) $share['affiliate']->getKey());
});

it('chooses a newer commercial referral over an older tracked share', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-28 12:00:00', 'UTC'));
    $share = signupResolverShareFixture(User::factory()->create());
    $this->travel(10)->minutes();
    $owner = User::factory()->create();
    $commercial = signupResolverCommercialFixture($owner);
    $this->travel(10)->minutes();
    $share['attribution']->forceFill([
        'last_seen_at' => now(),
        'last_cookie_seen_at' => now(),
    ])->save();
    $cookieName = (string) config('affiliates.cookies.name', 'affiliate_session');
    $share['request']->cookies->set($cookieName, $commercial['request']->cookie($cookieName));

    $source = app(AffiliateSignupAttributionSourceResolver::class)->resolve($share['request']);

    expect($source?->type)->toBe(SignupAttributionSourceType::CommercialAffiliate);
    expect($source?->referrerUserId)->toBe((string) $owner->getKey());
});

it('chooses a newer share despite later passive commercial cookie maintenance', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-28 12:00:00', 'UTC'));
    $commercial = signupResolverCommercialFixture(User::factory()->create());
    $this->travel(10)->minutes();
    $sharer = User::factory()->create();
    $share = signupResolverShareFixture($sharer);
    $this->travel(10)->minutes();
    $commercial['attribution']->forceFill([
        'last_cookie_seen_at' => now(),
        'last_seen_at' => now(),
    ])->save();
    $cookieName = (string) config('affiliates.cookies.name', 'affiliate_session');
    $share['request']->cookies->set($cookieName, $commercial['request']->cookie($cookieName));

    $source = app(AffiliateSignupAttributionSourceResolver::class)->resolve($share['request']);

    expect($source?->type)->toBe(SignupAttributionSourceType::ShareMember);
    expect($source?->referrerUserId)->toBe((string) $sharer->getKey());
});

it('abstains from an undated commercial attribution without inferring an explicit touch', function (): void {
    $fixture = signupResolverCommercialFixture(User::factory()->create());
    $fixture['attribution']->forceFill(['last_referred_at' => null])->save();

    expect(app(AffiliateSignupAttributionSourceResolver::class)->resolve($fixture['request']))->toBeNull();
});

it('abstains from a share selection without an acceptance time despite its landing timestamps', function (): void {
    $fixture = signupResolverShareFixture(User::factory()->create());
    ShareBrowserSelection::query()->where('current_attribution_id', $fixture['attribution']->getKey())
        ->update(['accepted_at' => null]);

    expect(app(AffiliateSignupAttributionSourceResolver::class)->resolve($fixture['request']))->toBeNull();
});

it('ignores unusable commercial cookies', function (mixed $cookie): void {
    $request = Request::create(route('register'), 'POST');
    $request->cookies->set((string) config('affiliates.cookies.name', 'affiliate_session'), $cookie);

    expect(app(AffiliateSignupAttributionSourceResolver::class)->resolve($request))->toBeNull();
})->with([
    'absent' => [null],
    'empty' => [''],
    'array' => [['forged' => 'cookie']],
    'overlong' => [str_repeat('x', 121)],
    'embedded null' => ["cookie\0forgery"],
    'unknown opaque cookie' => ['not-a-persisted-cookie'],
]);

it('rejects commercial attribution whose source is no longer eligible', function (string $subject, array $attributes): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-28 12:00:00', 'UTC'));
    $fixture = signupResolverCommercialFixture(User::factory()->create());
    $models = [
        'attribution' => $fixture['attribution'],
        'affiliate' => $fixture['affiliate'],
        'link' => $fixture['link'],
    ];
    $models[$subject]->forceFill($attributes)->save();

    expect(app(AffiliateSignupAttributionSourceResolver::class)->resolve($fixture['request']))->toBeNull();
})->with([
    'expired cookie' => ['attribution', ['expires_at' => '2026-10-28 11:59:00+00:00']],
    'disabled affiliate' => ['affiliate', ['status' => Disabled::class]],
    'paused affiliate' => ['affiliate', ['paused_at' => '2026-10-28 11:59:00+00:00']],
    'deactivated affiliate' => ['affiliate', ['deactivated_at' => '2026-10-28 11:59:00+00:00']],
    'disabled link' => ['link', ['deactivated_at' => '2026-10-28 11:59:00+00:00']],
    'noncommercial profile' => ['affiliate', ['purpose' => 'share_member']],
    'marked share profile in commercial cookie' => ['affiliate', ['metadata' => ['share_tracking_profile' => true, 'profile_kind' => 'member']]],
]);

it('rejects a commercial attribution whose owner does not match its affiliate', function (): void {
    $fixture = signupResolverCommercialFixture(User::factory()->create());
    $otherOwner = User::factory()->create();
    $fixture['attribution']->forceFill(['owner_id' => $otherOwner->getKey()])->save();

    expect(app(AffiliateSignupAttributionSourceResolver::class)->resolve($fixture['request']))->toBeNull();
});

it('rejects a commercial attribution whose campaign link belongs to another affiliate', function (): void {
    $fixture = signupResolverCommercialFixture(User::factory()->create());
    $otherAffiliate = signupResolverCommercialAffiliate(User::factory()->create());
    $fixture['link']->forceFill(['affiliate_id' => $otherAffiliate->getKey()])->save();

    expect(app(AffiliateSignupAttributionSourceResolver::class)->resolve($fixture['request']))->toBeNull();
});

it('rejects a stale tracked share after its link is deactivated', function (): void {
    $fixture = signupResolverShareFixture(User::factory()->create());
    $fixture['link']->deactivate();

    expect(app(AffiliateSignupAttributionSourceResolver::class)->resolve($fixture['request']))->toBeNull();
});

it('round trips a frozen source without re-resolving its referring account', function (): void {
    $source = new SignupAttributionSource(
        type: SignupAttributionSourceType::ShareMember,
        referrerUserId: '019dd73d-319b-70c8-8a05-03900a49f4fe',
        affiliateId: '019dd73d-319b-70c8-8a05-03900a49f4ff',
        affiliateLinkId: '019dd73d-319b-70c8-8a05-03900a49f500',
        affiliateAttributionId: '019dd73d-319b-70c8-8a05-03900a49f501',
    );

    $restored = SignupAttributionSource::fromArray($source->toArray());

    expect($restored)->toEqual($source);
});
