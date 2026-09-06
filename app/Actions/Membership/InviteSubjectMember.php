<?php

declare(strict_types=1);

namespace App\Actions\Membership;

use AIArmada\Membership\Enums\MemberRole;
use App\Models\MemberInvitation;
use App\Notifications\Membership\MemberInvitationNotification;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class InviteSubjectMember
{
    public function __construct(
        private Dispatcher $notifications,
    ) {}

    public function handle(Model $subject, string $email, string $role, Model $inviter, ?CarbonInterface $expiresAt = null): MemberInvitation
    {
        if ($role === MemberRole::Owner->value) {
            throw new InvalidArgumentException('Ownership invitations are not allowed through the standard invitation flow.');
        }

        $memberRole = MemberRole::from($role);

        $rawToken = Str::random(64);
        $expiresAt ??= now()->addDays(
            (int) config('membership.invitations.default_expiry_days', 14)
        );

        $invitation = new MemberInvitation;
        $invitation->fill([
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'email' => mb_strtolower($email),
            'role' => $memberRole->spatieRoleName(),
            'invited_by' => $inviter->getKey(),
        ]);
        $invitation->issue($rawToken, $expiresAt)->save();

        $acceptUrl = route('member-invitations.show', ['token' => $rawToken]);

        $inviterName = (string) ($inviter->getAttribute('name') ?? '');
        $subjectName = (string) ($subject->getAttribute('name') ?? $subject->getAttribute('title') ?? '');

        $notifiable = new AnonymousNotifiable;
        $notifiable->route('mail', $email);

        $this->notifications->send($notifiable, new MemberInvitationNotification(
            inviterName: $inviterName,
            subjectLabel: $subject->getMorphClass(),
            subjectName: $subjectName,
            roleLabel: $memberRole->label(),
            invitedEmail: $email,
            acceptUrl: $acceptUrl,
            expiresAt: $expiresAt,
        ));

        return $invitation;
    }
}
