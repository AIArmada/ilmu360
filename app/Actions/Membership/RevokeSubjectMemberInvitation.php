<?php

declare(strict_types=1);

namespace App\Actions\Membership;

use AIArmada\Membership\Actions\RevokeInvitationAction;
use App\Models\MemberInvitation;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class RevokeSubjectMemberInvitation
{
    use AsAction;

    public function __construct(
        private RevokeInvitationAction $revokeInvitation,
    ) {}

    public function handle(MemberInvitation $invitation, Model $actor): void
    {
        $this->revokeInvitation->handle($invitation, $actor);
    }
}
