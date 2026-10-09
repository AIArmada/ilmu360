<?php

use App\Support\ShareCards\ShareCardText;

it('normalizes required text to NFC with folded line breaks', function (): void {
    expect(ShareCardText::sanitizeRequired("e\xCC\x81disi", 80))->toBe('édisi')
        ->and(ShareCardText::sanitizeRequired("Kuliah\nMaghrib\tSiri", 80))->toBe('Kuliah Maghrib Siri')
        ->and(ShareCardText::sanitizeRequired("  \u{00A0}Tajuk\u{2003}  ", 80))->toBe('Tajuk');
});

it('strips C0 controls, DEL and unicode non-characters', function (): void {
    expect(ShareCardText::sanitizeRequired("a\x00b\x07c\x7Fd", 80))->toBe('abcd')
        ->and(ShareCardText::sanitizeRequired("a\u{FDD0}b\u{FFFF}c\u{1FFFE}d", 80))->toBe('abcd');
});

it('neutralizes bidi overrides and the BOM while preserving isolates', function (): void {
    expect(ShareCardText::sanitizeRequired("a\u{202E}b\u{202A}c\u{202D}d\u{FEFF}e", 80))->toBe('abcde')
        ->and(ShareCardText::sanitizeRequired("a\u{2066}b\u{2069}c\u{2060}d\u{061C}e", 80))->toBe("a\u{2066}b\u{2069}c\u{2060}d\u{061C}e");
});

it('preserves joiners, combining marks and variation selectors the shaper needs', function (): void {
    $text = "a\u{200D}b\u{200C}c\u{064E}d\u{FE0F}";

    expect(ShareCardText::sanitizeRequired($text, 80))->toBe($text);
});

it('rejects undecodable or blank required text', function (): void {
    expect(ShareCardText::sanitizeRequired("\xFF\xFE", 80))->toBeNull()
        ->and(ShareCardText::sanitizeRequired('', 80))->toBeNull()
        ->and(ShareCardText::sanitizeRequired("  \t\n ", 80))->toBeNull();
});

it('distinguishes absent, blank and undecodable optional labels', function (): void {
    expect(ShareCardText::sanitizeOptional(null, 80))->toBeNull()
        ->and(ShareCardText::sanitizeOptional('', 80))->toBeNull()
        ->and(ShareCardText::sanitizeOptional('   ', 80))->toBeNull()
        ->and(ShareCardText::sanitizeOptional("\xFF\xFE", 80))->toBeFalse()
        ->and(ShareCardText::sanitizeOptional('  Dewan  ', 80))->toBe('Dewan');
});

it('truncates over-budget text with a single ellipsis', function (): void {
    $truncated = ShareCardText::sanitizeRequired(str_repeat('a', 300), 280);

    expect($truncated)->not->toBeNull()
        ->and(grapheme_strlen((string) $truncated))->toBe(280)
        ->and($truncated)->toEndWith('…')
        ->and(ShareCardText::sanitizeRequired(str_repeat('b', 280), 280))->toBe(str_repeat('b', 280));
});

it('never leaves a combining mark orphaned at the truncation boundary', function (): void {
    // q + U+0301 has no precomposed NFC form, so the mark survives
    // normalization and must be dropped with its truncated grapheme.
    $text = str_repeat('a', 278).'q'."\u{0301}".str_repeat('b', 50);

    $truncated = ShareCardText::sanitizeRequired($text, 280);

    expect($truncated)->not->toBeNull()
        ->and($truncated)->toEndWith('q…')
        ->and($truncated)->not->toContain("\u{0301}");
});

it('derives base direction from the first strong character only', function (string $text, string $expected): void {
    expect(ShareCardText::baseDirection($text))->toBe($expected);
})->with([
    'latin title' => ['Kuliah Maghrib', ShareCardText::DIRECTION_LTR],
    'arabic title' => ['محاضرة الأسبوع', ShareCardText::DIRECTION_RTL],
    'digits never decide ltr' => ['123 Kuliah', ShareCardText::DIRECTION_LTR],
    'digits never decide rtl' => ['١٢٣ محاضرة', ShareCardText::DIRECTION_RTL],
    'leading punctuation ignored' => ['«محاضرة»', ShareCardText::DIRECTION_RTL],
    'mixed script follows first strong run' => ['Ceramah 中文', ShareCardText::DIRECTION_LTR],
    'weak-only content defaults to ltr' => ['123 ···', ShareCardText::DIRECTION_LTR],
    'empty content defaults to ltr' => ['', ShareCardText::DIRECTION_LTR],
]);
