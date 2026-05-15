<?php

namespace App\Actions\Membership;

use App\Enums\MembershipClaimStatus;
use App\Models\MembershipClaim;
use App\Models\User;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class RejectMembershipClaimAction
{
    use AsAction;

    public function handle(MembershipClaim $claim, User $reviewer, ?string $reviewerNote = null): MembershipClaim
    {
        if (! $claim->isPending()) {
            throw new RuntimeException('membership_claim_not_pending');
        }

        $claim->forceFill([
            'status' => MembershipClaimStatus::Rejected,
            'granted_role_slug' => null,
            'reviewer_id' => $reviewer->getKey(),
            'reviewed_at' => now(),
            'reviewer_note' => filled($reviewerNote) ? trim($reviewerNote) : null,
        ])->save();

        return $claim->refresh();
    }
}
