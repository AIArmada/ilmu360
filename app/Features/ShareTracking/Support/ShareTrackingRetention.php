<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Support;

/**
 * Retention holds for share-tracking history.
 *
 * Guest profiles are the only disposable share identity: unclaimed guest
 * history is pruned at this age, while claims, conversions, published short
 * links and frozen outcome evidence are never pruned here.
 */
final class ShareTrackingRetention
{
    public const int GUEST_PROFILE_DAYS = 90;
}
