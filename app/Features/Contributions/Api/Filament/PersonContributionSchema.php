<?php

declare(strict_types=1);

namespace App\Features\Contributions\Api\Filament;

use Filament\Schemas\Components\Component;

/**
 * Shared person contribution form boundary.
 *
 * Person entrypoints compose these contribution components without importing
 * the Contributions Filament entrypoint that owns them.
 */
interface PersonContributionSchema
{
    /**
     * @return array<int, Component>
     */
    public function contributionComponents(
        bool $includeMedia = true,
        ?string $addressStatePath = null,
        bool $regionOnlyAddress = true,
        ?bool $showCountryField = null,
        bool $includeAlternativeNames = true,
        bool $useTitleMultiSelect = false,
        bool $splitProfileSections = false,
        ?string $defaultCountryId = null,
        bool $requireCountryField = false,
        bool $includeWazeUrlField = true,
    ): array;
}
