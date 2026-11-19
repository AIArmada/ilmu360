<?php

declare(strict_types=1);

namespace App\Features\Communications\Support\Telemetry;

use App\Platform\Telemetry\Contracts\SignalSchemaContributor;

final class CommunicationsSignalSchemaContributor implements SignalSchemaContributor
{
    public function supports(string $event): bool
    {
        return str_starts_with($event, 'notification.');
    }

    /**
     * @return array<string, list<string>>
     */
    public function schemas(): array
    {
        return [
            'notification.read' => ['notification_id', 'family', 'trigger', 'action_url'],
            'notification.read_all' => ['updated_count'],
        ];
    }
}
