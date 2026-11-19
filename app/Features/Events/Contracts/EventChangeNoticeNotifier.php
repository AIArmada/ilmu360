<?php

declare(strict_types=1);

namespace App\Features\Events\Contracts;

/** Root notification delivery contract for a published event change. */
interface EventChangeNoticeNotifier
{
    public function notify(string $announcementId): void;
}
