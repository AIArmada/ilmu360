<?php

declare(strict_types=1);

namespace App\Support\ShareCards;

use IntlChar;
use Normalizer;

/**
 * Deterministic, shaping-safe text normalization for share-card strips.
 *
 * The renderer passes strip text to pango-view as raw argv (never markup, never
 * a shell string), so this class owns the UTF-8/NFC/controls/bidi/budget
 * policy: reject undecodable input, preserve every codepoint the shaper needs
 * (joiners, marks, isolates), and neutralize only the controls that enable
 * visual spoofing or layout breakage.
 */
final class ShareCardText
{
    public const TITLE_MAX_CHARS = 280;

    public const LABEL_MAX_CHARS = 160;

    public const BRAND_MAX_CHARS = 80;

    public const DIRECTION_LTR = 'ltr';

    public const DIRECTION_RTL = 'rtl';

    private const ELLIPSIS = '…';

    /**
     * Direction overrides/embeddings U+202A-U+202E plus BOM U+FEFF.
     *
     * The recommended isolate controls (U+2066-U+2069, U+2060, U+061C) are
     * deliberately absent and therefore preserved.
     */
    private const BIDI_OVERRIDE_PATTERN = '/[\x{202A}-\x{202E}\x{FEFF}]/u';

    /**
     * Unicode non-characters (guaranteed .notdef boxes): U+FDD0-U+FDEF plus
     * U+nFFFE/U+nFFFF on planes 0-16.
     */
    private const NON_CHARACTER_PATTERN = '/[\x{FDD0}-\x{FDEF}\x{FFFE}\x{FFFF}\x{1FFFE}\x{1FFFF}\x{2FFFE}\x{2FFFF}\x{3FFFE}\x{3FFFF}\x{4FFFE}\x{4FFFF}\x{5FFFE}\x{5FFFF}\x{6FFFE}\x{6FFFF}\x{7FFFE}\x{7FFFF}\x{8FFFE}\x{8FFFF}\x{9FFFE}\x{9FFFF}\x{AFFFE}\x{AFFFF}\x{BFFFE}\x{BFFFF}\x{CFFFE}\x{CFFFF}\x{DFFFE}\x{DFFFF}\x{EFFFE}\x{EFFFF}\x{FFFFE}\x{FFFFF}\x{10FFFE}\x{10FFFF}]/u';

    /**
     * Normalize a required strip string. Returns null when the input is
     * undecodable or empty after normalization (caller maps to InvalidInput).
     */
    public static function sanitizeRequired(string $text, int $maxChars): ?string
    {
        $clean = self::normalize($text, $maxChars);

        if ($clean === null || $clean === '') {
            return null;
        }

        return $clean;
    }

    /**
     * Normalize an optional strip string.
     *
     * Returns the cleaned string, null when absent/blank (caller omits the
     * strip), or false when present but undecodable (caller maps to
     * InvalidInput rather than silently dropping a supplied label).
     */
    public static function sanitizeOptional(?string $text, int $maxChars): string|false|null
    {
        if ($text === null) {
            return null;
        }

        $clean = self::normalize($text, $maxChars);

        if ($clean === null) {
            return false;
        }

        if ($clean === '') {
            return null;
        }

        return $clean;
    }

    /**
     * Base direction from the first strong directional character.
     *
     * Weak and neutral characters (digits, spaces, punctuation) never decide:
     * a Latin title stays LTR even with an Arabic-Indic digit prefix, and an
     * Arabic title stays RTL even with a leading quote. Content with no
     * strong character defaults to LTR.
     */
    public static function baseDirection(string $text): string
    {
        $length = mb_strlen($text, 'UTF-8');

        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($text, $i, 1, 'UTF-8');
            $code = IntlChar::ord($char);

            if ($code === null) {
                continue;
            }

            $direction = IntlChar::charDirection($code);

            if ($direction === IntlChar::CHAR_DIRECTION_LEFT_TO_RIGHT) {
                return self::DIRECTION_LTR;
            }

            if ($direction === IntlChar::CHAR_DIRECTION_RIGHT_TO_LEFT
                || $direction === IntlChar::CHAR_DIRECTION_RIGHT_TO_LEFT_ARABIC) {
                return self::DIRECTION_RTL;
            }
        }

        return self::DIRECTION_LTR;
    }

    /**
     * Clean text, or null when undecodable.
     */
    private static function normalize(string $text, int $maxChars): ?string
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            return null;
        }

        $normalized = Normalizer::normalize($text, Normalizer::FORM_C);

        if ($normalized === false) {
            return null;
        }

        // Single-line strips: every line break or tab becomes one space first.
        $normalized = str_replace("\t", ' ', $normalized);
        $normalized = preg_replace('/\R+/u', ' ', $normalized) ?? '';

        // Strip C0/C1 controls and DEL. Grapheme joiners (ZWJ/ZWNJ), combining
        // marks, variation selectors and bidi isolates survive: the shaper
        // needs them and isolates cannot leak past their own scope.
        $normalized = preg_replace('/\p{Cc}+/u', '', $normalized) ?? '';

        // Neutralize explicit direction overrides/embeddings and the BOM.
        $normalized = preg_replace(self::BIDI_OVERRIDE_PATTERN, '', $normalized) ?? '';

        // Strip Unicode non-characters (guaranteed .notdef boxes).
        $normalized = preg_replace(self::NON_CHARACTER_PATTERN, '', $normalized) ?? '';

        $normalized = preg_replace('/^\p{Z}+|\p{Z}+$/u', '', $normalized) ?? '';

        $length = grapheme_strlen($normalized);

        if ($length === false || $length === null) {
            return null;
        }

        if ($length > $maxChars) {
            $normalized = self::truncateWithEllipsis($normalized, $maxChars);
        }

        return $normalized;
    }

    private static function truncateWithEllipsis(string $text, int $maxChars): string
    {
        if ($maxChars < 2) {
            return self::ELLIPSIS;
        }

        $kept = grapheme_substr($text, 0, $maxChars - 1);

        if ($kept === false || $kept === '') {
            return self::ELLIPSIS;
        }

        // Never leave a trailing combining mark separated from its base.
        $kept = preg_replace('/\p{M}+$/u', '', $kept) ?? '';

        if ($kept === '') {
            return self::ELLIPSIS;
        }

        return $kept.self::ELLIPSIS;
    }
}
