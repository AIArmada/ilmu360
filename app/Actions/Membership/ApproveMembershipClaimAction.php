<?php

namespace App\Actions\Membership;

use App\Enums\MembershipClaimStatus;
use App\Enums\MemberSubjectType;
use App\Models\MembershipClaim;
use App\Models\User;
use App\Support\Authz\MemberRoleCatalog;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class ApproveMembershipClaimAction
{
    use AsAction;

    public function __construct(
        private AddMemberToSubject $addMemberToSubject,
        private ChangeSubjectMemberRole $changeSubjectMemberRole,
        private MemberRoleCatalog $memberRoleCatalog,
    ) {}

    public function handle(MembershipClaim $claim, User $reviewer, string $grantedRoleSlug, ?string $reviewerNote = null): MembershipClaim
    {
        if (! $claim->isPending()) {
            throw new RuntimeException('membership_claim_not_pending');
        }

        $subjectType = $claim->subject_type instanceof MemberSubjectType
            ? $claim->subject_type
            : MemberSubjectType::from((string) $claim->subject_type);
        $claimant = $claim->claimant;

        if (! $subjectType->isClaimable()) {
            throw new RuntimeException('membership_claim_out_of_scope');
        }

        if (! $claimant instanceof User) {
            throw new RuntimeException('membership_claim_missing_claimant');
        }

        if (! $this->memberRoleCatalog->isMembershipClaimRole($subjectType, $grantedRoleSlug)) {
            throw new RuntimeException('membership_claim_invalid_role');
        }

        $subject = $subjectType->resolveSubject($claim->subject_id);
        $this->addMemberToSubject->handle($subject, $claimant);
        $this->changeSubjectMemberRole->handle($subjectType, $claimant, $grantedRoleSlug, allowProtectedRoleChange: true);

        $claim->forceFill([
            'status' => MembershipClaimStatus::Approved,
            'granted_role_slug' => $grantedRoleSlug,
            'reviewer_id' => $reviewer->getKey(),
            'reviewed_at' => now(),
            'reviewer_note' => filled($reviewerNote) ? trim($reviewerNote) : null,
        ])->save();

        return $claim->refresh();
    }
}
