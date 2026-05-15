<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ContactType: string implements HasLabel
{
    case Main = 'main';
    case Work = 'work';
    case Personal = 'personal';

    public function getLabel(): string
    {
        return match ($this) {
            self::Main => __('Main'),
            self::Work => __('Work'),
            self::Personal => __('Personal'),
        };
    }
}
