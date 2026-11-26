<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Actions\LandingAttribution;

use AIArmada\Affiliates\Features\Attribution\Models\AffiliateAttribution;
use AIArmada\CommerceSupport\Support\OwnerScope;
use App\Features\ShareTracking\Api\PassiveNavigationAttributionWriter;

/**
 * Refreshes passive-navigation timestamps through the configured Affiliates
 * model.
 *
 * The caller owns the transaction and attribution lock order.
 */
final readonly class TouchPassiveNavigationAttributionAction implements PassiveNavigationAttributionWriter
{
    public function touchPassiveNavigation(string $attributionId): void
    {
        $attribution = AffiliateAttribution::query()
            ->withoutGlobalScope(OwnerScope::class)
            ->whereKey($attributionId)
            ->firstOrFail();

        $attribution->forceFill([
            'last_seen_at' => now(),
            'last_cookie_seen_at' => now(),
        ])->save();
    }
}
