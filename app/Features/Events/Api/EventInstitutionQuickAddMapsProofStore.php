<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

interface EventInstitutionQuickAddMapsProofStore
{
    public function get(string $sessionId, string $componentId, string $token): ?EventInstitutionQuickAddMapsProof;

    public function put(
        string $sessionId,
        string $componentId,
        string $token,
        EventInstitutionQuickAddMapsProof $proof,
    ): void;

    public function forget(string $sessionId, string $componentId, string $token): void;
}
