<?php

use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Fortify\Actions\EnsureLoginIsNotThrottled;
use Laravel\Fortify\Fortify;

uses(RefreshDatabase::class);

it('does not allow verification timestamps through mass assignment', function () {
    $user = new User;

    expect(fn () => $user->fill([
        'email_verified_at' => now(),
        'phone_verified_at' => now(),
    ]))->toThrow(MassAssignmentException::class);
});

it('uses the configured Fortify login route limiter without duplicating its pipeline throttle', function () {
    $callback = Fortify::$authenticateThroughCallback;

    expect($callback)->toBeCallable();

    if (! is_callable($callback)) {
        return;
    }

    $pipeline = $callback(Request::create('/login'));

    expect($pipeline)->not->toContain(EnsureLoginIsNotThrottled::class);
});

it('supports Fortify two-factor authentication and hides its secrets', function () {
    $user = User::factory()->withTwoFactor()->create();

    expect($user->hasEnabledTwoFactorAuthentication())->toBeTrue()
        ->and($user->getHidden())
        ->toContain('two_factor_secret')
        ->toContain('two_factor_recovery_codes');
});

it('uses prefixed Sanctum tokens without imposing a global expiry', function () {
    expect(config('sanctum.expiration'))->toBeNull()
        ->and(config('sanctum.token_prefix'))->toBe('ilmu360_');
});
