<?php

declare(strict_types=1);

namespace App\Contracts\ShareCards;

/**
 * Minimal shaping/readiness contract for deterministic share-card art.
 *
 * Implementations render a fixed 1200x630 PNG from explicit canonical
 * strings. Both methods are total: they report capability problems as data
 * (readiness / error results) instead of throwing, so ordinary sharing can
 * survive a renderer outage via the cover fallback.
 */
interface ShareCardRenderer
{
    public function readiness(): ShareCardReadiness;

    public function render(ShareCardRenderInput $input): ShareCardRenderResult;
}
