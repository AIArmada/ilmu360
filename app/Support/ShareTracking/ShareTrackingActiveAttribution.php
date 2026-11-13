<?php

declare(strict_types=1);

namespace App\Support\ShareTracking;

use App\Services\ShareTracking\AffiliatesShareTrackingService;
use App\Shared\ShareTracking\ActiveAttribution;
use Illuminate\Http\Request;

/**
 * Root adapter for the active share-attribution lookup.
 *
 * Share tracking stays root-owned during the Registration migration; this
 * adapter preserves the canonical request fallback and exposes only the
 * attribution id the checkout intent needs.
 */
final readonly class ShareTrackingActiveAttribution implements ActiveAttribution
{
    public function __construct(private AffiliatesShareTrackingService $shareTracking) {}

    public function resolveId(?object $request = null): ?string
    {
        $httpRequest = $request instanceof Request ? $request : null;

        return $this->shareTracking->resolveActiveAttribution($httpRequest)?->id;
    }
}
