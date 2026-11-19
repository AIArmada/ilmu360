<?php

declare(strict_types=1);

namespace App\Features\Reports\Support\Telemetry;

use App\Platform\Telemetry\Contracts\SignalSchemaContributor;

final class ReportsSignalSchemaContributor implements SignalSchemaContributor
{
    public function supports(string $event): bool
    {
        return str_starts_with($event, 'report.');
    }

    /**
     * @return array<string, list<string>>
     */
    public function schemas(): array
    {
        return [
            'report.submitted' => ['report_id', 'entity_type', 'entity_id', 'category', 'status'],
        ];
    }
}
