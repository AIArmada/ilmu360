<?php

declare(strict_types=1);

namespace App\Features\Membership\Actions;

use AIArmada\Membership\Enums\InvitationStatus;
use AIArmada\Membership\Enums\MemberRole;
use AIArmada\Membership\Models\MembershipInvitation;
use App\Features\Membership\Notifications\MemberInvitationNotification;
use App\Platform\Models\MorphModelResolver;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * App-side member invitation writer.
 *
 * Application invitations keep their own raw bearer token and the app-only
 * invitation email. The package InviteMemberAction remains the canonical
 * external writer for package-driven flows (provider/admin relation managers)
 * and intentionally does not send this email.
 */
final readonly class InviteSubjectMember
{
    public function __construct(
        private Dispatcher $notifications,
    ) {}

    public function handle(
        Model $subject,
        string $email,
        string $role,
        Model $inviter,
        ?CarbonInterface $expiresAt = null,
    ): MembershipInvitation {
        if ($role === MemberRole::Owner->value) {
            throw new InvalidArgumentException('Ownership invitations are not allowed through the standard invitation flow.');
        }

        $memberRole = MemberRole::from($role);
        $email = mb_strtolower(mb_trim($email));

        $this->assertNoPendingInvitation($subject, $email);

        $rawToken = Str::random(64);
        $expiresAt ??= now()->addDays(
            (int) config('membership.invitations.default_expiry_days', 14)
        );

        /** @var class-string<MembershipInvitation> $invitationClass */
        $invitationClass = MorphModelResolver::resolve('member_invitation');

        $invitation = new $invitationClass;
        $invitation->fill([
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'email' => $email,
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

    private function assertNoPendingInvitation(Model $subject, string $email): void
    {
        /** @var class-string<MembershipInvitation> $invitationClass */
        $invitationClass = MorphModelResolver::resolve('member_invitation');

        $exists = $invitationClass::query()
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->where('email', $email)
            ->where('status', InvitationStatus::Pending->value)
            ->where(function (Builder $query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'inviteEmail' => __('There is already a pending invitation for this email address.'),
            ]);
        }
    }
}
