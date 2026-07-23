<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum AffiliationType: string implements HasLabel
{
    case Member = 'member';
    case Employee = 'employee';
    case Advisor = 'advisor';
    case Partner = 'partner';
    case ResidentScholar = 'resident_scholar';

    public function getLabel(): string
    {
        return match ($this) {
            self::Member => __('Member'),
            self::Employee => __('Employee'),
            self::Advisor => __('Advisor'),
            self::Partner => __('Partner'),
            self::ResidentScholar => __('Resident Scholar'),
        };
    }
}
