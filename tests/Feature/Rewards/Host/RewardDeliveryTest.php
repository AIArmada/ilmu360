<?php

declare(strict_types=1);

use AIArmada\Affiliates\Models\Affiliate;
use AIArmada\Affiliates\Models\AffiliateConversion;
use AIArmada\Affiliates\Models\AffiliateTouchpoint;
use AIArmada\Signals\Contracts\SignalEventIngestor;
use AIArmada\Signals\Models\SignalEvent;
use AIArmada\Signals\Models\TrackedProperty;
use App\Actions\Rewards\DispatchDueRewardDigestsAction;
use App\Actions\Rewards\PlanRewardGratitudeDigestAction;
use App\Actions\Share\PublishCommunityReleaseAction;
use App\Models\Event;
use App\Models\RewardDigestSlot;
use App\Models\ShareCohortProjection;
use App\Models\User;
use App\Support\Share\CommittedShareSignalProducer;
use App\Support\Share\ShareCohortAuthority;
use App\Support\Share\ShareMetricReader;
use App\Support\ShareTracking\ShareTrackingMeasurement;
use App\Support\ShareTracking\ShareTrackingProfile;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

function deliveryAffiliate(string $code, array $overrides = []): Affiliate
{
    return Affiliate::query()->create(array_merge([
        'code' => $code,
        'handle' => $code,
        'name' => 'Delivery test affiliate',
        'status' => 'active',
    ], $overrides));
}

function deliveryConversion(Affiliate $affiliate, array $overrides = []): AffiliateConversion
{
    return AffiliateConversion::query()->create(array_merge([
        'affiliate_id' => $affiliate->getKey(),
        'affiliate_code' => $affiliate->code,
        'commission_currency' => 'MYR',
        'status' => 'approved',
        'channel' => 'link',
        'conversion_type' => 'event_checkin',
        'occurred_at' => CarbonImmutable::parse('2026-05-10T00:00:00Z'),
    ], $overrides));
}

function deliveryCommittedShare(object $testCase, User $user, CarbonImmutable $at): AffiliateTouchpoint
{
    $event = Event::factory()->create(['status' => 'approved', 'visibility' => 'public']);

    $payload = $testCase->actingAs($user)->getJson(route('dawah-share.payload', [
        'url' => route('events.show', $event),
        'text' => 'Share this page',
        'title' => 'Shared Page',
    ]))->assertOk()->json();

    Carbon::setTestNow(Carbon::parse($at->toIso8601String()));
    $testCase->actingAs($user)->postJson(route('dawah-share.track'), [
        'provider' => 'copy_link',
        'tracking_token' => (string) $payload['tracking_token'],
    ])->assertNoContent();
    Carbon::setTestNow();

    return AffiliateTouchpoint::query()->where('touchpoint_type', 'outbound_share')->latest('touched_at')->firstOrFail();
}

it('summarizes share metrics with viewer-local bounds and freshness', function () {
    // Canonical R1 contract coverage lives in ShareMetricReaderTest /
    // ShareCohortAuthorityTest (real ingestion, hand ledger, A-B-A,
    // claimed guest, categories, viewer-local boundary, 30-day edges).
    // This pins the delivery-surface shape: status, canonical version,
    // scope, and privacy (no actor ids).
    config(['dawah-share.reporting.reporting_start' => '2026-05-01']);

    $sharer = User::factory()->create();

    $summary = (new ShareMetricReader)->summarize(
        'Asia/Kuala_Lumpur',
        CarbonImmutable::parse('2026-05-01'),
        CarbonImmutable::parse('2026-05-31'),
        $sharer,
    );

    expect($summary['status'])->toBe('active')
        ->and($summary['definition_version'])->toBe(ShareTrackingMeasurement::definitionVersion())
        ->and($summary['scope'])->toBe('member')
        ->and($summary['timezone'])->toBe('Asia/Kuala_Lumpur')
        ->and($summary['totals']['recorded_shares'])->toBe(0)
        ->and($summary['by_category']['unattributed_observed'])->toBeNull()
        ->and($summary['freshness']['read_at'])->not->toBeNull()
        ->and(array_keys($summary))->not->toContain('actor_ids');

    $cohorts = (new ShareCohortAuthority)->summarize(CarbonImmutable::parse('2026-06-17T00:00:00Z'), 1);

    expect($cohorts['status'])->toBe('active')
        ->and($cohorts['maturity_days'])->toBe(ShareCohortAuthority::MATURITY_DAYS)
        ->and($cohorts['cohorts'])->toHaveCount(1);

    expect(fn () => (new ShareMetricReader)->summarize('Mars/Olympus', CarbonImmutable::parse('2026-05-01'), CarbonImmutable::parse('2026-05-31')))
        ->toThrow(InvalidArgumentException::class, 'timezone');

    expect(fn () => (new ShareMetricReader)->summarize('UTC', CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-12-31')))
        ->toThrow(InvalidArgumentException::class, 'bound');
});

it('drains committed-share signals to projections through the operations command', function () {
    // Canonical producer/reader proof lives in CommittedShareSignalsTest
    // (real package ingest, semantic keys, dispatcher isolation, outage
    // survival). This pins the delivery/operations surface: the scheduled
    // drain command projecting the durable log exactly once.
    config(['dawah-share.reporting.reporting_start' => '2026-05-01']);

    $touchpoint = deliveryCommittedShare($this, User::factory()->create(), CarbonImmutable::parse('2026-05-10T08:00:00Z'));

    expect(SignalEvent::query()->where('event_name', CommittedShareSignalProducer::EVENT_NAME)->count())->toBe(1);

    expect(Artisan::call('signals:drain-committed-shares'))->toBe(0)
        ->and(Artisan::output())->toContain('Signals processed: 1; projected 1; failed 0.');

    $projection = ShareCohortProjection::query()
        ->where('business_key', 'committed-share:'.$touchpoint->getKey())
        ->firstOrFail();

    expect($projection->outcome)->toBe('committed_share')
        ->and($projection->stage)->toBe(ShareCohortProjection::STAGE_OBSERVED);

    // A quiet drain is a verified no-op: nothing double-applies.
    expect(Artisan::call('signals:drain-committed-shares'))->toBe(0)
        ->and(Artisan::output())->toContain('Signals processed: 0; projected 0; failed 0.')
        ->and(ShareCohortProjection::query()->count())->toBe(1);
});

it('resumes interrupted drains and skips poison signals through the operations command', function () {
    config(['dawah-share.reporting.reporting_start' => '2026-05-01']);

    $sharer = User::factory()->create();

    deliveryCommittedShare($this, $sharer, CarbonImmutable::parse('2026-05-10T08:00:00Z'));
    deliveryCommittedShare($this, $sharer, CarbonImmutable::parse('2026-05-11T08:00:00Z'));

    // Partial drain, as if the process died after one event; the next
    // scheduled run resumes exactly after the durable cursor.
    expect(Artisan::call('signals:drain-committed-shares', ['--batch' => 1]))->toBe(0)
        ->and(Artisan::output())->toContain('Signals processed: 1; projected 1; failed 0.')
        ->and(ShareCohortProjection::query()->count())->toBe(1);

    $property = TrackedProperty::query()->where('slug', 'ilmu360')->firstOrFail();

    app(SignalEventIngestor::class)->handle($property, [
        'event_name' => CommittedShareSignalProducer::EVENT_NAME,
        'event_category' => CommittedShareSignalProducer::EVENT_CATEGORY,
        'source_event_id' => 'garbage-without-prefix',
        'idempotency_key' => 'garbage-without-prefix',
        'occurred_at' => CarbonImmutable::parse('2026-05-12T08:00:00Z')->toIso8601String(),
        'properties' => [],
    ], true);

    // Poison is skipped loudly without wedging the pipeline; the
    // command still succeeds and the retained log keeps the evidence.
    expect(Artisan::call('signals:drain-committed-shares'))->toBe(0)
        ->and(Artisan::output())->toContain('Signals processed: 2; projected 1; failed 1.')
        ->and(ShareCohortProjection::query()->count())->toBe(2)
        ->and(SignalEvent::query()->where('event_name', CommittedShareSignalProducer::EVENT_NAME)->count())->toBe(3);

    expect(Artisan::call('signals:drain-committed-shares'))->toBe(0)
        ->and(Artisan::output())->toContain('Signals processed: 0; projected 0; failed 0.');
});

it('reports cohorts with fixed maturity, pending unknowns, and fraud separation', function () {
    // Canonical funnel coverage (per-signup windows, edges, fraud vs
    // reversal) lives in ShareCohortAuthorityTest with real ingestion.
    // This pins delivery-surface maturity/pending behavior.
    config(['dawah-share.reporting.reporting_start' => '2026-01-01']);

    User::factory()->create(['created_at' => CarbonImmutable::parse('2026-04-06T00:00:00Z')]);
    User::factory()->create(['created_at' => CarbonImmutable::now('UTC')->startOfWeek()->addDay()]);

    $summary = (new ShareCohortAuthority)->summarize(CarbonImmutable::now('UTC'), 30);

    expect($summary['status'])->toBe('active')
        ->and($summary['maturity_days'])->toBe(30);

    $oldWeek = collect($summary['cohorts'])->firstWhere('cohort_week', '2026-04-06');

    expect($oldWeek['mature'])->toBeTrue()
        ->and($oldWeek['denominator'])->toBe(1)
        ->and($oldWeek['attributed_signups'])->toBe(0)
        ->and($oldWeek['unknown'])->toBe(1)
        ->and($oldWeek['pending'])->toBe(0)
        ->and($oldWeek['first_shares'])->toBe(0)
        ->and($oldWeek['first_share_rate'])->toBe(0.0)
        ->and($oldWeek['participations'])->toBe(0)
        ->and($oldWeek['fraud_excluded'])->toBe(0)
        ->and($oldWeek['reversed_excluded'])->toBe(0);

    $newWeek = collect($summary['cohorts'])->firstWhere('cohort_week', CarbonImmutable::now('UTC')->startOfWeek()->toDateString());

    expect($newWeek['mature'])->toBeFalse()
        ->and($newWeek['first_share_rate'])->toBeNull()
        ->and($newWeek['participation_rate'])->toBeNull()
        ->and($newWeek['pending'])->toBeGreaterThanOrEqual(1);
});

it('plans one digest slot per recipient day and dispatches after recheck', function () {
    $user = User::factory()->create();

    $slot = PlanRewardGratitudeDigestAction::run('user', (string) $user->getKey());

    expect($slot->status)->toBe(RewardDigestSlot::STATUS_PLANNED)
        ->and($slot->plan_key)->toBe('gratitude:'.(string) $user->getKey().':'.$slot->local_day);

    // Own-slot-aware: planning again merges into the stored slot, so
    // the single daily send covers every planned section.
    $replay = PlanRewardGratitudeDigestAction::run('user', (string) $user->getKey(), ['awards', 'badges']);

    expect((string) $replay->getKey())->toBe((string) $slot->getKey())
        ->and($replay->categories)->toBe(['awards', 'badges'])
        ->and(array_keys($replay->payload['sections'] ?? []))->toBe(['awards', 'badges']);

    // Nothing to thank: an empty window cancels instead of sending.
    $outcome = DispatchDueRewardDigestsAction::run(50, $slot->due_at->addMinute());

    expect($outcome['cancelled'])->toBe(1)
        ->and($slot->refresh()->status)->toBe(RewardDigestSlot::STATUS_CANCELLED);
});

it('publishes community releases behind dual thresholds with suppression', function () {
    setPermissionsTeamId(null);
    $this->seed(RoleSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $affiliate = deliveryAffiliate('community-aff-1', [
        'metadata' => ShareTrackingProfile::memberMetadata(),
    ]);
    $staff = User::factory()->create();
    $staff->assignRole('admin');

    for ($i = 0; $i < 10; $i++) {
        deliveryConversion($affiliate, [
            'sharer_user_id' => (string) User::factory()->create()->getKey(),
            'actor_user_id' => (string) User::factory()->create()->getKey(),
            'status' => 'approved',
            'channel' => 'link',
            'conversion_type' => 'event_checkin',
            'occurred_at' => CarbonImmutable::parse('2026-05-10T00:00:00Z'),
        ]);
    }

    // Recent months refuse: releases are delayed micro-proof-free.
    expect(fn () => PublishCommunityReleaseAction::run(
        '2026-05', 'user', (string) $staff->getKey(), CarbonImmutable::parse('2026-06-01T00:00:00Z'),
    ))->toThrow(InvalidArgumentException::class, 'at least 7 days ago');

    // Sparse cells suppress; the release still publishes coarse totals.
    $release = PublishCommunityReleaseAction::run(
        '2026-05', 'user', (string) $staff->getKey(), CarbonImmutable::parse('2026-06-10T00:00:00Z'),
    );

    expect($release->sharer_count)->toBe(10)
        ->and($release->participant_count)->toBe(10)
        ->and($release->cells['total_outcomes'])->toBe(10)
        ->and($release->cells['by_channel'])->toBe(['link' => 10]);

    // Below-threshold months refuse with honest insufficient-data.
    expect(fn () => PublishCommunityReleaseAction::run(
        '2026-04', 'user', (string) $staff->getKey(), CarbonImmutable::parse('2026-06-10T00:00:00Z'),
    ))->toThrow(InvalidArgumentException::class, 'Insufficient data');

    // Releases are immutable.
    expect(fn () => $release->forceFill(['sharer_count' => 99])->save())
        ->toThrow(LogicException::class, 'immutable');
});

it('serves impact, cohort, and community endpoints with correct authorization', function () {
    setPermissionsTeamId(null);
    $this->seed(RoleSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    config(['dawah-share.reporting.reporting_start' => '2026-05-01']);

    $member = User::factory()->create();
    $staff = User::factory()->create();
    $staff->assignRole('admin');

    Sanctum::actingAs($member);

    $this->getJson('/api/v1/rewards/impact?from=2026-05-01&to=2026-05-31')->assertOk()
        ->assertJsonPath('data.definition_version', ShareTrackingMeasurement::definitionVersion())
        ->assertJsonPath('data.status', 'active');

    $this->getJson('/api/v1/rewards/cohort')->assertForbidden();

    Sanctum::actingAs($staff);

    $this->getJson('/api/v1/rewards/cohort?weeks=4')->assertOk()
        ->assertJsonPath('data.maturity_days', 30);

    $this->getJson('/api/v1/community/release')->assertOk()
        ->assertJsonPath('data.status', 'insufficient-data');
});
