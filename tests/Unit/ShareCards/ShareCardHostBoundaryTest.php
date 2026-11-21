<?php

declare(strict_types=1);

use App\Host\Pango\ShareCards\PangoShareCardRenderer;
use App\Host\Pango\ShareCards\ShareCardFonts;
use App\Shared\Media\ShareCards\ShareCardRenderer;
use App\Shared\Media\ShareCards\ShareCardRenderSpec;
use Tests\TestCase;

pest()->extend(TestCase::class);

it('binds the actual Pango renderer through the pure share card contract', function (): void {
    $renderer = app(ShareCardRenderer::class);

    expect($renderer)->toBeInstanceOf(PangoShareCardRenderer::class);
    expect(PangoShareCardRenderer::WIDTH)->toBe(ShareCardRenderSpec::WIDTH);
    expect(PangoShareCardRenderer::HEIGHT)->toBe(ShareCardRenderSpec::HEIGHT);
    expect(PangoShareCardRenderer::RENDERER_REVISION)->toBe(ShareCardRenderSpec::RENDERER_REVISION);
    expect(ShareCardFonts::MANIFEST_FILE)->toBe(ShareCardRenderSpec::FONT_MANIFEST_FILE);
    expect(ShareCardFonts::SUPPORTED_LOCALES)->toBe(ShareCardRenderSpec::SUPPORTED_LOCALES);
});

it('keeps supported locales owned by a pure shared value', function (): void {
    foreach (['ms', 'id', 'en', 'ar', 'zh', 'ta', 'jv'] as $locale) {
        expect(ShareCardRenderSpec::isSupportedLocale($locale))->toBeTrue();
    }

    expect(ShareCardRenderSpec::isSupportedLocale('xx'))->toBeFalse();
});
