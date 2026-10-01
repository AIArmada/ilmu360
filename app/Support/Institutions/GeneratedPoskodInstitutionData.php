<?php

namespace App\Support\Institutions;

class GeneratedPoskodInstitutionData
{
    /**
     * @var array<string, string>
     */
    private const array TITLE_CASE_EXCEPTIONS = [
        'ATM' => 'ATM',
        'ESTATE' => 'ESTATE',
        'FELDA' => 'FELDA',
        'IIUM' => 'IIUM',
        'IKIM' => 'IKIM',
        'IPD' => 'IPD',
        'IPG' => 'IPG',
        'IPT' => 'IPT',
        'JHEAINS' => 'JHEAINS',
        'JKR' => 'JKR',
        'KEMAS' => 'KEMAS',
        'KKM' => 'KKM',
        'MARA' => 'MARA',
        'PDRM' => 'PDRM',
        'RISDA' => 'RISDA',
        'TLDM' => 'TLDM',
        'UIAM' => 'UIAM',
        'UITM' => 'UiTM',
        'UKM' => 'UKM',
        'UM' => 'UM',
        'UMK' => 'UMK',
        'UMP' => 'UMP',
        'UNIMAS' => 'UNIMAS',
        'UPM' => 'UPM',
        'USIM' => 'USIM',
        'USM' => 'USM',
        'UTHM' => 'UTHM',
        'UTM' => 'UTM',
        'UUM' => 'UUM',
        'UTC' => 'UTC',
    ];

    /**
     * @var list<string>
     */
    private const array ROMAN_NUMERALS = ['II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII', 'XIII', 'XIV', 'XV'];

    public static function normalizeAddressLine(string $address): string
    {
        return self::normalizeSentenceCase($address);
    }

    private static function normalizeSentenceCase(string $value): string
    {
        $normalized = trim(preg_replace('/\s+/', ' ', trim($value)) ?? trim($value));

        if ($normalized === '') {
            return '';
        }

        $titleCased = mb_convert_case(mb_strtolower($normalized, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');

        foreach (self::TITLE_CASE_EXCEPTIONS as $token => $replacement) {
            $titleToken = mb_convert_case(mb_strtolower($token, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
            $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($titleToken, '/').'(?![\p{L}\p{N}])/u';
            $titleCased = preg_replace($pattern, $replacement, $titleCased) ?? $titleCased;
        }

        foreach (self::ROMAN_NUMERALS as $numeral) {
            $titleNumeral = mb_convert_case(mb_strtolower($numeral, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
            $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($titleNumeral, '/').'(?![\p{L}\p{N}])/u';
            $titleCased = preg_replace($pattern, $numeral, $titleCased) ?? $titleCased;
        }

        return $titleCased;
    }
}
