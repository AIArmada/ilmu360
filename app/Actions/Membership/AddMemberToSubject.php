<?php

namespace App\Actions\Membership;

use App\Enums\MemberSubjectType;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\Speaker;
use App\Models\User;
use App\Support\Submission\PublicSubmissionLockService;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class AddMemberToSubject
{
    use AsAction;

    public function __construct(
        private ChangeSubjectMemberRole $changeSubjectMemberRole,
        private PublicSubmissionLockService $publicSubmissionLockService,
    ) {}

    public function handle(Institution|Speaker|Event|Reference $subject, User $member, ?string $roleIdentifier = null): void
    {
        $subjectType = MemberSubjectType::forSubject($subject);

        match ($subjectType) {
            MemberSubjectType::Institution,
            MemberSubjectType::Speaker,
            MemberSubjectType::Reference => $this->syncMembersWithoutDetaching($subject, [$member->getKey()]),
            MemberSubjectType::Event => $this->syncMembersWithoutDetaching($subject, [
                $member->getKey() => ['joined_at' => now()],
            ]),
        };

        if ($roleIdentifier !== null && $roleIdentifier !== '') {
            $this->changeSubjectMemberRole->handle($subject, $member, $roleIdentifier);
        }

        if ($subject instanceof Institution) {
            $this->publicSubmissionLockService->ensureInstitutionUnlockedIfIneligible($subject->fresh());
        }

        if ($subject instanceof Speaker) {
            $this->publicSubmissionLockService->ensureSpeakerUnlockedIfIneligible($subject->fresh());
        }
    }

    /**
     * @param  array<int|string, mixed>  $members
     */
    private function syncMembersWithoutDetaching(Institution|Speaker|Event|Reference $subject, array $members): void
    {
        $subject->auditSync('members', $members, false, ['users.id', 'users.name']);
    }
}
