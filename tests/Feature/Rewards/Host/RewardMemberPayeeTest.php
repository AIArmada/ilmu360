<?php

declare(strict_types=1);

use App\Actions\Rewards\RegisterRewardPayeeAction;
use App\Actions\Rewards\RejectRewardPayeeAction;
use App\Actions\Rewards\VerifyRewardPayeeAction;
use App\Enums\Rewards\RewardPayeeStatus;
use App\Models\RewardPayee;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    setPermissionsTeamId(null);
    $this->seed(RoleSeeder::class);

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->member = User::factory()->create();
    $this->staff = User::factory()->create();
    $this->staff->assignRole('admin');
});

it('lets a member register a payout destination that lands pending', function (): void {
    Sanctum::actingAs($this->member);

    $response = $this->postJson('/api/v1/rewards/payees', [
        'method' => 'paypal',
        'account_ref' => 'member@example.com',
        'account_display' => 'm***@example.com',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.status', RewardPayeeStatus::Pending->value)
        ->assertJsonPath('data.method', 'paypal')
        ->assertJsonPath('data.account_display', 'm***@example.com')
        ->assertJsonMissingPath('data.account_ref');

    expect(RewardPayee::query()->count())->toBe(1);

    $list = $this->getJson('/api/v1/rewards/payees');

    $list->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonMissingPath('data.0.account_ref');
});

it('refuses unpayable destinations at registration via the installed processor', function (): void {
    Sanctum::actingAs($this->member);

    $response = $this->postJson('/api/v1/rewards/payees', [
        'method' => 'paypal',
        'account_ref' => 'not-an-email',
        'account_display' => 'n***',
    ]);

    $response->assertStatus(422);
    expect(RewardPayee::query()->count())->toBe(0);
});

it('supersedes only prior pending registrations, keeping a verified destination live', function (): void {
    $memberId = (string) $this->member->getKey();
    $staffId = (string) $this->staff->getKey();

    $first = RegisterRewardPayeeAction::run(
        'user', $memberId, 'manual', 'op-ref-1', 'Desk 1', 'user', $memberId, 'member',
    );
    $second = RegisterRewardPayeeAction::run(
        'user', $memberId, 'manual', 'op-ref-2', 'Desk 2', 'user', $memberId, 'member',
    );

    expect($first->refresh()->status)->toBe(RewardPayeeStatus::Superseded)
        ->and($second->refresh()->status)->toBe(RewardPayeeStatus::Pending);

    $verified = VerifyRewardPayeeAction::run(
        'user', $memberId, 'manual', 'op-ref-2', 'Desk 2', 'user', $staffId, 'staff',
        ['verified_from_registration_id' => (string) $second->getKey()],
    );

    expect($verified->status)->toBe(RewardPayeeStatus::Verified)
        ->and($second->refresh()->status)->toBe(RewardPayeeStatus::Superseded);

    $third = RegisterRewardPayeeAction::run(
        'user', $memberId, 'manual', 'op-ref-3', 'Desk 3', 'user', $memberId, 'member',
    );

    expect($verified->refresh()->status)->toBe(RewardPayeeStatus::Verified)
        ->and($third->status)->toBe(RewardPayeeStatus::Pending);
});

it('rejects pending registrations with reason evidence and never touches verified rows', function (): void {
    $memberId = (string) $this->member->getKey();
    $staffId = (string) $this->staff->getKey();

    $pending = RegisterRewardPayeeAction::run(
        'user', $memberId, 'manual', 'op-ref-9', 'Desk 9', 'user', $memberId, 'member',
    );

    $rejected = RejectRewardPayeeAction::run(
        $pending, 'user', $staffId, 'staff', 'Account holder name mismatch.',
    );

    expect($rejected->status)->toBe(RewardPayeeStatus::Rejected)
        ->and($rejected->rejected_at)->not->toBeNull()
        ->and($rejected->evidence['rejection_reason'] ?? null)->toBe('Account holder name mismatch.');

    $verified = VerifyRewardPayeeAction::run(
        'user', $memberId, 'manual', 'op-ref-9', 'Desk 9', 'user', $staffId, 'staff',
    );

    expect(fn () => RejectRewardPayeeAction::run($verified, 'user', $staffId, 'staff', 'Too late.'))
        ->toThrow(InvalidArgumentException::class, 'Only a pending payee registration can be rejected.');
});

it('prohibits self-verification, self-rejection, and registering for someone else', function (): void {
    $memberId = (string) $this->member->getKey();

    $pending = RegisterRewardPayeeAction::run(
        'user', $memberId, 'manual', 'op-ref-7', 'Desk 7', 'user', $memberId, 'member',
    );

    expect(fn () => VerifyRewardPayeeAction::run(
        'user', $memberId, 'manual', 'op-ref-7', 'Desk 7', 'user', $memberId, 'staff',
    ))->toThrow(InvalidArgumentException::class, 'self-verification');

    expect(fn () => RejectRewardPayeeAction::run($pending, 'user', $memberId, 'staff', 'No.'))
        ->toThrow(InvalidArgumentException::class, 'self-decision');

    $other = User::factory()->create();

    expect(fn () => RegisterRewardPayeeAction::run(
        'user', (string) $other->getKey(), 'manual', 'op-ref-x', 'Desk X', 'user', $memberId, 'member',
    ))->toThrow(InvalidArgumentException::class, 'only for the recipient themselves');
});
