<?php

use AIArmada\Affiliates\Models\AffiliateAttribution;
use AIArmada\Affiliates\Models\AffiliateLink;
use AIArmada\Affiliates\Models\AffiliateTouchpoint;
use App\Models\User;
use App\Services\ShareTracking\AffiliatesShareTrackingService;
use App\Services\ShareTrackingAnalyticsService;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    config(['share-tracking.reporting.reporting_start' => '2026-10-01']);
});

test('prepared links have no last share or recent activity until an outbound share is recorded', function (): void {
    $this->travelTo(now()->setDate(2026, 11, 5)->startOfDay());
    $user = User::factory()->create();
    app(AffiliatesShareTrackingService::class)->attributedUrl($user, route('events.index'), 'Events');
    $link = AffiliateLink::query()->firstOrFail();
    $analytics = app(ShareTrackingAnalyticsService::class);

    expect($analytics->findLinkForUser($user, $link->id)->lastSharedAt)->toBeNull();
    expect($analytics->linksForUser($user, status: 'active'))->toBeEmpty();

    $this->actingAs($user)->get(route('share.redirect', [
        'provider' => 'whatsapp',
        'text' => 'Share Events',
        'url' => route('events.index'),
        'title' => 'Events',
    ]))->assertRedirect();
    $recorded = $analytics->findLinkForUser($user, $link->id)->lastSharedAt;
    $this->travel(1)->hours();
    $link->touch();

    expect($recorded)->not->toBeNull();
    expect($analytics->findLinkForUser($user, $link->id)->lastSharedAt->equalTo($recorded))->toBeTrue();
    expect($analytics->linksForUser($user, status: 'active'))->toHaveCount(1);
});

test('channel breakdown includes unattributed channels so visits reconcile with the total', function (): void {
    $user = User::factory()->create();
    app(AffiliatesShareTrackingService::class)->attributedUrl($user, route('events.index'), 'Events');
    $link = AffiliateLink::query()->firstOrFail();

    $attribution = AffiliateAttribution::query()->create([
        'affiliate_id' => $link->affiliate_id,
        'affiliate_code' => $link->affiliate->code,
        'subject_key' => $link->id,
        'subject_instance' => 'share_tracking_link',
        'affiliate_link_id' => $link->id,
        'attribution_type' => 'landing',
        'cookie_value' => 'direct-channel-test',
        'landing_url' => $link->destination_url,
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);

    foreach ([null, '', 'whatsapp'] as $channel) {
        AffiliateTouchpoint::query()->create([
            'affiliate_id' => $link->affiliate_id,
            'affiliate_code' => $attribution->affiliate_code,
            'affiliate_attribution_id' => $attribution->id,
            'affiliate_link_id' => $link->id,
            'touchpoint_type' => 'visit',
            'channel' => $channel,
            'touched_at' => now(),
        ]);
    }

    $analytics = app(ShareTrackingAnalyticsService::class);
    $breakdown = $analytics->providerBreakdownForUser($user);

    expect($breakdown->firstWhere('provider', 'direct')['visits'])->toBe(2);
    expect($breakdown->sum('visits'))->toBe(3);
    expect($analytics->summaryForUser($user)['visits'])->toBe(3);
});

test('loading a library uses bounded measurement queries as the number of links grows', function (): void {
    $user = User::factory()->create();
    $share = app(AffiliatesShareTrackingService::class);
    $share->attributedUrl($user, route('events.index'), 'Events');
    $analytics = app(ShareTrackingAnalyticsService::class);

    DB::enableQueryLog();
    DB::flushQueryLog();
    $analytics->linksForUser($user);
    $singleLinkQueries = count(DB::getQueryLog());
    DB::disableQueryLog();

    $share->attributedUrl($user, route('institutions.index'), 'Institutions');
    $share->attributedUrl($user, route('persons.index'), 'Speakers');

    DB::enableQueryLog();
    DB::flushQueryLog();
    $links = $analytics->linksForUser($user);
    $multipleLinkQueries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($links)->toHaveCount(3);
    expect($multipleLinkQueries)->toBe($singleLinkQueries);
});
