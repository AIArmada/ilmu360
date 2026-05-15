<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum InstitutionType: string implements HasLabel
{
    case Masjid = 'masjid';
    case Surau = 'surau';
    case Madrasah = 'madrasah';
    case Maahad = 'maahad';
    case Pondok = 'pondok';
    case Sekolah = 'sekolah';
    case Kolej = 'kolej';
    case Universiti = 'universiti';

    public function getLabel(): string
    {
        return match ($this) {
            self::Masjid => __('Masjid'),
            self::Surau => __('Surau'),
            self::Madrasah => __('Madrasah'),
            self::Maahad => __('Maahad'),
            self::Pondok => __('Pondok'),
            self::Sekolah => __('Sekolah'),
            self::Kolej => __('Kolej'),
            self::Universiti => __('Universiti'),
        };
    }
}
