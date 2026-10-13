<?php

declare(strict_types=1);

namespace App\Policies;

use AIArmada\Rewards\Models\RewardAward;
use AIArmada\Rewards\Models\RewardPayoutRequest;
use App\Models\User;

/**
 * Payout authorization: every money step (request, approve, submit,
 * confirm, reconcile, cancel) requires staff AND denies recipient-owned
 * operation. Members read only their own awards' payout status with the
 * payee snapshot redacted to the masked display value.
 */
class RewardPayoutRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, RewardPayoutRequest $request): bool
    {
        if ($user->hasAnyRole(['super_admin', 'admin'])) {
            return true;
        }

        return $this->isRecipient($user, $request);
    }

    public function request(User $user, ?RewardAward $award = null): bool
    {
        return $this->canOperate($user, $award?->recipient_type, $award?->recipient_id);
    }

    public function approve(User $user, RewardPayoutRequest $request): bool
    {
        return $this->canOperateOn($user, $request)
            && (string) $request->requested_by_id !== (string) $user->getKey();
    }

    public function submit(User $user, RewardPayoutRequest $request): bool
    {
        return $this->canOperateOn($user, $request);
    }

    public function confirm(User $user, RewardPayoutRequest $request): bool
    {
        return $this->canOperateOn($user, $request)
            && (string) $request->requested_by_id !== (string) $user->getKey();
    }

    public function reconcile(User $user, RewardPayoutRequest $request): bool
    {
        return $this->canOperateOn($user, $request);
    }

    public function cancel(User $user, RewardPayoutRequest $request): bool
    {
        return $this->canOperateOn($user, $request);
    }

    private function canOperateOn(User $user, RewardPayoutRequest $request): bool
    {
        $award = RewardAward::query()->whereKey((string) $request->award_id)->first();

        if (! $award instanceof RewardAward) {
            return false;
        }

        return $this->canOperate($user, (string) $award->recipient_type, (string) $award->recipient_id);
    }

    private function canOperate(User $user, mixed $recipientType, mixed $recipientId): bool
    {
        if (! $user->hasAnyRole(['super_admin', 'admin'])) {
            return false;
        }

        return ! ((string) $recipientType === 'user' && (string) $recipientId === (string) $user->getKey());
    }

    private function isRecipient(User $user, RewardPayoutRequest $request): bool
    {
        $award = RewardAward::query()->whereKey((string) $request->award_id)->first();

        return $award instanceof RewardAward
            && (string) $award->recipient_type === 'user'
            && (string) $award->recipient_id === (string) $user->getKey();
    }
}
