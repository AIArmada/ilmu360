<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum EventAgeGroup: string implements HasColor, HasIcon, HasLabel
{
    case AllAges = 'all_ages';
    case Adults = 'adults';
    case Youth = 'youth';
    case Children = 'children';
    case Seniors = 'warga_emas';

    public function getLabel(): string
    {
        return match ($this) {
            self::AllAges => __('Semua Peringkat Umur'),
            self::Adults => __('Dewasa'),
            self::Youth => __('Remaja / Belia'),
            self::Children => __('Kanak-kanak'),
            self::Seniors => __('Warga Emas'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::AllAges => 'success',
            self::Adults => 'info',
            self::Youth => 'warning',
            self::Children => 'pink',
            self::Seniors => 'amber',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::AllAges => 'heroicon-o-users',
            self::Adults => 'heroicon-o-user',
            self::Youth => 'heroicon-o-academic-cap',
            self::Children => 'heroicon-o-face-smile',
            self::Seniors => 'heroicon-o-user-group',
        };
    }
}
