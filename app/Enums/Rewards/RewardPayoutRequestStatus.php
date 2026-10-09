<?php

declare(strict_types=1);

namespace App\Enums\Rewards;

enum RewardPayoutRequestStatus: string
{
    case Requested = 'requested';
    case Approved = 'approved';
    case Submitted = 'submitted';
    case Confirmed = 'confirmed';
    case Failed = 'failed';
    case Unknown = 'unknown';
    case Cancelled = 'cancelled';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Requested => [self::Approved, self::Cancelled],
            self::Approved => [self::Submitted, self::Failed, self::Unknown, self::Cancelled],
            self::Submitted => [self::Confirmed, self::Failed, self::Unknown],
            self::Unknown => [self::Submitted, self::Confirmed, self::Failed, self::Cancelled],
            // A confirmed provider failure may requeue under the SAME frozen
            // operation identity with a fresh budget reservation; ambiguity
            // never requeues.
            self::Failed => [self::Requested],
            self::Confirmed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }
}
