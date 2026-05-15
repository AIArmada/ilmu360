<?php

namespace App\States\EventStatus;

use Filament\Support\Colors\Color;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

final class NeedsChanges extends EventStatus implements HasColor, HasDescription, HasIcon, HasLabel
{
    public static string $name = 'needs_changes';

    public function getLabel(): string
    {
        return __('Needs Changes');
    }

    public function getColor(): array
    {
        return Color::Orange;
    }

    public function getIcon(): string
    {
        return 'heroicon-o-exclamation-triangle';
    }

    public function getDescription(): string
    {
        return __('The event requires modifications before approval.');
    }
}
