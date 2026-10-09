<?php

declare(strict_types=1);

use AIArmada\Affiliates\Enums\CommissionType;
use AIArmada\Affiliates\Models\Affiliate;
use AIArmada\Affiliates\Models\AffiliateAttribution;
use AIArmada\Affiliates\Models\AffiliateConversion;
use AIArmada\Affiliates\Models\AffiliateLink;
use AIArmada\Affiliates\Models\AffiliateTouchpoint;
use AIArmada\Affiliates\States\Active;
use AIArmada\Affiliates\States\ApprovedConversion;
use App\Models\User;
use App\Services\ShareTrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function wp1MarkedShareAffiliate(User $user, string $code): Affiliate
{
    $affiliate = new Affiliate([
        'code' => $code,
        'name' => 'Scoped Share Profile',
        'status' => Active::class,
        'commission_type' => CommissionType::Percentage->value,
        'commission_rate' => 0,
        'currency' => 'MYR',
        'metadata' => [
            'share_tracking_profile' => true,
            'profile_kind' => 'member',
        ],
        'activated_at' => now(),
    ]);

    $affiliate->assignOwner($user);
    $affiliate->save();

    return $affiliate;
}

function wp1CommercialAffiliate(User $user, string $code): Affiliate
{
    $affiliate = new Affiliate([
        'code' => $code,
        'name' => 'Commercial Account',
        'status' => Active::class,
        'commission_type' => CommissionType::Percentage->value,
        'commission_rate' => 1500,
        'currency' => 'MYR',
        'metadata' => [
            'commercial_account' => true,
        ],
        'activated_at' => now(),
    ]);

    $affiliate->assignOwner($user);
    $affiliate->save();

    return $affiliate;
}

function wp1ShareFactRows(Affiliate $affiliate, string $keyPrefix): array
{
    $link = AffiliateLink::query()->create([
        'affiliate_id' => $affiliate->id,
        'destination_url' => 'https://ilmu360.test/events/'.$keyPrefix,
        'tracking_url' => 'https://ilmu360.test/r/'.$keyPrefix,
        'custom_slug' => substr($keyPrefix.'abcdefghijklmnop', 0, 16),
        'subject_type' => 'event',
        'subject_key' => 'event:'.$keyPrefix,
        'subject_instance' => 'default',
    ]);

    $attribution = AffiliateAttribution::query()->create([
        'affiliate_id' => $affiliate->id,
        'affiliate_code' => $affiliate->code,
        'subject_type' => 'event',
        'subject_key' => 'event:'.$keyPrefix,
        'subject_instance' => 'default',
        'cookie_value' => 'cookie-'.$keyPrefix,
        'landing_url' => 'https://ilmu360.test/events/'.$keyPrefix,
        'affiliate_link_id' => $link->id,
        'attribution_type' => 'landing',
        'visitor_key' => 'visitor-'.$keyPrefix,
        'first_seen_at' => now()->subHour(),
        'last_seen_at' => now()->subHour(),
        'expires_at' => now()->addDays(30),
    ]);

    $touchpoint = AffiliateTouchpoint::query()->create([
        'affiliate_attribution_id' => $attribution->id,
        'affiliate_id' => $affiliate->id,
        'affiliate_code' => $affiliate->code,
        'subject_type' => 'event',
        'subject_key' => 'event:'.$keyPrefix,
        'subject_instance' => 'default',
        'affiliate_link_id' => $link->id,
        'touchpoint_type' => 'visit',
        'visitor_key' => 'visitor-'.$keyPrefix,
        'url' => 'https://ilmu360.test/events/'.$keyPrefix,
        'touched_at' => now()->subMinutes(30),
    ]);

    $conversion = AffiliateConversion::query()->create([
        'affiliate_id' => $affiliate->id,
        'affiliate_code' => $affiliate->code,
        'affiliate_attribution_id' => $attribution->id,
        'subject_type' => 'event',
        'subject_key' => 'event:'.$keyPrefix,
        'subject_instance' => 'default',
        'conversion_type' => 'event_save',
        'external_reference' => 'event_save:'.$keyPrefix,
        'value_minor' => 0,
        'subtotal_minor' => 0,
        'commission_minor' => 0,
        'commission_currency' => 'MYR',
        'status' => ApprovedConversion::class,
        'affiliate_link_id' => $link->id,
        'occurred_at' => now()->subMinutes(10),
    ]);

    return [$link, $attribution, $touchpoint, $conversion];
}

it('removes only the marked share profile and its scoped facts on tracking cleanup', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $affiliate = wp1MarkedShareAffiliate($user, 'scoped-share-001');
    [$link, $attribution, $touchpoint, $conversion] = wp1ShareFactRows($affiliate, 'scoped-share');

    $otherAffiliate = wp1MarkedShareAffiliate($otherUser, 'scoped-share-002');
    [$otherLink] = wp1ShareFactRows($otherAffiliate, 'scoped-other');

    app(ShareTrackingService::class)->deleteUserTracking($user);

    expect(Affiliate::query()->whereKey($affiliate->id)->exists())->toBeFalse()
        ->and(AffiliateLink::query()->whereKey($link->id)->exists())->toBeFalse()
        ->and(AffiliateAttribution::query()->whereKey($attribution->id)->exists())->toBeFalse()
        ->and(AffiliateTouchpoint::query()->whereKey($touchpoint->id)->exists())->toBeFalse()
        ->and(AffiliateConversion::query()->whereKey($conversion->id)->exists())->toBeFalse();

    expect(Affiliate::query()->whereKey($otherAffiliate->id)->exists())->toBeTrue()
        ->and(AffiliateLink::query()->whereKey($otherLink->id)->exists())->toBeTrue();
});

it('never deletes an unmarked commercial profile through tracking cleanup', function (): void {
    $user = User::factory()->create();

    $commercial = wp1CommercialAffiliate($user, 'commercial-keep-001');
    [$commercialLink] = wp1ShareFactRows($commercial, 'commercial-keep');

    app(ShareTrackingService::class)->deleteUserTracking($user);

    expect(Affiliate::query()->whereKey($commercial->id)->exists())->toBeTrue()
        ->and(AffiliateLink::query()->whereKey($commercialLink->id)->exists())->toBeTrue()
        ->and(AffiliateAttribution::query()->where('affiliate_id', $commercial->id)->exists())->toBeTrue()
        ->and(AffiliateTouchpoint::query()->where('affiliate_id', $commercial->id)->exists())->toBeTrue()
        ->and(AffiliateConversion::query()->where('affiliate_id', $commercial->id)->exists())->toBeTrue();
});

it('exposes no global affiliate runtime purge path', function (): void {
    expect(class_exists('App\Services\ShareTracking\AffiliateRuntimeDataPurger'))->toBeFalse()
        ->and(config('dawah-share.runtime_data_purge'))->toBeNull();
});
