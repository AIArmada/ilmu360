<?php

use AIArmada\Signals\Features\Ingestion\Models\SignalEvent;
use App\Features\Identity\EntryPoints\Fortify\CreateNewUser;
use App\Features\Identity\Models\SignupAttribution;
use App\Features\ShareTracking\Api\SignupRecorder;
use App\Models\User;
use App\Shared\ShareTracking\ShareRequestContext;
use Illuminate\Validation\ValidationException;

it('can create user with email only', function () {
    User::factory()->emailOnly()->create();

    expect(User::count())->toBe(1)
        ->and(User::first()->email)->not->toBeNull()
        ->and(User::first()->phone)->toBeNull();
});

it('can create user with phone only', function () {
    User::factory()->phoneOnly()->create();

    expect(User::count())->toBe(1)
        ->and(User::first()->phone)->not->toBeNull()
        ->and(User::first()->email)->toBeNull();
});

it('requires either email or phone when creating user', function () {
    $action = app(CreateNewUser::class);

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

    $action = app(CreateNewUser::class);

    $action->create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);
})->throws(ValidationException::class);

it('validates unique phone', function () {
    User::factory()->create(['phone' => '+60123456789']);

    $action = app(CreateNewUser::class);

    $action->create([
        'name' => 'Test User',
        'phone' => '+60123456789',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);
})->throws(ValidationException::class);

it('allows registering with email and phone together', function () {
    $action = app(CreateNewUser::class);

    $user = $action->create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'phone' => '+60123456789',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    expect($user->email)->toBe('test@example.com')
        ->and($user->phone)->toBe('+60123456789');
});

it('records a completed Fortify signup through the share-tracking contract exactly once', function () {
    $recorder = new class implements SignupRecorder
    {
        public int $calls = 0;

        public ?string $accountId = null;

        public function recordSignup(string $accountId, ?ShareRequestContext $request = null): void
        {
            $this->calls++;
            $this->accountId = $accountId;
        }
    };

    app()->instance(SignupRecorder::class, $recorder);

    $user = app(CreateNewUser::class)->create([
        'name' => 'Boundary Registration',
        'email' => 'fortify-boundary@example.test',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ]);

    expect($recorder->calls)->toBe(1)
        ->and($recorder->accountId)->toBe((string) $user->getKey())
        ->and(User::query()->whereKey($user->getKey())->exists())->toBeTrue();
});

it('rolls back account creation when the share signup recording fails', function () {
    $recorder = new class implements SignupRecorder
    {
        public function recordSignup(string $accountId, ?ShareRequestContext $request = null): void
        {
            throw new RuntimeException('Share signup recording failed.');
        }
    };

    app()->instance(SignupRecorder::class, $recorder);

    expect(fn () => app(CreateNewUser::class)->create([
        'name' => 'Atomic Registration',
        'email' => 'fortify-atomic@example.test',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ]))->toThrow(RuntimeException::class, 'Share signup recording failed.')
        ->and(User::query()->where('email', 'fortify-atomic@example.test')->exists())->toBeFalse()
        ->and(SignupAttribution::query()->exists())->toBeFalse()
        ->and(SignalEvent::query()->where('event_name', 'auth.signup.completed')->exists())->toBeFalse();
});
