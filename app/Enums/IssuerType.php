<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum IssuerType: string implements HasLabel
{
    case Government = 'government';
    case Royal = 'royal';
    case ReligiousBody = 'religious_body';
    case University = 'university';
    case ProfessionalBoard = 'professional_board';
    case Organization = 'organization';

    public function getLabel(): string
    {
        return match ($this) {
            self::Government => __('Government'),
            self::Royal => __('Royal'),
            self::ReligiousBody => __('Religious Body'),
            self::University => __('University'),
            self::ProfessionalBoard => __('Professional Board'),
            self::Organization => __('Organization'),
        };
    }
}
