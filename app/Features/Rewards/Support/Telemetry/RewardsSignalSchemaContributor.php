<?php

declare(strict_types=1);

namespace App\Features\Rewards\Support\Telemetry;

use App\Platform\Telemetry\Contracts\SignalSchemaContributor;

final class RewardsSignalSchemaContributor implements SignalSchemaContributor
{
    public function supports(string $event): bool
    {
        return str_starts_with($event, 'rewards.');
    }

    /**
     * @return array<string, list<string>>
     */
    public function schemas(): array
    {
        return [
            'rewards.program_enrolled' => ['program_id', 'program_family', 'enrollment_id', 'surface'],
        ];
    }
}
