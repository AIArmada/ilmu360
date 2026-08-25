<?php

declare(strict_types=1);

namespace App\Support\Language;

final class MalaysiaLanguageCatalog
{
    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_keys(self::labels());
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            'ms' => 'Bahasa Melayu',
            'ar' => 'Bahasa Arab',
            'en' => 'Bahasa Inggeris',
            'id' => 'Bahasa Indonesia',
            'zh' => 'Bahasa Cina',
            'ta' => 'Bahasa Tamil',
            'jv' => 'Bahasa Jawa',
            'pa' => 'Bahasa Punjabi',
            'hi' => 'Bahasa Hindi',
            'ml' => 'Bahasa Malayalam',
            'te' => 'Bahasa Telugu',
            'bn' => 'Bahasa Bengali',
            'ne' => 'Bahasa Nepali',
            'th' => 'Bahasa Thai',
            'my' => 'Bahasa Myanmar',
            'vi' => 'Bahasa Vietnam',
            'tl' => 'Bahasa Tagalog',
            'ur' => 'Bahasa Urdu',
            'si' => 'Bahasa Sinhala',
            'km' => 'Bahasa Khmer',
            'gu' => 'Bahasa Gujarati',
            'kn' => 'Bahasa Kannada',
            'or' => 'Bahasa Odia',
            'sd' => 'Bahasa Sindhi',
            'fa' => 'Bahasa Parsi',
            'su' => 'Bahasa Sunda',
            'ja' => 'Bahasa Jepun',
            'ko' => 'Bahasa Korea',
        ];
    }
}
