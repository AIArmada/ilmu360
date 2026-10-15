<?php

declare(strict_types=1);

namespace App\Actions\Referrals;

use AIArmada\Referrals\Actions\FulfillReferralReward;
use AIArmada\Referrals\Actions\QualifyReferral;
use AIArmada\Referrals\Enums\ReferralInviteStatus;
use AIArmada\Referrals\Enums\ReferralRewardStatus;
use AIArmada\Referrals\Models\ReferralInvite;
use AIArmada\Rewards\Enums\RewardVerificationLevel;
use AIArmada\Rewards\Support\RewardSourceFact;
use App\Enums\DawahShareOutcomeType;
use App\Models\User;
use App\Support\Rewards\ActivityRewardSourceFactReader;
use App\Support\ShareTracking\ActivityRewardBusinessKey;
use App\Support\ShareTracking\ShareOutcomeBusinessKey;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Qualifies accepted invitees from the authoritative registration/check-in outcome path. */
final readonly class QualifyReferralFromShareOutcomeAction
{
    private const string QUALIFIER_OUTCOME = 'first_accepted_registration_or_staff_verified_checkin';

    public function __construct(
        private ActivityRewardSourceFactReader $activityReader,
        private QualifyReferral $qualifyReferral,
        private FulfillReferralReward $fulfillReferralReward,
    ) {}

    public function handle(string $businessKey): void
    {
        $source = $this->readSupportedSource($businessKey);

        if ($source === null
            || ! $source->isValid
            || $source->isCancelled
            || ! $source->isClaimedIdentity
            || ! is_string($source->actorId)
            || ! $this->hasQualificationVerification($source->outcome, $source->verification)) {
            return;
        }

        $invitee = User::query()->whereKey($source->actorId)->first();

        if (! $invitee instanceof User) {
            return;
        }

        $invites = ReferralInvite::query()
            ->where('status', ReferralInviteStatus::Accepted->value)
            ->where('accepted_by_type', $invitee->getMorphClass())
            ->where('accepted_by_id', (string) $invitee->getKey())
            ->whereHas('program', static function ($query): void {
                $query->whereNull('owner_type')
                    ->whereNull('owner_id')
                    ->where('status', 'active');
            })
            ->with('program')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        foreach ($invites as $invite) {
            $program = $invite->program;

            if ($program === null
                || $program->qualifier_outcome !== self::QUALIFIER_OUTCOME
                || ! $program->isActiveAt($source->occurredAt)) {
                continue;
            }

            try {
                $qualification = $this->qualifyReferral->handle(
                    $invite,
                    $businessKey,
                    $source->occurredAt,
                );
            } catch (Throwable $exception) {
                Log::warning('Referral qualification was isolated from the committed share outcome.', [
                    'invite_id' => (string) $invite->getKey(),
                    'business_key' => $businessKey,
                    'exception' => $exception,
                ]);

                continue;
            }

            foreach ($qualification->rewards as $reward) {
                try {
                    $fulfilledReward = $this->fulfillReferralReward->handle($reward);

                    if ($fulfilledReward->status !== ReferralRewardStatus::Fulfilled) {
                        Log::warning('Referral reward fulfillment did not complete after qualification.', [
                            'invite_id' => (string) $invite->getKey(),
                            'reward_id' => (string) $fulfilledReward->getKey(),
                            'status' => $fulfilledReward->status->value,
                            'failure' => data_get($fulfilledReward->metadata, 'fulfillment_failure'),
                            'business_key' => $businessKey,
                        ]);
                    }
                } catch (Throwable $exception) {
                    Log::warning('Referral reward fulfilment failed after qualification.', [
                        'invite_id' => (string) $invite->getKey(),
                        'reward_id' => (string) $reward->getKey(),
                        'business_key' => $businessKey,
                        'exception' => $exception,
                    ]);
                }
            }
        }
    }

    private function readSupportedSource(string $businessKey): ?RewardSourceFact
    {
        foreach ([DawahShareOutcomeType::EventRegistration, DawahShareOutcomeType::EventCheckin] as $outcome) {
            try {
                $parsed = ShareOutcomeBusinessKey::parse($businessKey, $outcome);
            } catch (\InvalidArgumentException) {
                continue;
            }

            $activityKey = ActivityRewardBusinessKey::for($outcome, $parsed['source_transition_id']);

            return $this->activityReader->read($activityKey);
        }

        return null;
    }

    private function hasQualificationVerification(string $outcome, RewardVerificationLevel $verification): bool
    {
        return match ($outcome) {
            DawahShareOutcomeType::EventRegistration->value => $verification === RewardVerificationLevel::Accepted,
            DawahShareOutcomeType::EventCheckin->value => $verification === RewardVerificationLevel::StaffVerified,
            default => false,
        };
    }
}
