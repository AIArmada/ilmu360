<?php

declare(strict_types=1);

namespace App\Enums\Rewards;

enum RewardBenefitGrantStatus: string
{
    case Reserved = 'reserved';
    case Issued = 'issued';
    case Consumed = 'consumed';
    case Revoked = 'revoked';
    case Expired = 'expired';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Reserved => [self::Issued, self::Revoked, self::Expired],
            self::Issued => [self::Consumed, self::Revoked, self::Expired],
            self::Consumed, self::Revoked, self::Expired => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }
}
