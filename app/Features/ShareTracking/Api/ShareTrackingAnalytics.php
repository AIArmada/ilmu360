<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Api;

use App\Shared\Auth\AccountActor;

/**
 * Public account-dashboard metrics as scalar values.
 * Accepts an identity contract instead of an Eloquent model.
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
    public function summaryForAccount(AccountActor $account): array;
}
