<?php

declare(strict_types=1);

use AIArmada\Affiliates\Models\Affiliate;
use AIArmada\Affiliates\Models\AffiliateAttribution;
use AIArmada\Affiliates\Models\AffiliateLink;
use AIArmada\Affiliates\Models\AffiliateTouchpoint;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Links\Actions\CreatePublicLink;
use AIArmada\Links\Actions\DeactivateLink;
use AIArmada\Links\Models\Link;
use AIArmada\Links\Models\LinkClick;
use App\Models\Event;
use App\Models\User;
use App\Services\ShareTrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->sharer = User::factory()->create([
        'name' => 'WP3 Sharer',
        'email' => 'wp3-sharer@example.test',
    ]);

    config(['dawah-share.reporting.reporting_start' => '2026-10-01']);
});

function wp3slLink(User $sharer, string $url): AffiliateLink
{
    app(ShareTrackingService::class)->createOrReuseLink($sharer, $url, 'Majlis', 'web');

    $affiliate = Affiliate::query()
        ->where('owner_type', $sharer->getMorphClass())
        ->where('owner_id', $sharer->getKey())
        ->firstOrFail();

    return AffiliateLink::query()->where('affiliate_id', $affiliate->getKey())->latest('created_at')->firstOrFail();
}

function wp3slEvent(): Event
{
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHours(2),
        'timing_mode' => 'absolute',
    ]);

    EventOccurrence::factory()->create([
        'event_id' => $event->getKey(),
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHours(2),
    ]);

    $fresh = $event->fresh();

    expect($fresh)->toBeInstanceOf(Event::class);

    return $fresh;
}

function wp3slShortSlug(AffiliateLink $link): string
{
    $tracked = $link->trackedLink()->firstOrFail();

    expect($tracked->slug_prefix)->toBeNull();

    return (string) $tracked->slug;
}

it('emits one canonical short URL per share payload', function (): void {
    $response = $this->actingAs($this->sharer)->getJson(route('dawah-share.payload', [
        'url' => route('events.index'),
        'text' => 'Share this page',
        'title' => 'Shared Page',
    ]));

    $response->assertOk();
    $payload = $response->json();

    expect($payload['url'])->toStartWith(url('/k/'))
        ->and($payload['tracking_token'])->toMatch('/^[a-z0-9]{16}$/')
        ->and($payload['channel_urls']['copy_link'])->toStartWith($payload['url'])
        ->and($payload['channel_urls']['whatsapp'])->toContain('channel=whatsapp');

    $link = AffiliateLink::query()->where('custom_slug', $payload['tracking_token'])->firstOrFail();

    expect($link->trackedLink()->count())->toBe(1)
        ->and($payload['url'])->toBe(route('share.short', ['slug' => wp3slShortSlug($link)]));
});

it('issues a separate claim capability alongside the guest credential', function (): void {
    $response = $this->getJson(route('dawah-share.payload', [
        'url' => route('events.index'),
        'text' => 'Share this page',
        'title' => 'Shared Page',
    ]));

    $response->assertOk();
    $payload = $response->json();

    expect($payload['guest_credential'])->toMatch('/^[A-Za-z0-9]{32}$/')
        ->and($payload['guest_claim_capability'])->toMatch('/^[A-Za-z0-9]{32}$/')
        ->and($payload['guest_claim_capability'])->not->toBe($payload['guest_credential']);

    $response->assertCookie('mi_dawah_guest')
        ->assertCookie('mi_dawah_guest_claim');

    // Reuse never re-issues the capability: presenting the share
    // credential again yields no fresh ownership proof. Header transport
    // here follows the proven API pattern; encrypted-cookie presentation
    // is covered on the web claim routes.
    $repeat = $this->withHeader('X-Dawah-Guest-Credential', $payload['guest_credential'])
        ->getJson(route('dawah-share.payload', [
            'url' => route('events.index'),
            'text' => 'Share this page',
            'title' => 'Shared Page',
        ]));

    $repeat->assertOk();
    expect($repeat->json('guest_claim_capability'))->toBeNull()
        ->and($repeat->json('tracking_token'))->toBe($payload['tracking_token']);
});

it('forwards share, origin, channel, and allowlisted campaign across the hop', function (): void {
    $link = wp3slLink($this->sharer, route('events.index'));

    $response = $this->get(route('share.short', ['slug' => wp3slShortSlug($link)]).'?channel=whatsapp&utm_campaign=ramadan');

    $response->assertRedirect();
    $target = (string) $response->headers->get('Location');

    expect($target)->toContain('share='.$link->custom_slug)
        ->and($target)->toContain('channel=whatsapp')
        ->and($target)->toContain('utm_campaign=ramadan');

    // The adapter is a pure redirect: no click trail, no attribution, no
    // touchpoint, and no cookie of its own.
    expect(LinkClick::query()->count())->toBe(0)
        ->and(AffiliateAttribution::query()->count())->toBe(0)
        ->and(AffiliateTouchpoint::query()->count())->toBe(0)
        ->and($response->getCookie((string) config('dawah-share.cookie.name')))->toBeNull();
});

it('drops invalid channels, click IDs, and secret-bearing campaign values', function (): void {
    $link = wp3slLink($this->sharer, route('events.index'));
    $long = str_repeat('a', 121);

    $response = $this->get(route('share.short', ['slug' => wp3slShortSlug($link)]).'?channel=evil&gclid=abc123&utm_campaign='.$long.'&utm_source=x%20y');

    $response->assertRedirect();
    $target = (string) $response->headers->get('Location');

    expect($target)->toContain('share='.$link->custom_slug)
        ->and($target)->not->toContain('channel=')
        ->and($target)->not->toContain('gclid')
        ->and($target)->not->toContain('utm_campaign')
        ->and($target)->not->toContain('utm_source');
});

it('records exactly one landing when the destination is visited after the hop', function (): void {
    $link = wp3slLink($this->sharer, route('events.show', wp3slEvent()));

    $hop = $this->get(route('share.short', ['slug' => wp3slShortSlug($link)]).'?channel=telegram');
    $hop->assertRedirect();

    $landing = $this->get((string) $hop->headers->get('Location'));
    $landing->assertOk();

    expect(AffiliateAttribution::query()->count())->toBe(1)
        ->and(AffiliateTouchpoint::query()->where('touchpoint_type', 'visit')->count())->toBe(1)
        ->and(LinkClick::query()->count())->toBe(0);

    $attribution = AffiliateAttribution::query()->firstOrFail();

    expect((string) $attribution->channel)->toBe('telegram')
        ->and((string) $attribution->affiliate_link_id)->toBe((string) $link->getKey());

    // A second adapter hit alone writes nothing more.
    $this->get(route('share.short', ['slug' => wp3slShortSlug($link)]));

    expect(AffiliateAttribution::query()->count())->toBe(1)
        ->and(AffiliateTouchpoint::query()->where('touchpoint_type', 'visit')->count())->toBe(1);
});

it('rejects unknown, branded, and non-share slugs honestly', function (): void {
    $this->get(route('share.short', ['slug' => 'no-such-slug-1']))->assertNotFound();
    $this->get('/k/not%20a%20slug!')->assertNotFound();

    $commercial = OwnerContext::withOwner(null, fn (): Link => CreatePublicLink::run(
        ['name' => 'Commercial', 'destination_url' => route('events.index')],
    ));

    $this->get(route('share.short', ['slug' => $commercial->slug]))->assertNotFound();
});

it('keeps opening the public destination with credit cleared after deactivation', function (): void {
    $event = wp3slEvent();
    $link = wp3slLink($this->sharer, route('events.show', $event));
    $link->deactivate();

    $response = $this->get(route('share.short', ['slug' => wp3slShortSlug($link)]));

    $response->assertRedirect();
    $target = (string) $response->headers->get('Location');

    // Plain destination, no dead token: a disabled link behaves like a
    // plain URL and can never clear an unrelated valid selection.
    expect($target)->not->toContain('share=');

    $landing = $this->get($target);
    $landing->assertOk();

    expect(AffiliateAttribution::query()->count())->toBe(0)
        ->and(AffiliateTouchpoint::query()->count())->toBe(0);
});

it('retains the public redirect with revoked credit after sharer deletion', function (): void {
    $event = wp3slEvent();
    $link = wp3slLink($this->sharer, route('events.show', $event));
    $slug = wp3slShortSlug($link);

    $this->sharer->delete();

    // The share link is gone but the managed redirect survives, scrubbed
    // into a minimal public mapping.
    expect(AffiliateLink::query()->whereKey($link->getKey())->exists())->toBeFalse();

    $tracked = Link::query()->withoutOwnerScope()->where('slug', $slug)->firstOrFail();

    expect((string) $tracked->subject_id)->toBe('00000000-0000-0000-0000-000000000000')
        ->and($tracked->parameters)->toBe([])
        ->and($tracked->name)->toBe('Detached share redirect');

    $response = $this->get(route('share.short', ['slug' => $slug]));
    $response->assertRedirect();
    $target = (string) $response->headers->get('Location');

    expect($target)->not->toContain('share=');

    // The generic commercial route keeps its lifecycle semantics: a
    // detached row is gone there, while the share entry still opens.
    $this->get(route('links.redirect', ['slug' => $slug]))->assertGone();

    $landing = $this->get($target);
    $landing->assertOk();

    expect(AffiliateAttribution::query()->count())->toBe(0);
});

it('shows honest unavailable content when the destination is no longer public', function (): void {
    $event = wp3slEvent();
    $link = wp3slLink($this->sharer, route('events.show', $event));

    $event->update(['status' => 'draft', 'published_at' => null]);

    $response = $this->get(route('share.short', ['slug' => wp3slShortSlug($link)]));
    $response->assertRedirect(route('share.unavailable'));

    $page = $this->get(route('share.unavailable'));
    $page->assertOk();
    $page->assertSee(__('This shared link is no longer available'));

    expect(AffiliateAttribution::query()->count())->toBe(0)
        ->and(AffiliateTouchpoint::query()->count())->toBe(0);
});

it('treats preview bots as non-impact while still redirecting them', function (): void {
    $link = wp3slLink($this->sharer, route('events.show', wp3slEvent()));

    $response = $this->withHeader('User-Agent', 'WhatsApp/2.24.0 iPhone')->get(
        route('share.short', ['slug' => wp3slShortSlug($link)])
    );

    $response->assertRedirect();

    expect(LinkClick::query()->count())->toBe(0)
        ->and(AffiliateAttribution::query()->count())->toBe(0)
        ->and(AffiliateTouchpoint::query()->count())->toBe(0);

    // The destination itself also ignores the bot: no attribution, no
    // visit, cookie untouched.
    $landing = $this->withHeader('User-Agent', 'WhatsApp/2.24.0 iPhone')->get(
        (string) $response->headers->get('Location')
    );

    $landing->assertOk();

    expect(AffiliateAttribution::query()->count())->toBe(0)
        ->and(AffiliateTouchpoint::query()->count())->toBe(0)
        ->and(LinkClick::query()->count())->toBe(0);
});

it('falls back to a safe direct attributed URL when the managed row is missing', function (): void {
    $link = wp3slLink($this->sharer, route('events.index'));
    $link->trackedLink()->delete();

    $payload = $this->actingAs($this->sharer)->getJson(route('dawah-share.payload', [
        'url' => route('events.index'),
        'text' => 'Share this page',
        'title' => 'Shared Page',
    ]))->json();

    expect($payload['url'])->toContain('share='.$link->custom_slug)
        ->and($payload['url'])->not->toContain('/k/');
});

it('never mints a second tracked row on reuse', function (): void {
    $url = route('events.index');
    $service = app(ShareTrackingService::class);

    $first = $service->createOrReuseLink($this->sharer, $url, 'Majlis', 'web');
    $second = $service->createOrReuseLink($this->sharer, $url, 'Majlis', 'web');

    expect($second->id)->toBe($first->id)
        ->and(Link::query()->withoutOwnerScope()->count())->toBe(1);
});

it('refuses share targets carrying the claim capability', function (): void {
    $secret = str_repeat('c', 32);

    foreach ([
        route('events.index').'?guest_claim_capability='.$secret,
        route('events.index').'?Guest_Claim_Capability='.$secret,
    ] as $url) {
        try {
            app(ShareTrackingService::class)->createOrReuseLink($this->sharer, $url, 'Majlis', 'web');

            $this->fail('A claim-capability-bearing URL must never become a tracked destination.');
        } catch (InvalidArgumentException $exception) {
            expect($exception->getMessage())->toContain('credentials or private tokens');
        }
    }

    expect(AffiliateLink::query()->count())->toBe(0);
});

it('preserves generic commercial redirect semantics on the /go/ route', function (): void {
    $active = OwnerContext::withOwner(null, fn (): Link => CreatePublicLink::run(
        ['name' => 'Commercial active', 'destination_url' => route('events.index')],
    ));
    $retired = OwnerContext::withOwner(null, fn (): Link => CreatePublicLink::run(
        ['name' => 'Commercial retired', 'destination_url' => route('events.index')],
    ));

    DeactivateLink::run($retired);

    $this->get(route('links.redirect', ['slug' => $active->slug]))->assertRedirect();
    $this->get(route('links.redirect', ['slug' => $retired->slug]))->assertGone();
});
