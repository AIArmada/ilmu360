<?php

namespace App\Actions\Membership;

use AIArmada\Membership\Enums\ApplicationStatus;
use App\Models\MembershipClaim;
use App\Models\User;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class CancelMembershipClaimAction
{
    use AsAction;

    public function handle(MembershipClaim $claim, User $claimant): MembershipClaim
    {
        if (! $claim->isPending() || (string) $claim->applicant_id !== (string) $claimant->getKey()) {
            throw new RuntimeException('membership_claim_cannot_cancel');
        }

        $claim->forceFill([
            'status' => ApplicationStatus::Cancelled,
            'cancelled_at' => now(),
        ])->save();

        return $claim->refresh();
    }
}
