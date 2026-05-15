<?php

declare(strict_types=1);

namespace App\Support\Notifications;

use App\Enums\NotificationCadence;
use App\Enums\NotificationPriority;
use App\Enums\NotificationTrigger;
use Carbon\CarbonInterface;

readonly class NotificationDispatchData
{
    /**
     * @param  array<string, mixed>  $meta
     * @param  array{
     *     title?: array{key?: string, params?: array<string, mixed>},
     *     body?: array{key?: string, params?: array<string, mixed>}
     * }|null  $render
     */
    public function __construct(
        public NotificationTrigger $trigger,
        public string $title,
        public string $body,
        public ?string $actionUrl = null,
        public ?string $entityType = null,
        public ?string $entityId = null,
        public NotificationPriority $priority = NotificationPriority::Medium,
        public ?NotificationCadence $forcedCadence = null,
        public ?string $fingerprint = null,
        public array $meta = [],
        public ?CarbonInterface $occurredAt = null,
        public bool $bypassQuietHours = false,
        public ?array $render = null,
    ) {}
}
