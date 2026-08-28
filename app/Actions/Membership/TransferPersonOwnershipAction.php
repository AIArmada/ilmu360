<?php

declare(strict_types=1);

namespace App\Actions\Membership;

use AIArmada\Membership\Contracts\MembershipHook;
use AIArmada\Membership\Enums\MemberRole;
use AIArmada\Membership\Services\MembershipRoleSyncService;
use App\Models\Person;
use App\Models\User;
use App\Support\Authz\MemberPermissionGate;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

final class TransferPersonOwnershipAction
{
    use AsAction;

    public function __construct(
        private readonly MemberPermissionGate $memberPermissionGate,
        private readonly MembershipRoleSyncService $membershipRoleSync,
        private readonly MembershipHook $membershipHook,
    ) {}

    public function handle(Person $person, User $actor, User $newOwner): Person
    {
        if (! $this->memberPermissionGate->canPerson($actor, 'person.transfer-ownership', $person)) {
            throw new AuthorizationException('Only the current speaker owner can transfer ownership.');
        }

        return DB::transaction(function () use ($actor, $person, $newOwner): Person {
            /** @var Person $lockedPerson */
            $lockedPerson = $person->newQuery()
                ->whereKey($person->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $owners = $lockedPerson->ownerMember()->lockForUpdate()->get();

            if ($owners->count() !== 1) {
                throw new RuntimeException('Person ownership invariant violated: exactly one owner must exist.');
            }

            $currentOwner = $owners->first();

            if (! $currentOwner instanceof User) {
                throw new RuntimeException('Person ownership invariant violated: owner must be a user.');
            }

            if (! $currentOwner->is($actor)) {
                throw new AuthorizationException('Only the current speaker owner can transfer ownership.');
            }

            $target = $lockedPerson->members()->lockForUpdate()->whereKey($newOwner->getKey())->first();

            if (! $target instanceof User) {
                throw new RuntimeException('The new owner must already be a speaker profile member.');
            }

            $targetRole = MemberRole::fromSpatieRoleName((string) data_get($target->getRelationValue('pivot'), 'role'));

            if (! $targetRole instanceof MemberRole) {
                throw new RuntimeException('The new owner has an invalid membership role.');
            }

            if ($currentOwner->is($target)) {
                return $lockedPerson->fresh() ?? $lockedPerson;
            }

            $members = $lockedPerson->members();
            $members->updateExistingPivot($currentOwner->getKey(), [
                'role' => MemberRole::Admin->spatieRoleName(),
            ]);
            $members->updateExistingPivot($target->getKey(), [
                'role' => MemberRole::Owner->spatieRoleName(),
            ]);

            $this->membershipRoleSync->revokeFromUser($lockedPerson, $currentOwner, MemberRole::Owner);
            $this->membershipRoleSync->assignToUser($lockedPerson, $currentOwner, MemberRole::Admin);

            if ($targetRole !== MemberRole::Owner) {
                $this->membershipRoleSync->revokeFromUser($lockedPerson, $target, $targetRole);
            }

            $this->membershipRoleSync->assignToUser($lockedPerson, $target, MemberRole::Owner);

            $this->membershipHook->onMemberRoleChanged($lockedPerson, $currentOwner, MemberRole::Owner, MemberRole::Admin);

            if ($targetRole !== MemberRole::Owner) {
                $this->membershipHook->onMemberRoleChanged($lockedPerson, $target, $targetRole, MemberRole::Owner);
            }

            return $lockedPerson->fresh() ?? $lockedPerson;
        });
    }
}
