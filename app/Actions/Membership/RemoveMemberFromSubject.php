<?php

namespace App\Actions\Membership;

use App\Enums\MemberSubjectType;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\Speaker;
use App\Models\User;
use App\Support\Authz\MemberRoleCatalog;
use App\Support\Submission\PublicSubmissionLockService;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

final readonly class RemoveMemberFromSubject
{
    use AsAction;

    public function __construct(
        private ChangeSubjectMemberRole $changeSubjectMemberRole,
        private MemberRoleCatalog $memberRoleCatalog,
        private PublicSubmissionLockService $publicSubmissionLockService,
    ) {}

    public function handle(Institution|Speaker|Event|Reference $subject, User $member): void
    {
        $subjectType = MemberSubjectType::forSubject($subject);
        $currentRoleName = $this->memberRoleCatalog->currentRoleName($member, $subjectType);

        if (
            $currentRoleName !== null &&
            $this->memberRoleCatalog->isProtectedRole($subjectType, $currentRoleName)
        ) {
            throw new RuntimeException('Protected ownership roles can only be changed from the global authz surface.');
        }

        $subject->auditDetach('members', $member->getKey(), true, ['users.id', 'users.name']);

        if (
            $currentRoleName !== null &&
            ! $this->memberRoleCatalog->isProtectedRole($subjectType, $currentRoleName) &&
            ! $subjectType->userHasAnyMembership($member)
        ) {
            $this->changeSubjectMemberRole->handle($subject, $member, null);
        }

        if ($subject instanceof Institution) {
            $this->publicSubmissionLockService->ensureInstitutionUnlockedIfIneligible($subject->fresh());
        }

        if ($subject instanceof Speaker) {
            $this->publicSubmissionLockService->ensureSpeakerUnlockedIfIneligible($subject->fresh());
        }
    }
}
