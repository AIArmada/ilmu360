<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PersonNameType: string implements HasLabel
{
    case Legal = 'legal';
    case Display = 'display';
    case Birth = 'birth';
    case Religious = 'religious';
    case Professional = 'professional';
    case Previous = 'previous';

    public function getLabel(): string
    {
        return match ($this) {
            self::Legal => __('Legal'),
            self::Display => __('Display'),
            self::Birth => __('Birth'),
            self::Religious => __('Religious'),
            self::Professional => __('Professional'),
            self::Previous => __('Previous'),
        };
    }
}
