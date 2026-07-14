<?php

declare(strict_types=1);

namespace App\Actions\Membership;

use App\Models\MemberInvitation;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class ResolveMemberInvitationByTokenAction
{
    use AsAction;

    public function handle(string $token): ?MemberInvitation
    {
        $hashedToken = MemberInvitation::tokenForStorage($token);

        /** @var MemberInvitation|null $invitation */
        $invitation = MemberInvitation::query()
            ->where('token', $hashedToken)
            ->first();

        return $invitation;
    }
}
