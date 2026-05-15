<?php

namespace App\Enums;

enum EventChangeStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Retracted = 'retracted';

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::Published => __('Published'),
            self::Retracted => __('Retracted'),
        };
    }
}
