<?php

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

uses(RefreshDatabase::class);

beforeEach(function () {
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

    Socialite::fake('google', SocialiteUser::fake([
        'id' => 'google-intended-123',
        'name' => 'Jane Intended',
        'email' => 'intended@example.com',
        'avatar' => 'https://example.com/intended.jpg',
        'email_verified' => true,
    ]));

    $this->get(route('socialite.redirect', ['provider' => 'google', 'redirect' => $target]))
        ->assertRedirect();

    $response = $this->get(route('socialite.callback', ['provider' => 'google']));

    $response->assertRedirect($target);
});

it('creates a user and social account on callback', function () {
    Socialite::fake('google', SocialiteUser::fake([
        'id' => 'google-123',
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'avatar' => 'https://example.com/avatar.jpg',
        'email_verified' => true,
    ]));

    $response = $this->get(route('socialite.callback', ['provider' => 'google']));

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

    Socialite::fake('google', SocialiteUser::fake([
        'id' => 'google-456',
        'name' => 'Existing User',
        'email' => 'existing@example.com',
        'avatar' => 'https://example.com/existing.jpg',
        'email_verified' => true,
    ]));

    $response = $this->get(route('socialite.callback', ['provider' => 'google']));

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

    Socialite::fake('google', SocialiteUser::fake([
        'id' => 'google-789',
        'name' => 'Linked User',
        'email' => 'linked@example.com',
        'avatar' => 'https://example.com/new-avatar.jpg',
        'email_verified' => true,
    ]));

    $response = $this->get(route('socialite.callback', ['provider' => 'google']));

    $response->assertRedirect(route('home'));

    expect($user->fresh()?->email_verified_at)->not->toBeNull();

    $this->assertDatabaseHas('socialite', [
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_id' => 'google-789',
        'avatar_url' => 'https://example.com/new-avatar.jpg',
    ]);
});
