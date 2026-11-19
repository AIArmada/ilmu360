<?php

declare(strict_types=1);

namespace App\Features\Institutions\Support\Telemetry;

use App\Platform\Telemetry\Contracts\SignalSchemaContributor;

final class InstitutionsSignalSchemaContributor implements SignalSchemaContributor
{
    public function supports(string $event): bool
    {
        return str_starts_with($event, 'admin.institution_facilities.');
    }

    /**
     * @return array<string, list<string>>
     */
    public function schemas(): array
    {
        return [
            'admin.institution_facilities.updated' => ['institution_id', 'changed_codes', 'enabled_count', 'disabled_count', 'cleared'],
        ];
    }
}
