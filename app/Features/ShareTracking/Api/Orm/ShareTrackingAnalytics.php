<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Api\Orm;

use App\Models\User;

/**
 * Scalar share summary for an account dashboard.
 *
 * @internal Explicit ORM integration port for framework callbacks, hydrated page state and builder composition.
 *
 * Consumers: Providers/AppServiceProvider.php, Identity/EntryPoints, ShareTracking/Services
 */
interface ShareTrackingAnalytics
{
    /**
     * @return array{
     *     outbound_shares: int,
     *     visits: int,
     *     distinct_browsers: int,
     *     signups: int,
     *     event_registrations: int,
     *     event_checkins: int,
     *     event_submissions: int,
     *     total_outcomes: int
     * }
     */
    public function summaryForUser(User $user): array;
}
