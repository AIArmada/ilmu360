<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Actions\CommittedSignals;

use RuntimeException;

/**
 * Internal control signal: the cursor row vanished mid-transaction.
 * Never escapes the drain action; the fenced attempt re-ensures and retries.
 *
 * @internal
 */
final class CommittedShareCursorRowMissingException extends RuntimeException {}
