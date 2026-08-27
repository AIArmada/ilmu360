<?php

declare(strict_types=1);

namespace App\Enums\TaxonomyTerm;

enum SourceTermCode: string
{
    case AlQuran = 'al-quran';
    case Hadith = 'hadith';
    case Fatwa = 'fatwa';
    case Kajian = 'kajian';
    case Ulama = 'ulama';

    public function label(): string
    {
        return match ($this) {
            self::AlQuran => 'Al-Quran',
            self::Hadith => 'Hadith',
            self::Fatwa => 'Fatwa',
            self::Kajian => 'Kajian',
            self::Ulama => 'Ulama',
        };
    }
}
