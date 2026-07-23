<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum AssignmentStatus: string implements HasLabel
{
    case Active = 'active';
    case Revoked = 'revoked';
    case Expired = 'expired';

    public function getLabel(): string
    {
        return match ($this) {
            self::Active => __('Active'),
            self::Revoked => __('Revoked'),
            self::Expired => __('Expired'),
        };
    }
}
