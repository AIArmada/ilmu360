<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum EventGenderRestriction: string implements HasColor, HasIcon, HasLabel
{
    case All = 'all';
    case MenOnly = 'men_only';
    case WomenOnly = 'women_only';

    public function getLabel(): string
    {
        return match ($this) {
            self::All => __('Semua (Lelaki & Wanita)'),
            self::MenOnly => __('Lelaki Sahaja'),
            self::WomenOnly => __('Wanita Sahaja'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::All => 'success',
            self::MenOnly => 'info',
            self::WomenOnly => 'pink',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::All => 'heroicon-o-user-group',
            self::MenOnly => 'heroicon-o-user',
            self::WomenOnly => 'heroicon-o-user',
        };
    }
}
