<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

/**
 * Pure boundary for the public directory impact of addressing catalog changes.
 */
interface AddressCatalogEffects
{
    public function invalidateLocationCatalogs(): void;
}
