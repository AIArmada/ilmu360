<?php

declare(strict_types=1);

namespace App\Enums\Rewards;

enum RewardProgramStatus: string
{
    case Draft = 'draft';
    case Ready = 'ready';
    case Active = 'active';
    case Paused = 'paused';
    case Closed = 'closed';

    /**
     * Canonical transition timestamp column for each non-draft status.
     * Draft inception is the row's created_at (no separate draft_at column).
     */
    public function transitionColumn(): ?string
    {
        return match ($this) {
            self::Draft => null,
            self::Ready => 'ready_at',
            self::Active => 'active_at',
            self::Paused => 'paused_at',
            self::Closed => 'closed_at',
        };
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Ready, self::Closed],
            self::Ready => [self::Active, self::Closed],
            self::Active => [self::Paused, self::Closed],
            self::Paused => [self::Active, self::Closed],
            self::Closed => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this === self::Closed;
    }
}
