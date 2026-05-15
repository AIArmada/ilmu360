<?php

namespace App\Actions\Membership;

use App\Enums\MemberSubjectType;
use App\Models\Event;
use App\Models\Institution;
use App\Models\MemberInvitation;
use App\Models\Reference;
use App\Models\Speaker;
use App\Models\User;
use App\Notifications\Membership\MemberInvitationNotification;
use App\Support\Authz\MemberRoleCatalog;
use App\Support\Authz\ScopedMemberRoleSeeder;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class InviteSubjectMember
{
    use AsAction;

    public function __construct(
        private MemberRoleCatalog $memberRoleCatalog,
        private ScopedMemberRoleSeeder $scopedMemberRoleSeeder,
    ) {}

    public function handle(
        Institution|Speaker|Event|Reference $subject,
        string $email,
        string $roleSlug,
        User $inviter,
        ?CarbonInterface $expiresAt = null,
    ): MemberInvitation {
        $normalizedEmail = mb_strtolower(trim($email));

        if ($normalizedEmail === '') {
            throw new InvalidArgumentException('Invitation email is required.');
        }

        $subjectType = MemberSubjectType::forSubject($subject);
        $this->scopedMemberRoleSeeder->ensure($subjectType);

        if (! $this->memberRoleCatalog->isInvitableRole($subjectType, $roleSlug)) {
            throw new InvalidArgumentException("Role [{$roleSlug}] cannot be invited for {$subjectType->value} members.");
        }

        $this->memberRoleCatalog->resolveRoleId($subjectType, $roleSlug);

        $invitation = MemberInvitation::create([
            'subject_type' => $subjectType,
            'subject_id' => $subject->getKey(),
            'email' => $normalizedEmail,
            'role_slug' => $roleSlug,
            'token' => Str::random(64),
            'invited_by' => $inviter->getKey(),
            'expires_at' => $expiresAt,
        ]);

        Notification::route('mail', $normalizedEmail)
            ->notify(new MemberInvitationNotification(
                inviterName: $inviter->name,
                subjectLabel: $this->subjectLabel($subjectType),
                subjectName: $this->subjectName($subject),
                roleLabel: $this->memberRoleCatalog->roleLabel($subjectType, $roleSlug),
                invitedEmail: $normalizedEmail,
                acceptUrl: route('member-invitations.show', ['token' => $invitation->token]),
                expiresAt: $expiresAt,
            ));

        return $invitation;
    }

    private function subjectLabel(MemberSubjectType $subjectType): string
    {
        return match ($subjectType) {
            MemberSubjectType::Institution => __('Institution'),
            MemberSubjectType::Speaker => __('Speaker'),
            MemberSubjectType::Event => __('Event'),
            MemberSubjectType::Reference => __('Reference'),
        };
    }

    private function subjectName(Institution|Speaker|Event|Reference $subject): string
    {
        return match (true) {
            $subject instanceof Event => $subject->title,
            $subject instanceof Reference => $subject->title,
            default => $subject->name,
        };
    }
}
