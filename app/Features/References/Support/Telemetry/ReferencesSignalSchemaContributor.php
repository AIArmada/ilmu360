<?php

declare(strict_types=1);

namespace App\Features\References\Support\Telemetry;

use App\Platform\Telemetry\Contracts\SignalSchemaContributor;

final class ReferencesSignalSchemaContributor implements SignalSchemaContributor
{
    public function supports(string $event): bool
    {
        return str_starts_with($event, 'reference.');
    }

    /**
     * @return array<string, list<string>>
     */
    public function schemas(): array
    {
        return [
            'reference.quick_created' => ['reference_id', 'record_kind', 'parent_id', 'status', 'source'],
        ];
    }
}
