<?php

/**
 * Codepoint coverage of the ACTUAL vendored TTFs, parsed from their cmap
 * tables without any font library. Render-ok alone cannot prove coverage
 * (a missing glyph still shapes as .notdef), so these assertions pin the
 * manifest's Jawi/script claims to the shipped binaries. Negative cases
 * prove the parser is not vacuous.
 */
if (! function_exists('shareCardFontsDir')) {
    function shareCardFontsDir(): string
    {
        return dirname(__DIR__, 3).'/public/fonts/share-cards';
    }
}

if (! function_exists('shareCardFontHasGlyph')) {
    function shareCardFontHasGlyph(string $path, int $codepoint): bool
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        try {
            $readAt = static function (int $offset, int $length) use ($handle): ?string {
                if (fseek($handle, $offset) !== 0) {
                    return null;
                }

                $bytes = fread($handle, $length);

                return is_string($bytes) && strlen($bytes) === $length ? $bytes : null;
            };

            $header = $readAt(0, 12);

            if ($header === null) {
                return false;
            }

            $numTables = (ord($header[4]) << 8) | ord($header[5]);
            $cmapOffset = null;
            $cmapLength = null;

            for ($i = 0; $i < $numTables; $i++) {
                $record = $readAt(12 + $i * 16, 16);

                if ($record === null) {
                    return false;
                }

                if (substr($record, 0, 4) === 'cmap') {
                    $cmapOffset = (ord($record[8]) << 24) | (ord($record[9]) << 16) | (ord($record[10]) << 8) | ord($record[11]);
                    $cmapLength = (ord($record[12]) << 24) | (ord($record[13]) << 16) | (ord($record[14]) << 8) | ord($record[15]);

                    break;
                }
            }

            if ($cmapOffset === null || $cmapLength === null || $cmapLength < 4) {
                return false;
            }

            $cmap = $readAt($cmapOffset, $cmapLength);

            if ($cmap === null) {
                return false;
            }

            $u16 = static function (string $bytes, int $offset): ?int {
                if ($offset < 0 || $offset + 2 > strlen($bytes)) {
                    return null;
                }

                return (ord($bytes[$offset]) << 8) | ord($bytes[$offset + 1]);
            };

            $u32 = static function (string $bytes, int $offset) use ($u16): ?int {
                $high = $u16($bytes, $offset);
                $low = $u16($bytes, $offset + 2);

                return $high === null || $low === null ? null : ($high << 16) | $low;
            };

            $numSubtables = $u16($cmap, 2);

            if ($numSubtables === null) {
                return false;
            }

            $candidates = [];

            for ($i = 0; $i < $numSubtables; $i++) {
                $platform = $u16($cmap, 4 + $i * 8);
                $offset = $u32($cmap, 4 + $i * 8 + 4);

                if ($platform === null || $offset === null) {
                    continue;
                }

                if ($platform !== 0 && $platform !== 3) {
                    continue;
                }

                $format = $u16($cmap, $offset);

                if ($format === 4 || $format === 6 || $format === 12) {
                    $candidates[$format] = $offset;
                }
            }

            foreach ([12, 4, 6] as $format) {
                if (! isset($candidates[$format])) {
                    continue;
                }

                return shareCardCmapLookup($cmap, $candidates[$format], $format, $codepoint, $u16, $u32);
            }

            return false;
        } finally {
            fclose($handle);
        }
    }
}

if (! function_exists('shareCardCmapLookup')) {
    function shareCardCmapLookup(string $cmap, int $offset, int $format, int $codepoint, callable $u16, callable $u32): bool
    {
        if ($format === 12) {
            $nGroups = $u32($cmap, $offset + 12);

            if ($nGroups === null) {
                return false;
            }

            for ($g = 0; $g < $nGroups; $g++) {
                $start = $u32($cmap, $offset + 16 + $g * 12);
                $end = $u32($cmap, $offset + 16 + $g * 12 + 4);
                $glyph = $u32($cmap, $offset + 16 + $g * 12 + 8);

                if ($start === null || $end === null || $glyph === null) {
                    return false;
                }

                if ($codepoint >= $start && $codepoint <= $end) {
                    return ($glyph + ($codepoint - $start)) !== 0;
                }
            }

            return false;
        }

        if ($format === 6) {
            $first = $u16($cmap, $offset + 6);
            $count = $u16($cmap, $offset + 8);

            if ($first === null || $count === null || $codepoint < $first || $codepoint >= $first + $count) {
                return false;
            }

            $glyph = $u16($cmap, $offset + 10 + ($codepoint - $first) * 2);

            return $glyph !== null && $glyph !== 0;
        }

        $segCountX2 = $u16($cmap, $offset + 6);

        if ($segCountX2 === null) {
            return false;
        }

        $segCount = (int) ($segCountX2 / 2);
        $endsAt = $offset + 14;
        $startsAt = $endsAt + $segCount * 2 + 2;
        $deltasAt = $startsAt + $segCount * 2;
        $rangeOffsetsAt = $deltasAt + $segCount * 2;

        for ($i = 0; $i < $segCount; $i++) {
            $end = $u16($cmap, $endsAt + $i * 2);

            if ($end === null || $end < $codepoint) {
                continue;
            }

            $start = $u16($cmap, $startsAt + $i * 2);
            $delta = $u16($cmap, $deltasAt + $i * 2);
            $rangeOffset = $u16($cmap, $rangeOffsetsAt + $i * 2);

            if ($start === null || $delta === null || $rangeOffset === null || $start > $codepoint) {
                return false;
            }

            $signedDelta = $delta >= 0x8000 ? $delta - 0x10000 : $delta;

            if ($rangeOffset === 0) {
                return (($codepoint + $signedDelta) & 0xFFFF) !== 0;
            }

            $glyph = $u16($cmap, $rangeOffsetsAt + $i * 2 + $rangeOffset + ($codepoint - $start) * 2);

            return $glyph !== null && (($glyph + $signedDelta) & 0xFFFF) !== 0;
        }

        return false;
    }
}

if (! function_exists('shareCardMatrixTitles')) {
    function shareCardMatrixTitles(): array
    {
        return [
            'ms' => 'Kuliah Maghrib: Tafsir Surah Al-Kahfi',
            'en' => 'Weekly Halaqah: Stories of the Prophets',
            'id' => 'Pengajian Rutin: Kajian Kitab Kuning',
            'ar' => 'محاضرة: تفسير سورة الكهف ١٢٣',
            'zh' => '周五讲座：古兰经讲解',
            'ta' => 'வெள்ளி சொற்பொழிவு: திருக்குர்ஆன் விளக்கம்',
            'jv-latin' => 'Pengajian Malam Jumat: Tafsir Al-Kahfi',
            'jv-java' => 'ꦥꦼꦔꦗꦶꦪꦤ꧀ ꦩꦭꦼꦩ꧀ꦗꦸꦩꦸꦮꦃ',
            'jawi-1' => 'کولياه مڬرب: تفسير سورة الکهف',
            'jawi-2' => 'چراماه ڤردانا اڠکاس',
            'mixed' => 'Ceramah 中文: தமிழ் و ملايو ꦗꦮ',
        ];
    }
}

it('covers latin chrome, digits and joiners in Noto Sans', function (int $codepoint): void {
    $path = shareCardFontsDir().'/NotoSans[wdth,wght].ttf';

    expect(shareCardFontHasGlyph($path, $codepoint))->toBeTrue();
})->with([
    'latin A' => [0x0041],
    'degree sign' => [0x00B0],
    'middle dot' => [0x00B7],
    'ellipsis' => [0x2026],
    'en dash' => [0x2013],
    'right quote' => [0x2019],
    'zero-width joiner' => [0x200D],
    'zero-width non-joiner' => [0x200C],
    'isolate initiator' => [0x2066],
]);

it('covers arabic plus all six jawi extensions in Noto Sans Arabic', function (int $codepoint): void {
    $path = shareCardFontsDir().'/NotoSansArabic[wdth,wght].ttf';

    expect(shareCardFontHasGlyph($path, $codepoint))->toBeTrue();
})->with([
    'alef' => [0x0627],
    'jawi ca' => [0x0686],
    'jawi nga' => [0x06A0],
    'jawi pa' => [0x06A4],
    'jawi ga' => [0x0762],
    'jawi va' => [0x06CF],
    'jawi nyeh' => [0x06BD],
    'arabic-indic digit' => [0x0661],
    'joiner' => [0x200D],
]);

it('covers han, tamil and javanese script codepoints in their fonts', function (string $file, int $codepoint): void {
    expect(shareCardFontHasGlyph(shareCardFontsDir().'/'.$file, $codepoint))->toBeTrue();
})->with([
    'han zhou' => ['NotoSansSC[wght].ttf', 0x5468],
    'han jiang' => ['NotoSansSC[wght].ttf', 0x8BB2],
    'han fullwidth colon' => ['NotoSansSC[wght].ttf', 0xFF1A],
    'tamil ta' => ['NotoSansTamil[wdth,wght].ttf', 0x0BA4],
    'tamil pulli' => ['NotoSansTamil[wdth,wght].ttf', 0x0BCD],
    'tamil ra' => ['NotoSansTamil[wdth,wght].ttf', 0x0BB0],
    'javanese sa' => ['NotoSansJavanese[wght].ttf', 0xA9B1],
    'javanese ja' => ['NotoSansJavanese[wght].ttf', 0xA997],
    'javanese pangkon' => ['NotoSansJavanese[wght].ttf', 0xA9C0],
]);

it('reports genuinely absent codepoints as missing', function (string $file, int $codepoint): void {
    expect(shareCardFontHasGlyph(shareCardFontsDir().'/'.$file, $codepoint))->toBeFalse();
})->with([
    'check mark absent from latin base (verified state stays text-only)' => ['NotoSans[wdth,wght].ttf', 0x2713],
    'arabic alef absent from latin base (fallback design)' => ['NotoSans[wdth,wght].ttf', 0x0627],
    'highest noncharacter absent everywhere' => ['NotoSans[wdth,wght].ttf', 0x10FFFF],
    'highest noncharacter absent from arabic' => ['NotoSansArabic[wdth,wght].ttf', 0x10FFFF],
]);

it('covers every matrix title character in at least one vendored font', function (string $title): void {
    $files = [
        'NotoSans[wdth,wght].ttf',
        'NotoSansArabic[wdth,wght].ttf',
        'NotoSansSC[wght].ttf',
        'NotoSansTamil[wdth,wght].ttf',
        'NotoSansJavanese[wght].ttf',
    ];

    $missing = [];

    foreach (mb_str_split($title, 1, 'UTF-8') as $char) {
        $codepoint = mb_ord($char, 'UTF-8');

        if ($codepoint === false || $codepoint <= 0x20) {
            continue;
        }

        $held = false;

        foreach ($files as $file) {
            if (shareCardFontHasGlyph(shareCardFontsDir().'/'.$file, $codepoint)) {
                $held = true;

                break;
            }
        }

        if (! $held) {
            $missing[] = sprintf('U+%04X', $codepoint);
        }
    }

    expect($missing)->toBe([]);
})->with(shareCardMatrixTitles());
