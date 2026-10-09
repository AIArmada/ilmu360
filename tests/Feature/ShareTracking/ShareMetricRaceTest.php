<?php

declare(strict_types=1);

// R1 real-driver proof: actual ingestion races (duplicate outcomes,
// concurrent shares, concurrent A-B landings) on the isolated
// PostgreSQL 17 and MariaDB 11.4 fixtures, with the metric reader and
// cohort authority asserting exact end states on each driver. If the
// receipt-first freeze, the outbound-share commit, the frozen
// visitor_key union, or the per-driver cohort window math were wrong,
// these tests fail. Timing is never asserted.
//
// Guarded test-only worker databases: loopback isolated ports/databases
// only. Never the live application database (ilmu360 / 127.0.0.1:5432).

use AIArmada\Affiliates\Enums\FraudSeverity;
use AIArmada\Affiliates\Enums\FraudSignalStatus;
use AIArmada\Affiliates\Models\Affiliate;
use AIArmada\Affiliates\Models\AffiliateAttribution;
use AIArmada\Affiliates\Models\AffiliateConversion;
use AIArmada\Affiliates\Models\AffiliateFraudSignal;
use AIArmada\Affiliates\Models\AffiliateTouchpoint;
use App\Actions\ShareTracking\ClaimGuestShareProfileAction;
use App\Enums\DawahShareOutcomeType;
use App\Models\ShareSourceAttributionReceipt;
use App\Models\User;
use App\Services\ShareTrackingService;
use App\Support\Share\ShareCohortAuthority;
use App\Support\Share\ShareMetricReader;
use App\Support\ShareTracking\ShareOutcomeBusinessKey;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Assert;
use Tests\Feature\ShareTracking\ShareMetricRaceHarness;
use Tests\TestCase;

function smrIngestionRaceCase(TestCase $testCase, string $driver): void
{
    [$database, $previous] = ShareMetricRaceHarness::worker($driver, 'shrmetric');

    $previousReportingStart = config('dawah-share.reporting.reporting_start');
    config(['dawah-share.reporting.reporting_start' => '2026-01-01']);

    try {
        $pageUrl = route('events.index');
        $signupAt = CarbonImmutable::now('UTC')->subDays(45)->startOfDay()->addHours(10);
        $factAt = $signupAt->addDays(5);

        $member = User::factory()->create([
            'email' => 'smr-member@example.test',
            'created_at' => $signupAt,
        ]);
        $actor = User::factory()->create(['email' => 'smr-actor@example.test']);

        $linkA = ShareMetricRaceHarness::link($member, $pageUrl);

        $landingCookie = ShareMetricRaceHarness::landing($pageUrl.'?share='.$linkA->custom_slug);

        Assert::assertIsString($landingCookie);

        // Race 1: two workers record the SAME outbound share token at
        // once. Shares are committed facts, not idempotent intents: both
        // commit, and the reader counts both.
        ShareMetricRaceHarness::runRace([
            'driver' => $driver,
            'database' => $database,
            'app_url' => (string) config('app.url'),
            'mode' => 'share',
            'payload' => [
                'tracking_token' => $linkA->custom_slug,
                'slots' => [
                    ['user_id' => (string) $member->getKey(), 'provider' => 'copy_link'],
                    ['user_id' => (string) $member->getKey(), 'provider' => 'whatsapp'],
                ],
            ],
        ]);

        // Race 2: two workers record the SAME outcome business key with
        // the same landing cookie. Exactly one attributed decision
        // freezes; the replay follows the winner. Signup-typed: its
        // source record (users) exists in the minimal worker schema on
        // every driver, so the real reward-evaluation listener runs
        // unmodified instead of being faked away.
        $businessKey = ShareOutcomeBusinessKey::signup((string) $actor->getKey());

        ShareMetricRaceHarness::runRace([
            'driver' => $driver,
            'database' => $database,
            'app_url' => (string) config('app.url'),
            'mode' => 'outcome',
            'payload' => [
                'business_key' => $businessKey,
                'type' => DawahShareOutcomeType::Signup->value,
                'actor_id' => (string) $actor->getKey(),
                'cookies' => [$landingCookie, $landingCookie],
            ],
        ]);

        // Race 3: the same browser lands on the same link with two new
        // channels at once. Both frozen landing rows commit (channel
        // switches reuse the deduped visit window by design); the mutable
        // selection resolves to exactly one winner, and the historical
        // browser union stays one.
        ShareMetricRaceHarness::runRace([
            'driver' => $driver,
            'database' => $database,
            'app_url' => (string) config('app.url'),
            'mode' => 'landing',
            'payload' => [
                'slots' => [
                    ['url' => $pageUrl.'?share='.$linkA->custom_slug.'&channel=whatsapp', 'cookie' => $landingCookie],
                    ['url' => $pageUrl.'?share='.$linkA->custom_slug.'&channel=telegram', 'cookie' => $landingCookie],
                ],
            ],
        ]);

        // Claimed guest history (parent leg): a pre-claim guest share on
        // this member's future claim joins the member scope.
        $guest = ShareMetricRaceHarness::guestPayload($pageUrl);
        $guestRequest = Request::create($pageUrl, 'POST');
        $guestRequest->request->set(
            (string) config('dawah-share.guest_credential.input', 'guest_credential'),
            (string) $guest['guest_credential']
        );
        app(ShareTrackingService::class)->recordShareAction(
            'copy_link', null, (string) $guest['tracking_token'], $guestRequest,
        );
        app(ClaimGuestShareProfileAction::class)->handle(
            $member, (string) $guest['guest_claim_capability'], true,
        );

        // Window the raced facts into the mature signup window. Rows were
        // written by real ingestion; only the clock is controlled.
        $factString = $factAt->format('Y-m-d H:i:s');
        AffiliateTouchpoint::query()->update(['touched_at' => $factString]);
        AffiliateConversion::query()->update(['occurred_at' => $factString, 'approved_at' => $factString]);
        AffiliateAttribution::query()->where('attribution_type', 'landing')->update(['first_seen_at' => $factString]);
        ShareSourceAttributionReceipt::query()->update(['occurred_at' => $factString]);

        expect(AffiliateTouchpoint::query()->where('touchpoint_type', 'outbound_share')->count())->toBe(3)
            ->and(AffiliateConversion::query()->count())->toBe(1)
            ->and(ShareSourceAttributionReceipt::query()->count())->toBe(1);

        $summary = (new ShareMetricReader)->summarize(
            'UTC',
            $signupAt->subDay(),
            CarbonImmutable::now('UTC'),
            $member,
        );

        // 2 raced member shares + 1 claimed guest share; 1 raced outcome;
        // 1 browser across 3 landings (setup + 2 raced channel switches)
        // and 1 visit (channel switches reuse the deduped visit window).
        expect($summary['status'])->toBe('active')
            ->and($summary['totals']['recorded_shares'])->toBe(3)
            ->and($summary['totals']['recorded_visits'])->toBe(1)
            ->and(AffiliateAttribution::query()->where('attribution_type', 'landing')->count())->toBe(3)
            ->and($summary['totals']['distinct_sharing_browsers'])->toBe(1)
            ->and($summary['totals']['accepted_participations'])->toBe(1)
            ->and($summary['by_category']['valid'])->toBe(1)
            ->and($summary['by_day'][$factAt->toDateString()] ?? 0)->toBe(3);

        $cohorts = (new ShareCohortAuthority)->summarize(CarbonImmutable::now('UTC'), 12);
        $week = collect($cohorts['cohorts'])->firstWhere('cohort_week', $signupAt->startOfWeek()->toDateString());

        // The raced first-shares count the member once; the raced outcome
        // is their accepted participation. No signup conversion exists
        // for this member, so they stay outside the attributed sample:
        // the funnel never invents a signup stage.
        expect($cohorts['status'])->toBe('active')
            ->and($week)->not->toBeNull()
            ->and($week['mature'])->toBeTrue()
            ->and($week['denominator'])->toBe(1)
            ->and($week['attributed_signups'])->toBe(0)
            ->and($week['first_shares'])->toBe(1)
            ->and($week['participations'])->toBe(1);
    } finally {
        config(['dawah-share.reporting.reporting_start' => $previousReportingStart]);
        ShareMetricRaceHarness::teardown($driver, $database, $previous);
    }
}

function smrCohortBoundaryRaceCase(TestCase $testCase, string $driver): void
{
    [$database, $previous] = ShareMetricRaceHarness::worker($driver, 'shrcohort');

    $previousReportingStart = config('dawah-share.reporting.reporting_start');
    config(['dawah-share.reporting.reporting_start' => '2026-01-01']);

    try {
        $pageUrl = route('events.index');
        $signupAt = CarbonImmutable::parse('2026-05-12T10:00:00Z');
        $edgeAt = CarbonImmutable::parse('2026-06-11T10:00:00Z');

        $member = User::factory()->create([
            'email' => 'smr-edge@example.test',
            'created_at' => $signupAt,
        ]);
        $founder = User::factory()->create([
            'email' => 'smr-edge-founder@example.test',
            'created_at' => CarbonImmutable::parse('2026-04-01T10:00:00Z'),
        ]);

        // Attributed signup evidence through the real recordSignup path.
        $founderLink = ShareMetricRaceHarness::link($founder, $pageUrl);
        $signupCookie = ShareMetricRaceHarness::landing($pageUrl.'?share='.$founderLink->custom_slug);
        $signupRequest = Request::create($pageUrl, 'GET');
        $signupRequest->cookies->set((string) config('dawah-share.cookie.name'), (string) $signupCookie);
        app(ShareTrackingService::class)->recordSignup($member, $signupRequest);

        $link = ShareMetricRaceHarness::link($member, $pageUrl);
        $landingCookie = ShareMetricRaceHarness::landing($pageUrl.'?share='.$link->custom_slug);

        // Two workers race the member's first shares at the 30-day edge.
        // Both commit; the cohort counts the member once.
        ShareMetricRaceHarness::runRace([
            'driver' => $driver,
            'database' => $database,
            'app_url' => (string) config('app.url'),
            'mode' => 'share',
            'payload' => [
                'tracking_token' => $link->custom_slug,
                'slots' => [
                    ['user_id' => (string) $member->getKey(), 'provider' => 'copy_link'],
                    ['user_id' => (string) $member->getKey(), 'provider' => 'telegram'],
                ],
            ],
        ]);

        // Two workers race the same downstream outcome at the edge.
        // Exactly one conversion commits. Signup-typed so the source
        // record resolves inside the minimal worker schema on every
        // driver (see Race 2 above).
        $edgeActor = User::factory()->create(['email' => 'smr-edge-actor2@example.test']);
        $businessKey = ShareOutcomeBusinessKey::signup((string) $edgeActor->getKey());

        ShareMetricRaceHarness::runRace([
            'driver' => $driver,
            'database' => $database,
            'app_url' => (string) config('app.url'),
            'mode' => 'outcome',
            'payload' => [
                'business_key' => $businessKey,
                'type' => DawahShareOutcomeType::Signup->value,
                'actor_id' => (string) $edgeActor->getKey(),
                'cookies' => [$landingCookie, $landingCookie],
            ],
        ]);

        // Pin the raced facts exactly at signup+30d: inside the window.
        $edgeString = $edgeAt->format('Y-m-d H:i:s');
        $memberAffiliateIds = Affiliate::query()
            ->where('owner_type', $member->getMorphClass())
            ->where('owner_id', $member->getKey())
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->all();
        AffiliateTouchpoint::query()->whereIn('affiliate_id', $memberAffiliateIds)->update(['touched_at' => $edgeString]);
        AffiliateConversion::query()
            ->where('idempotency_key', hash('sha256', $businessKey))
            ->update(['occurred_at' => $edgeString, 'approved_at' => $edgeString]);

        // A second member whose only participation carries CONFIRMED
        // fraud: excluded from participations, listed under fraud.
        $fraudMember = User::factory()->create([
            'email' => 'smr-fraud@example.test',
            'created_at' => CarbonImmutable::parse('2026-05-12T11:00:00Z'),
        ]);
        $fraudLink = ShareMetricRaceHarness::link($fraudMember, $pageUrl);
        $fraudCookie = ShareMetricRaceHarness::landing($pageUrl.'?share='.$fraudLink->custom_slug);
        $fraudRequest = Request::create($pageUrl, 'GET');
        $fraudRequest->cookies->set((string) config('dawah-share.cookie.name'), (string) $fraudCookie);
        $fraudActor = User::factory()->create(['email' => 'smr-fraud-actor@example.test']);
        $fraudKey = ShareOutcomeBusinessKey::signup((string) $fraudActor->getKey());
        app(ShareTrackingService::class)->recordOutcome(
            DawahShareOutcomeType::Signup,
            $fraudKey,
            null,
            $fraudActor,
            $fraudRequest,
            [],
            CarbonImmutable::parse('2026-05-20T11:00:00Z'),
        );
        $fraudConversion = AffiliateConversion::query()
            ->where('idempotency_key', hash('sha256', $fraudKey))
            ->firstOrFail();
        AffiliateFraudSignal::query()->create([
            'affiliate_id' => $fraudConversion->affiliate_id,
            'conversion_id' => $fraudConversion->getKey(),
            'rule_code' => 'SMR_CONFIRMED',
            'risk_points' => 95,
            'severity' => FraudSeverity::High,
            'description' => 'Race fraud probe',
            'status' => FraudSignalStatus::Confirmed,
            'detected_at' => CarbonImmutable::parse('2026-05-21T11:00:00Z'),
            'reviewed_at' => CarbonImmutable::parse('2026-05-22T11:00:00Z'),
            'reviewed_by' => (string) $founder->getKey(),
            'confirmed_at' => CarbonImmutable::parse('2026-05-22T11:00:00Z'),
        ]);

        $report = (new ShareCohortAuthority)->summarize(CarbonImmutable::parse('2026-06-17T00:00:00Z'), 6);
        $week = collect($report['cohorts'])->firstWhere('cohort_week', '2026-05-11');

        expect($report['status'])->toBe('active')
            ->and($week)->not->toBeNull()
            ->and($week['mature'])->toBeTrue()
            ->and($week['denominator'])->toBe(2)
            ->and($week['attributed_signups'])->toBe(1)
            ->and($week['unknown'])->toBe(1)
            ->and($week['first_shares'])->toBe(1)
            ->and($week['participations'])->toBe(1)
            ->and($week['fraud_excluded'])->toBe(1)
            ->and($week['reversed_excluded'])->toBe(0);

        // Metric reader on the same driver: edge facts inside the window.
        $summary = (new ShareMetricReader)->summarize(
            'UTC',
            CarbonImmutable::parse('2026-05-01'),
            CarbonImmutable::parse('2026-06-30'),
            $member,
        );

        expect($summary['totals']['recorded_shares'])->toBe(2)
            ->and($summary['totals']['accepted_participations'])->toBe(1)
            ->and($summary['by_day']['2026-06-11'] ?? 0)->toBe(2);
    } finally {
        config(['dawah-share.reporting.reporting_start' => $previousReportingStart]);
        ShareMetricRaceHarness::teardown($driver, $database, $previous);
    }
}

foreach (['pgsql', 'mariadb'] as $smrDriver) {
    $label = $smrDriver === 'pgsql' ? 'isolated PostgreSQL' : 'isolated MariaDB';

    it("keeps raced ingestion exact for the metric reader on {$label}", function () use ($smrDriver): void {
        smrIngestionRaceCase($this, $smrDriver);
    });

    it("holds the 30-day cohort boundary under raced ingestion on {$label}", function () use ($smrDriver): void {
        smrCohortBoundaryRaceCase($this, $smrDriver);
    });
}
