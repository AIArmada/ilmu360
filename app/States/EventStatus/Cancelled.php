<?php

namespace App\States\EventStatus;

use Filament\Support\Colors\Color;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

final class Cancelled extends EventStatus implements HasColor, HasIcon, HasLabel
{
    public static string $name = 'cancelled';

    public function getLabel(): string
    {
        return __('Cancelled');
    }

    public function getColor(): array
    {
        return Color::Red;
    }

    public function getIcon(): string
    {
        return 'heroicon-o-x-circle';
    }
}
