<?php

declare(strict_types=1);

// R1 proof: ShareCohortAuthority implements the landing → signup →
// first COMMITTED outbound share → accepted participation funnel with
// a fixed 30-day PER-SIGNUP maturation. Weeks mature only once their
// last signup matured; post-window and future events never count;
// only CONFIRMED fraud excludes (corrections are not fraud). Every
// fact below is written by real production ingestion.

use AIArmada\Affiliates\Enums\FraudSeverity;
use AIArmada\Affiliates\Enums\FraudSignalStatus;
use AIArmada\Affiliates\Models\AffiliateConversion;
use AIArmada\Affiliates\Models\AffiliateFraudSignal;
use App\Actions\ShareTracking\ClaimGuestShareProfileAction;
use App\Actions\ShareTracking\ReverseShareOutcomeAction;
use App\Enums\DawahShareOutcomeType;
use App\Models\Event;
use App\Models\User;
use App\Services\ShareTrackingService;
use App\Support\Share\ShareCohortAuthority;
use App\Support\ShareTracking\ShareOutcomeBusinessKey;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
    ]);

    config(['dawah-share.reporting.reporting_start' => '2026-05-01']);
});

function scoPayload(TestCase $testCase, ?User $user, string $url): array
{
    $call = $user instanceof User ? $testCase->actingAs($user) : $testCase;

    return $call->getJson(route('dawah-share.payload', [
        'url' => $url,
        'text' => 'Share this page',
        'title' => 'Shared Page',
    ]))->assertOk()->json();
}

function scoLandingCookie(TestCase $testCase, string $sharedUrl): string
{
    $cookie = $testCase->followingRedirects()->get($sharedUrl)->assertOk()
        ->getCookie((string) config('dawah-share.cookie.name'));

    expect($cookie)->not->toBeNull();

    return (string) $cookie?->getValue();
}

function scoSignup(TestCase $testCase, User $sharer, string $name, string $email, CarbonImmutable $at): User
{
    Carbon::setTestNow(Carbon::parse($at->toIso8601String()));

    $user = User::factory()->create([
        'name' => $name,
        'email' => $email,
        'created_at' => $at,
    ]);

    $payload = scoPayload($testCase, $sharer, route('events.show', $testCase->event));
    $cookie = scoLandingCookie($testCase, (string) $payload['url']);

    $request = Request::create('/register', 'POST');
    $request->cookies->set((string) config('dawah-share.cookie.name'), $cookie);

    $outcome = app(ShareTrackingService::class)->recordSignup($user, $request);

    Carbon::setTestNow();

    expect($outcome)->not->toBeNull();

    return $user;
}

function scoFirstShare(TestCase $testCase, User $user, CarbonImmutable $at): void
{
    $payload = scoPayload($testCase, $user, route('events.show', $testCase->event));

    Carbon::setTestNow(Carbon::parse($at->toIso8601String()));

    $testCase->actingAs($user)->postJson(route('dawah-share.track'), [
        'provider' => 'copy_link',
        'tracking_token' => (string) $payload['tracking_token'],
    ])->assertNoContent();

    Carbon::setTestNow();
}

/**
 * @return array{conversion: AffiliateConversion, business_key: string}
 */
function scoParticipation(TestCase $testCase, User $sharer, CarbonImmutable $at): array
{
    $payload = scoPayload($testCase, $sharer, route('events.show', $testCase->event));

    Carbon::setTestNow(Carbon::parse($at->toIso8601String()));
    $cookie = scoLandingCookie($testCase, (string) $payload['url']);
    Carbon::setTestNow();

    $actor = User::factory()->create();
    $key = ShareOutcomeBusinessKey::eventRegistration((string) Str::uuid());

    Carbon::setTestNow(Carbon::parse($at->toIso8601String()));

    $request = Request::create('/noop', 'GET');
    $request->cookies->set((string) config('dawah-share.cookie.name'), $cookie);

    app(ShareTrackingService::class)->recordOutcome(
        DawahShareOutcomeType::EventRegistration,
        $key,
        null,
        $actor,
        $request,
        [],
        $at,
    );

    Carbon::setTestNow();

    return [
        'conversion' => AffiliateConversion::query()->where('idempotency_key', hash('sha256', $key))->firstOrFail(),
        'business_key' => $key,
    ];
}

function scoMatureWeek(TestCase $testCase): array
{
    $founder = User::factory()->create(['created_at' => CarbonImmutable::parse('2026-04-01T10:00:00Z')]);

    // Mature week May 11–17 (Monday start). asOf Jun 17: week end
    // May 17 23:59:59 + 30d = Jun 16 23:59:59 <= asOf → mature.
    $u1 = scoSignup($testCase, $founder, 'Cohort One', 'cohort-1@example.test', CarbonImmutable::parse('2026-05-11T10:00:00Z'));
    scoFirstShare($testCase, $u1, CarbonImmutable::parse('2026-05-15T10:00:00Z'));
    scoParticipation($testCase, $u1, CarbonImmutable::parse('2026-05-20T10:00:00Z'));

    // Week-end signup gets its own full 30 days, not the week clock.
    $u2 = scoSignup($testCase, $founder, 'Cohort Two', 'cohort-2@example.test', CarbonImmutable::parse('2026-05-17T23:00:00Z'));
    scoFirstShare($testCase, $u2, CarbonImmutable::parse('2026-06-16T22:00:00Z'));
    scoParticipation($testCase, $u2, CarbonImmutable::parse('2026-06-16T22:30:00Z'));

    // Exact 30-day edge: share AT signup+30d counts.
    $u3 = scoSignup($testCase, $founder, 'Cohort Three', 'cohort-3@example.test', CarbonImmutable::parse('2026-05-12T10:00:00Z'));
    scoFirstShare($testCase, $u3, CarbonImmutable::parse('2026-06-11T10:00:00Z'));

    // One second past the window: excluded.
    $u4 = scoSignup($testCase, $founder, 'Cohort Four', 'cohort-4@example.test', CarbonImmutable::parse('2026-05-12T11:00:00Z'));
    scoFirstShare($testCase, $u4, CarbonImmutable::parse('2026-06-11T11:00:01Z'));

    // First share in window, participation after it: no participation.
    $u5 = scoSignup($testCase, $founder, 'Cohort Five', 'cohort-5@example.test', CarbonImmutable::parse('2026-05-12T12:00:00Z'));
    scoFirstShare($testCase, $u5, CarbonImmutable::parse('2026-05-20T12:00:00Z'));
    scoParticipation($testCase, $u5, CarbonImmutable::parse('2026-06-12T12:00:01Z'));

    // Reversed without fraud: corrected, not fraud, not participating.
    $u6 = scoSignup($testCase, $founder, 'Cohort Six', 'cohort-6@example.test', CarbonImmutable::parse('2026-05-12T13:00:00Z'));
    scoFirstShare($testCase, $u6, CarbonImmutable::parse('2026-05-20T13:00:00Z'));
    $reversedParticipation = scoParticipation($testCase, $u6, CarbonImmutable::parse('2026-05-21T13:00:00Z'));
    $correction = app(ReverseShareOutcomeAction::class)->handle(
        $reversedParticipation['business_key'],
        'source-corrected',
        $founder,
    );
    expect($correction)->not->toBeNull();

    // Confirmed fraud: excluded from participations, listed as fraud.
    $u7 = scoSignup($testCase, $founder, 'Cohort Seven', 'cohort-7@example.test', CarbonImmutable::parse('2026-05-12T14:00:00Z'));
    scoFirstShare($testCase, $u7, CarbonImmutable::parse('2026-05-20T14:00:00Z'));
    $fraudConversion = scoParticipation($testCase, $u7, CarbonImmutable::parse('2026-05-21T14:00:00Z'))['conversion'];
    AffiliateFraudSignal::query()->create([
        'affiliate_id' => $fraudConversion->affiliate_id,
        'conversion_id' => $fraudConversion->getKey(),
        'rule_code' => 'SCO_CONFIRMED',
        'risk_points' => 90,
        'severity' => FraudSeverity::High,
        'description' => 'Confirmed funnel fraud probe',
        'status' => FraudSignalStatus::Confirmed,
        'detected_at' => CarbonImmutable::parse('2026-05-22T14:00:00Z'),
        'reviewed_at' => CarbonImmutable::parse('2026-05-23T14:00:00Z'),
        'reviewed_by' => (string) $founder->getKey(),
        'confirmed_at' => CarbonImmutable::parse('2026-05-23T14:00:00Z'),
    ]);

    // Unattributed signup: no landing, no conversion → unknown.
    $u8 = User::factory()->create([
        'name' => 'Cohort Eight',
        'email' => 'cohort-8@example.test',
        'created_at' => CarbonImmutable::parse('2026-05-12T15:00:00Z'),
    ]);

    // Claimed guest history inside the window counts as first share.
    $u10 = scoSignup($testCase, $founder, 'Cohort Ten', 'cohort-10@example.test', CarbonImmutable::parse('2026-05-12T16:00:00Z'));
    auth()->logout();
    $guest = scoPayload($testCase, null, route('events.show', $testCase->event));
    Carbon::setTestNow(Carbon::parse('2026-05-20T16:00:00Z'));
    $testCase->postJson(route('dawah-share.track'), [
        'provider' => 'copy_link',
        'tracking_token' => (string) $guest['tracking_token'],
        (string) config('dawah-share.guest_credential.input') => (string) $guest['guest_credential'],
    ])->assertNoContent();
    Carbon::setTestNow();
    app(ClaimGuestShareProfileAction::class)->handle($u10, (string) $guest['guest_claim_capability'], true);

    // Dismissed fraud is not fraud: still participating.
    $u11 = scoSignup($testCase, $founder, 'Cohort Eleven', 'cohort-11@example.test', CarbonImmutable::parse('2026-05-12T17:00:00Z'));
    scoFirstShare($testCase, $u11, CarbonImmutable::parse('2026-05-20T17:00:00Z'));
    $cleared = scoParticipation($testCase, $u11, CarbonImmutable::parse('2026-05-21T17:00:00Z'))['conversion'];
    AffiliateFraudSignal::query()->create([
        'affiliate_id' => $cleared->affiliate_id,
        'conversion_id' => $cleared->getKey(),
        'rule_code' => 'SCO_DISMISSED',
        'risk_points' => 10,
        'severity' => FraudSeverity::Low,
        'description' => 'Dismissed funnel probe',
        'status' => FraudSignalStatus::Dismissed,
        'detected_at' => CarbonImmutable::parse('2026-05-22T17:00:00Z'),
        'reviewed_at' => CarbonImmutable::parse('2026-05-23T17:00:00Z'),
        'reviewed_by' => (string) $founder->getKey(),
        'dismissed_at' => CarbonImmutable::parse('2026-05-23T17:00:00Z'),
    ]);

    // Immature week Jun 8–14: a complete funnel stays pending.
    $late = scoSignup($testCase, $founder, 'Cohort Late', 'cohort-late@example.test', CarbonImmutable::parse('2026-06-10T10:00:00Z'));
    scoFirstShare($testCase, $late, CarbonImmutable::parse('2026-06-12T10:00:00Z'));
    scoParticipation($testCase, $late, CarbonImmutable::parse('2026-06-13T10:00:00Z'));

    return [$u1, $u2, $u3, $u4, $u5, $u6, $u7, $u8, $u10, $u11, $late];
}

it('reports the mature funnel with per-signup 30-day windows', function (): void {
    scoMatureWeek($this);

    $report = (new ShareCohortAuthority)->summarize(CarbonImmutable::parse('2026-06-17T00:00:00Z'), 6);

    expect($report['status'])->toBe('active')
        ->and($report['maturity_days'])->toBe(30);

    $week = collect($report['cohorts'])->firstWhere('cohort_week', '2026-05-11');

    expect($week)->not->toBeNull()
        ->and($week['mature'])->toBeTrue()
        ->and($week['denominator'])->toBe(10)
        ->and($week['attributed_signups'])->toBe(9)
        ->and($week['unknown'])->toBe(1)
        ->and($week['pending'])->toBe(0)
        ->and($week['first_shares'])->toBe(8)
        ->and($week['first_share_rate'])->toBe(0.8)
        ->and($week['participations'])->toBe(3)
        ->and($week['participation_rate'])->toBe(0.3)
        ->and($week['fraud_excluded'])->toBe(1)
        ->and($week['reversed_excluded'])->toBe(1);
});

it('holds immature weeks pending without rates', function (): void {
    scoMatureWeek($this);

    $report = (new ShareCohortAuthority)->summarize(CarbonImmutable::parse('2026-06-17T00:00:00Z'), 6);

    $week = collect($report['cohorts'])->firstWhere('cohort_week', '2026-06-08');

    expect($week)->not->toBeNull()
        ->and($week['mature'])->toBeFalse()
        ->and($week['denominator'])->toBe(1)
        ->and($week['pending'])->toBe(1)
        ->and($week['first_shares'])->toBeNull()
        ->and($week['first_share_rate'])->toBeNull()
        ->and($week['participations'])->toBeNull()
        ->and($week['participation_rate'])->toBeNull();
});

it('refuses to mature a week on the week-start clock', function (): void {
    scoMatureWeek($this);

    // Jun 16 noon: the old week_start+30d rule (May 11 + 30d = Jun 10)
    // would call this week mature, but its Sunday signups mature only
    // at Jun 16 23:59:59 + ... → still immature at noon.
    $report = (new ShareCohortAuthority)->summarize(CarbonImmutable::parse('2026-06-16T12:00:00Z'), 6);

    $week = collect($report['cohorts'])->firstWhere('cohort_week', '2026-05-11');

    expect($week)->not->toBeNull()
        ->and($week['mature'])->toBeFalse()
        ->and($week['pending'])->toBe(10)
        ->and($week['first_share_rate'])->toBeNull()
        ->and($week['participation_rate'])->toBeNull();
});

it('withholds cohorts before the reporting start', function (): void {
    config(['dawah-share.reporting.reporting_start' => null]);

    $report = (new ShareCohortAuthority)->summarize(CarbonImmutable::parse('2026-06-17T00:00:00Z'), 6);

    expect($report['status'])->toBe('not_configured')
        ->and($report['cohorts'])->toBe([]);
});
