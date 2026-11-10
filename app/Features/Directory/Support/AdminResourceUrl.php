<?php

declare(strict_types=1);

namespace App\Features\Directory\Support;

use Filament\Facades\Filament;

/**
 * Resolve admin-panel resource URLs without importing root Filament resources.
 */
final class AdminResourceUrl
{
    public static function edit(string $resourceSlug, string $recordId): ?string
    {
        $panel = Filament::getPanel('admin');

        if ($panel === null) {
            return null;
        }

        $base = $panel->getUrl();

        if ($base === null || $base === '') {
            return null;
        }

        return rtrim($base, '/').'/'.$resourceSlug.'/'.$recordId.'/edit';
    }
}
