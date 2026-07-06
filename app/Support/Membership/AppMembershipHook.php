<?php

namespace App\Support\Membership;

use AIArmada\Membership\Contracts\MembershipHook;
use AIArmada\Membership\Enums\MemberRole;
use App\Models\Institution;
use App\Models\Speaker;
use App\Support\Submission\PublicSubmissionLockService;
use Illuminate\Database\Eloquent\Model;

readonly class AppMembershipHook implements MembershipHook
{
    public function __construct(
        private PublicSubmissionLockService $publicSubmissionLockService,
    ) {}

    public function onMemberAdded(Model $subject, Model $user, MemberRole $role): void
    {
        $this->syncSubmissionLock($subject);
    }

    public function onMemberRemoved(Model $subject, Model $user, ?MemberRole $previousRole): void
    {
        $this->syncSubmissionLock($subject);
    }

    public function onMemberRoleChanged(Model $subject, Model $user, MemberRole $oldRole, MemberRole $newRole): void
    {
        $this->syncSubmissionLock($subject);
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
