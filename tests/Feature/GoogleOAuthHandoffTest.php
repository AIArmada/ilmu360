<?php

use AIArmada\Contacting\Enums\ContactMethodType;
use AIArmada\Contacting\Enums\ContactPurpose;
use App\Models\EventSubmission;
use App\Models\User;
use App\Support\Auth\OAuthTransactionStore;
use App\Support\Auth\SocialiteProviderConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

uses(RefreshDatabase::class);

function handoffTokenFromCallback(string $state): string
{
    $response = test()->get(route('socialite.callback', ['provider' => 'google', 'state' => $state]));
    $response->assertRedirect();

    $location = (string) $response->headers->get('Location');

    test()->assertStringStartsWith(
        rtrim((string) config('app.url'), '/').'/oauth/google/complete?token=',
        $location
    );

    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    $token = $query['token'] ?? null;

    if (! is_string($token) || $token === '') {
        test()->fail('Cross-domain callback did not issue a handoff token.');
    }

    return $token;
}

beforeEach(function () {
    config()->set('app.url', 'https://ilmu360.test');
    config()->set('services.google.client_id', 'google-client-id');
    config()->set('services.google.client_secret', 'google-client-secret');
    config()->set('services.google.redirect', 'https://dev.ilmu360.com/oauth/google/callback');
});

it('detects the cross-domain bridge from configuration', function () {
    expect(SocialiteProviderConfiguration::usesCrossDomainCallback('google'))->toBeTrue();

    config()->set('services.google.redirect', 'https://ilmu360.test/oauth/google/callback');

    expect(SocialiteProviderConfiguration::usesCrossDomainCallback('google'))->toBeFalse();
});

it('issues a one-time handoff instead of logging in on the callback host', function () {
    $token = googleOAuthHandoffToken();

    expect($token)->toHaveLength(64);

    $this->assertGuest();

    $this->assertDatabaseHas('users', [
        'email' => 'bridge@example.com',
        'name' => 'Bridge User',
    ]);

    // The handoff is stored hashed: the raw token must not be a cache key.
    expect(Cache::has('oauth-handoff:'.$token))->toBeFalse();
    expect(Cache::has('oauth-handoff:'.hash('sha256', $token)))->toBeTrue();
});

it('keeps user data out of the handoff URL', function () {
    $state = googleOAuthState();

    Socialite::fake('google', SocialiteUser::fake([
        'id' => 'google-bridge-123',
        'name' => 'Bridge User',
        'email' => 'bridge@example.com',
        'avatar' => 'https://example.com/bridge.jpg',
        'email_verified' => true,
    ]));

    $response = $this->get(route('socialite.callback', ['provider' => 'google', 'state' => $state]));

    $location = (string) $response->headers->get('Location');

    expect($location)
        ->not->toContain('bridge@example.com')
        ->not->toContain('google-bridge-123')
        ->not->toContain('Bridge User');
});

it('completes login on the canonical host and redirects to the intended page', function () {
    $target = route('persons.index', absolute: false);

    $token = googleOAuthHandoffToken([], ['redirect' => $target]);

    $response = $this->get(route('socialite.complete', ['provider' => 'google', 'token' => $token]));

    $response->assertRedirect($target);
    $this->assertAuthenticated();
    expect(session()->has('url.intended'))->toBeFalse();

    expect(User::query()->where('email', 'bridge@example.com')->count())->toBe(1);
});

it('rejects a reused handoff token', function () {
    $token = googleOAuthHandoffToken();

    $this->get(route('socialite.complete', ['provider' => 'google', 'token' => $token]))
        ->assertRedirect(route('home'));
    $this->assertAuthenticated();

    auth()->logout();

    $response = $this->get(route('socialite.complete', ['provider' => 'google', 'token' => $token]));

    $response->assertRedirect(route('login'));
    $response->assertSessionHas('toast.type', 'error');
    $this->assertGuest();
});

it('rejects an unknown handoff token', function () {
    $response = $this->get(route('socialite.complete', ['provider' => 'google', 'token' => str_repeat('a', 64)]));

    $response->assertRedirect(route('login'));
    $response->assertSessionHas('toast.type', 'error');
    $response->assertSessionHas('toast.message', __('Your sign-in session expired. Please try again.'));
    $this->assertGuest();
});

it('rejects an expired handoff token', function () {
    $user = User::factory()->create();

    $token = OAuthTransactionStore::issueHandoff('google', (string) $user->getKey(), false, null, hash('sha256', 'test-verifier'));

    // Simulate TTL expiry by dropping the cached entry.
    Cache::forget('oauth-handoff:'.hash('sha256', $token));

    $response = $this->get(route('socialite.complete', ['provider' => 'google', 'token' => $token]));

    $response->assertRedirect(route('login'));
    $response->assertSessionHas('toast.type', 'error');
    $this->assertGuest();
});

it('keeps handoff tokens short-lived', function () {
    expect(OAuthTransactionStore::HANDOFF_TTL_SECONDS)->toBeLessThanOrEqual(300);
    expect(OAuthTransactionStore::STATE_TTL_SECONDS)->toBeLessThanOrEqual(600);
});

it('rejects callbacks with a missing or unknown state', function () {
    $this->get(route('socialite.callback', ['provider' => 'google']))
        ->assertRedirect(route('login'));

    $response = $this->get(route('socialite.callback', ['provider' => 'google', 'state' => str_repeat('b', 64)]));

    $response->assertRedirect(route('login'));
    $response->assertSessionHas('toast.type', 'error');
    $response->assertSessionHas('toast.message', __('Your sign-in session expired. Please try again.'));
    $this->assertGuest();

    expect(User::query()->count())->toBe(0);
});

it('rejects a reused state value', function () {
    $state = googleOAuthState();

    Socialite::fake('google', SocialiteUser::fake([
        'id' => 'google-replay-123',
        'name' => 'Replay User',
        'email' => 'replay@example.com',
        'avatar' => 'https://example.com/replay.jpg',
        'email_verified' => true,
    ]));

    $this->get(route('socialite.callback', ['provider' => 'google', 'state' => $state]))
        ->assertRedirect();

    $response = $this->get(route('socialite.callback', ['provider' => 'google', 'state' => $state]));

    $response->assertRedirect(route('login'));
    $response->assertSessionHas('toast.type', 'error');

    expect(User::query()->where('email', 'replay@example.com')->count())->toBe(1);
});

it('rejects open redirects through the handoff', function () {
    $token = googleOAuthHandoffToken([], ['redirect' => 'https://evil.example.com/phish']);

    $response = $this->get(route('socialite.complete', ['provider' => 'google', 'token' => $token]));

    $response->assertRedirect(route('home'));
    $this->assertAuthenticated();
});

it('sanitizes absolute same-host redirects to their path', function () {
    $token = googleOAuthHandoffToken([], ['redirect' => 'https://ilmu360.test/majlis?foo=bar']);

    $response = $this->get(route('socialite.complete', ['provider' => 'google', 'token' => $token]));

    $response->assertRedirect('/majlis?foo=bar');
    $this->assertAuthenticated();
});

it('sends bridge-mode errors to the canonical host', function () {
    $callback = $this->get('https://dev.ilmu360.com/oauth/google/callback?state='.str_repeat('c', 64));

    $callback->assertRedirect('https://ilmu360.test/oauth/error/expired');

    $complete = $this->get('https://dev.ilmu360.com/oauth/google/complete?token='.str_repeat('d', 64));

    $complete->assertRedirect('https://ilmu360.test/oauth/error/expired');
});

it('maps bridge error codes to login toasts', function () {
    $this->get(route('socialite.error', ['code' => 'expired']))
        ->assertRedirect(route('login'))
        ->assertSessionHas('toast.message', __('Your sign-in session expired. Please try again.'));

    $this->get(route('socialite.error', ['code' => 'failed']))
        ->assertRedirect(route('login'))
        ->assertSessionHas('toast.message', __('Unable to authenticate. Please try again.'));

    $this->get(route('socialite.error', ['code' => 'unconfigured']))
        ->assertRedirect(route('login'))
        ->assertSessionHas('toast.message', __('Google sign-in is not configured right now. Please use email and password instead.'));

    $this->get(route('socialite.error', ['code' => 'bogus']))
        ->assertRedirect(route('login'))
        ->assertSessionHas('toast.message', __('Unable to authenticate. Please try again.'));
});

it('renders bridge error toasts on the login page', function () {
    $this->get(route('socialite.error', ['code' => 'expired']))
        ->assertRedirect(route('login'));

    $this->get(route('login'))
        ->assertOk()
        ->assertSee('app-toast-stack', escape: false)
        ->assertSee(__('Your sign-in session expired. Please try again.'), escape: false);
});

it('rejects a callback without the browser verifier cookie', function () {
    config()->set('services.google.redirect', 'https://ilmu360.test/oauth/google/callback');

    $issued = OAuthTransactionStore::issueState('google', null);

    Socialite::fake('google', SocialiteUser::fake([
        'id' => 'google-no-cookie-123',
        'name' => 'No Cookie',
        'email' => 'no-cookie@example.com',
        'avatar' => 'https://example.com/no-cookie.jpg',
        'email_verified' => true,
    ]));

    // No redirect call, so the browser never received a verifier cookie.
    $response = $this->get(route('socialite.callback', ['provider' => 'google', 'state' => $issued['state']]));

    $response->assertRedirect(route('login'));
    $response->assertSessionHas('toast.message', __('Your sign-in session expired. Please try again.'));
    $this->assertGuest();
});

it('rejects a handoff without the browser verifier cookie', function () {
    $user = User::factory()->create();

    $token = OAuthTransactionStore::issueHandoff('google', (string) $user->getKey(), false, null, hash('sha256', 'test-verifier'));

    // No redirect call, so the browser never received a verifier cookie.
    $response = $this->get(route('socialite.complete', ['provider' => 'google', 'token' => $token]));

    $response->assertRedirect(route('login'));
    $response->assertSessionHas('toast.type', 'error');
    $this->assertGuest();
});

it('treats a contended handoff as consumed', function () {
    $user = User::factory()->create();

    $token = OAuthTransactionStore::issueHandoff('google', (string) $user->getKey(), false, null, hash('sha256', 'test-verifier'));

    $lock = Cache::lock('lock:oauth-handoff:'.hash('sha256', $token), 5);

    expect($lock->get())->toBeTrue();

    try {
        $response = $this->get(route('socialite.complete', ['provider' => 'google', 'token' => $token]));

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('toast.type', 'error');
        $this->assertGuest();
    } finally {
        $lock->release();
    }
});

it('falls back to the session intended URL when the handoff has none', function () {
    $token = googleOAuthHandoffToken();

    $response = $this->withSession(['url.intended' => 'https://ilmu360.test/majlis'])
        ->get(route('socialite.complete', ['provider' => 'google', 'token' => $token]));

    $response->assertRedirect('/majlis');
    $this->assertAuthenticated();
});

it('handles provider errors without leaking details', function () {
    $state = googleOAuthState();

    Socialite::fake('google', function () {
        throw new RuntimeException('access denied by unit test');
    });

    $response = $this->get(route('socialite.callback', ['provider' => 'google', 'state' => $state]));

    $response->assertRedirect(route('login'));
    $response->assertSessionHas('toast.type', 'error');
    $response->assertSessionHas('toast.message', __('Unable to authenticate. Please try again.'));
    $this->assertGuest();
});

it('claims guest submissions and shows the notice when completing the handoff', function () {
    $submission = EventSubmission::factory()->create([
        'submitter_type' => null,
        'submitter_id' => null,
    ]);
    $submission->contactMethods()->create([
        'type' => ContactMethodType::Email->value,
        'purpose' => ContactPurpose::General->value,
        'value' => 'bridge@example.com',
        'is_public' => false,
        'sort_order' => 1,
    ]);

    $token = googleOAuthHandoffToken();

    // Resolving the user fires no verification event, so the bridge callback
    // claims nothing; the claim runs when the login completes.
    expect($submission->fresh()->submitter_id)->toBeNull();

    $response = $this->get(route('socialite.complete', ['provider' => 'google', 'token' => $token]));

    expect((string) $submission->fresh()->submitter_id)->toBe((string) auth()->id());
    $response->assertSessionHas('toast.type', 'success');
    $response->assertSessionHas('toast.message', trans_choice(
        'We linked :count past submission to your account.|We linked :count past submissions to your account.',
        1
    ));
    $this->assertAuthenticated();
});

it('supports concurrent sign-in attempts in the same browser', function () {
    $stateA = googleOAuthState();
    $stateB = googleOAuthState();

    Socialite::fake('google', SocialiteUser::fake([
        'id' => 'google-tab-a',
        'name' => 'Tab A',
        'email' => 'tab-a@example.com',
        'email_verified' => true,
    ]));

    $tokenA = handoffTokenFromCallback($stateA);

    Socialite::fake('google', SocialiteUser::fake([
        'id' => 'google-tab-b',
        'name' => 'Tab B',
        'email' => 'tab-b@example.com',
        'email_verified' => true,
    ]));

    $tokenB = handoffTokenFromCallback($stateB);

    $this->get(route('socialite.complete', ['provider' => 'google', 'token' => $tokenA]));
    $this->assertAuthenticatedAs(User::query()->where('email', 'tab-a@example.com')->firstOrFail());

    auth()->logout();

    $this->get(route('socialite.complete', ['provider' => 'google', 'token' => $tokenB]));
    $this->assertAuthenticatedAs(User::query()->where('email', 'tab-b@example.com')->firstOrFail());
});
