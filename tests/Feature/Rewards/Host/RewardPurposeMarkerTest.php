<?php

declare(strict_types=1);

use AIArmada\Affiliates\Models\Affiliate;
use AIArmada\Affiliates\Models\AffiliateLink;
use App\Models\User;
use App\Support\ShareTracking\ShareTrackingProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('marks guest share profiles with the neutral purpose', function (): void {
    $response = test()->getJson(route('dawah-share.payload', [
        'url' => route('events.index'),
        'text' => 'Share this page',
        'title' => 'Shared Page',
    ]));
    $response->assertOk();

    $link = AffiliateLink::query()->where('custom_slug', $response->json('tracking_token'))->firstOrFail();
    $affiliate = Affiliate::query()->whereKey($link->affiliate_id)->firstOrFail();

    expect($affiliate->purpose)->toBe(ShareTrackingProfile::PURPOSE_SHARE_GUEST);
    expect($affiliate->isCommercialPurpose())->toBeFalse();
});

it('marks member share profiles with the neutral purpose', function (): void {
    $member = User::factory()->create();

    $response = test()->actingAs($member)->getJson(route('dawah-share.payload', [
        'url' => route('events.index'),
        'text' => 'Share this page',
        'title' => 'Shared Page',
    ]));
    $response->assertOk();

    $link = AffiliateLink::query()->where('custom_slug', $response->json('tracking_token'))->firstOrFail();
    $affiliate = Affiliate::query()->whereKey($link->affiliate_id)->firstOrFail();

    expect($affiliate->purpose)->toBe(ShareTrackingProfile::PURPOSE_SHARE_MEMBER);
    expect($affiliate->isCommercialPurpose())->toBeFalse();
});

it('leaves unmarked commercial affiliates untouched', function (): void {
    $affiliate = Affiliate::query()->create([
        'code' => 'PURP-APP-'.strtoupper(uniqid()),
        'name' => 'Commercial control',
        'status' => 'active',
        'commission_type' => 'percentage',
        'commission_rate' => 1000,
        'currency' => 'MYR',
    ]);

    expect($affiliate->purpose)->toBeNull();
    expect($affiliate->isCommercialPurpose())->toBeTrue();
});
