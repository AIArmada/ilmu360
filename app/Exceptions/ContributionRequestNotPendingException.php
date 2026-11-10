<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a contribution review action (approve, reject) reaches the
 * handler with a request that is no longer pending. Callers that interact
 * with a human reviewer catch this separately from programmer errors so a
 * stale approval or rejection surfaces as an actionable message instead of
 * a generic server error.
 */
class ContributionRequestNotPendingException extends RuntimeException
{
    //
}
