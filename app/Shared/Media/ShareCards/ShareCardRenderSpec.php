<?php

declare(strict_types=1);

namespace App\Shared\Media\ShareCards;

final readonly class ShareCardRenderSpec
{
    public const int WIDTH = 1200;

    public const int HEIGHT = 630;

    public const string RENDERER_REVISION = 'scr-002';

    public const string FONT_MANIFEST_FILE = 'fonts.json';

    public const array SUPPORTED_LOCALES = ['ms', 'id', 'en', 'ar', 'zh', 'ta', 'jv'];

    public static function isSupportedLocale(string $locale): bool
    {
        return in_array($locale, self::SUPPORTED_LOCALES, true);
    }
}
