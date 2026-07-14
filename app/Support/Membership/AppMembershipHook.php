<?php

namespace App\Support\Membership;

use AIArmada\Membership\Contracts\MembershipHook;
use AIArmada\Membership\Enums\MemberRole;
use App\Models\Institution;
use App\Models\Speaker;
use App\Support\Submission\PublicSubmissionLockService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

readonly class AppMembershipHook implements MembershipHook
{
    public function __construct(
        private PublicSubmissionLockService $publicSubmissionLockService,
    ) {}

    public function onMemberAdded(Model $subject, Model $user, MemberRole $role): void
    {
        $this->recordMemberSyncAudit($subject);
        $this->recordMemberRoleAudit($subject, $user, null, $role);
        $this->syncSubmissionLock($subject);
    }

    public function onMemberRemoved(Model $subject, Model $user, ?MemberRole $previousRole): void
    {
        $this->syncSubmissionLock($subject);
    }

    public function onMemberRoleChanged(Model $subject, Model $user, MemberRole $oldRole, MemberRole $newRole): void
    {
        $this->recordMemberRoleAudit($subject, $user, $oldRole, $newRole);

        $this->syncSubmissionLock($subject);
    }

    private function recordMemberRoleAudit(Model $subject, Model $user, ?MemberRole $oldRole, MemberRole $newRole): void
    {
        if (! method_exists($subject, 'recordCustomAudit')) {
            return;
        }

        $subject->recordCustomAudit(
            'member_role_changed',
            ['member_role' => ['user_id' => $user->getKey(), 'role' => $oldRole?->spatieRoleName()]],
            ['member_role' => ['user_id' => $user->getKey(), 'role' => $newRole->spatieRoleName()]],
        );
    }

    private function recordMemberSyncAudit(Model $subject): void
    {
        if (! method_exists($subject, 'recordCustomAudit') || ! method_exists($subject, 'members')) {
            return;
        }

        /** @var Collection<int, Model> $members */
        $members = $subject->members()->get();

        $subject->recordCustomAudit('sync', [], [
            'members' => $members->map(function (Model $member): array {
                return [
                    'id' => $member->getKey(),
                    'name' => $member->getAttribute('name'),
                    'role' => $member->getRelationValue('pivot')?->getAttribute('role'),
                ];
            })->values()->all(),
        ]);
    }

    private function syncSubmissionLock(Model $subject): void
    {
        if (! $subject->exists) {
            return;
        }

        match ($subject::class) {
            Institution::class => $this->publicSubmissionLockService->ensureInstitutionUnlockedIfIneligible($subject->fresh()),
            Speaker::class => $this->publicSubmissionLockService->ensureSpeakerUnlockedIfIneligible($subject->fresh()),
            default => null,
        };
    }
}
