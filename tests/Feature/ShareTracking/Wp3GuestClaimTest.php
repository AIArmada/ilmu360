<?php

declare(strict_types=1);

use AIArmada\Affiliates\Actions\Identity\RevokeAffiliateClaim;
use AIArmada\Affiliates\Models\Affiliate;
use AIArmada\Affiliates\Models\AffiliateAttribution;
use AIArmada\Affiliates\Models\AffiliateClaim;
use AIArmada\Affiliates\Models\AffiliateConversion;
use AIArmada\Affiliates\Models\AffiliateCredential;
use AIArmada\Affiliates\Models\AffiliateLink;
use AIArmada\Affiliates\Models\AffiliateOwnershipCapability;
use AIArmada\Events\Models\EventOccurrence;
use App\Actions\ShareTracking\ClaimGuestShareProfileAction;
use App\Enums\ShareGuestClaimResult;
use App\Models\Event;
use App\Models\ShareSourceAttributionReceipt;
use App\Models\User;
use App\Services\ShareTracking\AffiliatesShareTrackingAnalyticsService;
use App\Services\ShareTracking\AffiliatesShareTrackingService;
use App\Services\ShareTrackingService;
use App\Support\ShareTracking\ShareTrackingProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->claimant = User::factory()->create([
        'name' => 'WP3 Claimant',
        'email' => 'wp3-claimant@example.test',
    ]);

    $this->rival = User::factory()->create([
        'name' => 'WP3 Rival',
        'email' => 'wp3-rival@example.test',
    ]);

    config(['dawah-share.reporting.reporting_start' => '2026-10-01']);
});

/**
 * @return array{capability: string, credential: string, token: string, affiliate_id: string}
 */
function wp3gcGuestShare(?string $url = null): array
{
    $response = test()->getJson(route('dawah-share.payload', [
        'url' => $url ?? route('events.index'),
        'text' => 'Share this page',
        'title' => 'Shared Page',
    ]));

    $response->assertOk();
    $payload = $response->json();

    $link = AffiliateLink::query()->where('custom_slug', $payload['tracking_token'])->firstOrFail();

    return [
        'capability' => $payload['guest_claim_capability'],
        'credential' => $payload['guest_credential'],
        'token' => $payload['tracking_token'],
        'affiliate_id' => (string) $link->affiliate_id,
    ];
}

function wp3gcEvent(): Event
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

it('claims a guest history explicitly after signup', function (): void {
    Notification::fake();
    $guest = wp3gcGuestShare();

    $page = $this->actingAs($this->claimant)
        ->withCredentials()
        ->withCookie('mi_dawah_guest_claim', $guest['capability'])
        ->get(route('share.claim.show'));

    $page->assertOk();
    $page->assertSee(__('Link my past shares'));

    $claim = $this->actingAs($this->claimant)
        ->withCredentials()
        ->withCookie('mi_dawah_guest_claim', $guest['capability'])
        ->post(route('share.claim.store'), ['confirm' => true]);

    $claim->assertRedirect(route('share.claim.show'));
    $claim->assertSessionHas('share_claim_status', 'claimed');

    $row = AffiliateClaim::query()->where('affiliate_id', $guest['affiliate_id'])->firstOrFail();

    expect($row->status)->toBe('active')
        ->and((string) $row->claimant_id)->toBe((string) $this->claimant->getKey())
        ->and($row->claimed_at)->not->toBeNull()
        ->and($row->revoked_at)->toBeNull();

    // Single-use: the capability is consumed, never reusable.
    $capability = AffiliateOwnershipCapability::query()
        ->where('affiliate_id', $guest['affiliate_id'])
        ->firstOrFail();

    expect($capability->status)->toBe('consumed')
        ->and($capability->consumed_at)->not->toBeNull();

    // The guest source is untouched (owner stays null, kind stays
    // guest); the future recipient is the distinct member identity.
    $source = Affiliate::query()->whereKey($guest['affiliate_id'])->firstOrFail();
    $recipient = app(AffiliatesShareTrackingService::class)->findAffiliateForUser($this->claimant);

    expect(ShareTrackingProfile::isMarkedGuest($source))->toBeTrue()
        ->and($source->owner)->toBeNull()
        ->and($recipient)->toBeInstanceOf(Affiliate::class)
        ->and((string) $recipient->getKey())->not->toBe($guest['affiliate_id'])
        ->and(ShareTrackingProfile::isMarkedMemberFor($recipient, $this->claimant))->toBeTrue();

    // A claim records the association only: no award, no notification,
    // no receipt rewrite.
    expect(AffiliateConversion::query()->count())->toBe(0)
        ->and(ShareSourceAttributionReceipt::query()->count())->toBe(0);

    Notification::assertNothingSent();
});

it('replays success idempotently to the original claimant without effects', function (): void {
    $guest = wp3gcGuestShare();

    $call = fn () => $this->actingAs($this->claimant)
        ->withCredentials()
        ->withCookie('mi_dawah_guest_claim', $guest['capability'])
        ->postJson(route('share.claim.store'), ['confirm' => true]);

    $first = $call();
    $first->assertOk();
    expect($first->json('result'))->toBe(ShareGuestClaimResult::Claimed->value);

    $consumedAt = AffiliateOwnershipCapability::query()
        ->where('affiliate_id', $guest['affiliate_id'])
        ->value('consumed_at');

    $second = $call();
    $second->assertOk();
    expect($second->json('result'))->toBe(ShareGuestClaimResult::AlreadyClaimedByYou->value);

    // No new row, no touch, no effect replay.
    expect(AffiliateClaim::query()->where('affiliate_id', $guest['affiliate_id'])->count())->toBe(1)
        ->and((string) AffiliateOwnershipCapability::query()->where('affiliate_id', $guest['affiliate_id'])->value('consumed_at'))
        ->toBe((string) $consumedAt)
        ->and(AffiliateConversion::query()->count())->toBe(0)
        ->and(ShareSourceAttributionReceipt::query()->count())->toBe(0);
});

it('refuses competing claimants generically without leaking history', function (): void {
    $guest = wp3gcGuestShare();

    app(ClaimGuestShareProfileAction::class)->handle($this->claimant, $guest['capability'], true);

    $rival = $this->actingAs($this->rival)
        ->withCredentials()
        ->withCookie('mi_dawah_guest_claim', $guest['capability'])
        ->postJson(route('share.claim.store'), ['confirm' => true]);

    $rival->assertStatus(422);
    expect($rival->json('result'))->toBe('unverified');

    $stranger = User::factory()->create();
    $expired = $this->actingAs($stranger)
        ->withCredentials()
        ->withCookie('mi_dawah_guest_claim', str_repeat('z', 32))
        ->postJson(route('share.claim.store'), ['confirm' => true]);

    $expired->assertStatus(422);
    expect($expired->json('result'))->toBe('unverified');

    // Identical rendered refusal: status, wire code, and message are
    // equal, so claimed-by-other reveals nothing beyond what an
    // invalid capability would.
    expect($rival->json('result'))->toBe($expired->json('result'))
        ->and($rival->json('message'))->toBe($expired->json('message'))
        ->and($rival->getStatusCode())->toBe($expired->getStatusCode());

    // The committed winner stands; the loser wrote nothing.
    expect(AffiliateClaim::query()->count())->toBe(1)
        ->and((string) AffiliateClaim::query()->value('claimant_id'))
        ->toBe((string) $this->claimant->getKey());
});

it('never accepts public tokens, query secrets, or empty proof', function (): void {
    $guest = wp3gcGuestShare();

    // A stolen public share token is not ownership proof.
    $stolen = $this->actingAs($this->rival)
        ->postJson(route('share.claim.store'), [
            'confirm' => true,
            'guest_claim_capability' => $guest['token'],
        ]);

    $stolen->assertStatus(422);
    expect($stolen->json('result'))->toBe('unverified');

    $stranger = User::factory()->create();

    // The valid capability in a GET query is never read, even when it
    // is the only transport carrying it.
    $query = $this->actingAs($stranger)
        ->postJson(route('share.claim.store').'?guest_claim_capability='.$guest['capability'], ['confirm' => true]);

    $query->assertStatus(422);
    expect($query->json('result'))->toBe('unverified');

    // Same session, same network, no capability: no proof, no claim.
    $empty = $this->actingAs($stranger)
        ->postJson(route('share.claim.store'), ['confirm' => true]);

    $empty->assertStatus(422);
    expect($empty->json('result'))->toBe('unverified')
        ->and(AffiliateClaim::query()->count())->toBe(0);
});

it('treats expired capabilities as honestly unverifiable', function (): void {
    $guest = wp3gcGuestShare();

    AffiliateOwnershipCapability::query()
        ->where('affiliate_id', $guest['affiliate_id'])
        ->update(['expires_at' => now()->subMinute()]);

    $response = $this->actingAs($this->claimant)
        ->withCredentials()
        ->withCookie('mi_dawah_guest_claim', $guest['capability'])
        ->postJson(route('share.claim.store'), ['confirm' => true]);

    $response->assertStatus(422);
    expect($response->json('result'))->toBe('unverified')
        ->and(AffiliateClaim::query()->count())->toBe(0);

    $page = $this->actingAs($this->claimant)
        ->withCredentials()
        ->withCookie('mi_dawah_guest_claim', $guest['capability'])
        ->get(route('share.claim.show'));

    $page->assertOk();
    $page->assertDontSee(__('Link my past shares'));
});

it('never silently inherits claimed history across logout and account switch', function (): void {
    $guest = wp3gcGuestShare();

    app(ClaimGuestShareProfileAction::class)->handle($this->claimant, $guest['capability'], true);

    // A different account on the same browser (same cookies) sees no
    // prompt and cannot take the history.
    $page = $this->actingAs($this->rival)
        ->withCredentials()
        ->withCookie('mi_dawah_guest_claim', $guest['capability'])
        ->get(route('share.claim.show'));

    $page->assertOk();
    $page->assertDontSee(__('Link my past shares'));

    $steal = $this->actingAs($this->rival)
        ->withCredentials()
        ->withCookie('mi_dawah_guest_claim', $guest['capability'])
        ->postJson(route('share.claim.store'), ['confirm' => true]);

    $steal->assertStatus(422);
    expect($steal->json('result'))->toBe('unverified');

    $dashboard = $this->actingAs($this->rival)
        ->withCredentials()
        ->withCookie('mi_dawah_guest_claim', $guest['capability'])
        ->get(route('dashboard'));

    $dashboard->assertOk();
    $dashboard->assertDontSee(__('Review and link'));

    // The original claimant replays their own success.
    $mine = $this->actingAs($this->claimant)
        ->withCredentials()
        ->withCookie('mi_dawah_guest_claim', $guest['capability'])
        ->get(route('share.claim.show'));

    $mine->assertOk();
    $mine->assertSee(__('Those past shares are already linked to your account. Nothing further is needed.'));
});

it('prompts an unclaimed browser on the dashboard without auto-claiming', function (): void {
    $guest = wp3gcGuestShare();

    // Quiet first: test-client cookies persist across calls, so the
    // capability-bearing request runs last.
    $quiet = $this->actingAs($this->claimant)->get(route('dashboard'));

    $quiet->assertOk();
    $quiet->assertDontSee(__('Review and link'));

    $prompted = $this->actingAs($this->claimant)
        ->withCredentials()
        ->withCookie('mi_dawah_guest_claim', $guest['capability'])
        ->get(route('dashboard'));

    $prompted->assertOk();
    $prompted->assertSee(__('Review and link'));

    expect(AffiliateClaim::query()->count())->toBe(0);
});

it('revokes claims on claimant deletion without steal, reassign, or restore', function (): void {
    $guest = wp3gcGuestShare();

    app(ClaimGuestShareProfileAction::class)->handle($this->claimant, $guest['capability'], true);

    $this->claimant->delete();

    $row = AffiliateClaim::query()->where('affiliate_id', $guest['affiliate_id'])->firstOrFail();

    // Preserved as revoked history: never stealable or reassignable.
    expect($row->status)->toBe('revoked')
        ->and($row->revoked_at)->not->toBeNull()
        ->and(AffiliateOwnershipCapability::query()->where('affiliate_id', $guest['affiliate_id'])->value('status'))
        ->toBe('consumed');

    $rival = $this->actingAs($this->rival)
        ->withCredentials()
        ->withCookie('mi_dawah_guest_claim', $guest['capability'])
        ->postJson(route('share.claim.store'), ['confirm' => true]);

    $rival->assertStatus(422);
    expect($rival->json('result'))->toBe('unverified');

    User::restoreDeletedUser($this->claimant->getKey());

    $restored = AffiliateClaim::query()->where('affiliate_id', $guest['affiliate_id'])->firstOrFail();

    expect($restored->status)->toBe('revoked')
        ->and($restored->revoked_at)->not->toBeNull();

    // Even the original claimant cannot reclaim deleted history, and
    // nothing was replayed by the restore.
    // fresh() on the deleted in-memory model resolves null; requery.
    $restoredClaimant = User::query()->whereKey($this->claimant->getKey())->firstOrFail();

    $retry = $this->actingAs($restoredClaimant)
        ->withCredentials()
        ->withCookie('mi_dawah_guest_claim', $guest['capability'])
        ->postJson(route('share.claim.store'), ['confirm' => true]);

    $retry->assertStatus(422);
    expect($retry->json('result'))->toBe('unverified')
        ->and(AffiliateClaim::query()->count())->toBe(1)
        ->and(AffiliateConversion::query()->count())->toBe(0)
        ->and(ShareSourceAttributionReceipt::query()->count())->toBe(0);
});

it('re-evaluates self credit after the claim without rewriting frozen attribution', function (): void {
    $event = wp3gcEvent();

    $response = $this->getJson(route('dawah-share.payload', [
        'url' => route('events.show', $event),
        'text' => 'Share this page',
        'title' => 'Shared Page',
    ]));

    $payload = $response->json();
    $link = AffiliateLink::query()->where('custom_slug', $payload['tracking_token'])->firstOrFail();

    $landing = $this->get(route('events.show', $event).'?share='.$link->custom_slug);
    $landing->assertOk();
    $cookie = (string) $landing->getCookie((string) config('dawah-share.cookie.name'))?->getValue();

    expect($cookie)->not->toBe('');

    $attributionBefore = AffiliateAttribution::query()->firstOrFail();

    app(ClaimGuestShareProfileAction::class)->handle($this->claimant, $payload['guest_claim_capability'], true);

    // Frozen source attribution is untouched by the claim.
    expect((string) $attributionBefore->fresh()->affiliate_id)->toBe((string) $link->affiliate_id);

    $signupRequest = Request::create(route('events.show', $event), 'GET');
    $signupRequest->cookies->set((string) config('dawah-share.cookie.name'), $cookie);

    // The claimant acting through their own claimed history earns
    // nothing: self credit with an honest unattributed receipt.
    $outcome = app(ShareTrackingService::class)->recordSignup($this->claimant, $signupRequest);

    expect($outcome)->toBeNull();

    $receipt = ShareSourceAttributionReceipt::query()->firstOrFail();

    expect($receipt->isAttributed())->toBeFalse()
        ->and(AffiliateConversion::query()->count())->toBe(0)
        ->and((string) $attributionBefore->fresh()->affiliate_id)->toBe((string) $link->affiliate_id);
});

it('scopes ownership reads to primary plus actively claimed profiles', function (): void {
    $event = wp3gcEvent();
    $guest = wp3gcGuestShare(route('events.show', $event));
    $guestLinkId = (string) AffiliateLink::query()->where('custom_slug', $guest['token'])->value('id');

    $this->withHeader('X-Dawah-Guest-Credential', $guest['credential'])
        ->postJson(route('api.client.share.track'), [
            'tracking_token' => $guest['token'],
            'provider' => 'copy_link',
        ])->assertNoContent();

    $this->get(route('events.show', $event).'?share='.$guest['token'])->assertOk();

    app(ClaimGuestShareProfileAction::class)->handle($this->claimant, $guest['capability'], true);

    $analytics = app(AffiliatesShareTrackingAnalyticsService::class);
    $summary = $analytics->summaryForUser($this->claimant);

    expect($summary['outbound_shares'])->toBe(1)
        ->and($summary['visits'])->toBe(1);

    $links = $analytics->linksForUser($this->claimant);

    expect($links->contains(fn ($link): bool => (string) $link->id === $guestLinkId))->toBeTrue()
        ->and($analytics->findLinkForUser($this->claimant, $guestLinkId))->not->toBeNull();

    // A revoked claim detaches the history from every read.
    $revocable = AffiliateClaim::query()->where('affiliate_id', $guest['affiliate_id'])->firstOrFail();
    app(RevokeAffiliateClaim::class)->handle($revocable);

    expect($analytics->summaryForUser($this->claimant)['outbound_shares'])->toBe(0)
        ->and($analytics->summaryForUser($this->claimant)['visits'])->toBe(0)
        ->and($analytics->linksForUser($this->claimant)->isEmpty())->toBeTrue()
        ->and($analytics->findLinkForUser($this->claimant, $guestLinkId))->toBeNull();

    // The rival never saw the history at all.
    expect($analytics->summaryForUser($this->rival)['outbound_shares'])->toBe(0)
        ->and($analytics->summaryForUser($this->rival)['visits'])->toBe(0);
});

it('never auto-claims through signup or login', function (): void {
    $guest = wp3gcGuestShare();

    $registered = $this->withCredentials()
        ->withCookie('mi_dawah_guest_claim', $guest['capability'])
        ->post(route('register.store'), [
            'name' => 'Fresh Signup',
            'email' => 'wp3-fresh-signup@example.test',
            'password' => 'secret-secret-1',
            'password_confirmation' => 'secret-secret-1',
        ]);

    $registered->assertRedirect();
    $this->assertAuthenticated();

    expect(AffiliateClaim::query()->count())->toBe(0);

    // The fresh account can still claim explicitly afterwards.
    $user = User::query()->where('email', 'wp3-fresh-signup@example.test')->firstOrFail();

    $claim = $this->actingAs($user)
        ->withCredentials()
        ->withCookie('mi_dawah_guest_claim', $guest['capability'])
        ->post(route('share.claim.store'), ['confirm' => true]);

    $claim->assertRedirect(route('share.claim.show'));
    expect(AffiliateClaim::query()->count())->toBe(1);
});

it('fails honestly when the member recipient cannot receive', function (): void {
    app(ShareTrackingService::class)->createOrReuseLink($this->claimant, route('events.index'), 'Majlis', 'web');

    $member = app(AffiliatesShareTrackingService::class)->findAffiliateForUser($this->claimant);

    expect($member)->toBeInstanceOf(Affiliate::class);

    $member->update(['deactivated_at' => now()]);

    $guest = wp3gcGuestShare();

    $response = $this->actingAs($this->claimant)
        ->withCredentials()
        ->withCookie('mi_dawah_guest_claim', $guest['capability'])
        ->postJson(route('share.claim.store'), ['confirm' => true]);

    $response->assertStatus(422);
    expect($response->json('result'))->toBe(ShareGuestClaimResult::RecipientIneligible->value)
        ->and(AffiliateClaim::query()->count())->toBe(0)
        ->and(AffiliateOwnershipCapability::query()->where('affiliate_id', $guest['affiliate_id'])->value('status'))
        ->toBe('active');
});

it('claims natively after API login with the same guarantees', function (): void {
    $guest = wp3gcGuestShare();

    Sanctum::actingAs($this->claimant);

    $available = $this->withHeader('X-Dawah-Claim-Capability', $guest['capability'])
        ->getJson(route('api.client.share.claim.availability'));

    $available->assertOk();
    expect($available->json('available'))->toBeTrue();

    $claim = $this->withHeader('X-Dawah-Claim-Capability', $guest['capability'])
        ->postJson(route('api.client.share.claim'), ['confirm' => true]);

    $claim->assertOk();
    expect($claim->json('result'))->toBe(ShareGuestClaimResult::Claimed->value);

    $spent = $this->withHeader('X-Dawah-Claim-Capability', $guest['capability'])
        ->getJson(route('api.client.share.claim.availability'));

    expect($spent->json('available'))->toBeFalse()
        ->and(AffiliateClaim::query()->count())->toBe(1);

    Sanctum::actingAs($this->rival);

    $rival = $this->withHeader('X-Dawah-Claim-Capability', $guest['capability'])
        ->postJson(route('api.client.share.claim'), ['confirm' => true]);

    $rival->assertStatus(422);
    expect($rival->json('result'))->toBe('unverified');
});

it('renders the claim success message in Malay when the session is Malay', function (): void {
    $guest = wp3gcGuestShare();

    app()->setLocale('ms');

    $claim = $this->actingAs($this->claimant)
        ->withCredentials()
        ->withCookie('mi_dawah_guest_claim', $guest['capability'])
        ->postJson(route('share.claim.store'), ['confirm' => true]);

    $claim->assertOk();
    expect($claim->json('message'))->toBe('Kongsi lalu anda kini dipautkan ke akaun anda.');
});

it('renders the claim success message in English when the session is English', function (): void {
    $guest = wp3gcGuestShare();

    app()->setLocale('en');

    $claim = $this->actingAs($this->claimant)
        ->withCredentials()
        ->withCookie('mi_dawah_guest_claim', $guest['capability'])
        ->postJson(route('share.claim.store'), ['confirm' => true]);

    $claim->assertOk();
    expect($claim->json('message'))->toBe('Your past shares are now linked to your account.');
});

it('requires explicit confirmation for every claim', function (): void {
    $guest = wp3gcGuestShare();

    $this->actingAs($this->claimant)
        ->withCredentials()
        ->withCookie('mi_dawah_guest_claim', $guest['capability'])
        ->postJson(route('share.claim.store'), [])
        ->assertStatus(422);

    expect(fn () => app(ClaimGuestShareProfileAction::class)->handle($this->claimant, $guest['capability'], false))
        ->toThrow(InvalidArgumentException::class);

    expect(AffiliateClaim::query()->count())->toBe(0);
});

it('seals claimed history from later anonymous activity on the same browser', function (): void {
    $event = wp3gcEvent();
    $guest = wp3gcGuestShare(route('events.show', $event));

    // Pre-claim activity on the old history.
    $this->withHeader('X-Dawah-Guest-Credential', $guest['credential'])
        ->postJson(route('api.client.share.track'), [
            'tracking_token' => $guest['token'],
            'provider' => 'copy_link',
        ])->assertNoContent();

    $this->get(route('events.show', $event).'?share='.$guest['token'])->assertOk();

    // A claims explicitly over the web, then logs out.
    $this->actingAs($this->claimant)
        ->withCredentials()
        ->withCookie('mi_dawah_guest_claim', $guest['capability'])
        ->post(route('share.claim.store'), ['confirm' => true])
        ->assertRedirect(route('share.claim.show'));

    auth()->logout();

    expect(auth()->check())->toBeFalse();

    // The historical credential is sealed in the claim transaction.
    $credentialRow = AffiliateCredential::query()->where('affiliate_id', $guest['affiliate_id'])->firstOrFail();

    expect($credentialRow->status)->toBe('revoked')
        ->and($credentialRow->revoked_at)->not->toBeNull();

    // B anonymously shares from the same browser presenting the stale
    // credential: a genuinely new guest identity is issued, never a
    // write into A's claimed history.
    $second = $this->withHeader('X-Dawah-Guest-Credential', $guest['credential'])
        ->getJson(route('dawah-share.payload', [
            'url' => route('events.index'),
            'text' => 'Share this page',
            'title' => 'Shared Page',
        ]))
        ->assertOk()
        ->json();

    $secondAffiliateId = (string) AffiliateLink::query()->where('custom_slug', $second['tracking_token'])->value('affiliate_id');

    expect($second['guest_credential'])->toMatch('/^[0-9a-f]{64}$/')
        ->and($second['guest_credential'])->not->toBe($guest['credential'])
        ->and($second['guest_claim_capability'])->toMatch('/^[0-9a-f]{64}$/')
        ->and($second['guest_claim_capability'])->not->toBe($guest['capability'])
        ->and($second['tracking_token'])->not->toBe($guest['token'])
        ->and($secondAffiliateId)->not->toBe($guest['affiliate_id']);

    // The new identity is stable and reusable; replaying the dead
    // credential mints fresh again instead of reusing claimed history.
    $reuse = $this->withHeader('X-Dawah-Guest-Credential', $second['guest_credential'])
        ->getJson(route('dawah-share.payload', [
            'url' => route('events.index'),
            'text' => 'Share this page',
            'title' => 'Shared Page',
        ]))
        ->assertOk()
        ->json();

    expect($reuse['tracking_token'])->toBe($second['tracking_token'])
        ->and($reuse['guest_claim_capability'] ?? null)->toBeNull();

    $replay = $this->withHeader('X-Dawah-Guest-Credential', $guest['credential'])
        ->getJson(route('dawah-share.payload', [
            'url' => route('events.index', ['category' => 'fiqh']),
            'text' => 'Share this page',
            'title' => 'Shared Page',
        ]))
        ->assertOk()
        ->json();

    $replayAffiliateId = (string) AffiliateLink::query()->where('custom_slug', $replay['tracking_token'])->value('affiliate_id');

    expect($replayAffiliateId)->not->toBe($guest['affiliate_id'])
        ->and($replayAffiliateId)->not->toBe($secondAffiliateId);

    // A's owned history is untouched: original source, same rows, same
    // analytics; B's shares appear nowhere under A.
    $oldLink = AffiliateLink::query()->where('custom_slug', $guest['token'])->firstOrFail();
    $source = Affiliate::query()->whereKey($guest['affiliate_id'])->firstOrFail();

    expect((string) $oldLink->affiliate_id)->toBe($guest['affiliate_id'])
        ->and(ShareTrackingProfile::isMarkedGuest($source))->toBeTrue()
        ->and($source->owner)->toBeNull();

    $analytics = app(AffiliatesShareTrackingAnalyticsService::class);
    $summary = $analytics->summaryForUser($this->claimant);

    expect($summary['outbound_shares'])->toBe(1)
        ->and($summary['visits'])->toBe(1);

    $linkIds = $analytics->linksForUser($this->claimant)->map(fn ($link): string => (string) $link->id)->all();
    $secondLinkId = (string) AffiliateLink::query()->where('custom_slug', $second['tracking_token'])->value('id');

    expect($linkIds)->toContain((string) $oldLink->getKey())
        ->and($linkIds)->not->toContain($secondLinkId);

    // A lost capability stays honestly unverifiable: no credential, no
    // header, no prompt, no proof.
    $page = $this->actingAs($this->rival)->get(route('share.claim.show'));

    $page->assertOk();
    $page->assertDontSee(__('Link my past shares'));
});

it('attributes later anonymous history to its own claimant, never the first', function (): void {
    $event = wp3gcEvent();
    $guest = wp3gcGuestShare(route('events.show', $event));

    app(ClaimGuestShareProfileAction::class)->handle($this->claimant, $guest['capability'], true);

    $second = $this->withHeader('X-Dawah-Guest-Credential', $guest['credential'])
        ->getJson(route('dawah-share.payload', [
            'url' => route('events.index'),
            'text' => 'Share this page',
            'title' => 'Shared Page',
        ]))
        ->assertOk()
        ->json();

    $secondAffiliateId = (string) AffiliateLink::query()->where('custom_slug', $second['tracking_token'])->value('affiliate_id');

    // B logs in and claims the new history explicitly.
    $bClaim = $this->actingAs($this->rival)
        ->withCredentials()
        ->withCookie('mi_dawah_guest_claim', $second['guest_claim_capability'])
        ->postJson(route('share.claim.store'), ['confirm' => true]);

    $bClaim->assertOk();
    expect($bClaim->json('result'))->toBe(ShareGuestClaimResult::Claimed->value);

    // Ownership splits cleanly: each claimant reads only their history,
    // each recipient is their own distinct member identity.
    $analytics = app(AffiliatesShareTrackingAnalyticsService::class);
    $aLinks = $analytics->linksForUser($this->claimant)->map(fn ($link): string => (string) $link->id)->all();
    $bLinks = $analytics->linksForUser($this->rival)->map(fn ($link): string => (string) $link->id)->all();
    $oldLinkId = (string) AffiliateLink::query()->where('custom_slug', $guest['token'])->value('id');
    $newLinkId = (string) AffiliateLink::query()->where('custom_slug', $second['tracking_token'])->value('id');

    expect($aLinks)->toContain($oldLinkId)
        ->and($aLinks)->not->toContain($newLinkId)
        ->and($bLinks)->toContain($newLinkId)
        ->and($bLinks)->not->toContain($oldLinkId);

    $service = app(AffiliatesShareTrackingService::class);
    $aRecipient = $service->findAffiliateForUser($this->claimant);
    $bRecipient = $service->findAffiliateForUser($this->rival);

    expect((string) $aRecipient->getKey())->not->toBe($guest['affiliate_id'])
        ->and((string) $bRecipient->getKey())->not->toBe($secondAffiliateId)
        ->and((string) $bRecipient->getKey())->not->toBe((string) $aRecipient->getKey());

    // Self-credit follows proven ownership: A acting through B's history
    // attributes honestly, while B acting through it earns nothing.
    $landing = $this->get(route('events.index').'?share='.$second['tracking_token']);
    $landing->assertOk();
    $cookie = (string) $landing->getCookie((string) config('dawah-share.cookie.name'))?->getValue();

    expect($cookie)->not->toBe('');

    $requestFor = function (string $cookieValue): Request {
        $request = Request::create(route('events.index'), 'GET');
        $request->cookies->set((string) config('dawah-share.cookie.name'), $cookieValue);

        return $request;
    };

    expect(app(ShareTrackingService::class)->recordSignup($this->claimant, $requestFor($cookie)))->not->toBeNull()
        ->and(app(ShareTrackingService::class)->recordSignup($this->rival, $requestFor($cookie)))->toBeNull();

    // The claimant keeps recording on their owned tokens after the seal;
    // strangers and the other claimant are refused on each history.
    $this->actingAs($this->claimant)
        ->postJson(route('api.client.share.track'), ['tracking_token' => $guest['token'], 'provider' => 'copy_link'])
        ->assertNoContent();

    $this->actingAs($this->claimant)
        ->postJson(route('api.client.share.track'), ['tracking_token' => $second['tracking_token'], 'provider' => 'copy_link'])
        ->assertStatus(422);

    auth()->logout();

    $this->withHeader('X-Dawah-Guest-Credential', $guest['credential'])
        ->postJson(route('api.client.share.track'), ['tracking_token' => $guest['token'], 'provider' => 'copy_link'])
        ->assertStatus(422);
});

it('seals claimed history across native API transport with the same guarantees', function (): void {
    // Anonymous native issuance: no authentication on this client yet.
    $guest = $this->getJson(route('api.client.share.payload', [
        'url' => route('events.index'),
        'text' => 'Share this page',
        'title' => 'Shared Page',
    ]))->assertOk()->json();

    expect($guest['guest_credential'] ?? null)->toMatch('/^[0-9a-f]{64}$/');

    $guestAffiliateId = (string) AffiliateLink::query()->where('custom_slug', $guest['tracking_token'])->value('affiliate_id');

    Sanctum::actingAs($this->claimant);

    $claim = $this->withHeader('X-Dawah-Claim-Capability', $guest['guest_claim_capability'])
        ->postJson(route('api.client.share.claim'), ['confirm' => true]);

    $claim->assertOk();
    expect($claim->json('result'))->toBe(ShareGuestClaimResult::Claimed->value);

    expect(AffiliateCredential::query()->where('affiliate_id', $guestAffiliateId)->value('status'))
        ->toBe('revoked');

    // Back to an anonymous native client for B's share.
    auth()->forgetGuards();

    $second = $this->withHeader('X-Dawah-Guest-Credential', $guest['guest_credential'])
        ->getJson(route('api.client.share.payload', [
            'url' => route('events.index', ['category' => 'aqidah']),
            'text' => 'Share this page',
            'title' => 'Shared Page',
        ]))
        ->assertOk()
        ->json();

    $secondAffiliateId = (string) AffiliateLink::query()->where('custom_slug', $second['tracking_token'])->value('affiliate_id');

    expect($secondAffiliateId)->not->toBe($guestAffiliateId);

    Sanctum::actingAs($this->rival);

    $bClaim = $this->withHeader('X-Dawah-Claim-Capability', $second['guest_claim_capability'])
        ->postJson(route('api.client.share.claim'), ['confirm' => true]);

    $bClaim->assertOk();
    expect($bClaim->json('result'))->toBe(ShareGuestClaimResult::Claimed->value);

    // Recording matrix over API: owners record on their history, revoked
    // credentials and foreign histories refuse.
    $this->postJson(route('api.client.share.track'), ['tracking_token' => $second['tracking_token'], 'provider' => 'copy_link'])
        ->assertNoContent();

    $this->postJson(route('api.client.share.track'), ['tracking_token' => $guest['tracking_token'], 'provider' => 'copy_link'])
        ->assertStatus(422);

    auth()->forgetGuards();

    $this->withHeader('X-Dawah-Guest-Credential', $guest['guest_credential'])
        ->postJson(route('api.client.share.track'), ['tracking_token' => $guest['tracking_token'], 'provider' => 'copy_link'])
        ->assertStatus(422);

    // B's claim sealed the second credential too: anonymous recording on
    // the new history refuses once owned.
    $this->withHeader('X-Dawah-Guest-Credential', $second['guest_credential'])
        ->postJson(route('api.client.share.track'), ['tracking_token' => $second['tracking_token'], 'provider' => 'copy_link'])
        ->assertStatus(422);
});

it('centralizes claim transitions in actions with no model-level mutation path', function (): void {
    $guest = wp3gcGuestShare();

    app(ClaimGuestShareProfileAction::class)->handle($this->claimant, $guest['capability'], true);

    $claim = AffiliateClaim::query()->where('affiliate_id', $guest['affiliate_id'])->firstOrFail();
    $capability = AffiliateOwnershipCapability::query()->where('affiliate_id', $guest['affiliate_id'])->firstOrFail();

    // Rows intact; the centralized transition still works exactly once.
    expect((string) $claim->fresh()->claimant_id)->toBe((string) $this->claimant->getKey())
        ->and($claim->fresh()->status)->toBe('active')
        ->and($capability->fresh()->status)->toBe('consumed');

    app(RevokeAffiliateClaim::class)->handle($claim->fresh());

    expect($claim->fresh()->status)->toBe('revoked')
        ->and($claim->fresh()->revoked_at)->not->toBeNull();

    // Revoking twice is a safe no-op, and a revoked claim seals every
    // future claim — including the original claimant's.
    app(RevokeAffiliateClaim::class)->handle($claim->fresh());

    $retry = $this->actingAs($this->claimant)
        ->withCredentials()
        ->withCookie('mi_dawah_guest_claim', $guest['capability'])
        ->postJson(route('share.claim.store'), ['confirm' => true]);

    $retry->assertStatus(422);
    expect($retry->json('result'))->toBe('unverified');

    $reassign = $this->actingAs($this->rival)
        ->withCredentials()
        ->withCookie('mi_dawah_guest_claim', $guest['capability'])
        ->postJson(route('share.claim.store'), ['confirm' => true]);

    $reassign->assertStatus(422);
    expect($reassign->json('result'))->toBe('unverified')
        ->and(AffiliateClaim::query()->where('affiliate_id', $guest['affiliate_id'])->count())->toBe(1);
});

it('revokes claims and disarms sources when the member profile is already gone', function (): void {
    $guest = wp3gcGuestShare();

    app(ClaimGuestShareProfileAction::class)->handle($this->claimant, $guest['capability'], true);

    $service = app(AffiliatesShareTrackingService::class);
    $service->findAffiliateForUser($this->claimant)->delete();

    expect($service->findAffiliateForUser($this->claimant))->toBeNull();

    app(ShareTrackingService::class)->deleteUserTracking($this->claimant);

    expect(AffiliateClaim::query()->where('affiliate_id', $guest['affiliate_id'])->value('status'))
        ->toBe('revoked');

    $link = AffiliateLink::query()->where('custom_slug', $guest['token'])->firstOrFail();

    expect($link->deactivated_at)->not->toBeNull();

    // The public redirect survives with credit cleared, and a second
    // cleanup run is a safe no-op.
    $tracked = $link->trackedLink()->firstOrFail();
    $response = $this->get(route('share.short', ['slug' => $tracked->slug]));

    $response->assertRedirect();
    expect((string) $response->headers->get('Location'))->not->toContain('share=');

    app(ShareTrackingService::class)->deleteUserTracking($this->claimant);

    expect(AffiliateClaim::query()->count())->toBe(1)
        ->and(AffiliateClaim::query()->value('status'))->toBe('revoked');
});

it('preserves settlement and the public redirect when a claimant is deleted', function (): void {
    $event = wp3gcEvent();
    $guest = wp3gcGuestShare(route('events.show', $event));
    $link = AffiliateLink::query()->where('custom_slug', $guest['token'])->firstOrFail();
    $slug = (string) $link->trackedLink()->firstOrFail()->slug;

    $landing = $this->get(route('events.show', $event).'?share='.$guest['token']);
    $landing->assertOk();
    $cookie = (string) $landing->getCookie((string) config('dawah-share.cookie.name'))?->getValue();

    expect($cookie)->not->toBe('');

    $stranger = User::factory()->create();
    $request = Request::create(route('events.show', $event), 'GET');
    $request->cookies->set((string) config('dawah-share.cookie.name'), $cookie);

    expect(app(ShareTrackingService::class)->recordSignup($stranger, $request))->not->toBeNull();

    $conversionId = (string) AffiliateConversion::query()->value('id');

    app(ClaimGuestShareProfileAction::class)->handle($this->claimant, $guest['capability'], true);

    $this->claimant->delete();

    expect(AffiliateClaim::query()->where('affiliate_id', $guest['affiliate_id'])->value('status'))
        ->toBe('revoked')
        ->and($link->fresh()->deactivated_at)->not->toBeNull()
        ->and(AffiliateConversion::query()->whereKey($conversionId)->exists())->toBeTrue();

    $response = $this->get(route('share.short', ['slug' => $slug]));

    $response->assertRedirect();
    $target = (string) $response->headers->get('Location');

    expect($target)->not->toContain('share=');

    $this->get($target)->assertOk();

    // No new credit accrues on the disarmed source.
    expect(AffiliateAttribution::query()->count())->toBe(1);
});
