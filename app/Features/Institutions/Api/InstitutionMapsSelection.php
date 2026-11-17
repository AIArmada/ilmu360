<?php

declare(strict_types=1);

namespace App\Features\Institutions\Api;

use App\Shared\Directory\Enums\InstitutionType;

/** Institution Google Maps selection for contribution and event forms. */
interface InstitutionMapsSelection
{
    /** @return array<string, mixed> */
    public function resolve(string $placeId, string $validationKey): array;

    /**
     * @param array<string, mixed> $formState
     * @param array<string, mixed> $selection
     * @param array<string, mixed>|null $previousSelection
     * @return array<string, mixed>
     */
    public function merge(array $formState, array $selection, ?array $previousSelection = null): array;

    /** @param array<string, mixed> $place */
    public function inferType(array $place): ?InstitutionType;
}
