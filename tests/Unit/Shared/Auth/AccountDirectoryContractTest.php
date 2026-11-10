<?php

use AIArmada\Communications\Models\CommunicationPreference;
use App\Models\User;
use App\Shared\Auth\AccountDirectory;
use App\Shared\Auth\AccountProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('returns an immutable profile for a known account', function (): void {
    $user = User::factory()->create([
        'name' => 'Aminah Rahman',
        'email' => 'aminah@example.test',
        'phone' => '+60123456789',
        'timezone' => 'Asia/Kuala_Lumpur',
        'email_verified_at' => now(),
        'phone_verified_at' => null,
    ]);

    CommunicationPreference::query()->forceCreate([
        'recipient_type' => $user->getMorphClass(),
        'recipient_id' => $user->id,
        'channel' => null,
        'category' => null,
        'scope_type' => null,
        'locale' => 'en',
        'timezone' => 'Asia/Kuala_Lumpur',
        'enabled_at' => now(),
    ]);

    $profile = app(AccountDirectory::class)->profile((string) $user->getKey());

    expect($profile)
        ->toBeInstanceOf(AccountProfile::class)
        ->and($profile->id)->toBe((string) $user->getKey())
        ->and($profile->name)->toBe('Aminah Rahman')
        ->and($profile->email)->toBe('aminah@example.test')
        ->and($profile->phone)->toBe('+60123456789')
        ->and($profile->emailVerified)->toBeTrue()
        ->and($profile->phoneVerified)->toBeFalse()
        ->and($profile->locale)->toBe('en')
        ->and($profile->timezone)->toBe('Asia/Kuala_Lumpur')
        ->and($profile->createdAt->getTimestamp())->toBe($user->created_at->getTimestamp());
});

it('falls back to configured identity preferences when none are stored', function (): void {
    config()->set('app.locale', 'ms');

    $user = User::factory()->emailOnly()->create([
        'timezone' => 'Asia/Jakarta',
    ]);

    $profile = app(AccountDirectory::class)->profile((string) $user->getKey());

    expect($profile)->toBeInstanceOf(AccountProfile::class)
        ->and($profile->emailVerified)->toBeTrue()
        ->and($profile->phone)->toBeNull()
        ->and($profile->phoneVerified)->toBeFalse()
        ->and($profile->locale)->toBe('ms')
        ->and($profile->timezone)->toBe('Asia/Jakarta');
});

it('returns null for unknown accounts and exposes the account type for known ones', function (): void {
    $unknownId = (string) Str::uuid();

    expect(app(AccountDirectory::class)->profile($unknownId))->toBeNull()
        ->and(app(AccountDirectory::class)->accountType($unknownId))->toBeNull();

    $user = User::factory()->create();

    expect(app(AccountDirectory::class)->accountType((string) $user->getKey()))->toBe('user');
});
