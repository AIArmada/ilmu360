<?php

declare(strict_types=1);

namespace App\Actions\Referrals;

use AIArmada\Referrals\Actions\AcceptReferralInvite;
use AIArmada\Referrals\Enums\ReferralInviteStatus;
use AIArmada\Referrals\Enums\ReferralProgramStatus;
use AIArmada\Referrals\Exceptions\ReferralInviteNotFoundException;
use AIArmada\Referrals\Models\ReferralInvite;
use AIArmada\Referrals\Support\ReferralProgramGuard;
use App\Models\User;
use Carbon\CarbonImmutable;

/** Accepts a referral only when its invitee lands and presents a verified matching contact. */
final readonly class ResolveReferralInviteLandingAction
{
    public function __construct(
        private AcceptReferralInvite $acceptReferralInvite,
        private ReferralProgramGuard $programGuard,
    ) {}

    /**
     * @return array{outcome: string, invite: ReferralInvite|null}
     */
    public function handle(string $token, User $user): array
    {
        try {
            $invite = $this->programGuard->inviteByToken($token);
        } catch (ReferralInviteNotFoundException) {
            return ['outcome' => 'unavailable', 'invite' => null];
        }

        $invite->loadMissing('program');
        $program = $invite->program;
        $now = CarbonImmutable::now('UTC');

        if ($invite->status === ReferralInviteStatus::Revoked) {
            return ['outcome' => 'revoked', 'invite' => $invite];
        }

        if ($invite->status === ReferralInviteStatus::Expired || $invite->isExpiredAt($now)) {
            return ['outcome' => 'expired', 'invite' => $invite];
        }

        if ($invite->status !== ReferralInviteStatus::Pending) {
            return ['outcome' => 'already_claimed', 'invite' => $invite];
        }

        if ($program === null
            || $program->status !== ReferralProgramStatus::Active
            || ! $program->isActiveAt($now)) {
            return ['outcome' => 'program_inactive', 'invite' => $invite];
        }

        if ($invite->referrer_type === $user->getMorphClass()
            && (string) $invite->referrer_id === (string) $user->getKey()) {
            return ['outcome' => 'self_invite', 'invite' => $invite];
        }

        $contacts = $this->verifiedContacts($user);

        if ($contacts === []) {
            return ['outcome' => 'no_verified_contact', 'invite' => $invite];
        }

        foreach ($contacts as $contact) {
            try {
                $acceptedInvite = $this->acceptReferralInvite->handle($token, $user, $contact);

                return ['outcome' => 'accepted', 'invite' => $acceptedInvite];
            } catch (ReferralInviteNotFoundException) {
                $freshInvite = $invite->fresh();

                if (! $freshInvite instanceof ReferralInvite) {
                    return ['outcome' => 'unavailable', 'invite' => null];
                }

                if ($freshInvite->status === ReferralInviteStatus::Expired || $freshInvite->isExpiredAt($now)) {
                    return ['outcome' => 'expired', 'invite' => $freshInvite];
                }

                if ($freshInvite->status === ReferralInviteStatus::Revoked) {
                    return ['outcome' => 'revoked', 'invite' => $freshInvite];
                }

                if ($freshInvite->status !== ReferralInviteStatus::Pending) {
                    return ['outcome' => 'already_claimed', 'invite' => $freshInvite];
                }
            }
        }

        return ['outcome' => 'contact_mismatch', 'invite' => $invite->fresh()];
    }

    /** @return list<string> */
    private function verifiedContacts(User $user): array
    {
        $contacts = [];

        if ($user->email_verified_at !== null && is_string($user->email) && trim($user->email) !== '') {
            $contacts[] = trim($user->email);
        }

        if ($user->phone_verified_at !== null && is_string($user->phone) && trim($user->phone) !== '') {
            $contacts[] = trim($user->phone);
        }

        return $contacts;
    }
}
