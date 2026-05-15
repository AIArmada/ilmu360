<?php

namespace App\Support\Institutions;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class GeneratedPoskodInstitutionData
{
    private const string CSV_PATH = 'seeders/Generated_File_Final_Fixed_Poskod.csv';

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

    /**
     * @var array<string, string>|null
     */
    private static ?array $canonicalSlugsByRowNumber = null;

    public static function normalizeInstitutionName(string $name): string
    {
        $normalized = preg_replace('/^\s*\[\d+\]\s*/', '', trim($name)) ?? trim($name);

        if (preg_match('/^\s*\(ESTATE\)\s*(.+)$/i', $normalized, $matches) === 1) {
            $normalized = trim($matches[1]).' (ESTATE)';
        }

        return self::normalizeSentenceCase($normalized);
    }

    public static function normalizeAddressLine(string $address): string
    {
        return self::normalizeSentenceCase($address);
    }

    public static function canonicalSlug(string $name, string $rowNumber): string
    {
        $baseSlug = Str::slug(self::normalizeInstitutionName($name));

        if ($baseSlug === '') {
            $baseSlug = 'institusi';
        }

        return $baseSlug.'-'.trim($rowNumber);
    }

    /**
     * @return list<string>
     */
    public static function allCanonicalSlugs(): array
    {
        return array_values(self::canonicalSlugsByRowNumber());
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

    /**
     * @return array<string, string>
     */
    private static function canonicalSlugsByRowNumber(): array
    {
        if (is_array(self::$canonicalSlugsByRowNumber)) {
            return self::$canonicalSlugsByRowNumber;
        }

        $csvPath = database_path(self::CSV_PATH);

        if (! File::exists($csvPath)) {
            return self::$canonicalSlugsByRowNumber = [];
        }

        $handle = fopen($csvPath, 'r');

        if ($handle === false) {
            return self::$canonicalSlugsByRowNumber = [];
        }

        $header = fgetcsv($handle, escape: '\\');

        if (! is_array($header)) {
            fclose($handle);

            return self::$canonicalSlugsByRowNumber = [];
        }

        $normalizedHeader = array_map(
            static fn (string $value): string => ltrim($value, "\xEF\xBB\xBF"),
            $header,
        );

        $slugsByRowNumber = [];

        while (($row = fgetcsv($handle, escape: '\\')) !== false) {
            $mapped = array_combine($normalizedHeader, array_pad($row, count($normalizedHeader), ''));
            $rowNumber = trim((string) ($mapped['No.'] ?? ''));
            $name = (string) ($mapped['Nama'] ?? '');

            if ($rowNumber === '' || $name === '') {
                continue;
            }

            $slugsByRowNumber[$rowNumber] = self::canonicalSlug($name, $rowNumber);
        }

        fclose($handle);

        return self::$canonicalSlugsByRowNumber = $slugsByRowNumber;
    }
}
