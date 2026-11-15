<?php

declare(strict_types=1);

namespace App\Features\Contributions\Api;

interface VerifiedInstitutionMapsSelections
{
    public function replace(
        InstitutionMapsSelectionContext $context,
        string $sessionId,
        string $componentId,
        string $token,
        VerifiedInstitutionMapsSelection $selection,
        ?string $previousToken,
    ): void;

    public function get(
        InstitutionMapsSelectionContext $context,
        string $sessionId,
        string $componentId,
        ?string $token,
    ): ?VerifiedInstitutionMapsSelection;

    public function forget(
        InstitutionMapsSelectionContext $context,
        string $sessionId,
        string $componentId,
        ?string $token,
    ): void;
}
