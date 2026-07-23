<?php

use App\Actions\Auth\AuthenticateApiUserAction;
use App\Actions\Auth\ResolveSocialiteUserAction;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Contracts\User as SocialiteUser;

uses(RefreshDatabase::class);

it('resolves an existing social account by provider id', function () {
    $user = User::factory()->create();
    $provider = 'google';
    $providerId = 'google-123';

    SocialAccount::factory()->create([
        'user_id' => $user->id,
        'provider' => $provider,
        'provider_id' => $providerId,
    ]);

    $socialUser = Mockery::mock(SocialiteUser::class);
    $socialUser->shouldReceive('getId')->andReturn($providerId);
    $socialUser->shouldReceive('getAvatar')->andReturn('https://example.com/avatar.jpg');
    $socialUser->shouldReceive('getEmail')->andReturn($user->email);

    $result = app(ResolveSocialiteUserAction::class)->handle($provider, $socialUser);

    expect($result['user']->is($user))->toBeTrue()
        ->and($result['created_account'])->toBeFalse();
});

it('creates a new user when no social account or user exists', function () {
    $socialUser = Mockery::mock(SocialiteUser::class);
    $socialUser->shouldReceive('getId')->andReturn('google-456');
    $socialUser->shouldReceive('getName')->andReturn('Ali New User');
    $socialUser->shouldReceive('getNickname')->andReturn(null);
    $socialUser->shouldReceive('getEmail')->andReturn('ali@example.com');
    $socialUser->shouldReceive('getAvatar')->andReturn(null);

    $result = app(ResolveSocialiteUserAction::class)->handle('google', $socialUser);

    expect($result['user'])->toBeInstanceOf(User::class)
        ->and($result['user']->email)->toBe('ali@example.com')
        ->and($result['user']->hasVerifiedEmail())->toBeTrue()
        ->and($result['created_account'])->toBeTrue();

    expect(SocialAccount::query()
        ->where('provider', 'google')
        ->where('provider_id', 'google-456')
        ->exists()
    )->toBeTrue();
});

it('links existing user by email when social account is new', function () {
    $user = User::factory()->create(['email' => 'existing@example.com']);

    $socialUser = Mockery::mock(SocialiteUser::class);
    $socialUser->shouldReceive('getId')->andReturn('google-789');
    $socialUser->shouldReceive('getName')->andReturn('Existing User');
    $socialUser->shouldReceive('getNickname')->andReturn(null);
    $socialUser->shouldReceive('getEmail')->andReturn('existing@example.com');
    $socialUser->shouldReceive('getAvatar')->andReturn(null);

    $result = app(ResolveSocialiteUserAction::class)->handle('google', $socialUser);

    expect($result['user']->is($user))->toBeTrue()
        ->and($result['created_account'])->toBeFalse();

    expect(SocialAccount::query()
        ->where('user_id', $user->id)
        ->where('provider', 'google')
        ->exists()
    )->toBeTrue();
});

it('authenticates a user by email', function () {
    $user = User::factory()->create([
        'email' => 'test@example.com',
        'password' => bcrypt('password123'),
    ]);

    $result = app(AuthenticateApiUserAction::class)->handle(
        'test@example.com',
        'password123',
        'test-device',
        Request::create('/'),
    );

    expect($result['user']->is($user))->toBeTrue()
        ->and($result['access_token'])->toBeString();
});

it('authenticates a user by phone', function () {
    $user = User::factory()->create([
        'email' => 'test@example.com',
        'phone' => '+60123456789',
        'password' => bcrypt('password123'),
    ]);

    $result = app(AuthenticateApiUserAction::class)->handle(
        '+60123456789',
        'password123',
        'test-device',
        Request::create('/'),
    );

    expect($result['user']->is($user))->toBeTrue()
        ->and($result['access_token'])->toBeString();
});

it('throws validation exception for invalid credentials', function () {
    $user = User::factory()->create([
        'email' => 'test@example.com',
        'password' => bcrypt('correct-password'),
    ]);

    app(AuthenticateApiUserAction::class)->handle(
        'test@example.com',
        'wrong-password',
        'test-device',
        Request::create('/'),
    );
})->throws(ValidationException::class);

it('returns null for empty login string', function () {
    $reflection = new ReflectionMethod(AuthenticateApiUserAction::class, 'resolveUser');
    $reflection->setAccessible(true);
    $action = app(AuthenticateApiUserAction::class);

    $result = $reflection->invoke($action, '');

    expect($result)->toBeNull();
});
