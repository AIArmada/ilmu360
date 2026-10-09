<?php

declare(strict_types=1);

namespace App\Contracts\ShareCards;

/**
 * Machine-readable leaf renderer failures.
 *
 * Every variant is an explicit non-render, never a degraded card that
 * pretends to be complete. Callers map these to the honest cover fallback
 * (existing card_image_url as og:image) owned by future WP3 integration.
 * UnsupportedText means caller-supplied text has no glyph in the render's
 * pinned font set: retrying with different copy (or a future font revision)
 * is the only fix, never a silent strip or substitution.
 */
enum ShareCardError: string
{
    case InvalidInput = 'invalid_input';
    case UnsupportedLocale = 'unsupported_locale';
    case UnsupportedText = 'unsupported_text';
    case ShaperUnavailable = 'shaper_unavailable';
    case FontsUnavailable = 'fonts_unavailable';
    case RasterRejected = 'raster_rejected';
    case RenderFailed = 'render_failed';
    case TimedOut = 'timed_out';
}
