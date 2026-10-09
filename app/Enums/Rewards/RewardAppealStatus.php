<?php

declare(strict_types=1);

namespace App\Enums\Rewards;

enum RewardAppealStatus: string
{
    case Open = 'open';
    case InReview = 'in_review';
    case Upheld = 'upheld';
    case Overturned = 'overturned';
    case Closed = 'closed';

    public function transitionColumn(): string
    {
        return match ($this) {
            self::Open => 'opened_at',
            self::InReview => 'in_review_at',
            self::Upheld => 'upheld_at',
            self::Overturned => 'overturned_at',
            self::Closed => 'closed_at',
        };
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Open => [self::InReview, self::Closed],
            self::InReview => [self::Upheld, self::Overturned, self::Closed],
            self::Upheld => [self::Closed],
            self::Overturned => [self::Closed],
            self::Closed => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }
}
