<?php

declare(strict_types=1);

namespace App\Features\Contributions\Support;

use App\Features\Contributions\Models\ContributionRequest;

/**
 * Contributor notifications for reviewed contribution requests.
 *
 * The conventional notification service keeps recipient lookup, locale
 * switching and dispatch; Contributions triggers it after the review
 * transaction commits.
 */
interface ContributionRequestNotifier
{
    public function notifyRequestApproved(ContributionRequest $request): void;

    public function notifyRequestRejected(ContributionRequest $request): void;
}
