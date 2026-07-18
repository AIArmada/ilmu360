<?php

use AIArmada\CommerceSupport\Models\Role;
use AIArmada\Engagement\Contracts\EngagementCounterService;
use AIArmada\Engagement\Contracts\EngagementManager;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Spatie\DeletedModels\Models\DeletedModel;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.google.client_id', 'google-client-id');
    config()->set('services.google.client_secret', 'google-client-secret');
    config()->set('services.google.redirect', 'https://ilmu360.test/oauth/google/callback');
});

it('registers an api user and returns a bearer token', function () {
    $response = $this->postJson(route('api.auth.register'), [
        'name' => 'Mobile User',
        'email' => 'mobile@example.test',
        'password' => 'password',
        'password_confirmation' => 'password',
        'device_name' => 'iPhone 15 Pro',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonPath('data.user.email', 'mobile@example.test')
        ->assertJsonPath('data.access_token', fn (string $token) => filled($token));

    $token = $response->json('data.access_token');

    $this->withToken($token)
        ->getJson('/api/v1/user')
        ->assertOk()
        ->assertJsonPath('data.email', 'mobile@example.test')
        ->assertJsonPath('meta.request_id', fn (string $requestId) => filled($requestId));
});

it('logs in an api user with phone credentials and returns a bearer token', function () {
    User::factory()->create([
        'phone' => '+60111222333',
        'password' => 'password',
    ]);

    $response = $this->postJson(route('api.auth.login'), [
        'login' => '+60111222333',
        'password' => 'password',
        'device_name' => 'Pixel 9',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonPath('data.user.phone', '+60111222333')
        ->assertJsonPath('data.access_token', fn (string $token) => filled($token));
});

it('serializes the auth user payload with verification timestamps and timezone', function () {
    $emailVerifiedAt = now()->startOfSecond();
    $phoneVerifiedAt = now()->addMinute()->startOfSecond();

    $user = User::factory()->create([
        'email' => 'verified-auth@example.test',
        'phone' => '+60119998877',
        'timezone' => 'Asia/Kuala_Lumpur',
        'email_verified_at' => $emailVerifiedAt,
        'phone_verified_at' => $phoneVerifiedAt,
        'password' => 'password',
    ]);

    $this->postJson(route('api.auth.login'), [
        'login' => 'verified-auth@example.test',
        'password' => 'password',
        'device_name' => 'Galaxy S30',
    ])->assertOk()
        ->assertJsonPath('data.user.id', $user->id)
        ->assertJsonPath('data.user.email', 'verified-auth@example.test')
        ->assertJsonPath('data.user.phone', '+60119998877')
        ->assertJsonPath('data.user.timezone', 'Asia/Kuala_Lumpur')
        ->assertJsonPath('data.user.email_verified_at', $emailVerifiedAt->format(DateTimeInterface::ATOM))
        ->assertJsonPath('data.user.phone_verified_at', $phoneVerifiedAt->format(DateTimeInterface::ATOM));
});

it('preserves the authenticated user endpoint payload contract', function () {
    $dailyPrayerInstitutionId = (string) str()->uuid();
    $fridayPrayerInstitutionId = (string) str()->uuid();
    $previousTeam = getPermissionsTeamId();

    try {
        setPermissionsTeamId(null);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        if (! Role::query()->where('name', 'viewer')->where('guard_name', 'web')->exists()) {
            $roleRecord = new Role;
            $roleRecord->forceFill([
                'id' => (string) Str::uuid(),
                'name' => 'viewer',
                'guard_name' => 'web',
            ])->save();
        }

        $user = User::factory()->create([
            'name' => 'Current User',
            'email' => 'current-user@example.test',
            'phone' => '+60115550000',
            'timezone' => 'Asia/Kuala_Lumpur',
            'daily_prayer_institution_id' => $dailyPrayerInstitutionId,
            'friday_prayer_institution_id' => $fridayPrayerInstitutionId,
            'email_verified_at' => now()->subHour()->startOfSecond(),
            'phone_verified_at' => now()->subMinutes(30)->startOfSecond(),
        ]);

        $user->assignRole('viewer');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Sanctum::actingAs($user);

        $response = $this->getJson(route('api.user.show'));

        $response->assertOk()
            ->assertJsonPath('data.roles', ['viewer'])
            ->assertJsonPath('meta.request_id', fn (string $requestId) => filled($requestId));

        $expectedPayload = $user->fresh()->toArray();
        unset($expectedPayload['current_team_id']);

        expect($response->json('data'))->toEqual(array_merge($expectedPayload, [
            'roles' => ['viewer'],
        ]));
    } finally {
        setPermissionsTeamId($previousTeam);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
});

it('deletes the authenticated user account, revokes tokens, and keeps a sanitized restore snapshot', function () {
    $user = User::factory()->create([
        'name' => 'Deleting User',
        'email' => 'delete-me@example.test',
        'password' => 'password',
        'remember_token' => 'remember-me-token',
    ]);

    $engagementEvent = Event::factory()->create();

    app(EngagementManager::class)->bookmark($user, $engagementEvent);
    $user->respond($engagementEvent, 'going');

    $plainTextToken = $user->createToken('iPhone 17')->plainTextToken;
    $passportAccessTokenId = Str::random(80);

    DB::table('sessions')->insert([
        'id' => 'delete-me-session',
        'user_id' => $user->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Pest',
        'payload' => 'serialized-session',
        'last_activity' => now()->timestamp,
    ]);
    DB::table('password_reset_tokens')->insert([
        'email' => $user->email,
        'token' => 'password-reset-token',
        'created_at' => now(),
    ]);
    DB::table('oauth_auth_codes')->insert([
        'id' => Str::random(80),
        'user_id' => $user->id,
        'client_id' => (string) Str::uuid(),
        'scopes' => '[]',
        'revoked' => false,
        'expires_at' => now()->addMinutes(10),
    ]);
    DB::table('oauth_access_tokens')->insert([
        'id' => $passportAccessTokenId,
        'user_id' => $user->id,
        'client_id' => (string) Str::uuid(),
        'name' => 'passport-token',
        'scopes' => '[]',
        'revoked' => false,
        'created_at' => now(),
        'updated_at' => now(),
        'expires_at' => now()->addDay(),
    ]);
    DB::table('oauth_refresh_tokens')->insert([
        'id' => Str::random(80),
        'access_token_id' => $passportAccessTokenId,
        'revoked' => false,
        'expires_at' => now()->addMonth(),
    ]);
    DB::table('oauth_device_codes')->insert([
        'id' => Str::random(80),
        'user_id' => $user->id,
        'client_id' => (string) Str::uuid(),
        'user_code' => 'ABC12345',
        'scopes' => '[]',
        'revoked' => false,
        'user_approved_at' => now(),
        'last_polled_at' => null,
        'expires_at' => now()->addMinutes(10),
    ]);

    $response = $this->withToken($plainTextToken)->deleteJson(route('api.user.destroy'));

    $response->assertOk()
        ->assertJsonPath('message', 'Account deleted successfully.')
        ->assertJsonPath('meta.request_id', fn (string $requestId) => filled($requestId));

    $this->assertDatabaseMissing('users', ['id' => $user->id]);
    $this->assertDatabaseHas('deleted_models', [
        'key' => $user->id,
        'model' => $user->getMorphClass(),
    ]);
    $this->assertDatabaseMissing('personal_access_tokens', [
        'tokenable_type' => User::class,
        'tokenable_id' => $user->id,
    ]);
    $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
    $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    $this->assertDatabaseMissing('oauth_auth_codes', ['user_id' => $user->id]);
    $this->assertDatabaseMissing('oauth_access_tokens', ['user_id' => $user->id]);
    $this->assertDatabaseMissing('oauth_refresh_tokens', ['access_token_id' => $passportAccessTokenId]);
    $this->assertDatabaseMissing('oauth_device_codes', ['user_id' => $user->id]);
    $this->assertDatabaseMissing('engagement_bookmarks', [
        'bookmarkable_type' => $engagementEvent->getMorphClass(),
        'bookmarkable_id' => $engagementEvent->id,
        'bookmarker_type' => $user->getMorphClass(),
        'bookmarker_id' => $user->id,
        'status' => 'active',
    ]);
    $this->assertDatabaseMissing((string) config('engagement.database.tables.responses'), [
        'respondable_type' => $engagementEvent->getMorphClass(),
        'respondable_id' => $engagementEvent->id,
        'responder_type' => $user->getMorphClass(),
        'responder_id' => $user->id,
        'response_type' => 'going',
    ]);
    expect($engagementEvent->fresh())
        ->not->toBeNull();

    expect(app(EngagementCounterService::class)->countBookmarks($engagementEvent))->toBe(0);
    expect(app(EngagementCounterService::class)->countResponses($engagementEvent, 'going'))->toBe(0);

    $deletedModel = DeletedModel::query()
        ->where('key', $user->id)
        ->where('model', $user->getMorphClass())
        ->firstOrFail();

    expect($deletedModel->values)
        ->toMatchArray([
            'name' => 'Deleting User',
            'email' => 'delete-me@example.test',
        ])
        ->not->toHaveKey('password')
        ->not->toHaveKey('remember_token');
});

it('rejects invalid api login credentials', function () {
    User::factory()->create([
        'email' => 'mobile@example.test',
        'password' => 'password',
    ]);

    $this->postJson(route('api.auth.login'), [
        'login' => 'mobile@example.test',
        'password' => 'wrong-password',
        'device_name' => 'Pixel 9',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['login'])
        ->assertJsonPath('error.code', 'validation_error')
        ->assertJsonPath('meta.request_id', fn (string $requestId) => filled($requestId));
});

it('rate limits repeated api login attempts for the same account fingerprint', function () {
    User::factory()->create([
        'email' => 'rate-limited@example.test',
        'password' => 'password',
    ]);

    $payload = [
        'login' => 'rate-limited@example.test',
        'password' => 'wrong-password',
        'device_name' => 'Pixel 9',
    ];

    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $this->postJson(route('api.auth.login'), $payload)->assertUnprocessable();
    }

    $this->postJson(route('api.auth.login'), $payload)->assertTooManyRequests();
});

it('logs in through google api token exchange and returns a bearer token', function () {
    $provider = Mockery::mock();
    $provider->shouldReceive('stateless')->once()->andReturnSelf();
    $provider->shouldReceive('userFromToken')->once()->with('google-access-token')->andReturn(
        (new SocialiteUser)->map([
            'id' => 'google-api-123',
            'name' => 'Mobile Google User',
            'email' => 'google-mobile@example.test',
            'avatar' => 'https://example.com/google-mobile.jpg',
        ])
    );

    Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);

    $response = $this->postJson(route('api.auth.social.google'), [
        'access_token' => 'google-access-token',
        'device_name' => 'iPhone 16',
    ]);

    $response->assertOk()
        ->assertJsonPath('message', 'API Google login successful.')
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonPath('data.user.email', 'google-mobile@example.test')
        ->assertJsonPath('data.access_token', fn (string $token) => filled($token));

    $this->assertDatabaseHas('socialite', [
        'provider' => 'google',
        'provider_id' => 'google-api-123',
        'avatar_url' => 'https://example.com/google-mobile.jpg',
    ]);
});

it('links an existing user during google api token exchange', function () {
    $user = User::factory()->unverified()->create([
        'email' => 'existing-google@example.test',
        'name' => 'Existing Google User',
    ]);

    $provider = Mockery::mock();
    $provider->shouldReceive('stateless')->once()->andReturnSelf();
    $provider->shouldReceive('userFromToken')->once()->with('google-access-token')->andReturn(
        (new SocialiteUser)->map([
            'id' => 'google-api-456',
            'name' => 'Existing Google User',
            'email' => 'existing-google@example.test',
            'avatar' => 'https://example.com/existing-google.jpg',
        ])
    );

    Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);

    $this->postJson(route('api.auth.social.google'), [
        'access_token' => 'google-access-token',
        'device_name' => 'Pixel 10',
    ])->assertOk()
        ->assertJsonPath('data.user.email', 'existing-google@example.test');

    expect(User::query()->where('email', 'existing-google@example.test')->count())->toBe(1);
    expect($user->fresh()?->email_verified_at)->not->toBeNull();

    $this->assertDatabaseHas('socialite', [
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_id' => 'google-api-456',
    ]);
});

it('rejects google api token exchange when the provider is not configured', function () {
    config()->set('services.google.client_id', '');
    config()->set('services.google.client_secret', '');
    config()->set('services.google.redirect', '');

    $this->postJson(route('api.auth.social.google'), [
        'access_token' => 'google-access-token',
        'device_name' => 'iPhone 16',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['provider'])
        ->assertJsonPath('error.code', 'validation_error');
});

it('rejects invalid google api access tokens', function () {
    $provider = Mockery::mock();
    $provider->shouldReceive('stateless')->once()->andReturnSelf();
    $provider->shouldReceive('userFromToken')->once()->with('bad-google-access-token')->andThrow(new RuntimeException('invalid token'));

    Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);

    $this->postJson(route('api.auth.social.google'), [
        'access_token' => 'bad-google-access-token',
        'device_name' => 'Pixel 10',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['access_token'])
        ->assertJsonPath('error.code', 'validation_error');
});

it('revokes the current api token on logout', function () {
    $user = User::factory()->create();
    $token = $user->createToken('Test Device');

    $this->withToken($token->plainTextToken)
        ->postJson(route('api.auth.logout'))
        ->assertOk()
        ->assertJsonPath('message', 'API token revoked successfully.');

    expect(PersonalAccessToken::query()->count())->toBe(0);
});

it('returns success for session-authenticated logout without a bearer token', function () {
    $user = User::factory()->create();
    $token = $user->createToken('Other Device');

    $this->actingAs($user)
        ->postJson(route('api.auth.logout'))
        ->assertOk()
        ->assertJsonPath('message', 'API token revoked successfully.');

    expect(PersonalAccessToken::query()->whereKey($token->accessToken->id)->exists())->toBeTrue();
});
