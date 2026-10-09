<?php

declare(strict_types=1);

namespace App\Enums\Rewards;

enum RewardAwardStatus: string
{
    case Held = 'held';
    case PendingReview = 'pending_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Fulfilled = 'fulfilled';
    case Reversed = 'reversed';
    case Corrected = 'corrected';

    /**
     * Canonical transition timestamp column. Each terminal transition stamps
     * its own dedicated column exactly once; columns are never rewritten.
     */
    public function transitionColumn(): string
    {
        return match ($this) {
            self::Held => 'held_at',
            self::PendingReview => 'pending_review_at',
            self::Approved => 'approved_at',
            self::Rejected => 'rejected_at',
            self::Fulfilled => 'fulfilled_at',
            self::Reversed => 'reversed_at',
            self::Corrected => 'corrected_at',
        };
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Held => [self::PendingReview, self::Approved, self::Rejected],
            self::PendingReview => [self::Held, self::Approved, self::Rejected],
            self::Approved => [self::Fulfilled, self::Reversed, self::Corrected],
            self::Rejected => [self::Approved],
            self::Fulfilled => [self::Reversed, self::Corrected],
            self::Reversed => [],
            self::Corrected => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this === self::Reversed || $this === self::Corrected;
    }
}
