<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Thrown when a review action reaches a request that has already been
 * reviewed by someone else (e.g. a second approver acting on a stale
 * Pending snapshot after the first approval committed). Extends the generic
 * stale-request exception so API and UI layers can keep the specific
 * "already reviewed" wording while sharing the 409 stale handling.
 */
final class ContributionRequestAlreadyReviewedException extends ContributionRequestNotPendingException
{
    //
}
