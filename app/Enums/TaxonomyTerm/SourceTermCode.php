<?php

declare(strict_types=1);

namespace App\Enums\TaxonomyTerm;

enum SourceTermCode: string
{
    case AlQuran = 'al-quran';
    case Hadith = 'hadith';
    case Ulama = 'ulama';
    case Fatwa = 'fatwa';
    case Qias = 'qias';
    case Kajian = 'kajian';

    public function label(): string
    {
        return match ($this) {
            self::AlQuran => 'Al-Quran',
            self::Hadith => 'Al-Sunnah (Hadith)',
            self::Ulama => "Ijma' Ulama",
            self::Fatwa => 'Fatwa',
            self::Qias => 'Qias',
            self::Kajian => 'Kajian',
        };
    }
}
