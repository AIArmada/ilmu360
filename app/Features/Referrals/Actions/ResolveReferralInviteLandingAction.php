<?php

declare(strict_types=1);

namespace App\Features\Referrals\Actions;

use AIArmada\Referrals\Actions\AcceptReferralInvite;
use AIArmada\Referrals\Enums\ReferralInviteStatus;
use AIArmada\Referrals\Enums\ReferralProgramStatus;
use AIArmada\Referrals\Exceptions\ReferralInviteNotFoundException;
use AIArmada\Referrals\Models\ReferralInvite;
use AIArmada\Referrals\Support\ReferralProgramGuard;
use App\Features\Referrals\Api\ReferralInvitations;
use App\Platform\Models\MorphModelResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/** Accepts a referral only when its invitee lands and presents a verified matching contact. */
final readonly class ResolveReferralInviteLandingAction implements ReferralInvitations
{
    public function __construct(
        private AcceptReferralInvite $acceptReferralInvite,
        private ReferralProgramGuard $programGuard,
    ) {}

    public function land(string $token, string $actorId): array
    {
        $user = MorphModelResolver::resolve('user')::query()->findOrFail($actorId);
        $result = $this->handle($token, $user);
        $invite = $result['invite'];

        return [
            'outcome' => $result['outcome'],
            'invite_id' => $invite instanceof ReferralInvite ? (string) $invite->getKey() : null,
            'program_id' => $invite instanceof ReferralInvite ? (string) $invite->program_id : null,
        ];
    }

    /** @return array{outcome: string, invite: ReferralInvite|null} */
    private function handle(string $token, Model $user): array
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
    private function verifiedContacts(Model $user): array
    {
        $contacts = [];

        if ($user->getAttribute('email_verified_at') !== null && is_string($user->getAttribute('email')) && trim($user->getAttribute('email')) !== '') {
            $contacts[] = trim($user->getAttribute('email'));
        }

        if ($user->getAttribute('phone_verified_at') !== null && is_string($user->getAttribute('phone')) && trim($user->getAttribute('phone')) !== '') {
            $contacts[] = trim($user->getAttribute('phone'));
        }

        return $contacts;
    }
}
