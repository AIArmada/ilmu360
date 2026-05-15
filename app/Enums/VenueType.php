<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum VenueType: string implements HasLabel
{
    case Dewan = 'dewan';
    case Auditorium = 'auditorium';
    case Stadium = 'stadium';
    case Perpustakaan = 'perpustakaan';
    case Padang = 'padang';
    case Hotel = 'hotel';

    public function getLabel(): string
    {
        return match ($this) {
            self::Dewan => __('Dewan'),
            self::Auditorium => __('Auditorium'),
            self::Stadium => __('Stadium'),
            self::Perpustakaan => __('Perpustakaan'),
            self::Padang => __('Padang'),
            self::Hotel => __('Hotel'),
        };
    }
}
