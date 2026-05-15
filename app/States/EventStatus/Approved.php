<?php

namespace App\States\EventStatus;

use Filament\Support\Colors\Color;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

final class Approved extends EventStatus implements HasColor, HasIcon, HasLabel
{
    public static string $name = 'approved';

    public function getLabel(): string
    {
        return __('Approved');
    }

    public function getColor(): array
    {
        return Color::Emerald;
    }

    public function getIcon(): string
    {
        return 'heroicon-o-check-circle';
    }
}
