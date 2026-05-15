<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ContactCategory: string implements HasLabel
{
    case Email = 'email';
    case Phone = 'phone';
    case WhatsApp = 'whatsapp';

    public function getLabel(): string
    {
        return match ($this) {
            self::Email => __('Email'),
            self::Phone => __('Phone'),
            self::WhatsApp => __('WhatsApp'),
        };
    }
}
