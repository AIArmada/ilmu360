<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum InstitutionVenueRole: string implements HasLabel
{
    case Operated = 'operated';
    case Preferred = 'preferred';

    public function getLabel(): string
    {
        return match ($this) {
            self::Operated => __('Operated'),
            self::Preferred => __('Preferred'),
        };
    }
}
