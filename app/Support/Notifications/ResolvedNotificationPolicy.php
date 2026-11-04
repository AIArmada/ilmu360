<?php

declare(strict_types=1);

namespace App\Support\Notifications;

use App\Enums\NotificationCadence;
use App\Enums\NotificationFamily;
use App\Enums\NotificationTrigger;

readonly class ResolvedNotificationPolicy
{
    /**
     * @param  list<string>  $channels
     */
    public function __construct(
        public NotificationFamily $family,
        public NotificationTrigger $trigger,
        public bool $enabled,
        public NotificationCadence $cadence,
        public array $channels,
        public ?string $quietHoursStart,
        public ?string $quietHoursEnd,
        public ?string $digestDeliveryTime,
        public int $digestWeeklyDay,
        public string $locale,
        public string $timezone,
    ) {}
}
