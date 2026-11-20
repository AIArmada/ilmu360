<?php

declare(strict_types=1);

namespace App\Features\Institutions\Api\Filament;

use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;

/**
 * Institution quick-add Filament form boundary.
 *
 * Consumers compose the owner's institution create-option form without
 * importing the feature's Filament entrypoints.
 */
interface InstitutionQuickAdd
{
    /**
     * @return array<int, Component>
     */
    public function formComponents(
        bool $includeLocationPicker = false,
        bool $requireVerifiedMapsSelection = false,
        ?string $mapsSelectionOwnerPath = null,
    ): array;

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $verifiedMapsSelection
     */
    public function createOption(
        array $data,
        ?Schema $schema = null,
        bool $requireVerifiedMapsSelection = false,
        ?array $verifiedMapsSelection = null,
    ): string;
}
