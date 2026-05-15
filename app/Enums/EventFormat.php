<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum EventFormat: string implements HasLabel
{
    case Physical = 'physical';
    case Online = 'online';
    case Hybrid = 'hybrid';

    public function getLabel(): string
    {
        return match ($this) {
            self::Physical => __('Physical'),
            self::Online => __('Online'),
            self::Hybrid => __('Hybrid'),
        };
    }

    public function label(): string
    {
        return $this->getLabel();
    }
}
