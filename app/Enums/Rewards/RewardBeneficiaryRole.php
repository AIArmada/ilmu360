<?php

declare(strict_types=1);

namespace App\Enums\Rewards;

enum RewardBeneficiaryRole: string
{
    case Recipient = 'recipient';
    case Participant = 'participant';
    case Purchaser = 'purchaser';
    case Staff = 'staff';
    case Sharer = 'sharer';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
