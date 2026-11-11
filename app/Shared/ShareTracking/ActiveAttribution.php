<?php

declare(strict_types=1);

namespace App\Shared\ShareTracking;

/**
 * Resolves the share-attribution decision already active on a request.
 *
 * Checkout preparation freezes this decision before the external payment
 * handoff so payment callbacks inherit the persisted intent instead of
 * reading late cookies. Share tracking remains root-owned until the
 * ShareTracking migration; callers receive only the stable attribution id.
 */
interface ActiveAttribution
{
    public function resolveId(?object $request = null): ?string;
}
