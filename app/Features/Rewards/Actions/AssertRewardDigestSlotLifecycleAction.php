<?php

declare(strict_types=1);

namespace App\Features\Rewards\Actions;

use App\Features\Rewards\Models\RewardDigestSlot;
use LogicException;

/** Enforce frozen plan fields and one-way reward digest transitions. */
final readonly class AssertRewardDigestSlotLifecycleAction
{
    public function updating(RewardDigestSlot $slot): void
    {
        foreach (['recipient_type', 'recipient_id', 'local_day', 'plan_key', 'timezone', 'due_at'] as $field) {
            if ($slot->isDirty($field)) {
                throw new LogicException("Reward digest slot [{$field}] is frozen at plan time.");
            }
        }

        foreach (['sent_at', 'cancelled_at'] as $field) {
            if ($slot->getOriginal($field) !== null && $slot->isDirty($field)) {
                throw new LogicException("Reward digest slot [{$field}] is an immutable transition timestamp.");
            }
        }

        if ($slot->isDirty('status')) {
            $from = (string) $slot->getOriginal('status');
            $allowed = match ($from) {
                RewardDigestSlot::STATUS_PLANNED => [RewardDigestSlot::STATUS_SENDING, RewardDigestSlot::STATUS_CANCELLED],
                RewardDigestSlot::STATUS_SENDING => [RewardDigestSlot::STATUS_SENT, RewardDigestSlot::STATUS_CANCELLED, RewardDigestSlot::STATUS_PLANNED],
                default => [],
            };

            if (! in_array((string) $slot->status, $allowed, true)) {
                throw new LogicException('Invalid reward digest slot status transition.');
            }
        }
    }

    public function deleting(): never
    {
        throw new LogicException('Reward digest slots cannot be deleted; cancel them instead.');
    }
}
