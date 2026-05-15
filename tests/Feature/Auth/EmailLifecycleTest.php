<?php

use App\Models\User;
use App\Notifications\Auth\VerifyEmailNotification;
use App\Notifications\Auth\WelcomeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

it('sends welcome and verification emails when a new web user registers', function () {
    Notification::fake();

    $response = $this->post('/register', [
        'name' => 'Web User',
        'email' => 'web-user@example.test',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertRedirect('/dashboard');

    $user = User::query()->where('email', 'web-user@example.test')->firstOrFail();

    Notification::assertSentToTimes($user, VerifyEmailNotification::class, 1);
    Notification::assertSentToTimes($user, WelcomeNotification::class, 1);
});
