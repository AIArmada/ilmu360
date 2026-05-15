<?php

namespace App\States\EventStatus;

use Filament\Support\Colors\Color;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

final class Pending extends EventStatus implements HasColor, HasIcon, HasLabel
{
    public static string $name = 'pending';

    public function getLabel(): string
    {
        return __('Pending Review');
    }

    public function getColor(): array
    {
        return Color::Amber;
    }

    public function getIcon(): string
    {
        return 'heroicon-o-clock';
    }
}
