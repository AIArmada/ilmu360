<?php

namespace App\Actions\Membership;

use App\Models\MemberInvitation;
use Lorisleiva\Actions\Concerns\AsAction;

final class ResolveMemberInvitationByTokenAction
{
    use AsAction;

    public function handle(string $token): MemberInvitation
    {
        return MemberInvitation::query()
            ->with(['inviter', 'acceptedBy', 'revokedBy'])
            ->where('token', $token)
            ->firstOrFail();
    }
}
