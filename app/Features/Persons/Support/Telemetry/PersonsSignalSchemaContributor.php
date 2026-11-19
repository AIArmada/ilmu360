<?php

declare(strict_types=1);

namespace App\Features\Persons\Support\Telemetry;

use App\Platform\Telemetry\Contracts\SignalSchemaContributor;

final class PersonsSignalSchemaContributor implements SignalSchemaContributor
{
    public function supports(string $event): bool
    {
        return str_starts_with($event, 'author.');
    }

    /**
     * @return array<string, list<string>>
     */
    public function schemas(): array
    {
        return [
            'author.quick_created' => ['person_id', 'status', 'source'],
        ];
    }
}
