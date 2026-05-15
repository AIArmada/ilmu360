<?php

use App\Models\User;
use App\Notifications\Auth\ResetPasswordNotification;
use App\Notifications\Auth\VerifyEmailNotification;
use App\Notifications\Auth\WelcomeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

it('sends welcome and verification emails when an api user registers', function () {
    Notification::fake();

    $response = $this->postJson(route('api.auth.register'), [
        'name' => 'Mobile User',
        'email' => 'mobile-email@example.test',
        'password' => 'password',
        'password_confirmation' => 'password',
        'device_name' => 'iPhone 16',
    ]);

    $response->assertCreated();

    $user = User::query()->where('email', 'mobile-email@example.test')->firstOrFail();

    Notification::assertSentToTimes($user, VerifyEmailNotification::class, 1);
    Notification::assertSentToTimes($user, WelcomeNotification::class, 1);
});

it('resends verification emails for authenticated api users who are still unverified', function () {
    Notification::fake();

    $user = User::factory()->create([
        'email' => 'verify-me@example.test',
        'email_verified_at' => null,
    ]);

    $this->actingAs($user)
        ->postJson(route('api.auth.verification-notification'))
        ->assertOk()
        ->assertJsonPath('message', 'Verification email sent successfully.');

    Notification::assertSentTo($user, VerifyEmailNotification::class);
});

it('rejects api verification resend when the email is already verified', function () {
    Notification::fake();

    $user = User::factory()->create([
        'email' => 'already-verified@example.test',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->postJson(route('api.auth.verification-notification'))
        ->assertStatus(422)
        ->assertJsonPath('message', 'Email address is already verified.');

    Notification::assertNothingSent();
});

it('returns the same forgot-password response whether or not the account exists', function () {
    Notification::fake();

    User::factory()->create([
        'email' => 'existing-reset@example.test',
    ]);

    $expectedMessage = 'If we found an account with that email address, we have emailed a password reset link.';

    $this->postJson(route('api.auth.forgot-password'), [
        'email' => 'existing-reset@example.test',
    ])->assertOk()
        ->assertJsonPath('message', $expectedMessage);

    $this->postJson(route('api.auth.forgot-password'), [
        'email' => 'missing-reset@example.test',
    ])->assertOk()
        ->assertJsonPath('message', $expectedMessage);

    Notification::assertSentTo(
        User::query()->where('email', 'existing-reset@example.test')->firstOrFail(),
        ResetPasswordNotification::class,
    );
});

it('resets passwords through the api password broker flow', function () {
    Notification::fake();

    $user = User::factory()->create([
        'email' => 'reset-api@example.test',
        'password' => 'old-password',
    ]);

    $this->postJson(route('api.auth.forgot-password'), [
        'email' => $user->email,
    ])->assertOk();

    $token = null;

    Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $notification) use (&$token): bool {
        $token = $notification->token;

        return true;
    });

    expect($token)->toBeString();

    $this->postJson(route('api.auth.reset-password'), [
        'token' => $token,
        'email' => $user->email,
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ])->assertOk()
        ->assertJsonPath('message', 'Password reset successfully.');

    expect(Hash::check('new-password', (string) $user->fresh()->password))->toBeTrue();
});
