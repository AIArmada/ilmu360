<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PostNominal: string implements HasLabel
{
    case PhD = 'PhD';
    case MSc = 'MSc';
    case MA = 'MA';
    case BA = 'BA';
    case BSc = 'BSc';
    case Hons = 'HONS';
    case Lc = 'Lc.';
    case Dpl = 'Dpl.';

    public function getLabel(): string
    {
        return match ($this) {
            self::PhD => __('PhD'),
            self::MSc => __('MSc'),
            self::MA => __('MA'),
            self::BA => __('BA'),
            self::BSc => __('BSc'),
            self::Hons => __('HONS'),
            self::Lc => __('Lc.'),
            self::Dpl => __('Dpl.'),
        };
    }
}
