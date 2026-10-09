<?php

use App\Contracts\ShareCards\ShareCardError;
use App\Contracts\ShareCards\ShareCardRenderInput;
use App\Support\ShareCards\PangoShareCardRenderer;
use App\Support\ShareCards\ShareCardGlyphCoverage;

if (! function_exists('shareCardFontsDir')) {
    function shareCardFontsDir(): string
    {
        return dirname(__DIR__, 3).'/public/fonts/share-cards';
    }
}

if (! function_exists('shareCardRenderer')) {
    function shareCardRenderer(?string $fontDirectory = null, ?string $pangoBinary = null, ?string $workDirectory = null, float $timeout = 8.0): PangoShareCardRenderer
    {
        return new PangoShareCardRenderer($fontDirectory ?? shareCardFontsDir(), $pangoBinary, $workDirectory, null, $timeout);
    }
}

it('refuses emoji with a typed unsupported-text error instead of tofu', function (string $title, ?string $venue): void {
    $result = shareCardRenderer()->render(new ShareCardRenderInput(
        title: $title,
        locale: 'ms',
        brandLine: 'ilmu360°',
        scheduleLabel: 'Sabtu · 7:30 malam',
        venueLabel: $venue,
    ));

    expect($result->ok)->toBeFalse()
        ->and($result->error)->toBe(ShareCardError::UnsupportedText)
        ->and($result->pngBytes)->toBeNull()
        ->and($result->width)->toBe(1200)
        ->and($result->height)->toBe(630)
        ->and($result->rendererRevision)->toBe('scr-001')
        ->and($result->errorMessage)->not->toContain('MARKER-should-never-echo');
})->with([
    'grinning face in title' => ['Kuliah Maghrib 😀 MARKER-should-never-echo-glyph', null],
    'party popper in title' => ['Kuliah Maghrib 🎉', null],
    'emoji in an optional label' => ['Kuliah Maghrib', 'Dewan 🎉 Utama'],
    'zwj emoji sequence refuses on its bases, not its joiners' => ["Keluarga \u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}", null],
]);

it('refuses scripts the pinned set cannot shape', function (string $title): void {
    $result = shareCardRenderer()->render(new ShareCardRenderInput(
        title: $title,
        locale: 'ms',
        brandLine: 'ilmu360°',
        scheduleLabel: 'Sabtu · 7:30 malam',
    ));

    expect($result->ok)->toBeFalse()
        ->and($result->error)->toBe(ShareCardError::UnsupportedText)
        ->and($result->pngBytes)->toBeNull();
})->with([
    'thai' => ['สวัสดี'],
    'hebrew' => ['שלום'],
    'georgian' => ['გამარჯობა'],
]);

it('renders the check mark because the pinned set actually covers it', function (): void {
    // U+2713 is absent from the Noto Sans base family but present via the
    // fallback chain (verified by raster inspection, not just cmap): the
    // truthful outcome is success, not refusal.
    $result = shareCardRenderer()->render(new ShareCardRenderInput(
        title: 'Kuliah Maghrib ✓ Siri 5',
        locale: 'ms',
        brandLine: 'ilmu360°',
        scheduleLabel: 'Sabtu · 7:30 malam',
    ));

    expect($result->ok)->toBeTrue();
});

it('still renders joiners, isolates, selectors and shaping marks', function (string $title, string $locale): void {
    $result = shareCardRenderer()->render(new ShareCardRenderInput(
        title: $title,
        locale: $locale,
        brandLine: 'ilmu360°',
        scheduleLabel: 'Sabtu · 7:30 malam',
    ));

    expect($result->ok)->toBeTrue()
        ->and($result->pngBytes)->not->toBeNull();
})->with([
    'zero-width joiners in latin' => ["a\u{200D}b\u{200C}c Kuliah", 'ms'],
    'bidi isolates around a run' => ["Kuliah\u{2066}Maghrib\u{2069} Perdana", 'ms'],
    'variation selector after a symbol' => ["Kuliah Maghrib°\u{FE0F} Siri", 'ms'],
    'arabic harakat marks' => ['مُحَاضَرَة: تفسير', 'ar'],
    'tamil with explicit joiner' => ["தமிழ்\u{200D} சொல்", 'ta'],
]);

it('exempts only non-visible codepoints from coverage', function (): void {
    $exempt = [0x0000, 0x200B, 0x200C, 0x200D, 0x2060, 0x2066, 0x2069, 0x061C, 0x00AD, 0xFE00, 0xFE0F, 0xE0100, 0x034F];

    foreach ($exempt as $codepoint) {
        expect(ShareCardGlyphCoverage::isExemptCodepoint($codepoint))->toBeTrue(sprintf('U+%04X should be exempt', $codepoint));
    }

    // Spaces, letters, visible marks that shape output, symbols, emoji and
    // non-characters must all require real glyphs; uncovered ones refuse.
    $visible = [0x0020, 0x0041, 0x0627, 0x064E, 0x0BCD, 0xA9C0, 0x2713, 0x1F600, 0x0E24, 0xFDD0, 0x10FFFF];

    foreach ($visible as $codepoint) {
        expect(ShareCardGlyphCoverage::isExemptCodepoint($codepoint))->toBeFalse(sprintf('U+%04X must require a glyph', $codepoint));
    }
});

it('measures coverage against the configured font set only', function (): void {
    $directory = shareCardFontsDir();
    $manifest = json_decode((string) file_get_contents($directory.'/fonts.json'), true);
    $allPaths = array_map(static fn (array $entry): string => $directory.'/'.$entry['file'], $manifest['fonts']);

    $matrixTitles = [
        'Kuliah Maghrib: Tafsir Surah Al-Kahfi',
        'محاضرة: تفسير سورة الكهف ١٢٣',
        '周五讲座：古兰经讲解',
        'வெள்ளி சொற்பொழிவு: திருக்குர்ஆன் விளக்கம்',
        'ꦥꦼꦔꦗꦶꦪꦤ꧀ ꦩꦭꦼꦩ꧀ꦗꦸꦩꦸꦮꦃ',
        'Ceramah 中文: தமிழ் و ملايو ꦗꦮ',
    ];

    expect(ShareCardGlyphCoverage::missingVisibleCodepoints($allPaths, $matrixTitles))->toBe([])
        ->and(ShareCardGlyphCoverage::missingVisibleCodepoints($allPaths, ['Kuliah 🎉']))->toBe([0x1F389])
        ->and(ShareCardGlyphCoverage::missingVisibleCodepoints($allPaths, [null, '']))->toBe([]);

    $latinOnly = [$directory.'/NotoSans[wdth,wght].ttf'];

    expect(ShareCardGlyphCoverage::missingVisibleCodepoints($latinOnly, ['محاضرة']))->toContain(0x0627)
        ->and(ShareCardGlyphCoverage::missingVisibleCodepoints([], ['Kuliah']))->not->toBe([])
        ->and(ShareCardGlyphCoverage::missingVisibleCodepoints([$directory.'/does-not-exist.ttf'], ['Kuliah']))->not->toBe([]);
});

it('keeps the unsupported-text failure contract truthful for future fallback', function (): void {
    $renderer = shareCardRenderer();
    $readiness = $renderer->readiness();

    $result = $renderer->render(new ShareCardRenderInput(
        title: 'Kuliah Maghrib 🎉 MARKER-should-never-echo-fallback',
        locale: 'ms',
        brandLine: 'ilmu360°',
        scheduleLabel: 'Sabtu · 7:30 malam',
    ));

    expect($result->ok)->toBeFalse()
        ->and($result->error)->toBe(ShareCardError::UnsupportedText)
        ->and($result->pngBytes)->toBeNull()
        ->and($result->width)->toBe(1200)
        ->and($result->height)->toBe(630)
        ->and($result->rendererRevision)->toBe('scr-001')
        ->and($result->fontRevision)->toBe($readiness->fontRevision)
        ->and($result->cacheRevision())->toBe('scr-001:'.$readiness->fontRevision)
        ->and($result->errorMessage)->toBeString()
        ->and($result->errorMessage)->not->toContain('MARKER-should-never-echo')
        ->and($result->errorMessage)->not->toContain('🎉');
});

it('pins the unsupported-text error value for future fallback mapping', function (): void {
    expect(ShareCardError::UnsupportedText->value)->toBe('unsupported_text')
        ->and(ShareCardError::cases())->toContain(ShareCardError::UnsupportedText);
});

it('documents refusal instead of tofu in the vendored support policy', function (): void {
    $manifest = json_decode((string) file_get_contents(shareCardFontsDir().'/fonts.json'), true);

    expect($manifest['support_policy']['emoji'])->toContain('unsupported_text')
        ->and($manifest['support_policy']['emoji'])->not->toContain('may render as')
        ->and($manifest['support_policy']['symbols'])->toContain('unsupported_text');
});
