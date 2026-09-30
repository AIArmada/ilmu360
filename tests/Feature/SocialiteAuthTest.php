<?php

use App\Models\SocialAccount;
use App\Models\User;
use App\Support\Auth\OAuthTransactionStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.url', 'https://ilmu360.test');
    config()->set('services.google.client_id', 'google-client-id');
    config()->set('services.google.client_secret', 'google-client-secret');
    config()->set('services.google.redirect', 'https://ilmu360.test/oauth/google/callback');
});

it('redirects to google with the account chooser prompt when the provider is configured', function () {
    $response = $this->get(route('socialite.redirect', ['provider' => 'google']));

    $response->assertRedirect();

    $location = (string) $response->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($location)
        ->toContain('https://accounts.google.com/o/oauth2/auth');

    expect($query)
        ->toMatchArray([
            'client_id' => 'google-client-id',
            'redirect_uri' => 'https://ilmu360.test/oauth/google/callback',
            'prompt' => 'select_account',
        ]);

    expect($query['state'] ?? null)->toBeString()->toHaveLength(64);
    $response->assertCookie(OAuthTransactionStore::VERIFIER_COOKIE);
});

it('does not expose google sign-in when the provider is not configured', function () {
    config()->set('services.google.client_id', '');
    config()->set('services.google.client_secret', '');
    config()->set('services.google.redirect', '');

    $this->get(route('login'))
        ->assertOk()
        ->assertDontSee('Sign in with Google');

    $this->get(route('register'))
        ->assertOk()
        ->assertDontSee('Sign up with Google');
});

it('redirects back to login when the provider is not configured', function () {
    config()->set('services.google.client_id', '');
    config()->set('services.google.client_secret', '');
    config()->set('services.google.redirect', '');

    $response = $this->get(route('socialite.redirect', ['provider' => 'google']));

    $response->assertRedirect(route('login'));
    $response->assertSessionHas('toast.type', 'error');
    $response->assertSessionHas('toast.message', __('Google sign-in is not configured right now. Please use email and password instead.'));
});

it('redirects to the intended page after password login', function () {
    $user = User::factory()->create([
        'password' => Hash::make('Password123!'),
    ]);

    $target = route('events.index', absolute: false);

    $this->get(route('login', ['redirect' => $target]))->assertOk();

    $response = $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'Password123!',
    ]);

    $response->assertRedirect($target);
    $this->assertAuthenticatedAs($user);
});

it('redirects to the intended page after registration', function () {
    $target = route('events.index', absolute: false);

    $this->get(route('register', ['redirect' => $target]))->assertOk();

    $response = $this->post(route('register.store'), [
        'name' => 'New Member',
        'email' => 'new-member@example.com',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ]);

    $response->assertRedirect($target);
    $this->assertAuthenticated();
});

it('redirects to the intended page after google sign-in', function () {
    $target = route('persons.index', absolute: false);

    $state = googleOAuthState(['redirect' => $target]);

    Socialite::fake('google', SocialiteUser::fake([
        'id' => 'google-intended-123',
        'name' => 'Jane Intended',
        'email' => 'intended@example.com',
        'avatar' => 'https://example.com/intended.jpg',
        'email_verified' => true,
    ]));

    $response = $this->get(route('socialite.callback', ['provider' => 'google', 'state' => $state]));

    $response->assertRedirect($target);
});

it('creates a user and social account on callback', function () {
    $state = googleOAuthState();

    Socialite::fake('google', SocialiteUser::fake([
        'id' => 'google-123',
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'avatar' => 'https://example.com/avatar.jpg',
        'email_verified' => true,
    ]));

    $response = $this->get(route('socialite.callback', ['provider' => 'google', 'state' => $state]));

    $response->assertRedirect(route('home'));

    $this->assertDatabaseHas('users', [
        'email' => 'jane@example.com',
        'name' => 'Jane Doe',
    ]);

    $user = User::query()->where('email', 'jane@example.com')->first();

    expect($user)->not->toBeNull();
    expect($user?->email_verified_at)->not->toBeNull();

    $this->assertDatabaseHas('socialite', [
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_id' => 'google-123',
        'avatar_url' => 'https://example.com/avatar.jpg',
    ]);
});

it('links a social account to an existing user', function () {
    $user = User::factory()->create([
        'email' => 'existing@example.com',
        'name' => 'Existing User',
        'email_verified_at' => null,
    ]);

    $state = googleOAuthState();

    Socialite::fake('google', SocialiteUser::fake([
        'id' => 'google-456',
        'name' => 'Existing User',
        'email' => 'existing@example.com',
        'avatar' => 'https://example.com/existing.jpg',
        'email_verified' => true,
    ]));

    $response = $this->get(route('socialite.callback', ['provider' => 'google', 'state' => $state]));

    $response->assertRedirect(route('home'));

    expect(User::query()->where('email', 'existing@example.com')->count())
        ->toBe(1);

    $this->assertDatabaseHas('socialite', [
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_id' => 'google-456',
        'avatar_url' => 'https://example.com/existing.jpg',
    ]);

    expect(SocialAccount::query()->where('user_id', $user->id)->count())
        ->toBe(1);
    expect($user->fresh()?->email_verified_at)->not->toBeNull();
});

it('verifies an existing user when signing in through an existing google social account', function () {
    $user = User::factory()->unverified()->create([
        'email' => 'linked@example.com',
        'name' => 'Linked User',
    ]);

    SocialAccount::factory()->create([
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_id' => 'google-789',
        'avatar_url' => 'https://example.com/old-avatar.jpg',
    ]);

    $state = googleOAuthState();

    Socialite::fake('google', SocialiteUser::fake([
        'id' => 'google-789',
        'name' => 'Linked User',
        'email' => 'linked@example.com',
        'avatar' => 'https://example.com/new-avatar.jpg',
        'email_verified' => true,
    ]));

    $response = $this->get(route('socialite.callback', ['provider' => 'google', 'state' => $state]));

    $response->assertRedirect(route('home'));

    expect($user->fresh()?->email_verified_at)->not->toBeNull();

    $this->assertDatabaseHas('socialite', [
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_id' => 'google-789',
        'avatar_url' => 'https://example.com/new-avatar.jpg',
    ]);
});

it('loads the livewire runtime on the login page so toasts and alpine widgets render', function () {
    // The auth layout once shipped only @livewireScriptConfig (config JSON,
    // no runtime), which left every Alpine-driven widget on /login dead —
    // including the error toasts OAuth failures flash.
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('livewire.js', false);
});

it('loads the livewire runtime on the register page so toasts and alpine widgets render', function () {
    $this->get(route('register'))
        ->assertOk()
        ->assertSee('livewire.js', false);
});

it('sets the verifier cookie without a domain attribute so browsers accept the __Host- prefix', function () {
    // Cookie::make() backfills a null domain from the CookieJar default
    // (config session.domain in production), and any Domain attribute makes
    // browsers reject __Host- cookies outright — breaking every OAuth
    // completion with verifier_mismatch. Pin the production-like default
    // explicitly: the jar singleton resolves at boot, so a config override
    // here would not reproduce the backfill and this would pass vacuously.
    $session = config('session');
    app('cookie')->setDefaultPathAndDomain($session['path'], '.ilmu360.test', $session['secure'], $session['same_site'] ?? null);

    $response = $this->get(route('socialite.redirect', ['provider' => 'google']));

    $verifier = collect($response->headers->getCookies())
        ->first(fn (SymfonyCookie $cookie) => $cookie->getName() === OAuthTransactionStore::VERIFIER_COOKIE);

    expect($verifier)->not->toBeNull();
    expect($verifier->getDomain())->toBeNull();
    expect($verifier->isSecure())->toBeTrue();
    expect($verifier->getPath())->toBe('/');

    $raw = collect($response->headers->all('set-cookie'))
        ->first(fn (string $header) => str_starts_with($header, OAuthTransactionStore::VERIFIER_COOKIE.'='));

    expect($raw)->not->toBeNull();
    expect(strtolower((string) strstr((string) $raw, ';')))->not->toContain('domain=');
});
