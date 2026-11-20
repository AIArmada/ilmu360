<?php

declare(strict_types=1);

namespace App\Features\Persons\Api\Filament;

use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;

/**
 * Person quick-add Filament form boundary.
 *
 * Consumers compose the owner's person create-option form without importing
 * the feature's Filament entrypoints.
 */
interface PersonQuickAdd
{
    /**
     * @return array<int, Component>
     */
    public function formComponents(): array;

    /**
     * @param  array<string, mixed>  $data
     */
    public function createOption(array $data, ?Schema $schema = null): string;
}
