<?php

use AIArmada\Authz\Models\Role;
use App\Models\User;
use App\Shared\Auth\AccountActor;
use App\Shared\Auth\GlobalRole;
use App\Shared\Feedback\FeedbackActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('exposes global roles through the account actor contract', function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $user = User::factory()->create();
    $user->assignRole(Role::findOrCreate(GlobalRole::SUPER_ADMIN, 'web'));

    expect($user)
        ->toBeInstanceOf(AccountActor::class)
        ->and($user->hasRole(GlobalRole::SUPER_ADMIN))->toBeTrue()
        ->and($user->hasRole(GlobalRole::ADMIN))->toBeFalse()
        ->and($user->hasRole(GlobalRole::MODERATOR))->toBeFalse();
});

it('implements feedback eligibility through the segregated feedback actor contract', function (): void {
    $user = User::factory()->create();

    expect($user)
        ->toBeInstanceOf(FeedbackActor::class)
        ->and($user->canSubmitAppFeedback())->toBeTrue()
        ->and($user->canSubmitDirectoryFeedback())->toBeTrue()
        ->and($user->directoryFeedbackBanMessage())->toBeString()
        ->and((new ReflectionClass(AccountActor::class))->hasMethod('canSubmitAppFeedback'))->toBeFalse();
});
