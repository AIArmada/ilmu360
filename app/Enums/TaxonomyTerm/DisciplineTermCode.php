<?php

declare(strict_types=1);

namespace App\Enums\TaxonomyTerm;

enum DisciplineTermCode: string
{
    case Aqidah = 'aqidah';
    case Fiqh = 'fiqh';
    case IlmuHadith = 'hadith';
    case Tafsir = 'tafsir';
    case Sirah = 'sirah';
    case Akhlak = 'akhlak';
    case Tasawuf = 'tasawuf';
    case Ibadah = 'ibadah';
    case Tadabbur = 'tadabbur';
    case Tazkiyah = 'tazkiyah';
    case BahasaArab = 'bahasa-arab';

    public function label(): string
    {
        return match ($this) {
            self::Aqidah => 'Aqidah',
            self::Fiqh => 'Fiqh',
            self::IlmuHadith => 'Ilmu Hadith',
            self::Tafsir => 'Tafsir',
            self::Sirah => 'Sirah',
            self::Akhlak => 'Akhlak & Adab',
            self::Tasawuf => 'Tasawuf',
            self::Ibadah => 'Ibadah',
            self::Tadabbur => 'Tadabbur',
            self::Tazkiyah => 'Tazkiyah',
            self::BahasaArab => 'Bahasa Arab',
        };
    }
}
