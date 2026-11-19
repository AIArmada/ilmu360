<?php

declare(strict_types=1);

use AIArmada\Affiliates\Models\AffiliateTouchpoint;
use AIArmada\Signals\Contracts\SignalEventIngestor;
use AIArmada\Signals\Models\SignalEvent;
use AIArmada\Signals\Models\TrackedProperty;
use App\Features\Communications\Api\AccountNotificationPreferences;
use App\Features\Events\Models\Event;
use App\Features\Rewards\Actions\GratitudeDigests\DispatchDueRewardDigestsAction;
use App\Features\Rewards\Actions\GratitudeDigests\PlanRewardGratitudeDigestAction;
use App\Features\Rewards\Models\RewardDigestSlot;
use App\Features\ShareTracking\Models\ShareCohortProjection;
use App\Features\ShareTracking\Queries\ShareMetricReader;
use App\Features\ShareTracking\Services\CommittedShareSignalProducer;
use App\Features\ShareTracking\Services\ShareCohortAuthority;
use App\Models\User;
use App\Shared\ShareTracking\ShareTrackingMeasurement;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

function deliveryCommittedShare(object $testCase, User $user, CarbonImmutable $at): AffiliateTouchpoint
{
    $event = Event::factory()->create(['status' => 'approved', 'visibility' => 'public']);

    $payload = $testCase->actingAs($user)->getJson(route('share.payload', [
        'url' => route('events.show', $event),
        'text' => 'Share this page',
        'title' => 'Shared Page',
    ]))->assertOk()->json();

    Carbon::setTestNow(Carbon::parse($at->toIso8601String()));
    $testCase->actingAs($user)->postJson(route('share.track'), [
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
    config(['share-tracking.reporting.reporting_start' => '2026-05-01']);

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
        ->and($cohorts['cohorts'])->toHaveCount(1)
        ->and(fn () => (new ShareMetricReader)->summarize('Mars/Olympus', CarbonImmutable::parse('2026-05-01'), CarbonImmutable::parse('2026-05-31')))->toThrow(InvalidArgumentException::class, 'timezone')
        ->and(fn () => (new ShareMetricReader)->summarize('UTC', CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-12-31')))->toThrow(InvalidArgumentException::class, 'bound');
});

it('drains committed-share signals to projections through the operations command', function () {
    // Canonical producer/reader proof lives in CommittedShareSignalsTest
    // (real package ingest, semantic keys, dispatcher isolation, outage
    // survival). This pins the delivery/operations surface: the scheduled
    // drain command projecting the durable log exactly once.
    config(['share-tracking.reporting.reporting_start' => '2026-05-01']);

    $touchpoint = deliveryCommittedShare($this, User::factory()->create(), CarbonImmutable::parse('2026-05-10T08:00:00Z'));

    expect(SignalEvent::query()->where('event_name', CommittedShareSignalProducer::EVENT_NAME)->count())->toBe(1)
        ->and(Artisan::call('signals:drain-committed-shares'))->toBe(0)
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
    config(['share-tracking.reporting.reporting_start' => '2026-05-01']);

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
    config(['share-tracking.reporting.reporting_start' => '2026-01-01']);

    $oldWeekStart = CarbonImmutable::now('UTC')->startOfWeek()->subWeeks(8);
    User::factory()->create(['created_at' => $oldWeekStart]);
    User::factory()->create(['created_at' => CarbonImmutable::now('UTC')->startOfWeek()->addDay()]);

    $summary = (new ShareCohortAuthority)->summarize(CarbonImmutable::now('UTC'), 30);

    expect($summary['status'])->toBe('active')
        ->and($summary['maturity_days'])->toBe(30);

    $oldWeek = collect($summary['cohorts'])->firstWhere('cohort_week', $oldWeekStart->toDateString());

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

    app(AccountNotificationPreferences::class)->save((string) $user->getKey(), [
        'settings' => [],
        'families' => ['sharing' => ['enabled' => true, 'cadence' => 'daily']],
        'triggers' => ['share_gratitude_digest' => ['enabled' => true]],
    ]);

    $slot = PlanRewardGratitudeDigestAction::run('user', (string) $user->getKey());

    expect($slot->status)->toBe(RewardDigestSlot::STATUS_PLANNED)
        ->and($slot->plan_key)->toBe('gratitude:'.$user->getKey().':'.$slot->local_day);

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

it('serves impact, cohort, and community endpoints with correct authorization', function () {
    setPermissionsTeamId(null);
    $this->seed(RoleSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    config(['share-tracking.reporting.reporting_start' => '2026-05-01']);

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
