<?php

declare(strict_types=1);

namespace App\Features\References\Api\Filament;

use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;

/**
 * Reference quick-create Filament form boundary.
 *
 * Consumers compose the owner's reference fields and pending-record creator
 * without importing the feature's Filament entrypoints.
 */
interface ReferenceQuickCreate
{
    /**
     * @return array<int, Component>
     */
    public function referenceFields(string $yearField = 'year', bool $publicOnly = true): array;

    /**
     * @return array<int, Component>
     */
    public function quickCreateSections(): array;

    /**
     * @param  array<string, mixed>  $data
     */
    public function createPendingReference(array $data, ?Schema $schema = null): string;

    /**
     * @return array<string, string>
     */
    public function searchReferenceOptions(string $search): array;

    /**
     * @param  array<int, mixed>  $values
     * @return array<string, string>
     */
    public function selectedReferenceLabels(array $values): array;
}
