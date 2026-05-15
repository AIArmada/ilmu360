<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ReferencePartType: string implements HasLabel
{
    case Jilid = 'jilid';
    case Bahagian = 'bahagian';
    case Part = 'part';
    case Volume = 'volume';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Jilid => __('Jilid'),
            self::Bahagian => __('Bahagian'),
            self::Part => __('Part'),
            self::Volume => __('Volume'),
            self::Other => __('Other'),
        };
    }
}
