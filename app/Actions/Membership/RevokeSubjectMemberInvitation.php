<?php

namespace App\Actions\Membership;

use App\Models\MemberInvitation;
use App\Models\User;
use Lorisleiva\Actions\Concerns\AsAction;

final class RevokeSubjectMemberInvitation
{
    use AsAction;

    public function handle(MemberInvitation $invitation, User $actor): MemberInvitation
    {
        if ($invitation->isAccepted() || $invitation->isRevoked()) {
            return $invitation;
        }

        $invitation->forceFill([
            'revoked_at' => now(),
            'revoked_by' => $actor->getKey(),
        ])->save();

        return $invitation->fresh() ?? $invitation;
    }
}
