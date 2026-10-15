<?php

declare(strict_types=1);

namespace App\Actions\Referrals;

use AIArmada\Referrals\Actions\ReverseReferralReward;
use AIArmada\Referrals\Enums\ReferralRewardStatus;
use AIArmada\Referrals\Models\ReferralInvite;
use App\Enums\DawahShareOutcomeType;
use App\Support\Rewards\ReferralRewardSourceFactReader;
use App\Support\ShareTracking\ShareOutcomeBusinessKey;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Reverses both referral rails after their qualifying share source is invalidated. */
final readonly class ReverseReferralRewardsForShareOutcomeAction
{
    public function __construct(
        private ReverseReferralReward $reverseReferralReward,
        private ReferralRewardSourceFactReader $sourceFactReader,
    ) {}

    public function handle(string $businessKey, string $reason): void
    {
        if (! $this->isSupportedBusinessKey($businessKey)) {
            return;
        }

        $invite = ReferralInvite::query()
            ->where('qualification_business_key', $businessKey)
            ->with('rewards')
            ->first();

        if (! $invite instanceof ReferralInvite) {
            return;
        }

        foreach ($invite->rewards as $reward) {
            if ($reward->status !== ReferralRewardStatus::Fulfilled) {
                continue;
            }

            $source = $this->sourceFactReader->read('referrals:qualified:'.$invite->getKey().':'.$reward->side->value);

            if ($source !== null && $source->isValid && ! $source->isCancelled) {
                continue;
            }

            try {
                $this->reverseReferralReward->handle($reward, $reason);
            } catch (Throwable $exception) {
                Log::warning('Referral reward reversal failed after a source correction.', [
                    'invite_id' => (string) $invite->getKey(),
                    'reward_id' => (string) $reward->getKey(),
                    'business_key' => $businessKey,
                    'reason' => $reason,
                    'exception' => $exception,
                ]);
            }
        }
    }

    private function isSupportedBusinessKey(string $businessKey): bool
    {
        foreach ([DawahShareOutcomeType::EventRegistration, DawahShareOutcomeType::EventCheckin] as $outcome) {
            try {
                ShareOutcomeBusinessKey::parse($businessKey, $outcome);

                return true;
            } catch (\InvalidArgumentException) {
                continue;
            }
        }

        return false;
    }
}
