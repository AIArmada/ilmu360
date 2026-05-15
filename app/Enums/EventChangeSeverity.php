<?php

namespace App\Enums;

enum EventChangeSeverity: string
{
    case Info = 'info';
    case High = 'high';
    case Urgent = 'urgent';

    public function label(): string
    {
        return match ($this) {
            self::Info => __('Info'),
            self::High => __('High'),
            self::Urgent => __('Urgent'),
        };
    }
}
