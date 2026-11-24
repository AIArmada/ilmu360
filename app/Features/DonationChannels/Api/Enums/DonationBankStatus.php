<?php

declare(strict_types=1);

namespace App\Features\DonationChannels\Api\Enums;

use Filament\Support\Contracts\HasLabel;

enum DonationBankStatus: string implements HasLabel
{
    case Active = 'active';
    case Retired = 'retired';

    public function getLabel(): string
    {
        return match ($this) {
            self::Active => __('Active'),
            self::Retired => __('Retired'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Retired => 'gray',
        };
    }
}
