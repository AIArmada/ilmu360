<?php

namespace App\Actions\Membership;

use AIArmada\FilamentAuthz\Facades\Authz;
use App\Enums\MemberSubjectType;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\Speaker;
use App\Models\User;
use App\Support\Authz\MemberRoleCatalog;
use App\Support\Authz\MemberRoleScopes;
use App\Support\Authz\ScopedMemberRoleSeeder;
use App\Support\Submission\PublicSubmissionLockService;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

final readonly class ChangeSubjectMemberRole
{
    use AsAction;

    public function __construct(
        private MemberRoleCatalog $memberRoleCatalog,
        private MemberRoleScopes $memberRoleScopes,
        private ScopedMemberRoleSeeder $scopedMemberRoleSeeder,
        private PublicSubmissionLockService $publicSubmissionLockService,
    ) {}

    public function handle(
        Institution|Speaker|Event|Reference|MemberSubjectType $subject,
        User $member,
        ?string $roleIdentifier,
        bool $allowProtectedRoleChange = false,
    ): void {
        $subjectType = $subject instanceof MemberSubjectType
            ? $subject
            : MemberSubjectType::forSubject($subject);
        $previousRoleName = $this->memberRoleCatalog->currentRoleName($member, $subjectType);

        $this->scopedMemberRoleSeeder->ensure($subjectType);

        $this->ensureProtectedRoleCanBeChanged($member, $subjectType, $roleIdentifier, $allowProtectedRoleChange);

        $resolvedRoleId = $this->memberRoleCatalog->resolveRoleId($subjectType, $roleIdentifier);
        $scope = $subjectType->authzScope($this->memberRoleScopes);

        Authz::withScope($scope, function () use ($member, $resolvedRoleId): void {
            $member->syncRoles($resolvedRoleId === null ? [] : [$resolvedRoleId]);
        }, $member);

        $currentRoleName = $this->memberRoleCatalog->currentRoleName($member, $subjectType);

        if (! $subject instanceof MemberSubjectType) {
            $subject->recordCustomAuditDifferences(
                'member_role_changed',
                [
                    'member_role' => [
                        'user_id' => $member->getKey(),
                        'user_name' => $member->name,
                        'role' => $previousRoleName,
                    ],
                ],
                [
                    'member_role' => [
                        'user_id' => $member->getKey(),
                        'user_name' => $member->name,
                        'role' => $currentRoleName,
                    ],
                ],
            );
        }

        if ($subjectType->usesPublicSubmissionLocks()) {
            $this->publicSubmissionLockService->syncForUser($member);
        }
    }

    private function ensureProtectedRoleCanBeChanged(
        User $member,
        MemberSubjectType $subjectType,
        ?string $roleIdentifier,
        bool $allowProtectedRoleChange,
    ): void {
        if ($allowProtectedRoleChange) {
            return;
        }

        $currentRoleName = $this->memberRoleCatalog->currentRoleName($member, $subjectType);

        if ($currentRoleName === null || ! $this->memberRoleCatalog->isProtectedRole($subjectType, $currentRoleName)) {
            return;
        }

        $targetRoleName = $this->memberRoleCatalog->resolveRoleName($subjectType, $roleIdentifier);

        if ($targetRoleName === $currentRoleName) {
            return;
        }

        throw new RuntimeException('Protected ownership roles can only be changed from the global authz surface.');
    }
}
