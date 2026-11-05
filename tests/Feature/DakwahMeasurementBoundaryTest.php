<?php

use AIArmada\Affiliates\Models\AffiliateAttribution;
use AIArmada\Affiliates\Models\AffiliateConversion;
use AIArmada\Affiliates\Models\AffiliateLink;
use AIArmada\Affiliates\Models\AffiliateTouchpoint;
use AIArmada\Affiliates\States\ApprovedConversion;
use App\Models\User;
use App\Services\ShareTrackingAnalyticsService;
use App\Services\ShareTrackingService;
use App\Support\Share\ShareMetricReader;
use App\Support\ShareTracking\ShareTrackingMeasurement;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

test('invalid reporting starts withhold measurements consistently across readers', function (mixed $reportingStart): void {
    config(['share-tracking.reporting.reporting_start' => $reportingStart]);
    $user = User::factory()->create();
    app(ShareTrackingService::class)->attributedUrl($user, route('events.index'), 'Events');
    $analytics = app(ShareTrackingAnalyticsService::class);

    $canonical = app(ShareMetricReader::class)->summarize(
        'UTC',
        CarbonImmutable::parse('2026-11-01'),
        CarbonImmutable::parse('2026-11-05'),
        $user,
    );

    expect(ShareTrackingMeasurement::reportingStart())->toBeNull();
    expect(ShareTrackingMeasurement::status())->toBe('not_configured');
    expect($canonical['status'])->toBe('not_configured');
    expect($analytics->summaryForUser($user)['visits'])->toBe(0);
    expect($analytics->providerBreakdownForUser($user))->toBeEmpty();
    expect($analytics->linksForUser($user)->first()->lastSharedAt)->toBeNull();
})->with([
    'missing' => null,
    'empty' => '',
    'whitespace' => '   ',
    'wrong type' => 360,
    'unparseable' => 'this-is-not-a-reporting-start',
    'invalid calendar date' => '2026-02-30',
    'relative' => 'tomorrow',
]);

test('absolute reporting starts retain their declared representation', function (string $reportingStart, string $expected): void {
    config(['share-tracking.reporting.reporting_start' => $reportingStart]);

    expect(ShareTrackingMeasurement::reportingStart())->toBe($expected);
    expect(ShareTrackingMeasurement::status())->toBe('active');
})->with([
    'date' => ['2026-10-01', '2026-10-01'],
    'trimmed' => [' 2026-10-01 ', '2026-10-01'],
    'UTC instant' => ['2026-10-01T12:30:00Z', '2026-10-01T12:30:00Z'],
    'offset instant' => ['2026-10-01T12:30:00+08:00', '2026-10-01T12:30:00+08:00'],
]);

test('landing browser history remains visible in channel breakdowns without visit touchpoints', function (): void {
    config(['share-tracking.reporting.reporting_start' => '2026-10-01']);
    $this->travelTo(CarbonImmutable::parse('2026-11-05T12:00:00Z'));
    $user = User::factory()->create();
    app(ShareTrackingService::class)->attributedUrl($user, route('events.index'), 'Events');
    $link = AffiliateLink::query()->firstOrFail();

    AffiliateAttribution::query()->create([
        'affiliate_id' => $link->affiliate_id,
        'affiliate_code' => $link->affiliate->code,
        'affiliate_link_id' => $link->id,
        'subject_key' => $link->subject_key,
        'subject_instance' => 'default',
        'attribution_type' => 'landing',
        'cookie_value' => 'historical-landing-channel',
        'landing_url' => $link->destination_url,
        'visitor_key' => 'historical-browser',
        'channel' => 'telegram',
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);
    $analytics = app(ShareTrackingAnalyticsService::class);
    $linkData = $analytics->findLinkForUser($user, $link->id);
    $expected = [
        'provider' => 'telegram',
        'label' => 'Telegram',
        'outbound_shares' => 0,
        'visits' => 0,
        'distinct_browsers' => 1,
        'outcomes' => 0,
        'signups' => 0,
        'event_registrations' => 0,
        'event_checkins' => 0,
        'event_submissions' => 0,
    ];

    expect($analytics->summaryForUser($user)['distinct_browsers'])->toBe(1);
    expect($analytics->providerBreakdownForUser($user)->firstWhere('provider', 'telegram'))->toBe($expected);
    expect($analytics->providerBreakdownForLink($linkData)->firstWhere('provider', 'telegram'))->toBe($expected);
});

function dakwahBoundaryFacts(AffiliateLink $linkedPage, AffiliateLink $factOwner, CarbonInterface $at): AffiliateAttribution
{
    $reference = (string) Str::uuid();
    $attribution = AffiliateAttribution::query()->create([
        'affiliate_id' => $factOwner->affiliate_id,
        'affiliate_code' => $factOwner->affiliate->code,
        'affiliate_link_id' => $linkedPage->id,
        'subject_key' => $linkedPage->subject_key,
        'subject_instance' => 'default',
        'attribution_type' => 'landing',
        'cookie_value' => $reference,
        'landing_url' => $linkedPage->destination_url,
        'visitor_key' => $reference,
        'channel' => 'telegram',
        'first_seen_at' => $at,
        'last_seen_at' => $at,
    ]);

    foreach (['outbound_share', 'visit'] as $type) {
        AffiliateTouchpoint::query()->create([
            'affiliate_id' => $factOwner->affiliate_id,
            'affiliate_code' => $attribution->affiliate_code,
            'affiliate_attribution_id' => $attribution->id,
            'affiliate_link_id' => $linkedPage->id,
            'touchpoint_type' => $type,
            'visitor_key' => $reference,
            'channel' => 'telegram',
            'touched_at' => $at,
        ]);
    }

    AffiliateConversion::query()->create([
        'affiliate_id' => $factOwner->affiliate_id,
        'affiliate_code' => $attribution->affiliate_code,
        'affiliate_attribution_id' => $attribution->id,
        'affiliate_link_id' => $linkedPage->id,
        'conversion_type' => 'signup',
        'external_reference' => $reference,
        'value_minor' => 0,
        'subtotal_minor' => 0,
        'commission_minor' => 0,
        'commission_currency' => 'MYR',
        'status' => ApprovedConversion::class,
        'channel' => 'telegram',
        'occurred_at' => $at,
    ]);

    return $attribution;
}

test('future facts are excluded from user and link measurements even later on the same day', function (int $futureHours): void {
    config(['share-tracking.reporting.reporting_start' => '2026-10-01']);
    $this->travelTo(CarbonImmutable::parse('2026-11-05T12:00:00Z'));
    $user = User::factory()->create();
    app(ShareTrackingService::class)->attributedUrl($user, route('events.index'), 'Events');
    $link = AffiliateLink::query()->firstOrFail();
    dakwahBoundaryFacts($link, $link, now()->subMinute());
    dakwahBoundaryFacts($link, $link, now()->addHours($futureHours));
    $analytics = app(ShareTrackingAnalyticsService::class);
    $linkData = $analytics->findLinkForUser($user, $link->id);

    expect($analytics->summaryForUser($user))->toBe([
        'outbound_shares' => 1,
        'visits' => 1,
        'distinct_browsers' => 1,
        'signups' => 1,
        'event_registrations' => 0,
        'event_checkins' => 0,
        'event_submissions' => 0,
        'total_outcomes' => 1,
    ]);
    expect($analytics->summaryForLink($linkData))->toMatchArray([
        'outbound_shares' => 1,
        'visits' => 1,
        'distinct_browsers' => 1,
        'signups' => 1,
        'outcomes' => 1,
    ]);
    expect($analytics->linksForUser($user)->first()->visitsCount)->toBe(1);
    expect($analytics->providerBreakdownForUser($user)->sum('visits'))->toBe(1);
    expect($analytics->providerBreakdownForLink($linkData)->sum('visits'))->toBe(1);
    expect($analytics->dailyPerformanceForLink($linkData, 14, 'UTC')->sum('visits'))->toBe(1);
    expect($analytics->dailyPerformanceForLink($linkData, 14, 'UTC')->sum('outcomes'))->toBe(1);
    expect($analytics->outcomeBreakdownForLink($linkData)->sum('count'))->toBe(1);
    expect($analytics->recentVisitsForLink($linkData))->toHaveCount(1);
    expect($analytics->recentOutcomesForLink($linkData))->toHaveCount(1);
    expect($analytics->recentOutcomesForUser($user))->toHaveCount(1);
    expect($analytics->activityWindowForLink($linkData)['last_visit_at']->equalTo(now()->subMinute()))->toBeTrue();
})->with([
    'later today' => 1,
    'tomorrow' => 24,
]);

test('link detail excludes foreign affiliate facts incorrectly pointing at an owned link', function (): void {
    config(['share-tracking.reporting.reporting_start' => '2026-10-01']);
    $this->travelTo(CarbonImmutable::parse('2026-11-05T12:00:00Z'));
    $user = User::factory()->create();
    $other = User::factory()->create();
    $shares = app(ShareTrackingService::class);
    $shares->attributedUrl($user, route('events.index'), 'Events');
    $link = AffiliateLink::query()->firstOrFail();
    $shares->attributedUrl($other, route('events.index'), 'Events');
    $foreignLink = AffiliateLink::query()->where('id', '!=', $link->id)->firstOrFail();
    dakwahBoundaryFacts($link, $foreignLink, now()->subMinute());
    $analytics = app(ShareTrackingAnalyticsService::class);
    $linkData = $analytics->findLinkForUser($user, $link->id);

    expect($analytics->summaryForUser($user)['total_outcomes'])->toBe(0);
    expect($analytics->summaryForLink($linkData))->toBe([
        'outbound_shares' => 0,
        'visits' => 0,
        'distinct_browsers' => 0,
        'outcomes' => 0,
        'signups' => 0,
        'event_registrations' => 0,
        'event_checkins' => 0,
        'event_submissions' => 0,
    ]);
    expect($analytics->providerBreakdownForLink($linkData))->toBeEmpty();
    expect($analytics->dailyPerformanceForLink($linkData, 14, 'UTC')->sum('visits'))->toBe(0);
    expect($analytics->dailyPerformanceForLink($linkData, 14, 'UTC')->sum('outcomes'))->toBe(0);
    expect($analytics->outcomeBreakdownForLink($linkData))->toBeEmpty();
    expect($analytics->recentVisitsForLink($linkData))->toBeEmpty();
    expect($analytics->recentOutcomesForLink($linkData))->toBeEmpty();
    expect($analytics->activityWindowForLink($linkData)['latest_activity_at'])->toBeNull();
});

test('deactivated links keep their historical detail report readable without creating a replacement link', function (): void {
    config(['share-tracking.reporting.reporting_start' => '2026-10-01']);
    $this->travelTo(CarbonImmutable::parse('2026-11-05T12:00:00Z'));
    $user = User::factory()->create();
    app(ShareTrackingService::class)->attributedUrl($user, route('events.index'), 'Historical events');
    $link = AffiliateLink::query()->firstOrFail();
    dakwahBoundaryFacts($link, $link, now()->subMinute());
    $link->update(['deactivated_at' => now()]);
    $analytics = app(ShareTrackingAnalyticsService::class);

    $this->actingAs($user)->get(route('dakwah.links.show', ['link' => $link->id]))
        ->assertSuccessful()
        ->assertSee('Historical events');

    expect(AffiliateLink::query()->count())->toBe(1);
    expect($analytics->summaryForUser($user)['visits'])->toBe(1);
    expect($analytics->summaryForLink($analytics->findLinkForUser($user, $link->id))['signups'])->toBe(1);
});
