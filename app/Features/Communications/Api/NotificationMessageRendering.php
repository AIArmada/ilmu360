<?php

declare(strict_types=1);

namespace App\Features\Communications\Api;

interface NotificationMessageRendering
{
    /**
     * @param  array{key?: mixed, params?: mixed}|null  $definition
     */
    public function renderDefinition(
        ?array $definition,
        ?string $timezone = null,
        string $fallback = '',
    ): string;

    /**
     * @return array{type: string, starts_at: string|null, timezone: string|null}
     */
    public function eventTimingToken(?string $startsAt, ?string $eventTimezone): array;
}
