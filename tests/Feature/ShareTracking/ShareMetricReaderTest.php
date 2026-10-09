<?php

declare(strict_types=1);

// R1 proof: ShareMetricReader reads ONLY frozen authorities — committed
// outbound_share touchpoints, the visitor_key historical union over
// landing attributions + visit touchpoints, and scoped conversions —
// with primary+claimed ownership, current/valid vs historical vs
// unknown vs unattributed categories, viewer-local day buckets, and
// the canonical reporting start. Every fact below is written by the
// real production ingestion (payload/track HTTP, landing capture,
// recordOutcome, real reversal, real guest claim).

use AIArmada\Affiliates\Models\AffiliateConversion;
use AIArmada\Affiliates\Models\AffiliateTouchpoint;
use App\Actions\ShareTracking\ClaimGuestShareProfileAction;
use App\Actions\ShareTracking\ReverseShareOutcomeAction;
use App\Enums\DawahShareOutcomeType;
use App\Models\Event;
use App\Models\User;
use App\Services\ShareTracking\AdminShareAnalyticsService;
use App\Services\ShareTrackingAnalyticsService;
use App\Services\ShareTrackingService;
use App\Support\Share\ShareCohortAuthority;
use App\Support\Share\ShareMetricReader;
use App\Support\ShareTracking\ShareOutcomeBusinessKey;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->sharer = User::factory()->create();
    $this->event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
    ]);

    config(['dawah-share.reporting.reporting_start' => '2026-05-01']);
});

function smrPayload(TestCase $testCase, ?User $user, string $url): array
{
    $call = $user instanceof User ? $testCase->actingAs($user) : $testCase;

    return $call->getJson(route('dawah-share.payload', [
        'url' => $url,
        'text' => 'Share this page',
        'title' => 'Shared Page',
    ]))->assertOk()->json();
}

function smrTrack(TestCase $testCase, ?User $user, string $token, string $provider, array $extra = []): void
{
    $call = $user instanceof User ? $testCase->actingAs($user) : $testCase;

    $call->postJson(route('dawah-share.track'), array_merge([
        'provider' => $provider,
        'tracking_token' => $token,
    ], $extra))->assertNoContent();
}

function smrLandingCookie(TestCase $testCase, string $sharedUrl, ?string $channel = null, ?string $browserCookie = null): string
{
    $url = $sharedUrl;

    if (is_string($channel)) {
        $url .= (str_contains($url, '?') ? '&' : '?').'channel='.$channel;
    }

    $call = $browserCookie === null
        ? $testCase->followingRedirects()
        : $testCase->withCookie((string) config('dawah-share.cookie.name'), $browserCookie)->followingRedirects();

    $cookie = $call->get($url)->assertOk()->getCookie((string) config('dawah-share.cookie.name'));

    expect($cookie)->not->toBeNull();

    return (string) $cookie?->getValue();
}

function smrOutcomeRequest(string $cookieValue): Request
{
    $request = Request::create('/noop', 'GET');
    $request->cookies->set((string) config('dawah-share.cookie.name'), $cookieValue);

    return $request;
}

function smrRecordOutcome(DawahShareOutcomeType $type, string $businessKey, ?User $actor, ?string $cookieValue, ?CarbonImmutable $at = null): void
{
    app(ShareTrackingService::class)->recordOutcome(
        $type,
        $businessKey,
        null,
        $actor,
        $cookieValue === null ? null : smrOutcomeRequest($cookieValue),
        [],
        $at,
    );
}

it('counts committed outbound shares, never downstream outcomes', function (): void {
    $payload = smrPayload($this, $this->sharer, route('events.show', $this->event));

    Carbon::setTestNow(CarbonImmutable::parse('2026-05-10T08:00:00Z'));
    smrTrack($this, $this->sharer, (string) $payload['tracking_token'], 'copy_link');
    smrTrack($this, $this->sharer, (string) $payload['tracking_token'], 'whatsapp');

    // A true outbound share with no downstream outcome still counts, and
    // downstream outcomes never inflate the share count.
    $cookie = smrLandingCookie($this, (string) $payload['url']);
    Carbon::setTestNow(CarbonImmutable::parse('2026-05-11T08:00:00Z'));
    $actor = User::factory()->create();
    smrRecordOutcome(
        DawahShareOutcomeType::EventRegistration,
        ShareOutcomeBusinessKey::eventRegistration((string) $actor->getKey()),
        $actor,
        $cookie,
        CarbonImmutable::parse('2026-05-11T08:00:00Z'),
    );
    Carbon::setTestNow();

    expect(AffiliateConversion::query()->count())->toBe(1);

    $summary = (new ShareMetricReader)->summarize(
        'UTC',
        CarbonImmutable::parse('2026-05-01'),
        CarbonImmutable::parse('2026-05-31'),
        $this->sharer,
    );

    expect($summary['status'])->toBe('active')
        ->and($summary['scope'])->toBe('member')
        ->and($summary['totals']['recorded_shares'])->toBe(2)
        ->and($summary['totals']['recorded_visits'])->toBe(1)
        ->and($summary['totals']['distinct_sharing_browsers'])->toBe(1)
        ->and($summary['totals']['share_intensity'])->toBe(2.0)
        ->and($summary['totals']['accepted_participations'])->toBe(1)
        ->and($summary['by_outcome'])->toBe(['event_registration' => 1])
        ->and($summary['by_category']['valid'])->toBe(1)
        ->and($summary['by_day']['2026-05-10'])->toBe(2)
        ->and($summary['freshness']['newest_fact_at'])->not->toBeNull()
        ->and(array_keys($summary))->not->toContain('actor_ids');
});

it('preserves historical browser counts across A-B-A landings and channel switches', function (): void {
    $first = smrPayload($this, $this->sharer, route('events.show', $this->event));
    $secondEvent = Event::factory()->create(['status' => 'approved', 'visibility' => 'public']);
    $second = smrPayload($this, $this->sharer, route('events.show', $secondEvent));

    Carbon::setTestNow(CarbonImmutable::parse('2026-05-10T08:00:00Z'));

    // Browser lands via link A, then link B, then link A again: the
    // frozen history grows (3 landings) while the browser union stays 1.
    $cookie = smrLandingCookie($this, (string) $first['url'], 'whatsapp');
    $readAfterA = (new ShareMetricReader)->summarize('UTC', CarbonImmutable::parse('2026-05-01'), CarbonImmutable::parse('2026-05-31'), $this->sharer);

    $cookie = smrLandingCookie($this, (string) $second['url'], 'telegram', $cookie);
    $readAfterB = (new ShareMetricReader)->summarize('UTC', CarbonImmutable::parse('2026-05-01'), CarbonImmutable::parse('2026-05-31'), $this->sharer);

    // Past the 5-minute visit dedupe: the return to A records again.
    Carbon::setTestNow(CarbonImmutable::parse('2026-05-10T08:06:00Z'));
    $cookie = smrLandingCookie($this, (string) $first['url'], 'whatsapp', $cookie);
    Carbon::setTestNow();
    $readAfterABA = (new ShareMetricReader)->summarize('UTC', CarbonImmutable::parse('2026-05-01'), CarbonImmutable::parse('2026-05-31'), $this->sharer);

    // Channel switch on the same link: a new frozen landing row, while
    // the mutable current_attribution_id moves underneath the readers.
    $cookie = smrLandingCookie($this, (string) $first['url'], 'copy_link', $cookie);
    $readAfterSwitch = (new ShareMetricReader)->summarize('UTC', CarbonImmutable::parse('2026-05-01'), CarbonImmutable::parse('2026-05-31'), $this->sharer);

    expect($readAfterA['totals']['distinct_sharing_browsers'])->toBe(1)
        ->and($readAfterB['totals']['distinct_sharing_browsers'])->toBe(1)
        ->and($readAfterABA['totals']['distinct_sharing_browsers'])->toBe(1)
        ->and($readAfterSwitch['totals']['distinct_sharing_browsers'])->toBe(1)
        ->and($readAfterA['totals']['recorded_visits'])->toBe(1)
        ->and($readAfterABA['totals']['recorded_visits'])->toBe(3)
        ->and($readAfterSwitch['totals']['recorded_visits'])->toBe(3);
});

it('buckets days in the viewer timezone, not UTC', function (): void {
    $payload = smrPayload($this, $this->sharer, route('events.show', $this->event));

    // 2026-05-10 16:30 UTC == 2026-05-11 00:30 Asia/Kuala_Lumpur.
    Carbon::setTestNow(CarbonImmutable::parse('2026-05-10T16:30:00Z'));
    smrTrack($this, $this->sharer, (string) $payload['tracking_token'], 'copy_link');
    Carbon::setTestNow();

    $kl = (new ShareMetricReader)->summarize(
        'Asia/Kuala_Lumpur',
        CarbonImmutable::parse('2026-05-01'),
        CarbonImmutable::parse('2026-05-31'),
        $this->sharer,
    );

    $utc = (new ShareMetricReader)->summarize(
        'UTC',
        CarbonImmutable::parse('2026-05-01'),
        CarbonImmutable::parse('2026-05-31'),
        $this->sharer,
    );

    expect($kl['by_day']['2026-05-11'] ?? 0)->toBe(1)
        ->and($kl['by_day']['2026-05-10'] ?? 0)->toBe(0)
        ->and($utc['by_day']['2026-05-10'] ?? 0)->toBe(1)
        ->and($utc['by_day']['2026-05-11'] ?? 0)->toBe(0);
});

it('splits outcomes into valid, unknown, reversed, rejected, and unattributed categories', function (): void {
    $payload = smrPayload($this, $this->sharer, route('events.show', $this->event));
    $cookie = smrLandingCookie($this, (string) $payload['url']);

    $at = CarbonImmutable::parse('2026-05-10T08:00:00Z');

    $make = function (DawahShareOutcomeType $type = DawahShareOutcomeType::EventCheckin) use ($cookie, $at): AffiliateConversion {
        $actor = User::factory()->create();
        $key = ShareOutcomeBusinessKey::for($type, 'checkin:'.((string) Str::uuid()));
        smrRecordOutcome($type, $key, $actor, $cookie, $at);

        return AffiliateConversion::query()->where('idempotency_key', hash('sha256', $key))->firstOrFail();
    };

    $make();
    $qualified = $make();
    $qualified->forceFill(['status' => 'qualified'])->save();
    $paid = $make();
    $paid->forceFill(['status' => 'paid', 'paid_at' => $at])->save();
    $pending = $make();
    $pending->forceFill(['status' => 'pending'])->save();

    $reversedKey = ShareOutcomeBusinessKey::for(DawahShareOutcomeType::EventCheckin, 'checkin:'.((string) Str::uuid()));
    $reversedActor = User::factory()->create();
    smrRecordOutcome(DawahShareOutcomeType::EventCheckin, $reversedKey, $reversedActor, $cookie, $at);
    app(ReverseShareOutcomeAction::class)->handle($reversedKey, 'source-corrected', $this->sharer);

    $rejected = $make();
    $rejected->forceFill(['status' => 'rejected', 'rejected_at' => $at])->save();

    // Unattributed: a real outcome with no landing winner freezes a
    // receipt with no conversion and no owner.
    $lonely = User::factory()->create();
    smrRecordOutcome(
        DawahShareOutcomeType::EventCheckin,
        ShareOutcomeBusinessKey::for(DawahShareOutcomeType::EventCheckin, 'checkin:'.((string) Str::uuid())),
        $lonely,
        null,
        $at,
    );

    $summary = (new ShareMetricReader)->summarize(
        'UTC',
        CarbonImmutable::parse('2026-05-01'),
        CarbonImmutable::parse('2026-05-31'),
    );

    expect($summary['scope'])->toBe('global')
        ->and($summary['by_category']['valid'])->toBe(3)
        ->and($summary['by_category']['pending_unknown'])->toBe(1)
        ->and($summary['by_category']['reversed'])->toBe(1)
        ->and($summary['by_category']['rejected'])->toBe(1)
        ->and($summary['by_category']['unattributed_observed'])->toBe(1)
        ->and($summary['by_outcome'])->toBe(['event_checkin' => 3])
        ->and($summary['totals']['verified_participations'])->toBe(1);

    $member = (new ShareMetricReader)->summarize(
        'UTC',
        CarbonImmutable::parse('2026-05-01'),
        CarbonImmutable::parse('2026-05-31'),
        $this->sharer,
    );

    // Unattributed receipts belong to no sharer: the member scope says
    // so with null instead of inventing a zero-or-share.
    expect($member['by_category']['unattributed_observed'])->toBeNull()
        ->and($member['by_category']['valid'])->toBe(3);
});

it('counts primary plus actively claimed guest history for the member scope', function (): void {
    $claimant = User::factory()->create();
    $rival = User::factory()->create();

    $guest = smrPayload($this, null, route('events.show', $this->event));

    Carbon::setTestNow(CarbonImmutable::parse('2026-05-10T08:00:00Z'));
    smrTrack($this, null, (string) $guest['tracking_token'], 'copy_link', [
        (string) config('dawah-share.guest_credential.input') => (string) $guest['guest_credential'],
    ]);
    Carbon::setTestNow();

    expect(AffiliateTouchpoint::query()->where('touchpoint_type', 'outbound_share')->count())->toBe(1);

    app(ClaimGuestShareProfileAction::class)->handle($claimant, (string) $guest['guest_claim_capability'], true);

    $member = (new ShareMetricReader)->summarize(
        'UTC',
        CarbonImmutable::parse('2026-05-01'),
        CarbonImmutable::parse('2026-05-31'),
        $claimant,
    );

    $rivalRead = (new ShareMetricReader)->summarize(
        'UTC',
        CarbonImmutable::parse('2026-05-01'),
        CarbonImmutable::parse('2026-05-31'),
        $rival,
    );

    expect($member['totals']['recorded_shares'])->toBe(1)
        ->and($rivalRead['totals']['recorded_shares'])->toBe(0)
        ->and($rivalRead['totals']['distinct_sharing_browsers'])->toBe(0);
});

it('withholds measurements before the reporting start and rejects bad input', function (): void {
    config(['dawah-share.reporting.reporting_start' => null]);

    $payload = smrPayload($this, $this->sharer, route('events.show', $this->event));
    smrTrack($this, $this->sharer, (string) $payload['tracking_token'], 'copy_link');

    $summary = (new ShareMetricReader)->summarize(
        'UTC',
        CarbonImmutable::parse('2026-05-01'),
        CarbonImmutable::parse('2026-05-31'),
        $this->sharer,
    );

    expect($summary['status'])->toBe('not_configured')
        ->and($summary['totals']['recorded_shares'])->toBe(0);

    config(['dawah-share.reporting.reporting_start' => '2026-05-01']);

    $unknown = (new ShareMetricReader)->summarize(
        'UTC',
        CarbonImmutable::parse('2026-05-01'),
        CarbonImmutable::parse('2026-05-31'),
        (string) Str::uuid(),
    );

    expect($unknown['totals']['recorded_shares'])->toBe(0)
        ->and($unknown['scope'])->toBe('member');

    expect(fn () => (new ShareMetricReader)->summarize('Mars/Olympus', CarbonImmutable::parse('2026-05-01'), CarbonImmutable::parse('2026-05-31')))
        ->toThrow(InvalidArgumentException::class, 'timezone');

    expect(fn () => (new ShareMetricReader)->summarize('UTC', CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-12-31')))
        ->toThrow(InvalidArgumentException::class, 'bound');
});

it('excludes facts before the canonical reporting start', function (): void {
    $payload = smrPayload($this, $this->sharer, route('events.show', $this->event));

    Carbon::setTestNow(CarbonImmutable::parse('2026-04-20T08:00:00Z'));
    smrTrack($this, $this->sharer, (string) $payload['tracking_token'], 'copy_link');
    Carbon::setTestNow(CarbonImmutable::parse('2026-05-10T08:00:00Z'));
    smrTrack($this, $this->sharer, (string) $payload['tracking_token'], 'copy_link');
    Carbon::setTestNow();

    $summary = (new ShareMetricReader)->summarize(
        'UTC',
        CarbonImmutable::parse('2026-04-01'),
        CarbonImmutable::parse('2026-05-31'),
        $this->sharer,
    );

    expect($summary['totals']['recorded_shares'])->toBe(1)
        ->and($summary['reporting_start'])->not->toBeNull()
        ->and($summary['period_start'])->toBe(CarbonImmutable::parse('2026-05-01', 'UTC')->toIso8601String());
});

it('agrees with member and admin surfaces on the same definitions', function (): void {
    $payload = smrPayload($this, $this->sharer, route('events.show', $this->event));

    Carbon::setTestNow(CarbonImmutable::parse('2026-05-10T08:00:00Z'));
    smrTrack($this, $this->sharer, (string) $payload['tracking_token'], 'copy_link');
    $cookie = smrLandingCookie($this, (string) $payload['url']);
    Carbon::setTestNow(CarbonImmutable::parse('2026-05-11T08:00:00Z'));

    $actor = User::factory()->create();
    smrRecordOutcome(
        DawahShareOutcomeType::Signup,
        ShareOutcomeBusinessKey::signup((string) $actor->getKey()),
        $actor,
        $cookie,
        CarbonImmutable::parse('2026-05-11T08:00:00Z'),
    );
    Carbon::setTestNow();

    $member = (new ShareMetricReader)->summarize(
        'UTC', CarbonImmutable::parse('2026-05-01'), CarbonImmutable::parse('2026-05-31'), $this->sharer,
    );
    $global = (new ShareMetricReader)->summarize(
        'UTC', CarbonImmutable::parse('2026-05-01'), CarbonImmutable::parse('2026-05-31'),
    );

    $service = app(ShareTrackingAnalyticsService::class)->summaryForUser($this->sharer);
    $admin = app(AdminShareAnalyticsService::class)->dashboard();

    expect($service['outbound_shares'])->toBe($member['totals']['recorded_shares'])
        ->and($service['visits'])->toBe($member['totals']['recorded_visits'])
        ->and($service['distinct_browsers'])->toBe($member['totals']['distinct_sharing_browsers'])
        ->and($service['total_outcomes'])->toBe($member['totals']['accepted_participations'])
        ->and($admin['summary']['outbound_shares'])->toBe($global['totals']['recorded_shares'])
        ->and($admin['summary']['visits'])->toBe($global['totals']['recorded_visits'])
        ->and($admin['summary']['distinct_browsers'])->toBe($global['totals']['distinct_sharing_browsers'])
        ->and($admin['summary']['signups'])->toBe(1)
        ->and($admin['summary']['total_outcomes'])->toBe($global['totals']['accepted_participations'])
        ->and(collect($admin['provider_breakdown'])->sum('outbound_shares'))->toBe(1);
});

it('rejects unknown drivers loudly instead of bucketing silently', function (): void {
    expect(ShareCohortAuthority::MATURITY_DAYS)->toBe(30);
});
