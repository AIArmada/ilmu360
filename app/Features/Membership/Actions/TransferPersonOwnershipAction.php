<?php

declare(strict_types=1);

namespace App\Features\Membership\Actions;

use AIArmada\Membership\Contracts\MembershipHook;
use AIArmada\Membership\Enums\MemberRole;
use AIArmada\Membership\Services\MembershipRoleSyncService;
use App\Shared\Membership\MembershipAuthorization;
use App\Shared\Membership\MembershipSubjectType;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Transfer speaker ownership between two existing person members.
 *
 * The person row and both pivot rows are locked, the two pivot roles are
 * updated, the mapped scoped roles are synchronised, and the membership hooks
 * run in the same transaction. One-owner tolerance is preserved: exactly one
 * owner must exist before the transfer starts.
 */
final readonly class TransferPersonOwnershipAction
{
    use AsAction;

    private const string PERSON_MEMBERS_TABLE = 'person_members';

    public function __construct(
        private MembershipAuthorization $membershipAuthorization,
        private MembershipRoleSyncService $membershipRoleSync,
        private MembershipHook $membershipHook,
    ) {}

    public function handle(Model $person, Model $actor, Model $newOwner): Model
    {
        $this->assertCanTransfer($person, $actor);

        return DB::transaction(function () use ($actor, $person, $newOwner): Model {
            /** @var Model $lockedPerson */
            $lockedPerson = $person->newQuery()
                ->whereKey($person->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $personId = (string) $lockedPerson->getKey();
            $actorId = (string) $actor->getKey();
            $newOwnerId = (string) $newOwner->getKey();

            $owners = DB::table(self::PERSON_MEMBERS_TABLE)
                ->where('person_id', $personId)
                ->where('role', MemberRole::Owner->spatieRoleName())
                ->lockForUpdate()
                ->get();

            if ($owners->count() !== 1) {
                throw new RuntimeException('Person ownership invariant violated: exactly one owner must exist.');
            }

            if ((string) data_get($owners->first(), 'user_id') !== $actorId) {
                throw new AuthorizationException('Only the current speaker owner can transfer ownership.');
            }

            $target = DB::table(self::PERSON_MEMBERS_TABLE)
                ->where('person_id', $personId)
                ->where('user_id', $newOwnerId)
                ->lockForUpdate()
                ->first();

            if ($target === null) {
                throw new RuntimeException('The new owner must already be a speaker profile member.');
            }

            $targetRole = MemberRole::fromSpatieRoleName((string) data_get($target, 'role'));

            if (! $targetRole instanceof MemberRole) {
                throw new RuntimeException('The new owner has an invalid membership role.');
            }

            if ($actorId === $newOwnerId) {
                return $lockedPerson->fresh() ?? $lockedPerson;
            }

            $updatedAt = now();

            DB::table(self::PERSON_MEMBERS_TABLE)
                ->where('person_id', $personId)
                ->where('user_id', $actorId)
                ->update([
                    'role' => MemberRole::Admin->spatieRoleName(),
                    'updated_at' => $updatedAt,
                ]);
            DB::table(self::PERSON_MEMBERS_TABLE)
                ->where('person_id', $personId)
                ->where('user_id', $newOwnerId)
                ->update([
                    'role' => MemberRole::Owner->spatieRoleName(),
                    'updated_at' => $updatedAt,
                ]);

            $this->membershipRoleSync->revokeFromUser($lockedPerson, $actor, MemberRole::Owner);
            $this->membershipRoleSync->assignToUser($lockedPerson, $actor, MemberRole::Admin);

            if ($targetRole !== MemberRole::Owner) {
                $this->membershipRoleSync->revokeFromUser($lockedPerson, $newOwner, $targetRole);
            }

            $this->membershipRoleSync->assignToUser($lockedPerson, $newOwner, MemberRole::Owner);

            $this->membershipHook->onMemberRoleChanged($lockedPerson, $actor, MemberRole::Owner, MemberRole::Admin);

            if ($targetRole !== MemberRole::Owner) {
                $this->membershipHook->onMemberRoleChanged($lockedPerson, $newOwner, $targetRole, MemberRole::Owner);
            }

            return $lockedPerson->fresh() ?? $lockedPerson;
        });
    }

    private function assertCanTransfer(Model $person, Model $actor): void
    {
        if (! $this->membershipAuthorization->can(
            (string) $actor->getKey(),
            'person.transfer-ownership',
            MembershipSubjectType::Person,
            (string) $person->getKey(),
        )) {
            throw new AuthorizationException('Only the current speaker owner can transfer ownership.');
        }
    }
}
