<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ReferencePartType: string implements HasLabel
{
    case Jilid = 'jilid';
    case Bahagian = 'bahagian';

    public function getLabel(): string
    {
        return match ($this) {
            self::Jilid => __('Jilid'),
            self::Bahagian => __('Bahagian'),
        };
    }
}
