<?php

declare(strict_types=1);

namespace App\Shared\Contributions;

use App\Models\ContributionRequest;

/**
 * Contributor notifications for reviewed create requests.
 *
 * The conventional notification service keeps recipient lookup, locale
 * switching and dispatch; Contributions triggers it after the review
 * transaction commits.
 */
interface ContributionRequestNotifier
{
    public function notifyCreateRequestApproved(ContributionRequest $request): void;

    public function notifyCreateRequestRejected(ContributionRequest $request): void;
}
