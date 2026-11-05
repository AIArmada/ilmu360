<?php

use AIArmada\Affiliates\Actions\Identity\RevokeAffiliateClaim;
use AIArmada\Affiliates\Models\Affiliate;
use AIArmada\Affiliates\Models\AffiliateAttribution;
use AIArmada\Affiliates\Models\AffiliateClaim;
use AIArmada\Affiliates\Models\AffiliateConversion;
use AIArmada\Affiliates\Models\AffiliateLink;
use AIArmada\Affiliates\Models\AffiliateTouchpoint;
use App\Actions\ShareTracking\ClaimGuestShareProfileAction;
use App\Enums\ShareOutcomeType;
use App\Models\Event;
use App\Models\Institution;
use App\Models\User;
use App\Services\ShareTrackingAnalyticsService;
use App\Services\ShareTrackingService;
use App\Support\Share\ShareMetricReader;
use App\Support\Share\ShareProfileScope;
use App\Support\ShareTracking\ShareTrackingProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

beforeEach(function (): void {
    config(['share-tracking.reporting.reporting_start' => '2026-11-01T00:00:00Z']);
});

function dakwahReconciliationLink(User $user, string $url, string $title = 'Shared page'): AffiliateLink
{
    $data = app(ShareTrackingService::class)->createOrReuseLink($user, $url, $title);

    return AffiliateLink::query()->withoutGlobalScope('affiliate_owner')->findOrFail($data->id);
}

function dakwahReconciliationTouchpoint(AffiliateLink $link, string $kind, string $browser, ?string $channel, string $at, string $visitKind = 'landing'): AffiliateTouchpoint
{
    $attribution = AffiliateAttribution::query()->create([
        'affiliate_id' => $link->affiliate_id,
        'affiliate_code' => $link->affiliate->code,
        'affiliate_link_id' => $link->id,
        'subject_type' => $link->subject_type,
        'subject_key' => $link->subject_key,
        'subject_title_snapshot' => $link->subject_title_snapshot,
        'attribution_type' => $kind === 'outbound_share' ? 'outbound_share' : 'landing',
        'visitor_key' => $browser,
        'channel' => $channel,
        'cookie_value' => (string) Str::uuid(),
        'landing_url' => $link->destination_url,
        'first_seen_at' => $at,
        'last_seen_at' => $at,
    ]);

    return AffiliateTouchpoint::query()->create([
        'affiliate_id' => $link->affiliate_id,
        'affiliate_code' => $attribution->affiliate_code,
        'affiliate_attribution_id' => $attribution->id,
        'affiliate_link_id' => $link->id,
        'subject_type' => $link->subject_type,
        'subject_key' => $link->subject_key,
        'subject_title_snapshot' => $link->subject_title_snapshot,
        'touchpoint_type' => $kind,
        'visitor_key' => $browser,
        'channel' => $channel,
        'metadata' => ['visit_kind' => $visitKind],
        'touched_at' => $at,
    ]);
}

function dakwahReconciliationConversion(AffiliateLink $link, string $type, string $status = 'qualified', ?string $channel = 'whatsapp', string $at = '2026-11-05T08:00:00Z'): AffiliateConversion
{
    return AffiliateConversion::query()->create([
        'affiliate_id' => $link->affiliate_id,
        'affiliate_code' => $link->affiliate->code,
        'affiliate_link_id' => $link->id,
        'subject_type' => $link->subject_type,
        'subject_key' => $link->subject_key,
        'subject_title_snapshot' => $link->subject_title_snapshot,
        'conversion_type' => $type,
        'idempotency_key' => hash('sha256', (string) Str::uuid()),
        'commission_currency' => 'MYR',
        'commission_minor' => 0,
        'value_minor' => 0,
        'subtotal_minor' => 0,
        'status' => $status,
        'channel' => $channel,
        'occurred_at' => $at,
    ]);
}

test('all additive dakwah counts reconcile across summaries links subjects channels and the canonical reader', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-05T12:00:00Z'));
    $user = User::factory()->create();
    $event = Event::factory()->create(['status' => 'approved', 'visibility' => 'public']);
    $institution = Institution::factory()->create(['status' => 'verified']);
    $eventLink = dakwahReconciliationLink($user, route('events.show', $event), 'Shared event');
    $institutionLink = dakwahReconciliationLink($user, route('institutions.show', $institution), 'Shared institution');
    dakwahReconciliationLink($user, route('persons.index'), 'Prepared only');

    dakwahReconciliationTouchpoint($eventLink, 'outbound_share', 'sharer-browser', 'whatsapp', '2026-11-05T07:00:00Z');
    dakwahReconciliationTouchpoint($eventLink, 'outbound_share', 'sharer-browser', 'telegram', '2026-11-05T07:05:00Z');
    dakwahReconciliationTouchpoint($institutionLink, 'outbound_share', 'sharer-browser', 'telegram', '2026-11-05T07:10:00Z');
    dakwahReconciliationTouchpoint($eventLink, 'visit', 'shared-browser', 'whatsapp', '2026-11-05T07:15:00Z');
    dakwahReconciliationTouchpoint($eventLink, 'visit', 'shared-browser', 'telegram', '2026-11-05T07:20:00Z', 'return');
    dakwahReconciliationTouchpoint($eventLink, 'visit', 'event-only-browser', 'whatsapp', '2026-11-05T07:25:00Z');
    dakwahReconciliationTouchpoint($institutionLink, 'visit', 'shared-browser', 'telegram', '2026-11-05T07:30:00Z');
    dakwahReconciliationTouchpoint($institutionLink, 'visit', 'institution-only-browser', null, '2026-11-05T07:35:00Z');

    $expectedOutcomes = [
        'signup' => 1,
        'event_registration' => 1,
        'event_checkin' => 1,
        'event_submission' => 1,
        'event_save' => 1,
        'event_going' => 1,
        'institution_follow' => 1,
        'person_follow' => 1,
        'series_follow' => 1,
        'reference_follow' => 1,
        'saved_search_created' => 1,
    ];

    foreach (array_keys($expectedOutcomes) as $index => $type) {
        dakwahReconciliationConversion($eventLink, $type, ['qualified', 'approved', 'paid'][$index % 3]);
    }

    dakwahReconciliationConversion($institutionLink, 'signup', 'approved', null);
    dakwahReconciliationConversion($eventLink, 'event_registration', 'pending');
    dakwahReconciliationConversion($eventLink, 'event_registration', 'rejected');
    dakwahReconciliationConversion($eventLink, 'event_registration', 'reversed');
    $analytics = app(ShareTrackingAnalyticsService::class);

    expect($analytics->summaryForUser($user))->toBe([
        'outbound_shares' => 3,
        'visits' => 5,
        'distinct_browsers' => 3,
        'signups' => 2,
        'event_registrations' => 1,
        'event_checkins' => 1,
        'event_submissions' => 1,
        'total_outcomes' => 12,
    ]);

    $library = $analytics->linksForUser($user);
    expect($library)->toHaveCount(3);
    expect($library->sum('outboundShares'))->toBe(3);
    expect($library->sum('visitsCount'))->toBe(5);
    expect($library->sum('outcomesCount'))->toBe(12);
    expect($library->sum('signupsCount'))->toBe(2);
    expect($library->sum('eventRegistrationsCount'))->toBe(1);
    expect($library->sum('eventCheckinsCount'))->toBe(1);
    expect($library->sum('eventSubmissionsCount'))->toBe(1);

    $subjects = $analytics->subjectSummariesForUser($user);
    expect($subjects->sum('links'))->toBe(3);
    expect($subjects->sum('visits'))->toBe(5);
    expect($subjects->sum('total_outcomes'))->toBe(12);
    expect($subjects->sum('signups'))->toBe(2);
    expect($subjects->sum('event_registrations'))->toBe(1);
    expect($subjects->sum('event_checkins'))->toBe(1);
    expect($subjects->sum('event_submissions'))->toBe(1);
    expect($analytics->topSubjectsForUser($user)->first())->toMatchArray(['subject_type' => 'event', 'visits' => 3, 'total_outcomes' => 11]);

    $channels = $analytics->providerBreakdownForUser($user);
    expect($channels->sum('outbound_shares'))->toBe(3);
    expect($channels->sum('visits'))->toBe(5);
    expect($channels->sum('outcomes'))->toBe(12);
    expect($channels->sum('signups'))->toBe(2);
    expect($channels->sum('event_registrations'))->toBe(1);
    expect($channels->sum('event_checkins'))->toBe(1);
    expect($channels->sum('event_submissions'))->toBe(1);
    expect($channels->firstWhere('provider', 'whatsapp'))->toMatchArray(['visits' => 2, 'distinct_browsers' => 2, 'outcomes' => 11]);
    expect($channels->firstWhere('provider', 'telegram'))->toMatchArray(['visits' => 2, 'distinct_browsers' => 1, 'outcomes' => 0]);
    expect($channels->firstWhere('provider', 'direct'))->toMatchArray(['visits' => 1, 'distinct_browsers' => 1, 'outcomes' => 1]);

    $link = $analytics->findLinkForUser($user, $eventLink->id);
    expect($analytics->summaryForLink($link))->toBe([
        'outbound_shares' => 2,
        'visits' => 3,
        'distinct_browsers' => 2,
        'outcomes' => 11,
        'signups' => 1,
        'event_registrations' => 1,
        'event_checkins' => 1,
        'event_submissions' => 1,
    ]);
    expect($analytics->outcomeBreakdownForLink($link)->pluck('count', 'outcome_type')->all())->toEqual($expectedOutcomes);
    expect($analytics->recentVisitsForLink($link))->toHaveCount(3);
    expect($analytics->recentOutcomesForLink($link))->toHaveCount(11);
    expect($analytics->recentOutcomesForUser($user))->toHaveCount(12);
    expect($analytics->providerBreakdownForLink($link)->sum('outcomes'))->toBe(11);
    expect($analytics->providerBreakdownForLink($link)->sum('visits'))->toBe(3);
    expect($link->lastSharedAt->toIso8601String())->toBe('2026-11-05T07:05:00+00:00');
    $activity = $analytics->activityWindowForLink($link);
    expect($activity['first_seen_at']->toIso8601String())->toBe('2026-11-05T07:15:00+00:00');
    expect($activity['last_visit_at']->toIso8601String())->toBe('2026-11-05T07:25:00+00:00');
    expect($activity['last_outcome_at']->toIso8601String())->toBe('2026-11-05T08:00:00+00:00');
    expect($activity['latest_activity_at']->toIso8601String())->toBe('2026-11-05T08:00:00+00:00');
    $daily = $analytics->dailyPerformanceForLink($link, 14, 'UTC');
    expect($daily)->toHaveCount(14);
    expect($daily->firstWhere('date', '2026-11-05'))->toMatchArray(['visits' => 3, 'outcomes' => 11, 'signups' => 1, 'event_registrations' => 1, 'event_checkins' => 1, 'event_submissions' => 1]);
    expect($daily->sum('visits'))->toBe(3);
    expect($daily->sum('outcomes'))->toBe(11);

    $canonical = (new ShareMetricReader)->summarize('UTC', CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-05'), $user);
    expect($canonical['totals'])->toMatchArray(['recorded_shares' => 3, 'recorded_visits' => 5, 'distinct_sharing_browsers' => 3, 'accepted_participations' => 12]);
    expect($canonical['by_category'])->toMatchArray(['valid' => 12, 'pending_unknown' => 1, 'rejected' => 1, 'reversed' => 1]);
    expect(array_keys($expectedOutcomes))->toEqualCanonicalizing(array_column(ShareOutcomeType::cases(), 'value'));
});

test('a reversed conversion disappears consistently without deleting the historical source fact', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-05T12:00:00Z'));
    $user = User::factory()->create();
    $link = dakwahReconciliationLink($user, route('events.index'));
    $conversion = dakwahReconciliationConversion($link, 'event_registration', 'approved');
    $analytics = app(ShareTrackingAnalyticsService::class);
    expect($analytics->summaryForUser($user)['event_registrations'])->toBe(1);

    $conversion->forceFill(['status' => 'reversed', 'reversed_at' => now()])->save();

    expect($analytics->summaryForUser($user)['total_outcomes'])->toBe(0);
    expect($analytics->linksForUser($user)->first()->outcomesCount)->toBe(0);
    expect($analytics->linksForUser($user, outcomeType: 'event_registration'))->toBeEmpty();
    expect($analytics->subjectSummariesForUser($user)->sum('total_outcomes'))->toBe(0);
    expect($analytics->providerBreakdownForUser($user))->toBeEmpty();
    expect($analytics->recentOutcomesForUser($user))->toBeEmpty();
    expect($analytics->outcomeBreakdownForLink($analytics->findLinkForUser($user, $link->id)))->toBeEmpty();
    $this->assertDatabaseHas((new AffiliateConversion)->getTable(), ['id' => $conversion->id, 'status' => 'reversed']);
});

test('reporting start includes the exact boundary and excludes earlier and future dated facts', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-05T12:00:00Z'));
    $user = User::factory()->create();
    $link = dakwahReconciliationLink($user, route('events.index'));

    foreach (['2026-10-31T23:59:59Z', '2026-11-01T00:00:00Z', '2026-11-06T08:00:00Z'] as $index => $at) {
        dakwahReconciliationTouchpoint($link, 'outbound_share', 'sharer', 'whatsapp', $at);
        dakwahReconciliationTouchpoint($link, 'visit', "browser-{$index}", 'whatsapp', $at);
        dakwahReconciliationConversion($link, 'signup', 'qualified', 'whatsapp', $at);
    }

    $analytics = app(ShareTrackingAnalyticsService::class);
    expect($analytics->summaryForUser($user))->toMatchArray(['outbound_shares' => 1, 'visits' => 1, 'distinct_browsers' => 1, 'signups' => 1, 'total_outcomes' => 1]);
    expect($analytics->linksForUser($user)->first()->outcomesCount)->toBe(1);
    expect($analytics->providerBreakdownForUser($user)->sum('visits'))->toBe(1);
    expect($analytics->recentOutcomesForUser($user))->toHaveCount(1);
    $canonical = (new ShareMetricReader)->summarize('UTC', CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-05'), $user);
    expect($canonical['totals'])->toMatchArray(['recorded_shares' => 1, 'recorded_visits' => 1, 'distinct_sharing_browsers' => 1, 'accepted_participations' => 1]);
});

test('member metrics exclude another member and commercial facts but include deactivated historical shares', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-05T12:00:00Z'));
    $user = User::factory()->create();
    $other = User::factory()->create();
    $link = dakwahReconciliationLink($user, route('events.index'));
    $otherLink = dakwahReconciliationLink($other, route('events.index'));
    $commercial = Affiliate::query()->create(['code' => 'commercial-'.Str::random(8), 'name' => 'Commercial profile', 'status' => 'active', 'currency' => 'MYR']);
    $commercial->forceFill(['owner_type' => $user->getMorphClass(), 'owner_id' => $user->id])->save();
    $commercialLink = AffiliateLink::query()->create(['affiliate_id' => $commercial->id, 'destination_url' => route('events.index'), 'tracking_url' => route('events.index'), 'subject_type' => 'page', 'subject_key' => 'commercial-page']);

    foreach ([$link, $otherLink, $commercialLink] as $candidate) {
        dakwahReconciliationTouchpoint($candidate, 'visit', 'browser-'.$candidate->id, 'whatsapp', '2026-11-05T08:00:00Z');
        dakwahReconciliationConversion($candidate, 'signup');
    }

    $link->update(['deactivated_at' => now()]);
    $analytics = app(ShareTrackingAnalyticsService::class);

    expect($analytics->summaryForUser($user))->toMatchArray(['visits' => 1, 'distinct_browsers' => 1, 'signups' => 1, 'total_outcomes' => 1]);
    expect($analytics->linksForUser($user)->pluck('id')->all())->toBe([$link->id]);
    expect($analytics->findLinkForUser($user, $otherLink->id))->toBeNull();
    expect($analytics->findLinkForUser($user, $commercialLink->id))->toBeNull();
    expect($analytics->providerBreakdownForUser($user)->sum('outcomes'))->toBe(1);
});

test('actively claimed guest totals combine with member totals and detach on claim revocation', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-05T12:00:00Z'));
    $user = User::factory()->create();
    $memberLink = dakwahReconciliationLink($user, route('events.index'));
    $guest = Affiliate::query()->create([
        'code' => 'guest-'.Str::random(8),
        'name' => 'Guest profile',
        'status' => 'active',
        'purpose' => ShareTrackingProfile::PURPOSE_SHARE_GUEST,
        'currency' => 'MYR',
        'metadata' => ShareTrackingProfile::guestMetadata(),
    ]);
    $guestLink = AffiliateLink::query()->create(['affiliate_id' => $guest->id, 'destination_url' => route('events.index'), 'tracking_url' => route('events.index'), 'subject_type' => 'page', 'subject_key' => 'claimed-guest-page']);
    $claim = AffiliateClaim::query()->create(['affiliate_id' => $guest->id, 'claimant_type' => $user->getMorphClass(), 'claimant_id' => $user->id, 'status' => 'active', 'claimed_at' => now()]);

    foreach ([$memberLink, $guestLink] as $candidate) {
        dakwahReconciliationTouchpoint($candidate, 'outbound_share', 'sharer', 'whatsapp', '2026-11-05T07:00:00Z');
        dakwahReconciliationTouchpoint($candidate, 'visit', 'same-recipient-browser', 'whatsapp', '2026-11-05T08:00:00Z');
        dakwahReconciliationConversion($candidate, 'signup');
    }

    $analytics = app(ShareTrackingAnalyticsService::class);
    expect($analytics->summaryForUser($user))->toMatchArray(['outbound_shares' => 2, 'visits' => 2, 'distinct_browsers' => 1, 'signups' => 2, 'total_outcomes' => 2]);
    expect($analytics->linksForUser($user))->toHaveCount(2);

    app(RevokeAffiliateClaim::class)->handle($claim);

    expect($analytics->summaryForUser($user))->toMatchArray(['outbound_shares' => 1, 'visits' => 1, 'distinct_browsers' => 1, 'signups' => 1, 'total_outcomes' => 1]);
    expect($analytics->linksForUser($user)->pluck('id')->all())->toBe([$memberLink->id]);
    expect($analytics->findLinkForUser($user, $guestLink->id))->toBeNull();
    expect($analytics->providerBreakdownForUser($user)->sum('outcomes'))->toBe(1);
});

test('daily visits and outcomes use the full 25 hour viewer day through the autumn clock change', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-05T12:00:00Z'));
    $user = User::factory()->create();
    $link = dakwahReconciliationLink($user, route('events.index'));

    foreach ([
        '2026-11-01T03:59:59Z',
        '2026-11-01T04:00:00Z',
        '2026-11-01T05:30:00Z',
        '2026-11-01T06:30:00Z',
        '2026-11-02T04:59:59Z',
        '2026-11-02T05:00:00Z',
    ] as $index => $at) {
        dakwahReconciliationTouchpoint($link, 'visit', "dst-browser-{$index}", 'whatsapp', $at);
        dakwahReconciliationConversion($link, 'signup', 'qualified', 'whatsapp', $at);
    }

    $analytics = app(ShareTrackingAnalyticsService::class);
    $daily = $analytics->dailyPerformanceForLink($analytics->findLinkForUser($user, $link->id), 14, 'America/New_York');

    expect($daily->firstWhere('date', '2026-10-31'))->toMatchArray(['visits' => 1, 'signups' => 1, 'outcomes' => 1]);
    expect($daily->firstWhere('date', '2026-11-01'))->toMatchArray(['visits' => 4, 'signups' => 4, 'outcomes' => 4]);
    expect($daily->firstWhere('date', '2026-11-02'))->toMatchArray(['visits' => 1, 'signups' => 1, 'outcomes' => 1]);
    expect($daily->sum('visits'))->toBe(6);
    expect($daily->sum('outcomes'))->toBe(6);
    expect($analytics->summaryForUser($user))->toMatchArray(['visits' => 6, 'signups' => 6, 'total_outcomes' => 6]);
});

test('viewing claimed guest link analytics never prepares another link or changes the member profile', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-05T12:00:00Z'));
    $user = User::factory()->create();
    $guest = $this->getJson(route('share.payload', [
        'url' => route('persons.index'),
        'title' => 'Guest shared speakers',
        'text' => 'Useful speakers',
    ]))->assertSuccessful()->json();
    app(ClaimGuestShareProfileAction::class)->handle($user, $guest['guest_claim_capability'], true);
    $link = AffiliateLink::query()->withoutGlobalScope('affiliate_owner')->firstOrFail();
    $memberProfileId = ShareProfileScope::primaryAffiliateId($user);
    expect($memberProfileId)->not->toBeNull();
    expect(app(ShareTrackingAnalyticsService::class)->linksForUser($user))->toHaveCount(1);

    $this->actingAs($user)->get(route('dakwah.links.show', ['link' => $link->id]))->assertSuccessful();

    expect(ShareProfileScope::primaryAffiliateId($user))->toBe($memberProfileId);
    expect(app(ShareTrackingAnalyticsService::class)->linksForUser($user)->pluck('id')->all())->toBe([$link->id]);
    $this->assertDatabaseCount((new AffiliateLink)->getTable(), 1);
});

test('historical responses without a surviving link remain in totals but not page breakdowns', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-05T12:00:00Z'));
    $user = User::factory()->create();
    $link = dakwahReconciliationLink($user, route('events.index'));
    $conversion = dakwahReconciliationConversion($link, 'signup');
    $conversion->forceFill(['affiliate_link_id' => null])->save();
    $analytics = app(ShareTrackingAnalyticsService::class);

    expect($analytics->summaryForUser($user)['signups'])->toBe(1);
    expect($analytics->providerBreakdownForUser($user)->sum('signups'))->toBe(1);
    expect($analytics->linksForUser($user)->sum('signupsCount'))->toBe(0);
    expect($analytics->subjectSummariesForUser($user)->sum('signups'))->toBe(0);
    expect($analytics->recentOutcomesForUser($user))->toHaveCount(1);
});
