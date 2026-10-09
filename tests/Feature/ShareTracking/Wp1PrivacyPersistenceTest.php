<?php

declare(strict_types=1);

use AIArmada\Affiliates\Models\AffiliateAttribution;
use AIArmada\Affiliates\Models\AffiliateLink;
use AIArmada\Affiliates\Models\AffiliateTouchpoint;
use AIArmada\Affiliates\Support\IpHasher;
use App\Models\Event;
use App\Models\User;
use App\Services\ShareTrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->sharer = User::factory()->create([
        'name' => 'WP1 Privacy Sharer',
        'email' => 'wp1-privacy-sharer@example.test',
    ]);

    $this->event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
    ]);
});

function wp1PrivacySharePayload(TestCase $testCase, User $user, string $url): array
{
    return $testCase->actingAs($user)->getJson(route('dawah-share.payload', [
        'url' => $url,
        'text' => 'Share this page',
        'title' => 'Shared Page',
    ]))->assertOk()->json();
}

function wp1PrivacyLandingUrl(string $sharedUrl, array $params): string
{
    // The emitted share URL is a branded redirect without its own query
    // string; recipient-side parameters join with the correct separator.
    return $sharedUrl.(str_contains($sharedUrl, '?') ? '&' : '?').http_build_query($params);
}

function wp1PrivacyLandingCookie(TestCase $testCase, string $sharedUrl): string
{
    // WP3 cutover: the emitted share URL is a branded short redirect, so
    // the harness traverses the mandated 302 hop. Every assertion below
    // still reads the destination landing exactly as before.
    $cookie = $testCase->followingRedirects()->get($sharedUrl)->assertOk()->getCookie((string) config('dawah-share.cookie.name'));

    expect($cookie)->not->toBeNull();

    return (string) $cookie?->getValue();
}

it('persists only minimal route identity after landing with private recipient query', function (): void {
    $payload = wp1PrivacySharePayload($this, $this->sharer, route('events.show', $this->event));
    $link = AffiliateLink::query()->where('custom_slug', (string) $payload['tracking_token'])->firstOrFail();

    $landingUrl = wp1PrivacyLandingUrl((string) $payload['url'], [
        'foo' => 'wp1-private-foo-marker',
        'email' => 'recipient-private-wp1@example.test',
        'token' => 'wp1-private-reset-token',
        'q' => 'wp1 private search trail',
        'utm_campaign' => 'ramadan-drive-9',
        'utm_source' => 'Not A Slug!!',
        'utm_content' => str_repeat('x', 121),
    ]);

    wp1PrivacyLandingCookie($this, $landingUrl);

    $expectedMinimal = route('events.show', $this->event);
    $expectedHash = IpHasher::hash('127.0.0.1');

    $attribution = AffiliateAttribution::query()->where('attribution_type', 'landing')->firstOrFail();

    expect($attribution->landing_url)->toBe($expectedMinimal)
        ->and($attribution->metadata)->toBe(['utm_campaign' => 'ramadan-drive-9'])
        ->and($attribution->ip_address)->toBe($expectedHash)
        ->and($attribution->user_agent)->toBeNull()
        ->and($attribution->referrer_url)->toBeNull()
        ->and($attribution->user_id)->toBeNull()
        ->and($attribution->attribution_type)->toBe('landing')
        ->and((string) $attribution->affiliate_link_id)->toBe((string) $link->id)
        ->and((string) $attribution->affiliate_id)->toBe((string) $link->affiliate_id)
        ->and($attribution->subject_type)->toBe($link->subject_type)
        ->and($attribution->subject_key)->toBe((string) $link->subject_key)
        ->and($attribution->subject_title_snapshot)->toBe($link->subject_title_snapshot)
        ->and($attribution->cookie_value)->toBeString()->not->toBe('')
        ->and($attribution->visitor_key)->toBeString()->not->toBe('')
        ->and($attribution->expires_at->isFuture())->toBeTrue();

    $visit = AffiliateTouchpoint::query()->where('touchpoint_type', 'visit')->firstOrFail();

    expect($visit->url)->toBe($expectedMinimal)
        ->and($visit->metadata)->toBe(['visit_kind' => 'landing'])
        ->and($visit->ip_address)->toBe($expectedHash)
        ->and($visit->user_agent)->toBeNull()
        ->and($visit->referrer_url)->toBeNull()
        ->and($visit->subject_type)->toBe('event')
        ->and((string) $visit->affiliate_attribution_id)->toBe((string) $attribution->id)
        ->and((string) $visit->affiliate_link_id)->toBe((string) $link->id);

    $stored = json_encode(
        [$attribution->getAttributes(), $visit->getAttributes(), $link->getAttributes()],
        JSON_THROW_ON_ERROR,
    );

    foreach ([
        'wp1-private-foo-marker',
        'recipient-private-wp1@example.test',
        'wp1-private-reset-token',
        'wp1 private search trail',
        'Not A Slug',
        str_repeat('x', 121),
    ] as $marker) {
        expect($stored)->not->toContain($marker);
    }
});

it('records no visit for private dashboard account and admin navigation', function (): void {
    $payload = wp1PrivacySharePayload($this, $this->sharer, route('events.show', $this->event));
    $cookie = wp1PrivacyLandingCookie($this, (string) $payload['url']);
    $cookieName = (string) config('dawah-share.cookie.name');

    expect(AffiliateTouchpoint::query()->where('touchpoint_type', 'visit')->count())->toBe(1);

    $attribution = AffiliateAttribution::query()->where('attribution_type', 'landing')->firstOrFail();
    $lastSeen = $attribution->last_seen_at->copy();

    $this->travel(1)->hour();

    $this->actingAs($this->sharer)->withCookie($cookieName, $cookie)
        ->get(route('dashboard'))->assertOk();

    $this->actingAs($this->sharer)->withCookie($cookieName, $cookie)
        ->get(route('dashboard.account-settings'))->assertOk();

    $adminResponse = $this->actingAs($this->sharer)->withCookie($cookieName, $cookie)->get('/admin');

    expect($adminResponse->status())->toBeIn([200, 302, 403]);

    expect(AffiliateTouchpoint::query()->where('touchpoint_type', 'visit')->count())->toBe(1)
        ->and($attribution->fresh()->last_seen_at->equalTo($lastSeen))->toBeTrue();
});

it('ignores tracking writes for private paths presented with share tokens', function (): void {
    $payload = wp1PrivacySharePayload($this, $this->sharer, route('events.show', $this->event));
    $token = (string) $payload['tracking_token'];

    $service = app(ShareTrackingService::class);

    foreach ([
        '/account/profile',
        '/account',
        '/admin/reports',
        '/login',
        '/register',
        '/password/reset',
        '/email/verify/1/abc',
        '/sanctum/csrf-cookie',
        '/oauth/google',
    ] as $path) {
        $request = Request::create(
            'http://localhost'.$path.'?share='.$token.'&foo=wp1-private-marker',
            'GET',
        );

        expect($service->captureRequest($request))->toBeNull();
    }

    expect(AffiliateAttribution::query()->count())->toBe(0)
        ->and(AffiliateTouchpoint::query()->count())->toBe(0);
});

it('keeps sender-owned search criteria on the link while recipient trails stay unpersisted', function (): void {
    $searchUrl = rtrim((string) config('app.url'), '/').'/majlis?search=fiqh&person_ids%5B0%5D=a';
    $payload = wp1PrivacySharePayload($this, $this->sharer, $searchUrl);
    $link = AffiliateLink::query()->where('custom_slug', (string) $payload['tracking_token'])->firstOrFail();

    expect($link->subject_type)->toBe('search')
        ->and((string) $link->destination_url)->toContain('search=fiqh')
        ->and((string) $link->destination_url)->toContain('person_ids');

    $landingUrl = wp1PrivacyLandingUrl((string) $payload['url'], [
        'foo' => 'wp1-recipient-foo',
        'q' => 'wp1 recipient private trail',
    ]);

    wp1PrivacyLandingCookie($this, $landingUrl);

    $expectedMinimal = rtrim((string) config('app.url'), '/').'/majlis';

    $attribution = AffiliateAttribution::query()->where('attribution_type', 'landing')->firstOrFail();
    $visit = AffiliateTouchpoint::query()->where('touchpoint_type', 'visit')->firstOrFail();

    expect($attribution->landing_url)->toBe($expectedMinimal)
        ->and($attribution->subject_type)->toBe('search')
        ->and($attribution->subject_key)->toBe((string) $link->subject_key)
        ->and($visit->url)->toBe($expectedMinimal)
        ->and($link->fresh()->destination_url)->toContain('search=fiqh');

    $stored = json_encode(
        [$attribution->getAttributes(), $visit->getAttributes()],
        JSON_THROW_ON_ERROR,
    );

    expect($stored)->not->toContain('wp1-recipient-foo')
        ->and($stored)->not->toContain('wp1 recipient private trail');
});

it('stores only bounded safe campaign slugs and drops free-form secrets', function (): void {
    $payload = wp1PrivacySharePayload($this, $this->sharer, route('events.show', $this->event));

    $landingUrl = wp1PrivacyLandingUrl((string) $payload['url'], [
        'utm_source' => 'wa-group_9.2',
        'utm_medium' => 'share link!',
        'utm_campaign' => 'sk-2026-ramadan_01',
        'utm_term' => str_repeat('t', 120),
        'utm_content' => str_repeat('c', 121),
    ]);

    wp1PrivacyLandingCookie($this, $landingUrl);

    $attribution = AffiliateAttribution::query()->where('attribution_type', 'landing')->firstOrFail();

    expect($attribution->metadata)->toBe([
        'utm_source' => 'wa-group_9.2',
        'utm_campaign' => 'sk-2026-ramadan_01',
        'utm_term' => str_repeat('t', 120),
    ]);
});
