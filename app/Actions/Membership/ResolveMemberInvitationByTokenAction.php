<?php

namespace App\Actions\Membership;

use App\Models\MemberInvitation;
use Lorisleiva\Actions\Concerns\AsAction;

final class ResolveMemberInvitationByTokenAction
{
    use AsAction;

    public function handle(string $token): MemberInvitation
    {
        $storedToken = MemberInvitation::tokenForStorage($token);

        return MemberInvitation::query()
            ->with(['inviter', 'acceptedBy', 'revokedBy'])
            ->where(function ($query) use ($storedToken, $token): void {
                $query->where('token', $storedToken);

                if ($storedToken !== $token) {
                    $query->orWhere('token', $token);
                }
            })
            ->firstOrFail();
    }
}
