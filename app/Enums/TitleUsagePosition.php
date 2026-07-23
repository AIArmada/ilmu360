<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum TitleUsagePosition: string implements HasLabel
{
    case BeforeName = 'before_name';
    case AfterName = 'after_name';

    public function getLabel(): string
    {
        return match ($this) {
            self::BeforeName => __('Before Name'),
            self::AfterName => __('After Name'),
        };
    }
}
