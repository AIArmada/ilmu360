<?php

use App\Actions\Fortify\CreateNewUser;
use App\Models\User;
use Illuminate\Validation\ValidationException;

it('can create user with email only', function () {
    User::factory()->emailOnly()->create();

    expect(User::count())->toBe(1);
    expect(User::first()->email)->not->toBeNull();
    expect(User::first()->phone)->toBeNull();
});

it('can create user with phone only', function () {
    User::factory()->phoneOnly()->create();

    expect(User::count())->toBe(1);
    expect(User::first()->phone)->not->toBeNull();
    expect(User::first()->email)->toBeNull();
});

it('requires either email or phone when creating user', function () {
    $action = new CreateNewUser;

    $action->create([
        'name' => 'Test User',
        'email' => null,
        'phone' => null,
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);
})->throws(ValidationException::class);

it('validates unique email', function () {
    User::factory()->create(['email' => 'test@example.com']);

    $action = new CreateNewUser;

    $action->create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);
})->throws(ValidationException::class);

it('validates unique phone', function () {
    User::factory()->create(['phone' => '+60123456789']);

    $action = new CreateNewUser;

    $action->create([
        'name' => 'Test User',
        'phone' => '+60123456789',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);
})->throws(ValidationException::class);

it('allows registering with email and phone together', function () {
    $action = new CreateNewUser;

    $user = $action->create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'phone' => '+60123456789',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    expect($user->email)->toBe('test@example.com');
    expect($user->phone)->toBe('+60123456789');
});
