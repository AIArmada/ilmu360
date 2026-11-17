<?php

declare(strict_types=1);

namespace App\Shared\Events;

use Carbon\CarbonImmutable;

/**
 * Integration seam for the Events-owned reminder queue entrypoint.
 * The existing multi-domain notification service implements this contract.
 */
interface EventReminderNotificationDispatch
{
    public function dispatchDueReminderNotifications(?CarbonImmutable $now = null): void;
}
