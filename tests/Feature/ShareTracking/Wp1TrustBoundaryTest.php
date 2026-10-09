<?php

declare(strict_types=1);

use AIArmada\Affiliates\Enums\CommissionType;
use AIArmada\Affiliates\Models\Affiliate;
use AIArmada\Affiliates\Models\AffiliateAttribution;
use AIArmada\Affiliates\Models\AffiliateBalance;
use AIArmada\Affiliates\Models\AffiliateConversion;
use AIArmada\Affiliates\Models\AffiliateLink;
use AIArmada\Affiliates\Models\AffiliatePayout;
use AIArmada\Affiliates\Models\AffiliateTouchpoint;
use AIArmada\Affiliates\States\Active;
use AIArmada\Affiliates\States\ApprovedConversion;
use AIArmada\Affiliates\States\Disabled;
use AIArmada\Affiliates\States\PaidConversion;
use AIArmada\Affiliates\Support\IpHasher;
use App\Enums\DawahShareOutcomeType;
use App\Enums\ShareGuestCredentialStatus;
use App\Models\Event;
use App\Models\ShareGuestCredential;
use App\Models\User;
use App\Services\ShareTracking\AdminShareAnalyticsService;
use App\Services\ShareTracking\AffiliatesShareTrackingService;
use App\Services\ShareTrackingAnalyticsService;
use App\Services\ShareTrackingService;
use App\Support\ShareTracking\ShareOutcomeBusinessKey;
use App\Support\ShareTracking\ShareTrackingProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\DeletedModels\Models\DeletedModel;
use Tests\TestCase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->sharer = User::factory()->create([
        'name' => 'WP1 Sharer',
        'email' => 'wp1-sharer@example.test',
    ]);

    $this->event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
    ]);

    config(['dawah-share.reporting.reporting_start' => '2026-10-01']);
});

function wp1SharePayload(TestCase $testCase, ?User $user, string $url): array
{
    $call = $user instanceof User ? $testCase->actingAs($user) : $testCase;

    return $call->getJson(route('dawah-share.payload', [
        'url' => $url,
        'text' => 'Share this page',
        'title' => 'Shared Page',
    ]))->assertOk()->json();
}

function wp1SharedUrlWithQuery(string $sharedUrl, array $params): string
{
    // The emitted share URL is a branded redirect without its own query
    // string; recipient-side parameters join with the correct separator.
    return $sharedUrl.(str_contains($sharedUrl, '?') ? '&' : '?').http_build_query($params);
}

function wp1LandingCookie(TestCase $testCase, string $sharedUrl): string
{
    // WP3 cutover: the emitted share URL is a branded short redirect, so
    // the harness traverses the mandated 302 hop. Every assertion below
    // still reads the destination landing exactly as before.
    $cookie = $testCase->followingRedirects()->get($sharedUrl)->assertOk()->getCookie((string) config('dawah-share.cookie.name'));

    expect($cookie)->not->toBeNull();

    return (string) $cookie?->getValue();
}

function wp1TrustBoundaryCommercialAffiliate(User $user, string $code): Affiliate
{
    $affiliate = new Affiliate([
        'code' => $code,
        'name' => 'WP1 Commercial Account',
        'status' => Active::class,
        'commission_type' => CommissionType::Percentage->value,
        'commission_rate' => 1500,
        'currency' => 'MYR',
        'metadata' => ['commercial_account' => true],
        'activated_at' => now(),
    ]);

    $affiliate->assignOwner($user);
    $affiliate->save();

    return $affiliate;
}

it('marks new member share profiles and never adopts an unmarked commercial profile', function (): void {
    $commercial = wp1TrustBoundaryCommercialAffiliate($this->sharer, 'wp1-commercial-001');

    $payload = wp1SharePayload($this, $this->sharer, route('events.show', $this->event));

    expect($payload['tracking_token'])->toBeString()->not->toBe('');

    $affiliates = Affiliate::query()
        ->where('owner_type', $this->sharer->getMorphClass())
        ->where('owner_id', $this->sharer->getKey())
        ->get();

    expect($affiliates)->toHaveCount(2);

    $marked = $affiliates->first(fn (Affiliate $affiliate): bool => $affiliate->id !== $commercial->id);

    expect(ShareTrackingProfile::isMarkedMemberFor($marked, $this->sharer))->toBeTrue()
        ->and($marked->metadata)->toBe([
            'share_tracking_profile' => true,
            'profile_kind' => 'member',
        ]);

    $commercial->refresh();

    expect($commercial->metadata)->toBe(['commercial_account' => true])
        ->and(ShareTrackingProfile::isMarked($commercial))->toBeFalse();

    $link = AffiliateLink::query()->where('custom_slug', $payload['tracking_token'])->firstOrFail();

    expect((string) $link->affiliate_id)->toBe((string) $marked->id);

    // Repeated creation resolves the same marked identity; the commercial
    // profile is never touched.
    wp1SharePayload($this, $this->sharer, route('events.show', $this->event));

    expect(Affiliate::query()
        ->where('owner_type', $this->sharer->getMorphClass())
        ->where('owner_id', $this->sharer->getKey())
        ->count())->toBe(2);
});

it('reads analytics only for the marked profile and leaves commercial-only users empty', function (): void {
    wp1TrustBoundaryCommercialAffiliate($this->sharer, 'wp1-commercial-002');

    $summary = app(ShareTrackingAnalyticsService::class)->summaryForUser($this->sharer);

    expect($summary)->toMatchArray([
        'outbound_shares' => 0,
        'visits' => 0,
        'distinct_browsers' => 0,
        'total_outcomes' => 0,
    ]);

    expect(app(ShareTrackingAnalyticsService::class)->linksForUser($this->sharer))->toBeEmpty();
});

it('refuses share creation for suspended profiles without reactivating them', function (): void {
    wp1SharePayload($this, $this->sharer, route('events.show', $this->event));

    $affiliate = app(AffiliatesShareTrackingService::class)->findAffiliateForUser($this->sharer);
    expect($affiliate)->not->toBeNull();

    $affiliate->forceFill(['status' => Disabled::class])->save();

    $this->actingAs($this->sharer)
        ->getJson(route('dawah-share.payload', [
            'url' => route('events.show', $this->event),
            'text' => 'Share this page',
            'title' => 'Shared Page',
        ]))
        ->assertUnprocessable();

    expect($affiliate->fresh()->status)->toBeInstanceOf(Disabled::class);
});

it('refuses to reuse a deactivated link and never repairs its token', function (): void {
    $payload = wp1SharePayload($this, $this->sharer, route('events.show', $this->event));
    $token = (string) $payload['tracking_token'];

    $link = AffiliateLink::query()->where('custom_slug', $token)->firstOrFail();
    $link->deactivate();
    $disabledAt = $link->fresh()->deactivated_at;

    expect($disabledAt)->not->toBeNull();

    $this->actingAs($this->sharer)
        ->getJson(route('dawah-share.payload', [
            'url' => route('events.show', $this->event),
            'text' => 'Share this page',
            'title' => 'Shared Page',
        ]))
        ->assertUnprocessable();

    $link->refresh();

    expect($link->custom_slug)->toBe($token)
        ->and($link->deactivated_at)->not->toBeNull()
        ->and(AffiliateLink::query()->where('affiliate_id', $link->affiliate_id)->count())->toBe(1);
});

it('keeps the public destination readable while a disabled token earns nothing', function (): void {
    $payload = wp1SharePayload($this, $this->sharer, route('events.show', $this->event));
    $token = (string) $payload['tracking_token'];

    AffiliateLink::query()->where('custom_slug', $token)->firstOrFail()->deactivate();

    $this->followingRedirects()->get((string) $payload['url'])->assertOk();

    expect(AffiliateAttribution::query()->count())->toBe(0)
        ->and(AffiliateTouchpoint::query()->where('touchpoint_type', 'visit')->count())->toBe(0);
});

it('invalidates a stale cookie once its link is deactivated', function (): void {
    $visitor = User::factory()->create(['email' => 'wp1-visitor@example.test']);
    $payload = wp1SharePayload($this, $this->sharer, route('events.show', $this->event));
    $cookie = wp1LandingCookie($this, (string) $payload['url']);

    AffiliateLink::query()
        ->where('custom_slug', (string) $payload['tracking_token'])
        ->firstOrFail()
        ->deactivate();

    $request = Request::create(route('events.show', $this->event), 'GET');
    $request->cookies->set((string) config('dawah-share.cookie.name'), $cookie);
    $request->setUserResolver(fn (): User => $visitor);

    expect(app(ShareTrackingService::class)->resolveActiveAttribution($request))->toBeNull();

    $outcome = app(ShareTrackingService::class)->recordOutcome(
        DawahShareOutcomeType::EventSave,
        ShareOutcomeBusinessKey::eventSave((string) Str::uuid()),
        $this->event,
        $visitor,
        $request,
    );

    expect($outcome)->toBeNull()
        ->and(AffiliateConversion::query()->count())->toBe(0);
});

it('clears an unrelated stale selection when an explicit token is invalid', function (): void {
    $payload = wp1SharePayload($this, $this->sharer, route('events.show', $this->event));
    $cookie = wp1LandingCookie($this, (string) $payload['url']);

    $response = $this->withCookie((string) config('dawah-share.cookie.name'), $cookie)
        ->get(route('events.show', [$this->event, 'share' => 'deadbeefdeadbeef']));

    $response->assertOk();

    $cleared = $response->getCookie((string) config('dawah-share.cookie.name'));

    expect($cleared)->not->toBeNull();

    $request = Request::create(route('events.show', $this->event), 'GET');
    $request->cookies->set((string) config('dawah-share.cookie.name'), (string) $cleared?->getValue());

    expect(app(ShareTrackingService::class)->resolveActiveAttribution($request))->toBeNull()
        ->and(AffiliateAttribution::query()->count())->toBe(1);
});

it('does not renew attribution expiry on passive navigation', function (): void {
    $payload = wp1SharePayload($this, $this->sharer, route('events.show', $this->event));
    $cookie = wp1LandingCookie($this, (string) $payload['url']);

    $attribution = AffiliateAttribution::query()->where('attribution_type', 'landing')->firstOrFail();
    $landingExpiry = $attribution->expires_at->copy();

    $this->travel(24)->hours();

    $this->withCookie((string) config('dawah-share.cookie.name'), $cookie)
        ->get(route('events.index'))
        ->assertOk();

    expect($attribution->fresh()->expires_at->equalTo($landingExpiry))->toBeTrue();
});

it('excludes proven self-referral credit without inventing identities', function (): void {
    $payload = wp1SharePayload($this, $this->sharer, route('events.show', $this->event));
    $cookie = wp1LandingCookie($this, (string) $payload['url']);

    $request = Request::create(route('events.show', $this->event), 'GET');
    $request->cookies->set((string) config('dawah-share.cookie.name'), $cookie);
    $request->setUserResolver(fn (): User => $this->sharer);

    $outcome = app(ShareTrackingService::class)->recordOutcome(
        DawahShareOutcomeType::EventSave,
        ShareOutcomeBusinessKey::eventSave((string) Str::uuid()),
        $this->event,
        $this->sharer,
        $request,
    );

    expect($outcome)->toBeNull()
        ->and(AffiliateConversion::query()->count())->toBe(0);

    $visitor = User::factory()->create(['email' => 'wp1-other@example.test']);
    $visitorRequest = Request::create(route('events.show', $this->event), 'GET');
    $visitorRequest->cookies->set((string) config('dawah-share.cookie.name'), $cookie);
    $visitorRequest->setUserResolver(fn (): User => $visitor);

    $credited = app(ShareTrackingService::class)->recordOutcome(
        DawahShareOutcomeType::EventSave,
        ShareOutcomeBusinessKey::eventSave((string) Str::uuid()),
        $this->event,
        $visitor,
        $visitorRequest,
    );

    expect($credited)->not->toBeNull()
        ->and(AffiliateConversion::query()->count())->toBe(1);
});

it('issues server credentials for guests and rejects spoofed tracking', function (): void {
    $eventUrl = route('events.show', $this->event);

    $first = $this->getJson(route('dawah-share.payload', [
        'url' => $eventUrl,
        'text' => 'Share this page',
        'title' => 'Shared Page',
    ]))->assertOk()->json();

    $second = $this->getJson(route('dawah-share.payload', [
        'url' => $eventUrl,
        'text' => 'Share this page',
        'title' => 'Shared Page',
    ]))->assertOk()->json();

    // No presented credential, no fingerprint merge: each issuance is its
    // own profile. WP3 claim continuity builds on this foundation.
    expect((string) $first['guest_credential'])->not->toBe((string) $second['guest_credential']);

    $header = (string) config('dawah-share.guest_credential.header', 'X-Dawah-Guest-Credential');

    // Credential-less and forged tracking attempts fail before any
    // default header is set on this test case.
    $this->postJson(route('dawah-share.track'), [
        'provider' => 'copy_link',
        'tracking_token' => (string) $first['tracking_token'],
    ])->assertUnprocessable();

    // A forged public Signals cookie is not ownership proof.
    $this->withCookie((string) config('signals.integrations.browser.identifiers.visitor_cookie_name', 'sig_vid'), 'forged-visitor')
        ->postJson(route('dawah-share.track'), [
            'provider' => 'copy_link',
            'tracking_token' => (string) $first['tracking_token'],
        ])
        ->assertUnprocessable();

    // Another guest's credential cannot borrow this link.
    $this->withHeader($header, (string) $second['guest_credential'])
        ->postJson(route('dawah-share.track'), [
            'provider' => 'copy_link',
            'tracking_token' => (string) $first['tracking_token'],
        ])
        ->assertUnprocessable();

    $reused = $this->withHeader($header, (string) $first['guest_credential'])
        ->getJson(route('dawah-share.payload', [
            'url' => $eventUrl,
            'text' => 'Share this page',
            'title' => 'Shared Page',
        ]))->assertOk()->json();

    expect((string) $reused['tracking_token'])->toBe((string) $first['tracking_token'])
        ->and($reused['guest_credential'] ?? null)->toBeNull();

    $this->withHeader($header, (string) $first['guest_credential'])
        ->postJson(route('dawah-share.track'), [
            'provider' => 'copy_link',
            'tracking_token' => (string) $first['tracking_token'],
        ])
        ->assertNoContent();

    expect(AffiliateTouchpoint::query()->where('touchpoint_type', 'outbound_share')->count())->toBe(1);
});

it('stores only credential verifiers and honors expiry and revocation', function (): void {
    $payload = $this->getJson(route('dawah-share.payload', [
        'url' => route('events.show', $this->event),
        'text' => 'Share this page',
        'title' => 'Shared Page',
    ]))->assertOk()->json();

    $credential = (string) $payload['guest_credential'];
    $header = (string) config('dawah-share.guest_credential.header', 'X-Dawah-Guest-Credential');

    expect(ShareGuestCredential::query()->where('credential_verifier', $credential)->exists())->toBeFalse();

    $record = ShareGuestCredential::query()->firstOrFail();

    expect($record->credential_verifier)->toBe(hash('sha256', 'dawah-guest-credential-v1|'.$credential));

    $record->forceFill(['expires_at' => now()->subMinute()])->save();

    $this->withHeader($header, $credential)
        ->postJson(route('dawah-share.track'), [
            'provider' => 'copy_link',
            'tracking_token' => (string) $payload['tracking_token'],
        ])
        ->assertUnprocessable();

    $record->forceFill(['expires_at' => now()->addDays(30)])->save();

    expect($record->status)->toBe(ShareGuestCredentialStatus::Active);

    expect($record->revoke())->toBeTrue()
        ->and($record->status)->toBe(ShareGuestCredentialStatus::Revoked)
        ->and($record->revoked_at)->not->toBeNull()
        ->and($record->revoke())->toBeFalse();

    $this->withHeader($header, $credential)
        ->postJson(route('dawah-share.track'), [
            'provider' => 'copy_link',
            'tracking_token' => (string) $payload['tracking_token'],
        ])
        ->assertUnprocessable();

    expect(AffiliateTouchpoint::query()->where('touchpoint_type', 'outbound_share')->count())->toBe(0);
});

it('minimizes stored visitor data at every share write site', function (): void {
    $payload = wp1SharePayload($this, $this->sharer, route('events.show', $this->event));

    $landingUrl = wp1SharedUrlWithQuery((string) $payload['url'], [
        'utm_campaign' => 'ramadan-drive',
        'utm_source' => 'whatsapp',
        'evil_param' => 'should-never-persist',
        'tracking_note' => 'should-never-persist',
    ]);

    $cookie = wp1LandingCookie($this, $landingUrl);

    $this->withHeader('Referer', 'https://search.example.test/?q=sensitive')
        ->withCookie((string) config('dawah-share.cookie.name'), $cookie)
        ->postJson(route('dawah-share.track'), [
            'provider' => 'copy_link',
            'tracking_token' => (string) $payload['tracking_token'],
        ])
        ->assertNoContent();

    $expectedHash = IpHasher::hash('127.0.0.1');

    $attribution = AffiliateAttribution::query()->where('attribution_type', 'landing')->firstOrFail();

    expect($attribution->ip_address)->toBe($expectedHash)
        ->and($attribution->user_agent)->toBeNull()
        ->and($attribution->referrer_url)->toBeNull()
        ->and($attribution->user_id)->toBeNull()
        ->and($attribution->metadata)->toBe([
            'utm_source' => 'whatsapp',
            'utm_campaign' => 'ramadan-drive',
        ]);

    $visit = AffiliateTouchpoint::query()->where('touchpoint_type', 'visit')->firstOrFail();

    expect($visit->ip_address)->toBe($expectedHash)
        ->and($visit->user_agent)->toBeNull()
        ->and($visit->referrer_url)->toBeNull();

    $outbound = AffiliateTouchpoint::query()->where('touchpoint_type', 'outbound_share')->firstOrFail();

    expect($outbound->ip_address)->toBe($expectedHash)
        ->and($outbound->user_agent)->toBeNull()
        ->and($outbound->referrer_url)->toBeNull();

    expect(AffiliateAttribution::query()->where('ip_address', '127.0.0.1')->exists())->toBeFalse()
        ->and(AffiliateTouchpoint::query()->where('ip_address', '127.0.0.1')->exists())->toBeFalse();
});

it('rejects private and secret-bearing share targets while keeping visit classification safe', function (): void {
    $this->actingAs($this->sharer)
        ->getJson(route('dawah-share.payload', [
            'url' => route('dashboard'),
            'text' => 'Share this page',
            'title' => 'Shared Page',
        ]))
        ->assertUnprocessable();

    $this->actingAs($this->sharer)
        ->getJson(route('dawah-share.payload', [
            'url' => route('events.show', [$this->event, 'token' => 'secret-reset-token']),
            'text' => 'Share this page',
            'title' => 'Shared Page',
        ]))
        ->assertUnprocessable();

    expect(AffiliateLink::query()->count())->toBe(0);

    // Recipient navigation to private pages must never throw.
    $payload = wp1SharePayload($this, $this->sharer, route('events.show', $this->event));
    $cookie = wp1LandingCookie($this, (string) $payload['url']);

    $this->actingAs($this->sharer)
        ->withCookie((string) config('dawah-share.cookie.name'), $cookie)
        ->get(route('dashboard'))
        ->assertOk();
});

it('rejects cross-account reads and token borrowing without revealing existence', function (): void {
    $otherUser = User::factory()->create(['email' => 'wp1-other-owner@example.test']);
    $payload = wp1SharePayload($this, $this->sharer, route('events.show', $this->event));
    $link = AffiliateLink::query()->where('custom_slug', (string) $payload['tracking_token'])->firstOrFail();

    expect(app(ShareTrackingAnalyticsService::class)->findLinkForUser($otherUser, $link->id))->toBeNull();

    $this->actingAs($otherUser)
        ->get(route('dashboard.dawah-impact.links.show', ['link' => $link->id]))
        ->assertNotFound();

    $this->actingAs($otherUser)
        ->postJson(route('dawah-share.track'), [
            'provider' => 'copy_link',
            'tracking_token' => (string) $payload['tracking_token'],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('tracking_token');

    Sanctum::actingAs($otherUser);

    $this->getJson(route('api.client.share.analytics.links.show', ['link' => $link->id]))
        ->assertNotFound();
});

it('exposes a minimized analytics schema with the measurement contract', function (): void {
    Sanctum::actingAs($this->sharer);

    $payload = $this->getJson(route('api.client.share.payload', [
        'url' => route('events.show', $this->event),
        'text' => 'Join this majlis',
        'title' => $this->event->title,
        'origin' => 'iosapp',
    ]))->assertOk()->json();

    $this->postJson(route('api.client.share.track'), [
        'provider' => 'copy_link',
        'tracking_token' => (string) $payload['tracking_token'],
    ])->assertNoContent();

    $this->followingRedirects()->get((string) $payload['url'])->assertOk();

    $dashboard = $this->getJson(route('api.client.share.analytics'))
        ->assertOk()
        ->json();

    expect(data_get($dashboard, 'meta.measurement.definition_version'))->toBe('wp1')
        ->and(data_get($dashboard, 'meta.measurement.reporting_start'))->toBe('2026-10-01')
        ->and(data_get($dashboard, 'meta.measurement.status'))->toBe('active')
        ->and(data_get($dashboard, 'data.summary'))->not->toHaveKey('unique_visitors')
        ->and(data_get($dashboard, 'data.summary.distinct_browsers'))->toBeInt()
        ->and(data_get($dashboard, 'data.summary.outbound_shares'))->toBe(1);

    $linkId = (string) data_get($dashboard, 'data.links.data.0.id');

    $detail = $this->getJson(route('api.client.share.analytics.links.show', ['link' => $linkId]))
        ->assertOk()
        ->json();

    $visit = data_get($detail, 'data.recent_visits.0');

    expect($visit)->not->toBeNull()
        ->and(array_keys((array) $visit))->toBe([
            'id',
            'backend',
            'link_id',
            'subject_type',
            'subject_id',
            'subject_key',
            'visit_kind',
            'channel',
            'occurred_at',
        ]);
});

it('hides recipient trails on the web link page while keeping own-link context', function (): void {
    $payload = wp1SharePayload($this, $this->sharer, route('events.show', $this->event));
    $cookie = wp1LandingCookie($this, (string) $payload['url']);
    $link = AffiliateLink::query()->where('custom_slug', (string) $payload['tracking_token'])->firstOrFail();

    $this->withCookie((string) config('dawah-share.cookie.name'), $cookie)
        ->get(route('events.index', ['search' => 'wp1-private-trail-marker']))
        ->assertOk();

    $previousLocale = app()->getLocale();
    app()->setLocale('en');

    try {
        $this->actingAs($this->sharer)
            ->get(route('dashboard.dawah-impact.links.show', ['link' => $link->id]))
            ->assertOk()
            ->assertSee($link->destination_url)
            ->assertSee('Distinct Browsers')
            ->assertSee('Recorded Check-ins')
            ->assertDontSee('wp1-private-trail-marker')
            ->assertDontSee('Visitor key');
    } finally {
        app()->setLocale($previousLocale);
    }
});

it('shows honest measurement labels with consistent ms and en resources', function (): void {
    $previousLocale = app()->getLocale();
    app()->setLocale('en');

    try {
        $this->actingAs($this->sharer)
            ->get(route('dashboard.dawah-impact'))
            ->assertOk()
            ->assertSee('Distinct Browsers')
            ->assertSee('Recorded Responses')
            ->assertSee('Recorded Check-ins')
            ->assertSee('Delivery to recipients is unknown')
            ->assertDontSee('Unique Visitors')
            ->assertDontSee('Total Responses')
            ->assertDontSee('Manfaat Diterima');
    } finally {
        app()->setLocale($previousLocale);
    }

    app()->setLocale('ms');

    try {
        expect(__('Distinct Browsers'))->toBe('Pelayar Berbeza')
            ->and(__('Recorded Responses'))->toBe('Respons Direkodkan')
            ->and(__('Recorded Check-ins'))->toBe('Daftar Masuk Direkodkan')
            ->and(__('Pelayar Berbeza'))->toBe('Pelayar Berbeza');
    } finally {
        app()->setLocale('en');
    }

    expect(__('Pelayar Berbeza'))->toBe('Distinct Browsers');
});

it('retains share profiles with financial obligations during tracking cleanup', function (): void {
    $service = app(ShareTrackingService::class);
    $service->createOrReuseLink($this->sharer, route('events.show', $this->event), $this->event->title);

    $affiliate = app(AffiliatesShareTrackingService::class)->findAffiliateForUser($this->sharer);
    expect($affiliate)->not->toBeNull();

    AffiliateBalance::query()->create([
        'affiliate_id' => $affiliate->id,
        'currency' => 'MYR',
        'holding_minor' => 0,
        'available_minor' => 2500,
        'lifetime_earnings_minor' => 2500,
        'minimum_payout_minor' => 0,
        'payout_sequence' => 1,
    ]);

    AffiliatePayout::query()->create([
        'reference' => 'wp1-payout-001',
        'total_minor' => 2500,
        'currency' => 'MYR',
        'payee_type' => $affiliate->getMorphClass(),
        'payee_id' => $affiliate->id,
    ]);

    $service->deleteUserTracking($this->sharer);

    expect(Affiliate::query()->whereKey($affiliate->id)->exists())->toBeTrue()
        ->and(AffiliateBalance::query()->where('affiliate_id', $affiliate->id)->exists())->toBeTrue()
        ->and(AffiliatePayout::query()->where('reference', 'wp1-payout-001')->exists())->toBeTrue()
        ->and(AffiliateLink::query()->where('affiliate_id', $affiliate->id)->exists())->toBeFalse();
});

it('restores only canonical owned share records and skips foreign snapshots', function (): void {
    $service = app(ShareTrackingService::class);
    $service->createOrReuseLink($this->sharer, route('events.show', $this->event), $this->event->title);

    $affiliate = app(AffiliatesShareTrackingService::class)->findAffiliateForUser($this->sharer);
    expect($affiliate)->not->toBeNull();

    $link = AffiliateLink::query()->where('affiliate_id', $affiliate->id)->firstOrFail();

    $this->sharer->delete();

    expect(Affiliate::query()->whereKey($affiliate->id)->exists())->toBeFalse();

    $restored = User::restoreDeletedUser($this->sharer->id);

    expect($restored->exists)->toBeTrue()
        ->and(Affiliate::query()->whereKey($affiliate->id)->exists())->toBeTrue()
        ->and(AffiliateLink::query()->whereKey($link->id)->exists())->toBeTrue();

    $restoredProfile = Affiliate::query()->whereKey($affiliate->id)->firstOrFail();
    $restoredLink = AffiliateLink::query()->whereKey($link->id)->firstOrFail();

    expect(ShareTrackingProfile::isMarkedMemberFor($restoredProfile, $restored))->toBeTrue();

    // Restoration never reactivates referral tracking: the profile and its
    // links stay disabled until an explicit authorized fresh activation.
    expect($restoredProfile->deactivated_at)->not->toBeNull()
        ->and($restoredLink->deactivated_at)->not->toBeNull()
        ->and($restoredLink->custom_slug)->toBe($link->custom_slug)
        ->and(ShareTrackingProfile::isEligibleForNewCredit($restoredProfile))->toBeFalse();

    $this->actingAs($restored)
        ->getJson(route('dawah-share.payload', [
            'url' => route('events.show', $this->event),
            'text' => 'Share this page',
            'title' => 'Shared Page',
        ]))
        ->assertUnprocessable();
});

it('withholds uncertified measurements until reporting is configured', function (): void {
    config(['dawah-share.reporting.reporting_start' => null]);

    $payload = wp1SharePayload($this, $this->sharer, route('events.show', $this->event));
    wp1LandingCookie($this, (string) $payload['url']);

    $summary = app(ShareTrackingAnalyticsService::class)->summaryForUser($this->sharer);

    expect($summary)->toMatchArray([
        'outbound_shares' => 0,
        'visits' => 0,
        'distinct_browsers' => 0,
        'total_outcomes' => 0,
    ]);

    $links = app(ShareTrackingAnalyticsService::class)->linksForUser($this->sharer);

    // Link identity stays usable for share-again; counts are withheld.
    expect($links)->toHaveCount(1)
        ->and($links->first()->destinationUrl)->toBeString()
        ->and($links->first()->visitsCount)->toBe(0);

    expect(app(ShareTrackingAnalyticsService::class)->providerBreakdownForUser($this->sharer))->toBeEmpty()
        ->and(app(AdminShareAnalyticsService::class)->dashboard()['summary']['visits'])->toBe(0);

    Sanctum::actingAs($this->sharer);

    $dashboard = $this->getJson(route('api.client.share.analytics'))
        ->assertOk()
        ->json();

    expect(data_get($dashboard, 'meta.measurement.status'))->toBe('not_configured')
        ->and(data_get($dashboard, 'meta.measurement.reporting_start'))->toBeNull()
        ->and(data_get($dashboard, 'data.summary.visits'))->toBe(0);

    $previousLocale = app()->getLocale();
    app()->setLocale('en');

    try {
        $this->actingAs($this->sharer)
            ->get(route('dashboard.dawah-impact'))
            ->assertOk()
            ->assertSee('Reporting is not configured yet');
    } finally {
        app()->setLocale($previousLocale);
    }
});

it('ignores guest credentials presented through the GET query string', function (): void {
    $eventUrl = route('events.show', $this->event);
    $input = (string) config('dawah-share.guest_credential.input', 'guest_credential');

    $first = $this->getJson(route('dawah-share.payload', [
        'url' => $eventUrl,
        'text' => 'Share this page',
        'title' => 'Shared Page',
    ]))->assertOk()->json();

    // A valid credential smuggled in the query string must not prove
    // ownership: issuance starts a fresh profile instead of reusing.
    $querySmuggled = $this->getJson(route('dawah-share.payload', [
        'url' => $eventUrl,
        'text' => 'Share this page',
        'title' => 'Shared Page',
        $input => (string) $first['guest_credential'],
    ]))->assertOk()->json();

    expect((string) $querySmuggled['guest_credential'])->not->toBe((string) $first['guest_credential'])
        ->and((string) $querySmuggled['tracking_token'])->not->toBe((string) $first['tracking_token']);

    // The same applies to the track endpoint: query-only secrets fail.
    $this->postJson(route('dawah-share.track', [$input => (string) $first['guest_credential']]), [
        'provider' => 'copy_link',
        'tracking_token' => (string) $first['tracking_token'],
    ])->assertUnprocessable();

    // A stolen public tracking token is not a guest credential either.
    $header = (string) config('dawah-share.guest_credential.header', 'X-Dawah-Guest-Credential');

    $this->withHeader($header, (string) $first['tracking_token'])
        ->postJson(route('dawah-share.track'), [
            'provider' => 'copy_link',
            'tracking_token' => (string) $first['tracking_token'],
        ])
        ->assertUnprocessable();

    expect(AffiliateTouchpoint::query()->where('touchpoint_type', 'outbound_share')->count())->toBe(0);
});

it('accepts guest credentials from a private POST body', function (): void {
    $input = (string) config('dawah-share.guest_credential.input', 'guest_credential');

    $first = $this->getJson(route('dawah-share.payload', [
        'url' => route('events.show', $this->event),
        'text' => 'Share this page',
        'title' => 'Shared Page',
    ]))->assertOk()->json();

    $this->postJson(route('dawah-share.track'), [
        'provider' => 'copy_link',
        'tracking_token' => (string) $first['tracking_token'],
        $input => (string) $first['guest_credential'],
    ])->assertNoContent();

    expect(AffiliateTouchpoint::query()->where('touchpoint_type', 'outbound_share')->count())->toBe(1);
});

it('scopes admin analytics to marked share profiles', function (): void {
    $commercial = wp1TrustBoundaryCommercialAffiliate($this->sharer, 'wp1-commercial-003');

    AffiliateLink::query()->create([
        'affiliate_id' => $commercial->id,
        'destination_url' => 'https://ilmu360.test/events/wp1-commercial',
        'tracking_url' => 'https://ilmu360.test/r/wp1-commercial',
        'custom_slug' => 'wp1commercial000',
        'subject_type' => 'event',
        'subject_key' => 'event:wp1-commercial',
    ]);

    wp1SharePayload($this, $this->sharer, route('events.show', $this->event));

    $dashboard = app(AdminShareAnalyticsService::class)->dashboard();

    expect($dashboard['summary']['affiliates'])->toBe(1)
        ->and($dashboard['summary']['shared_links'])->toBe(1)
        ->and(collect($dashboard['top_sharers'])->pluck('affiliate_code')->all())->not->toContain('wp1-commercial-003');
});

it('preserves extra profile metadata across share payload reuse without purpose promotion', function (): void {
    wp1SharePayload($this, $this->sharer, route('events.show', $this->event));

    $affiliate = app(AffiliatesShareTrackingService::class)->findAffiliateForUser($this->sharer);
    expect($affiliate)->not->toBeNull();

    $affiliate->forceFill(['metadata' => array_merge($affiliate->metadata ?? [], [
        'fraud_review_note' => 'operator-hold-7',
        'reward_purpose' => 'excluded',
    ])])->save();

    // A public caller cannot smuggle metadata/purpose through payload input.
    $this->actingAs($this->sharer)
        ->getJson(route('dawah-share.payload', [
            'url' => route('events.show', $this->event),
            'text' => 'Share this page',
            'title' => 'Shared Page',
            'metadata' => ['reward_purpose' => 'included'],
            'purpose' => 'included',
        ]))
        ->assertOk();

    $affiliate->refresh();

    expect($affiliate->metadata)->toMatchArray([
        'share_tracking_profile' => true,
        'profile_kind' => 'member',
        'fraud_review_note' => 'operator-hold-7',
        'reward_purpose' => 'excluded',
    ])->and(Affiliate::query()
        ->where('owner_type', $this->sharer->getMorphClass())
        ->where('owner_id', $this->sharer->getKey())
        ->count())->toBe(1);
});

it('excludes new credit for marked profiles whose member owner is missing', function (): void {
    $orphan = new Affiliate([
        'code' => 'wp1-orphan-001',
        'name' => 'Orphaned Share Profile',
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
    $orphan->owner_type = (new User)->getMorphClass();
    $orphan->owner_id = (string) Str::uuid();
    $orphan->save();

    $link = AffiliateLink::query()->create([
        'affiliate_id' => $orphan->id,
        'destination_url' => route('events.show', $this->event),
        'tracking_url' => 'https://ilmu360.test/r/wp1-orphan-001',
        'custom_slug' => 'wp1orphan00000001',
        'subject_type' => 'event',
        'subject_key' => 'event:wp1-orphan',
    ]);

    expect(ShareTrackingProfile::isEligibleForNewCredit($orphan))->toBeFalse();

    // The public destination stays readable but earns nothing.
    $this->get(route('events.show', [$this->event, 'share' => 'wp1orphan00000001']))->assertOk();

    expect(AffiliateAttribution::query()->count())->toBe(0);

    // Even a pre-orphan cookie selection cannot earn locked credit.
    AffiliateAttribution::query()->create([
        'affiliate_id' => $orphan->id,
        'affiliate_code' => $orphan->code,
        'subject_type' => 'event',
        'subject_key' => 'event:wp1-orphan',
        'subject_instance' => 'default',
        'cookie_value' => 'cookie-wp1-orphan',
        'landing_url' => route('events.show', $this->event),
        'affiliate_link_id' => $link->id,
        'attribution_type' => 'landing',
        'visitor_key' => 'visitor-wp1-orphan',
        'first_seen_at' => now()->subHour(),
        'last_seen_at' => now()->subHour(),
        'expires_at' => now()->addDays(30),
    ]);

    $request = Request::create(route('events.show', $this->event), 'GET');
    $request->cookies->set((string) config('dawah-share.cookie.name'), base64_encode(json_encode([
        'visitor_key' => 'visitor-wp1-orphan',
        'attribution_cookie' => 'cookie-wp1-orphan',
    ], JSON_THROW_ON_ERROR)));

    expect(app(ShareTrackingService::class)->resolveActiveAttribution($request))->toBeNull();

    $visitor = User::factory()->create(['email' => 'wp1-orphan-visitor@example.test']);

    expect(app(ShareTrackingService::class)->recordOutcome(
        DawahShareOutcomeType::EventSave,
        ShareOutcomeBusinessKey::eventSave((string) Str::uuid()),
        $this->event,
        $visitor,
        $request,
    ))->toBeNull()
        ->and(AffiliateConversion::query()->count())->toBe(0);
});

it('survives a canonical member code collision inside a caller transaction', function (): void {
    $victim = User::factory()->create(['email' => 'wp1-collision-victim@example.test']);
    $otherUser = User::factory()->create(['email' => 'wp1-collision-squatter@example.test']);

    // Canonical deterministic identity (see AffiliatesShareTrackingService::memberAffiliateCode).
    $code = 'MI'.strtoupper(substr(hash('sha256', 'dawah-share-member-v1|'.$victim->getMorphClass().'|'.$victim->getKey()), 0, 12));

    $squatter = new Affiliate([
        'code' => $code,
        'name' => 'Squatter Commercial Account',
        'status' => Active::class,
        'commission_type' => CommissionType::Percentage->value,
        'commission_rate' => 1500,
        'currency' => 'MYR',
        'metadata' => ['commercial_account' => true],
        'activated_at' => now(),
    ]);
    $squatter->assignOwner($otherUser);
    $squatter->save();

    $failure = null;
    $outerSurvived = false;

    DB::transaction(function () use ($victim, $code, &$failure, &$outerSurvived): void {
        try {
            app(ShareTrackingService::class)->createOrReuseLink($victim, route('events.show', $this->event), $this->event->title);
        } catch (InvalidArgumentException $exception) {
            $failure = $exception->getMessage();
        }

        // The caller's outer transaction stays usable after the rolled-back
        // nested savepoint: the requery ran and ordinary reads still work.
        $outerSurvived = Affiliate::query()->where('code', $code)->exists()
            && User::query()->whereKey($victim->getKey())->exists();
    });

    expect($failure)->toBe('A share profile for this account conflicts with an existing profile identity.')
        ->and($outerSurvived)->toBeTrue()
        ->and(app(AffiliatesShareTrackingService::class)->findAffiliateForUser($victim))->toBeNull();

    expect($squatter->fresh()->metadata)->toBe(['commercial_account' => true]);
});

it('preserves paid monetary conversions with payout and balance across deletion and restore', function (): void {
    $service = app(ShareTrackingService::class);
    $service->createOrReuseLink($this->sharer, route('events.show', $this->event), $this->event->title);

    $affiliate = app(AffiliatesShareTrackingService::class)->findAffiliateForUser($this->sharer);
    expect($affiliate)->not->toBeNull();

    $link = AffiliateLink::query()->where('affiliate_id', $affiliate->id)->firstOrFail();
    $token = (string) $link->custom_slug;

    $attribution = AffiliateAttribution::query()->create([
        'affiliate_id' => $affiliate->id,
        'affiliate_code' => $affiliate->code,
        'subject_type' => 'event',
        'subject_key' => 'event:wp1-paid',
        'subject_instance' => 'default',
        'cookie_value' => 'cookie-wp1-paid',
        'landing_url' => route('events.show', $this->event),
        'affiliate_link_id' => $link->id,
        'attribution_type' => 'landing',
        'visitor_key' => 'visitor-wp1-paid',
        'first_seen_at' => now()->subDay(),
        'last_seen_at' => now()->subDay(),
        'expires_at' => now()->addDays(30),
    ]);

    $balance = AffiliateBalance::query()->create([
        'affiliate_id' => $affiliate->id,
        'currency' => 'MYR',
        'holding_minor' => 500,
        'available_minor' => 2500,
        'lifetime_earnings_minor' => 4000,
        'minimum_payout_minor' => 0,
        'payout_sequence' => 1,
    ]);

    $payout = AffiliatePayout::query()->create([
        'reference' => 'wp1-paid-payout-001',
        'total_minor' => 1500,
        'currency' => 'MYR',
        'payee_type' => $affiliate->getMorphClass(),
        'payee_id' => $affiliate->id,
    ]);

    $paid = AffiliateConversion::query()->create([
        'affiliate_id' => $affiliate->id,
        'affiliate_code' => $affiliate->code,
        'affiliate_attribution_id' => $attribution->id,
        'affiliate_payout_id' => $payout->id,
        'subject_type' => 'event',
        'subject_key' => 'event:wp1-paid',
        'subject_instance' => 'default',
        'conversion_type' => 'cash_reward',
        'external_reference' => 'wp1-paid:cash-reward',
        'subtotal_minor' => 10000,
        'value_minor' => 10000,
        'commission_minor' => 1500,
        'commission_currency' => 'MYR',
        'status' => PaidConversion::class,
        'affiliate_link_id' => $link->id,
        'occurred_at' => now()->subDay(),
        'paid_at' => now()->subDay(),
    ]);

    $held = AffiliateConversion::query()->create([
        'affiliate_id' => $affiliate->id,
        'affiliate_code' => $affiliate->code,
        'affiliate_attribution_id' => $attribution->id,
        'subject_type' => 'event',
        'subject_key' => 'event:wp1-held',
        'subject_instance' => 'default',
        'conversion_type' => 'cash_reward',
        'external_reference' => 'wp1-paid:held-reward',
        'subtotal_minor' => 5000,
        'value_minor' => 5000,
        'commission_minor' => 800,
        'commission_currency' => 'MYR',
        'status' => ApprovedConversion::class,
        'affiliate_link_id' => $link->id,
        'occurred_at' => now()->subHours(6),
    ]);
    $held->forceFill(['held_minor' => 300])->save();

    $noise = AffiliateConversion::query()->create([
        'affiliate_id' => $affiliate->id,
        'affiliate_code' => $affiliate->code,
        'affiliate_attribution_id' => $attribution->id,
        'subject_type' => 'event',
        'subject_key' => 'event:wp1-noise',
        'subject_instance' => 'default',
        'conversion_type' => 'event_save',
        'external_reference' => 'wp1-paid:event-save',
        'value_minor' => 0,
        'subtotal_minor' => 0,
        'commission_minor' => 0,
        'commission_currency' => 'MYR',
        'status' => ApprovedConversion::class,
        'affiliate_link_id' => $link->id,
        'occurred_at' => now()->subHour(),
    ]);

    $commercial = wp1TrustBoundaryCommercialAffiliate($this->sharer, 'wp1-paid-commercial-001');
    $commercialLink = AffiliateLink::query()->create([
        'affiliate_id' => $commercial->id,
        'destination_url' => 'https://ilmu360.test/events/wp1-paid-commercial',
        'tracking_url' => 'https://ilmu360.test/r/wp1-paid-commercial',
        'custom_slug' => 'wp1paidcom0000001',
        'subject_type' => 'event',
        'subject_key' => 'event:wp1-paid-commercial',
    ]);

    $service->deleteUserTracking($this->sharer);

    $paidAfterDelete = AffiliateConversion::query()->whereKey($paid->id)->firstOrFail();
    $heldAfterDelete = AffiliateConversion::query()->whereKey($held->id)->firstOrFail();

    expect($paidAfterDelete->commission_minor)->toBe(1500)
        ->and($paidAfterDelete->value_minor)->toBe(10000)
        ->and($paidAfterDelete->affiliate_payout_id)->toBe($payout->id)
        ->and($paidAfterDelete->status)->toBeInstanceOf(PaidConversion::class)
        ->and($heldAfterDelete->commission_minor)->toBe(800)
        ->and($heldAfterDelete->held_minor)->toBe(300)
        ->and(AffiliateBalance::query()->whereKey($balance->id)->exists())->toBeTrue()
        ->and(AffiliatePayout::query()->whereKey($payout->id)->exists())->toBeTrue()
        ->and(Affiliate::query()->whereKey($affiliate->id)->exists())->toBeTrue()
        ->and(AffiliateConversion::query()->whereKey($noise->id)->exists())->toBeFalse();

    // Referenced source facts stay as disarmed evidence; the commercial
    // profile is untouched alongside the financial preservation.
    expect($link->fresh()->deactivated_at)->not->toBeNull()
        ->and($attribution->fresh()->expires_at->lessThanOrEqualTo(now()))->toBeTrue()
        ->and(Affiliate::query()->whereKey($commercial->id)->exists())->toBeTrue()
        ->and(AffiliateLink::query()->whereKey($commercialLink->id)->exists())->toBeTrue();

    // Non-financial history created after the first cleanup is still
    // snapshotted on account deletion and restored as history.
    $noiseAfterCleanup = AffiliateConversion::query()->create([
        'affiliate_id' => $affiliate->id,
        'affiliate_code' => $affiliate->code,
        'affiliate_attribution_id' => $attribution->id,
        'subject_type' => 'event',
        'subject_key' => 'event:wp1-noise-after-cleanup',
        'subject_instance' => 'default',
        'conversion_type' => 'event_save',
        'external_reference' => 'wp1-paid:event-save-after-cleanup',
        'value_minor' => 0,
        'subtotal_minor' => 0,
        'commission_minor' => 0,
        'commission_currency' => 'MYR',
        'status' => ApprovedConversion::class,
        'affiliate_link_id' => $link->id,
        'occurred_at' => now()->subMinutes(5),
    ]);

    $this->sharer->delete();

    expect(AffiliateConversion::query()->whereKey($noiseAfterCleanup->id)->exists())->toBeFalse();

    $restored = User::restoreDeletedUser($this->sharer->id);

    expect($restored->exists)->toBeTrue();

    $paidAfterRestore = AffiliateConversion::query()->whereKey($paid->id)->firstOrFail();

    expect($paidAfterRestore->commission_minor)->toBe(1500)
        ->and($paidAfterRestore->value_minor)->toBe(10000)
        ->and($paidAfterRestore->affiliate_payout_id)->toBe($payout->id)
        ->and($paidAfterRestore->status)->toBeInstanceOf(PaidConversion::class)
        ->and(AffiliateConversion::query()->whereKey($held->id)->exists())->toBeTrue()
        ->and(AffiliateConversion::query()->whereKey($noiseAfterCleanup->id)->exists())->toBeTrue()
        ->and(AffiliateConversion::query()->where('affiliate_id', $affiliate->id)->count())->toBe(3)
        ->and(AffiliateBalance::query()->whereKey($balance->id)->exists())->toBeTrue()
        ->and(AffiliatePayout::query()->whereKey($payout->id)->exists())->toBeTrue()
        ->and($link->fresh()->deactivated_at)->not->toBeNull();

    // The retained ledger earns no new credit: the old token renders the
    // public page without recording a fresh landing.
    $attributionCount = AffiliateAttribution::query()->count();

    $this->get(route('events.show', [$this->event, 'share' => $token]))->assertOk();

    expect(AffiliateAttribution::query()->count())->toBe($attributionCount);
});

it('never overwrites retained financial profile state from a stale snapshot', function (): void {
    $service = app(ShareTrackingService::class);
    $service->createOrReuseLink($this->sharer, route('events.show', $this->event), $this->event->title);

    $affiliate = app(AffiliatesShareTrackingService::class)->findAffiliateForUser($this->sharer);
    expect($affiliate)->not->toBeNull();

    $balance = AffiliateBalance::query()->create([
        'affiliate_id' => $affiliate->id,
        'currency' => 'MYR',
        'holding_minor' => 0,
        'available_minor' => 2500,
        'lifetime_earnings_minor' => 2500,
        'minimum_payout_minor' => 0,
        'payout_sequence' => 1,
    ]);

    $payout = AffiliatePayout::query()->create([
        'reference' => 'wp1-stale-payout-001',
        'total_minor' => 2500,
        'currency' => 'MYR',
        'payee_type' => $affiliate->getMorphClass(),
        'payee_id' => $affiliate->id,
    ]);

    $this->sharer->delete();

    // Newer settlement activity lands on the retained live rows while the
    // account stays deleted (after the snapshot was captured).
    $affiliate->refresh()->forceFill([
        'name' => 'Settled Ledger Name',
        'metadata' => [
            'share_tracking_profile' => true,
            'profile_kind' => 'member',
            'settlement_batch' => 'batch-9',
        ],
    ])->save();

    $balance->forceFill(['available_minor' => 999])->save();

    $restored = User::restoreDeletedUser($this->sharer->id);

    expect($restored->exists)->toBeTrue();

    $retained = Affiliate::query()->whereKey($affiliate->id)->firstOrFail();

    expect($retained->name)->toBe('Settled Ledger Name')
        ->and($retained->metadata)->toBe([
            'share_tracking_profile' => true,
            'profile_kind' => 'member',
            'settlement_batch' => 'batch-9',
        ])
        ->and(Affiliate::query()->whereKey($affiliate->id)->count())->toBe(1)
        ->and(AffiliateBalance::query()->whereKey($balance->id)->value('available_minor'))->toBe(999)
        ->and(AffiliatePayout::query()->whereKey($payout->id)->exists())->toBeTrue();
});

it('rejects forged child snapshot ids that point at foreign rows', function (): void {
    $service = app(ShareTrackingService::class);
    $service->createOrReuseLink($this->sharer, route('events.show', $this->event), $this->event->title);

    $affiliate = app(AffiliatesShareTrackingService::class)->findAffiliateForUser($this->sharer);
    expect($affiliate)->not->toBeNull();

    $victimLink = AffiliateLink::query()->where('affiliate_id', $affiliate->id)->firstOrFail();

    $foreignUser = User::factory()->create(['email' => 'wp1-foreign-owner@example.test']);
    $service->createOrReuseLink($foreignUser, route('events.show', $this->event), $this->event->title);

    $foreignAffiliate = app(AffiliatesShareTrackingService::class)->findAffiliateForUser($foreignUser);
    expect($foreignAffiliate)->not->toBeNull();

    $foreignLink = AffiliateLink::query()->where('affiliate_id', $foreignAffiliate->id)->firstOrFail();

    $foreignConversion = AffiliateConversion::query()->create([
        'affiliate_id' => $foreignAffiliate->id,
        'affiliate_code' => $foreignAffiliate->code,
        'subject_type' => 'event',
        'subject_key' => 'event:wp1-foreign',
        'subject_instance' => 'default',
        'conversion_type' => 'event_save',
        'external_reference' => 'wp1-foreign:event-save',
        'value_minor' => 0,
        'subtotal_minor' => 0,
        'commission_minor' => 0,
        'commission_currency' => 'MYR',
        'status' => ApprovedConversion::class,
        'occurred_at' => now()->subMinutes(5),
    ]);

    $this->sharer->delete();

    // Tamper with the captured snapshot: point the victim's child rows at
    // the foreign affiliate's existing primary ids while the payload
    // affiliate_id still passes the snapshot filter.
    $deleted = DeletedModel::query()
        ->where('key', $this->sharer->id)
        ->where('model', $this->sharer->getMorphClass())
        ->firstOrFail();

    $values = $deleted->values;
    $values['deleted_affiliate_tracking_snapshot']['links'][0]['id'] = $foreignLink->id;
    $values['deleted_affiliate_tracking_snapshot']['conversions'] = [[
        'id' => $foreignConversion->id,
        'affiliate_id' => $affiliate->id,
        'affiliate_code' => $affiliate->code,
        'conversion_type' => 'event_save',
        'value_minor' => 0,
        'subtotal_minor' => 0,
        'commission_minor' => 0,
        'commission_currency' => 'MYR',
        'status' => ApprovedConversion::class,
    ]];
    $deleted->forceFill(['values' => $values])->save();

    $restored = User::restoreDeletedUser($this->sharer->id);

    expect($restored->exists)->toBeTrue();

    expect($foreignLink->fresh()->affiliate_id)->toBe($foreignAffiliate->id)
        ->and($foreignConversion->fresh()->affiliate_id)->toBe($foreignAffiliate->id)
        ->and($foreignConversion->fresh()->commission_minor)->toBe(0);

    // The victim's own deleted link is not resurrected under a foreign id.
    expect(AffiliateLink::query()->whereKey($victimLink->id)->exists())->toBeFalse()
        ->and(AffiliateLink::query()->where('affiliate_id', $affiliate->id)->exists())->toBeFalse();
});
