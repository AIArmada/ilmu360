<?php

declare(strict_types=1);

namespace App\Features\Membership\Actions;

use AIArmada\Membership\Channels\EmailInvitationChannel;
use AIArmada\Membership\Channels\InvitationChannelManager;
use AIArmada\Membership\Data\InvitationDeliveryData;
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
        private InvitationChannelManager $channels,
        private Dispatcher $notifications,
    ) {}

    public function handle(
        Model $subject,
        string $channel,
        string $recipient,
        string $role,
        Model $inviter,
        ?CarbonInterface $expiresAt = null,
    ): MembershipInvitation {
        if ($role === MemberRole::Owner->value) {
            throw new InvalidArgumentException('Ownership invitations are not allowed through the standard invitation flow.');
        }

        $memberRole = MemberRole::from($role);
        $channelDriver = $this->channels->channel($channel);
        $recipient = $channelDriver->normalize($recipient);

        if (mb_trim($recipient) === '' || mb_strlen($recipient) > 255) {
            throw new InvalidArgumentException('The normalized invitation recipient must contain 1 to 255 characters.');
        }

        $this->assertNoPendingInvitation($subject, $channel, $recipient);

        $rawToken = Str::random(64);
        $expiresAt ??= now()->addDays(
            (int) config('membership.invitations.default_expiry_days', 14)
        );

        $invitationClass = MorphModelResolver::resolve('member_invitation');

        $invitation = new $invitationClass;
        $invitation->fill([
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'channel' => $channel,
            'recipient' => $recipient,
            'role' => $memberRole->spatieRoleName(),
            'invited_by' => $inviter->getKey(),
        ]);
        $invitation->issue($rawToken, $expiresAt)->save();

        if ($channel !== EmailInvitationChannel::CHANNEL) {
            $delivery = new InvitationDeliveryData(
                invitationId: (string) $invitation->getKey(),
                channel: $channel,
                recipient: $recipient,
                subjectType: $subject->getMorphClass(),
                subjectId: $invitation->subject_id,
                role: $invitation->role,
                token: $rawToken,
                expiresAt: $invitation->expires_at,
            );

            $invitation->getConnection()->afterCommit(fn () => $channelDriver->deliver($delivery));

            return $invitation;
        }

        $acceptUrl = route('member-invitations.show', ['token' => $rawToken]);

        $inviterName = (string) ($inviter->getAttribute('name') ?? '');
        $subjectName = (string) ($subject->getAttribute('name') ?? $subject->getAttribute('title') ?? '');

        $notifiable = new AnonymousNotifiable;
        $notifiable->route('mail', $recipient);

        $this->notifications->send($notifiable, new MemberInvitationNotification(
            inviterName: $inviterName,
            subjectLabel: $subject->getMorphClass(),
            subjectName: $subjectName,
            roleLabel: $memberRole->label(),
            invitedEmail: $recipient,
            acceptUrl: $acceptUrl,
            expiresAt: $expiresAt,
        ));

        return $invitation;
    }

    private function assertNoPendingInvitation(Model $subject, string $channel, string $recipient): void
    {
        $invitationClass = MorphModelResolver::resolve('member_invitation');

        $exists = $invitationClass::query()
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->where('channel', $channel)
            ->where('recipient', $recipient)
            ->where('status', InvitationStatus::Pending->value)
            ->where(function (Builder $query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'inviteRecipient' => __('There is already a pending invitation for this recipient.'),
            ]);
        }
    }
}
