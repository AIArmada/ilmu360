<?php

declare(strict_types=1);

namespace App\Features\Membership\Actions;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Membership\Actions\AcceptInvitationAction;
use AIArmada\Membership\Models\MembershipInvitation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * App-side invitation acceptance guards around the canonical package action.
 */
final readonly class AcceptSubjectMemberInvitation
{
    use AsAction;

    public function __construct(
        private AcceptInvitationAction $acceptInvitation,
    ) {}

    public function handle(MembershipInvitation $invitation, Model $invitee): void
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

        $inviteeEmail = method_exists($invitee, 'getEmailForVerification')
            ? $invitee->getEmailForVerification()
            : $invitee->getAttribute('email');

        if (! is_string($inviteeEmail) || mb_trim($inviteeEmail) === '') {
            throw ValidationException::withMessages(['email' => 'User does not have an email address.']);
        }

        $subject = $invitation->subject;

        if ($subject === null || ! $subject->exists()) {
            throw ValidationException::withMessages(['subject' => 'The invited subject no longer exists.']);
        }

        if ($invitation->role === 'owner') {
            throw ValidationException::withMessages(['role' => 'Ownership invitations cannot be accepted through the standard flow.']);
        }

        OwnerContext::withOwner(null, function () use ($invitation, $invitee): void {
            $this->acceptInvitation->handle($invitation, $invitee);
        });
    }
}
