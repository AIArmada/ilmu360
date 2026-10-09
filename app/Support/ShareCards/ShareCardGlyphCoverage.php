<?php

declare(strict_types=1);

namespace App\Support\ShareCards;

use IntlChar;

/**
 * Truthful-render guard: every visible codepoint in the sanitized strip texts
 * must have a glyph in at least one font of the render's own pinned set.
 *
 * Render-ok alone cannot prove this (a missing glyph still shapes as a
 * .notdef/hex box), so the renderer refuses uncovered text with a typed
 * error instead of returning success-with-tofu. Coverage is read from the
 * ACTUAL font binaries passed in (the render's configured directory, never a
 * global or system font), parsed dependency-free from their cmap tables.
 *
 * Only genuinely non-visible codepoints are exempt: Unicode control, format,
 * line/paragraph separator and surrogate categories (joiners, isolates, word
 * joiner, soft hyphen, BOM remnants) plus variation selectors and the
 * combining grapheme joiner, which are marks by category but zero-width.
 * Combining marks that shape visible output (tamil pulli, javanese pangkon,
 * arabic harakat) are NOT exempt: uncovered marks refuse like any visible
 * character.
 */
final class ShareCardGlyphCoverage
{
    /**
     * Visible codepoints lacking a glyph in every given font file.
     *
     * @param  list<string>  $fontPaths  Absolute pinned font binaries for this render.
     * @param  list<string|null>  $texts  Sanitized strip texts (null strips skipped).
     * @return list<int> Sorted unique missing codepoints; empty means renderable.
     */
    public static function missingVisibleCodepoints(array $fontPaths, array $texts): array
    {
        $codepoints = self::uniqueVisibleCodepoints($texts);

        if ($codepoints === []) {
            return [];
        }

        if ($fontPaths === []) {
            return self::sorted($codepoints);
        }

        $tables = [];

        foreach ($fontPaths as $path) {
            $tables[] = self::loadCmap($path);
        }

        $missing = [];

        foreach ($codepoints as $codepoint) {
            $held = false;

            foreach ($tables as $table) {
                if ($table !== null && self::tableHasGlyph($table, $codepoint)) {
                    $held = true;

                    break;
                }
            }

            if (! $held) {
                $missing[] = $codepoint;
            }
        }

        return self::sorted($missing);
    }

    /**
     * True only for codepoints that never paint visible pixels: controls and
     * format characters (joiners, isolates, word joiner, soft hyphen), line
     * and paragraph separators, surrogates, variation selectors and the
     * combining grapheme joiner. Out-of-range values are exempt defensively;
     * they cannot occur in sanitized UTF-8.
     */
    public static function isExemptCodepoint(int $codepoint): bool
    {
        if ($codepoint < 0 || $codepoint > 0x10FFFF) {
            return true;
        }

        if ($codepoint >= 0xFE00 && $codepoint <= 0xFE0F) {
            return true;
        }

        if ($codepoint >= 0xE0100 && $codepoint <= 0xE01EF) {
            return true;
        }

        if ($codepoint === 0x034F) {
            return true;
        }

        $type = IntlChar::charType($codepoint);

        return $type === IntlChar::CHAR_CATEGORY_CONTROL_CHAR
            || $type === IntlChar::CHAR_CATEGORY_FORMAT_CHAR
            || $type === IntlChar::CHAR_CATEGORY_LINE_SEPARATOR
            || $type === IntlChar::CHAR_CATEGORY_PARAGRAPH_SEPARATOR
            || $type === IntlChar::CHAR_CATEGORY_SURROGATE;
    }

    /**
     * @param  list<string|null>  $texts
     * @return list<int>
     */
    private static function uniqueVisibleCodepoints(array $texts): array
    {
        $seen = [];

        foreach ($texts as $text) {
            if (! is_string($text) || $text === '') {
                continue;
            }

            foreach (mb_str_split($text, 1, 'UTF-8') as $char) {
                $codepoint = mb_ord($char, 'UTF-8');

                if ($codepoint === false || self::isExemptCodepoint($codepoint)) {
                    continue;
                }

                $seen[$codepoint] = true;
            }
        }

        return array_keys($seen);
    }

    /**
     * @param  list<int>  $codepoints
     * @return list<int>
     */
    private static function sorted(array $codepoints): array
    {
        sort($codepoints);

        return array_values(array_unique($codepoints));
    }

    /**
     * Best cmap subtable of one font: format 12 preferred, then 4, then 6,
     * over the Unicode (0) and Windows Unicode (3) platforms only. Null when
     * the file is unreadable or has no usable subtable; an unreadable font
     * contributes no coverage, so the caller fails closed.
     *
     * @return array{bytes: string, offset: int, format: int}|null
     */
    private static function loadCmap(string $path): ?array
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        try {
            $header = self::readAt($handle, 0, 12);

            if ($header === null) {
                return null;
            }

            $numTables = (ord($header[4]) << 8) | ord($header[5]);

            if ($numTables <= 0 || $numTables > 64) {
                return null;
            }

            $cmapOffset = null;
            $cmapLength = null;

            for ($i = 0; $i < $numTables; $i++) {
                $record = self::readAt($handle, 12 + $i * 16, 16);

                if ($record === null) {
                    return null;
                }

                if (substr($record, 0, 4) !== 'cmap') {
                    continue;
                }

                $cmapOffset = (ord($record[8]) << 24) | (ord($record[9]) << 16) | (ord($record[10]) << 8) | ord($record[11]);
                $cmapLength = (ord($record[12]) << 24) | (ord($record[13]) << 16) | (ord($record[14]) << 8) | ord($record[15]);

                break;
            }

            if ($cmapOffset === null || $cmapLength === null || $cmapLength < 4 || $cmapLength > 8 * 1024 * 1024) {
                return null;
            }

            $cmap = self::readAt($handle, $cmapOffset, $cmapLength);

            if ($cmap === null) {
                return null;
            }

            $numSubtables = self::u16($cmap, 2);

            if ($numSubtables === null || $numSubtables <= 0 || $numSubtables > 64) {
                return null;
            }

            /** @var array<int, int> $candidates */
            $candidates = [];

            for ($i = 0; $i < $numSubtables; $i++) {
                $platform = self::u16($cmap, 4 + $i * 8);
                $offset = self::u32($cmap, 4 + $i * 8 + 4);

                if ($platform === null || $offset === null) {
                    continue;
                }

                if ($platform !== 0 && $platform !== 3) {
                    continue;
                }

                $format = self::u16($cmap, $offset);

                if ($format === 4 || $format === 6 || $format === 12) {
                    $candidates[$format] = $offset;
                }
            }

            foreach ([12, 4, 6] as $format) {
                if (isset($candidates[$format])) {
                    return ['bytes' => $cmap, 'offset' => $candidates[$format], 'format' => $format];
                }
            }

            return null;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  resource  $handle
     */
    private static function readAt($handle, int $offset, int $length): ?string
    {
        if ($offset < 0 || $length <= 0) {
            return null;
        }

        if (fseek($handle, $offset) !== 0) {
            return null;
        }

        $bytes = fread($handle, $length);

        return is_string($bytes) && strlen($bytes) === $length ? $bytes : null;
    }

    private static function u16(string $bytes, int $offset): ?int
    {
        if ($offset < 0 || $offset + 2 > strlen($bytes)) {
            return null;
        }

        return (ord($bytes[$offset]) << 8) | ord($bytes[$offset + 1]);
    }

    private static function u32(string $bytes, int $offset): ?int
    {
        $high = self::u16($bytes, $offset);
        $low = self::u16($bytes, $offset + 2);

        return $high === null || $low === null ? null : ($high << 16) | $low;
    }

    /**
     * @param  array{bytes: string, offset: int, format: int}  $table
     */
    private static function tableHasGlyph(array $table, int $codepoint): bool
    {
        if ($codepoint < 0 || $codepoint > 0x10FFFF) {
            return false;
        }

        if ($table['format'] === 12) {
            return self::lookupFormat12($table['bytes'], $table['offset'], $codepoint);
        }

        if ($table['format'] === 6) {
            return self::lookupFormat6($table['bytes'], $table['offset'], $codepoint);
        }

        return self::lookupFormat4($table['bytes'], $table['offset'], $codepoint);
    }

    private static function lookupFormat12(string $cmap, int $offset, int $codepoint): bool
    {
        $nGroups = self::u32($cmap, $offset + 12);

        if ($nGroups === null || $nGroups < 0 || $nGroups > 100000) {
            return false;
        }

        for ($g = 0; $g < $nGroups; $g++) {
            $start = self::u32($cmap, $offset + 16 + $g * 12);
            $end = self::u32($cmap, $offset + 16 + $g * 12 + 4);
            $glyph = self::u32($cmap, $offset + 16 + $g * 12 + 8);

            if ($start === null || $end === null || $glyph === null) {
                return false;
            }

            if ($codepoint >= $start && $codepoint <= $end) {
                return ($glyph + ($codepoint - $start)) !== 0;
            }
        }

        return false;
    }

    private static function lookupFormat6(string $cmap, int $offset, int $codepoint): bool
    {
        $first = self::u16($cmap, $offset + 6);
        $count = self::u16($cmap, $offset + 8);

        if ($first === null || $count === null || $codepoint < $first || $codepoint >= $first + $count) {
            return false;
        }

        $glyph = self::u16($cmap, $offset + 10 + ($codepoint - $first) * 2);

        return $glyph !== null && $glyph !== 0;
    }

    private static function lookupFormat4(string $cmap, int $offset, int $codepoint): bool
    {
        $segCountX2 = self::u16($cmap, $offset + 6);

        if ($segCountX2 === null || $segCountX2 % 2 !== 0) {
            return false;
        }

        $segCount = (int) ($segCountX2 / 2);

        if ($segCount <= 0 || $segCount > 10000 || $codepoint > 0xFFFF) {
            return false;
        }

        $endsAt = $offset + 14;
        $startsAt = $endsAt + $segCount * 2 + 2;
        $deltasAt = $startsAt + $segCount * 2;
        $rangeOffsetsAt = $deltasAt + $segCount * 2;

        for ($i = 0; $i < $segCount; $i++) {
            $end = self::u16($cmap, $endsAt + $i * 2);

            if ($end === null || $end < $codepoint) {
                continue;
            }

            $start = self::u16($cmap, $startsAt + $i * 2);
            $delta = self::u16($cmap, $deltasAt + $i * 2);
            $rangeOffset = self::u16($cmap, $rangeOffsetsAt + $i * 2);

            if ($start === null || $delta === null || $rangeOffset === null || $start > $codepoint) {
                return false;
            }

            $signedDelta = $delta >= 0x8000 ? $delta - 0x10000 : $delta;

            if ($rangeOffset === 0) {
                return (($codepoint + $signedDelta) & 0xFFFF) !== 0;
            }

            $glyph = self::u16($cmap, $rangeOffsetsAt + $i * 2 + $rangeOffset + ($codepoint - $start) * 2);

            return $glyph !== null && (($glyph + $signedDelta) & 0xFFFF) !== 0;
        }

        return false;
    }
}
