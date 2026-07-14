<?php

declare(strict_types=1);

namespace App\Actions\Membership;

use AIArmada\Membership\Actions\AcceptInvitationAction;
use App\Models\MemberInvitation;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class AcceptSubjectMemberInvitation
{
    use AsAction;

    public function __construct(
        private AcceptInvitationAction $acceptInvitation,
    ) {}

    public function handle(MemberInvitation $invitation, User $invitee): void
    {
        if ($invitation->isExpired()) {
            throw ValidationException::withMessages(['invitation' => 'This invitation has expired.']);
        }

        if ($invitation->isAccepted()) {
            throw ValidationException::withMessages(['invitation' => 'This invitation has already been accepted.']);
        }

        if ($invitation->isRevoked()) {
            throw ValidationException::withMessages(['invitation' => 'This invitation has been revoked.']);
        }

        if ($invitee->getEmailForVerification() === null) {
            throw ValidationException::withMessages(['email' => 'User does not have an email address.']);
        }

        $subject = $invitation->subject;

        if ($subject === null || ! $subject->exists()) {
            throw ValidationException::withMessages(['subject' => 'The invited subject no longer exists.']);
        }

        if ($invitation->role === 'owner') {
            throw ValidationException::withMessages(['role' => 'Ownership invitations cannot be accepted through the standard flow.']);
        }

        $this->acceptInvitation->handle($invitation, $invitee);
    }
}
