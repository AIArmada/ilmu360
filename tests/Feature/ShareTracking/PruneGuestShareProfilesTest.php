<?php

declare(strict_types=1);

use AIArmada\Affiliates\Enums\CommissionType;
use AIArmada\Affiliates\Models\Affiliate;
use AIArmada\Affiliates\Models\AffiliateAttribution;
use AIArmada\Affiliates\Models\AffiliateClaim;
use AIArmada\Affiliates\Models\AffiliateConversion;
use AIArmada\Affiliates\Models\AffiliateCredential;
use AIArmada\Affiliates\Models\AffiliateLink;
use AIArmada\Affiliates\Models\AffiliateOwnershipCapability;
use AIArmada\Affiliates\Models\AffiliateTouchpoint;
use AIArmada\Affiliates\States\Active;
use AIArmada\Affiliates\States\ApprovedConversion;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\CommerceSupport\Support\OwnerScope;
use AIArmada\Links\Models\Link;
use App\Models\User;
use App\Support\ShareTracking\ShareTrackingProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * @return array{affiliate: Affiliate, link: AffiliateLink, tracked: Link|null}
 */
function pgspGuestShare(): array
{
    $response = test()->getJson(route('dawah-share.payload', [
        'url' => route('events.index'),
        'text' => 'Share this page',
    ]));

    $response->assertOk();

    $payload = $response->json();

    $link = AffiliateLink::query()->where('custom_slug', $payload['tracking_token'])->firstOrFail();
    $affiliate = Affiliate::query()
        ->withoutGlobalScope(OwnerScope::class)
        ->whereKey($link->affiliate_id)
        ->firstOrFail();

    return [
        'affiliate' => $affiliate,
        'link' => $link,
        'tracked' => $link->trackedLink()->first(),
    ];
}

function pgspBackdate(Affiliate $affiliate, int $days = 120): void
{
    $affiliate->forceFill(['created_at' => now()->subDays($days)])->save();
}

function pgspAttribution(Affiliate $affiliate, AffiliateLink $link): AffiliateAttribution
{
    return OwnerContext::withOwner(null, fn (): AffiliateAttribution => AffiliateAttribution::query()->create([
        'affiliate_id' => $affiliate->id,
        'affiliate_code' => $affiliate->code,
        'subject_type' => 'event',
        'subject_key' => 'event:prune-guest',
        'subject_instance' => 'default',
        'cookie_value' => 'prune-guest-cookie',
        'landing_url' => 'https://ilmu360.test/events/prune-guest',
        'affiliate_link_id' => $link->id,
        'attribution_type' => 'landing',
        'visitor_key' => 'prune-guest-visitor',
        'first_seen_at' => now()->subDays(100),
        'last_seen_at' => now()->subDays(100),
        'expires_at' => now()->subDays(70),
    ]));
}

function pgspConversion(Affiliate $affiliate, AffiliateLink $link): AffiliateConversion
{
    return OwnerContext::withOwner(null, fn (): AffiliateConversion => AffiliateConversion::query()->create([
        'affiliate_id' => $affiliate->id,
        'affiliate_code' => $affiliate->code,
        'affiliate_link_id' => $link->id,
        'subject_type' => 'event',
        'subject_key' => 'event:prune-guest',
        'subject_instance' => 'default',
        'conversion_type' => 'event_save',
        'external_reference' => 'prune-guest-conversion',
        'value_minor' => 0,
        'subtotal_minor' => 0,
        'commission_minor' => 0,
        'commission_currency' => 'MYR',
        'status' => ApprovedConversion::class,
        'occurred_at' => now()->subDays(100),
    ]));
}

function pgspMemberAffiliate(User $user): Affiliate
{
    $affiliate = new Affiliate([
        'code' => 'MI'.strtoupper(substr(hash('sha256', 'prune-guest-member'), 0, 12)),
        'name' => $user->name,
        'status' => Active::class,
        'commission_type' => CommissionType::Percentage,
        'commission_rate' => 0,
        'currency' => 'MYR',
        'metadata' => ShareTrackingProfile::memberMetadata(),
        'purpose' => ShareTrackingProfile::PURPOSE_SHARE_MEMBER,
        'activated_at' => now(),
    ]);

    $affiliate->assignOwner($user);
    $affiliate->save();

    return $affiliate;
}

it('prunes a stale unclaimed guest profile and keeps its published short link resolvable', function (): void {
    ['affiliate' => $affiliate, 'link' => $link, 'tracked' => $tracked] = pgspGuestShare();

    $attribution = pgspAttribution($affiliate, $link);

    $touchpoint = AffiliateTouchpoint::query()->create([
        'affiliate_attribution_id' => $attribution->id,
        'affiliate_id' => $affiliate->id,
        'affiliate_code' => $affiliate->code,
        'subject_type' => 'event',
        'subject_key' => 'event:prune-guest',
        'subject_instance' => 'default',
        'affiliate_link_id' => $link->id,
        'touchpoint_type' => 'visit',
        'visitor_key' => 'prune-guest-visitor',
        'url' => 'https://ilmu360.test/events/prune-guest',
        'touched_at' => now()->subDays(100),
    ]);

    pgspBackdate($affiliate);

    $this->artisan('share:prune-guest-profiles')->assertSuccessful();

    expect(Affiliate::query()->withoutGlobalScope(OwnerScope::class)->whereKey($affiliate->id)->exists())->toBeFalse()
        ->and(AffiliateLink::query()->whereKey($link->id)->exists())->toBeFalse()
        ->and(AffiliateAttribution::query()->whereKey($attribution->id)->exists())->toBeFalse()
        ->and(AffiliateTouchpoint::query()->whereKey($touchpoint->id)->exists())->toBeFalse()
        ->and(AffiliateCredential::query()->where('affiliate_id', $affiliate->id)->exists())->toBeFalse()
        ->and(AffiliateOwnershipCapability::query()->where('affiliate_id', $affiliate->id)->exists())->toBeFalse()
        ->and($tracked instanceof Link ? Link::query()->whereKey($tracked->id)->exists() : true)->toBeTrue()
        ->and($tracked instanceof Link ? Link::query()->whereKey($tracked->id)->value('subject_id') : null)->toBe('00000000-0000-0000-0000-000000000000');
});

it('keeps guest profiles inside retention and dry runs delete nothing', function (): void {
    ['affiliate' => $recent] = pgspGuestShare();

    ['affiliate' => $stale] = pgspGuestShare();
    pgspBackdate($stale);

    $this->artisan('share:prune-guest-profiles', ['--dry-run' => true])->assertSuccessful();

    expect(Affiliate::query()->withoutGlobalScope(OwnerScope::class)->whereKey($stale->id)->exists())->toBeTrue();

    $this->artisan('share:prune-guest-profiles')->assertSuccessful();

    expect(Affiliate::query()->withoutGlobalScope(OwnerScope::class)->whereKey($recent->id)->exists())->toBeTrue()
        ->and(Affiliate::query()->withoutGlobalScope(OwnerScope::class)->whereKey($stale->id)->exists())->toBeFalse();
});

it('keeps claimed guest profiles even when the claim was revoked', function (): void {
    ['affiliate' => $affiliate] = pgspGuestShare();
    pgspBackdate($affiliate);

    AffiliateClaim::query()->create([
        'affiliate_id' => $affiliate->getKey(),
        'claimant_type' => (new User)->getMorphClass(),
        'claimant_id' => (string) User::factory()->create()->getKey(),
        'capability_verifier_hash' => hash('sha256', Str::random(64)),
        'status' => 'revoked',
        'claimed_at' => now()->subDays(100),
        'revoked_at' => now()->subDays(90),
    ]);

    $this->artisan('share:prune-guest-profiles')->assertSuccessful();

    expect(Affiliate::query()->withoutGlobalScope(OwnerScope::class)->whereKey($affiliate->id)->exists())->toBeTrue();
});

it('keeps guest profiles with conversions and marked member profiles', function (): void {
    ['affiliate' => $guest, 'link' => $link] = pgspGuestShare();
    pgspAttribution($guest, $link);
    pgspConversion($guest, $link);
    pgspBackdate($guest);

    $member = pgspMemberAffiliate(User::factory()->create());
    pgspBackdate($member);

    $this->artisan('share:prune-guest-profiles')->assertSuccessful();

    expect(Affiliate::query()->withoutGlobalScope(OwnerScope::class)->whereKey($guest->id)->exists())->toBeTrue()
        ->and(Affiliate::query()->withoutGlobalScope(OwnerScope::class)->whereKey($member->id)->exists())->toBeTrue();
});
