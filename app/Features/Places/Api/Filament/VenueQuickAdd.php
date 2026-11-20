<?php

declare(strict_types=1);

namespace App\Features\Places\Api\Filament;

use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;

/**
 * Venue quick-add Filament form boundary.
 *
 * Consumers compose the owner's venue create-option form without importing
 * the feature's Filament entrypoints.
 */
interface VenueQuickAdd
{
    /**
     * @return array<int, Component>
     */
    public function formComponents(bool $includeLocationPicker = false): array;

    /**
     * @param  array<string, mixed>  $data
     */
    public function createOption(array $data, ?Schema $schema = null): string;
}
