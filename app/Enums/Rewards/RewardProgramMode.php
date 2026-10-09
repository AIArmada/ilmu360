<?php

declare(strict_types=1);

namespace App\Enums\Rewards;

enum RewardProgramMode: string
{
    case Recognition = 'recognition';
    case Points = 'points';
    case Badges = 'badges';
    case Cash = 'cash';
    case Benefit = 'benefit';
    case Combined = 'combined';

    /**
     * Components this mode may configure. Combined is the explicit
     * multi-component mode; single modes configure exactly their own type.
     *
     * @return list<RewardComponent>
     */
    public function allowedComponents(): array
    {
        return match ($this) {
            self::Recognition => [RewardComponent::Recognition],
            self::Points => [RewardComponent::Points],
            self::Badges => [RewardComponent::Badge],
            self::Cash => [RewardComponent::Cash],
            self::Benefit => [RewardComponent::Benefit],
            self::Combined => RewardComponent::cases(),
        };
    }

    public function involvesMoneyOrBenefitFulfillment(): bool
    {
        return match ($this) {
            self::Cash, self::Benefit, self::Combined => true,
            default => false,
        };
    }
}
