<?php

use App\Features\Identity\Actions\AuthenticateApiUserAction;
use App\Features\Identity\Actions\ResolveSocialiteUserAction;
use App\Features\Identity\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Two\User as SocialiteUser;

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

    $socialUser = SocialiteUser::fake([
        'id' => $providerId,
        'email' => $user->email,
        'avatar' => 'https://example.com/avatar.jpg',
        'email_verified' => true,
    ]);

    $result = app(ResolveSocialiteUserAction::class)->handle($provider, $socialUser);

    expect($result['profile']->id)->toBe((string) $user->getKey())
        ->and($result['created_account'])->toBeFalse();
});

it('creates a new user when no social account or user exists', function () {
    $socialUser = SocialiteUser::fake([
        'id' => 'google-456',
        'name' => 'Ali New User',
        'nickname' => null,
        'email' => 'ali@example.com',
        'avatar' => null,
        'email_verified' => true,
    ]);

    $result = app(ResolveSocialiteUserAction::class)->handle('google', $socialUser);

    expect($result['profile']->email)->toBe('ali@example.com')
        ->and($result['profile']->emailVerified)->toBeTrue()
        ->and($result['created_account'])->toBeTrue();

    expect(SocialAccount::query()
        ->where('provider', 'google')
        ->where('provider_id', 'google-456')
        ->exists()
    )->toBeTrue();
});

it('links existing user by email when social account is new', function () {
    $user = User::factory()->create(['email' => 'existing@example.com']);

    $socialUser = SocialiteUser::fake([
        'id' => 'google-789',
        'name' => 'Existing User',
        'nickname' => null,
        'email' => 'existing@example.com',
        'avatar' => null,
        'email_verified' => true,
    ]);

    $result = app(ResolveSocialiteUserAction::class)->handle('google', $socialUser);

    expect($result['profile']->id)->toBe((string) $user->getKey())
        ->and($result['created_account'])->toBeFalse();

    expect(SocialAccount::query()
        ->where('user_id', $user->id)
        ->where('provider', 'google')
        ->exists()
    )->toBeTrue();
});

it('does not link an unverified provider email to an existing user', function () {
    $victim = User::factory()->unverified()->create([
        'email' => 'existing@example.com',
    ]);

    $socialUser = SocialiteUser::fake([
        'id' => 'google-unverified',
        'name' => 'Unverified Provider User',
        'email' => 'existing@example.com',
        'email_verified' => false,
    ]);

    $result = app(ResolveSocialiteUserAction::class)->handle('google', $socialUser);

    expect($result['profile']->id)->not->toBe((string) $victim->getKey())
        ->and($result['profile']->email)->toBeNull()
        ->and($result['profile']->emailVerified)->toBeFalse()
        ->and($result['created_account'])->toBeTrue();

    expect(SocialAccount::query()
        ->where('user_id', $victim->id)
        ->where('provider', 'google')
        ->exists()
    )->toBeFalse();
});

it('accepts the Google provider verified_email claim', function () {
    $user = User::factory()->unverified()->create([
        'email' => 'verified-claim@example.com',
    ]);

    $socialUser = SocialiteUser::fake([
        'id' => 'google-verified-claim',
        'name' => 'Verified Claim User',
        'email' => 'verified-claim@example.com',
        'verified_email' => true,
    ]);

    $result = app(ResolveSocialiteUserAction::class)->handle('google', $socialUser);

    expect($result['profile']->id)->toBe((string) $user->getKey())
        ->and($user->fresh()?->hasVerifiedEmail())->toBeTrue();
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

    expect($result->accountId)->toBe((string) $user->getKey())
        ->and($result->accessToken)->toBeString();
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

    expect($result->accountId)->toBe((string) $user->getKey())
        ->and($result->accessToken)->toBeString();
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

it('rejects an empty login string', function () {
    app(AuthenticateApiUserAction::class)->handle(
        '',
        'password123',
        'test-device',
        Request::create('/'),
    );
})->throws(ValidationException::class);
