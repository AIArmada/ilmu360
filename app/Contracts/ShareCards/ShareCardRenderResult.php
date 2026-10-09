<?php

declare(strict_types=1);

namespace App\Contracts\ShareCards;

/**
 * Explicit render output for one share card.
 *
 * A successful result carries raw PNG bytes (1200x630, no EXIF) plus the
 * deterministic revision pair the future cache key must include. A failed
 * result carries a machine-readable error and a human message free of
 * private data; it never carries a partial or placeholder image.
 */
final readonly class ShareCardRenderResult
{
    public function __construct(
        public bool $ok,
        public ?string $pngBytes,
        public int $width,
        public int $height,
        public string $rendererRevision,
        public string $fontRevision,
        public ?ShareCardError $error = null,
        public ?string $errorMessage = null,
    ) {}

    public static function success(
        string $pngBytes,
        int $width,
        int $height,
        string $rendererRevision,
        string $fontRevision,
    ): self {
        return new self(
            ok: true,
            pngBytes: $pngBytes,
            width: $width,
            height: $height,
            rendererRevision: $rendererRevision,
            fontRevision: $fontRevision,
        );
    }

    public static function failure(
        ShareCardError $error,
        string $errorMessage,
        int $width,
        int $height,
        string $rendererRevision,
        string $fontRevision,
    ): self {
        return new self(
            ok: false,
            pngBytes: null,
            width: $width,
            height: $height,
            rendererRevision: $rendererRevision,
            fontRevision: $fontRevision,
            error: $error,
            errorMessage: $errorMessage,
        );
    }

    /**
     * Stable revision token for future cache keys (renderer + font assets).
     */
    public function cacheRevision(): string
    {
        return $this->rendererRevision.':'.$this->fontRevision;
    }
}
