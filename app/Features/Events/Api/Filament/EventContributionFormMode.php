<?php

declare(strict_types=1);

namespace App\Features\Events\Api\Filament;

enum EventContributionFormMode: string
{
    case Create = 'create';
    case Update = 'update';

    public function isCreate(): bool
    {
        return $this === self::Create;
    }
}
