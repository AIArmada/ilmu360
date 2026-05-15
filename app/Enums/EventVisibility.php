<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum EventVisibility: string implements HasColor, HasIcon, HasLabel
{
    case Public = 'public';
    case Unlisted = 'unlisted';
    case Private = 'private';

    public function getLabel(): string
    {
        return match ($this) {
            self::Public => __('Awam'),
            self::Unlisted => __('Tidak Tersenarai'),
            self::Private => __('Peribadi'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Public => 'success',
            self::Unlisted => 'warning',
            self::Private => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Public => 'heroicon-m-globe-alt',
            self::Unlisted => 'heroicon-m-eye-slash',
            self::Private => 'heroicon-m-lock-closed',
        };
    }
}
