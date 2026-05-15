<?php

namespace App\Actions\Membership;

use App\Enums\MemberSubjectType;
use App\Models\MemberInvitation;
use App\Models\User;
use App\Support\Authz\MemberRoleCatalog;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

final readonly class AcceptSubjectMemberInvitation
{
    use AsAction;

    public function __construct(
        private AddMemberToSubject $addMemberToSubject,
        private MemberRoleCatalog $memberRoleCatalog,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(MemberInvitation $invitation, User $user): MemberInvitation
    {
        $this->ensureInvitationIsAcceptable($invitation, $user);

        $subjectType = $invitation->subject_type;

        if (! $subjectType instanceof MemberSubjectType) {
            throw new RuntimeException('Invitation subject type is not valid.');
        }

        try {
            $subject = $subjectType->resolveSubject($invitation->subject_id);
        } catch (ModelNotFoundException) {
            throw ValidationException::withMessages([
                'invitation' => __('This invitation is no longer valid.'),
            ]);
        }

        $this->addMemberToSubject->handle($subject, $user, $invitation->role_slug);

        $invitation->forceFill([
            'accepted_at' => now(),
            'accepted_by' => $user->getKey(),
        ])->save();

        return $invitation->fresh() ?? $invitation;
    }

    /**
     * @throws ValidationException
     */
    private function ensureInvitationIsAcceptable(MemberInvitation $invitation, User $user): void
    {
        if ($invitation->isAccepted()) {
            throw ValidationException::withMessages([
                'invitation' => __('This invitation has already been accepted.'),
            ]);
        }

        if ($invitation->isRevoked()) {
            throw ValidationException::withMessages([
                'invitation' => __('This invitation has been revoked.'),
            ]);
        }

        if ($invitation->isExpired()) {
            throw ValidationException::withMessages([
                'invitation' => __('This invitation has expired.'),
            ]);
        }

        $subjectType = $invitation->subject_type;

        if (! $subjectType instanceof MemberSubjectType) {
            throw ValidationException::withMessages([
                'invitation' => __('This invitation is no longer valid.'),
            ]);
        }

        if (! $this->memberRoleCatalog->isInvitableRole($subjectType, $invitation->role_slug)) {
            throw ValidationException::withMessages([
                'invitation' => __('This invitation is no longer valid.'),
            ]);
        }

        $userEmail = is_string($user->email) ? trim($user->email) : '';

        if ($userEmail === '') {
            throw ValidationException::withMessages([
                'email' => __('Add an email address to your account before accepting this invitation.'),
            ]);
        }

        if (mb_strtolower($userEmail) !== mb_strtolower($invitation->email)) {
            throw ValidationException::withMessages([
                'email' => __('This invitation was sent to a different email address.'),
            ]);
        }
    }
}
