<?php

declare(strict_types=1);

namespace App\Features\Contributions\Persistence;

use App\Features\Contributions\Api\InstitutionMapsSelectionContext;
use App\Features\Contributions\Api\VerifiedInstitutionMapsSelection;
use App\Features\Contributions\Api\VerifiedInstitutionMapsSelections;
use Illuminate\Support\Facades\Cache;

final readonly class CacheVerifiedInstitutionMapsSelections implements VerifiedInstitutionMapsSelections
{
    public function replace(
        InstitutionMapsSelectionContext $context,
        string $sessionId,
        string $componentId,
        string $token,
        VerifiedInstitutionMapsSelection $selection,
        ?string $previousToken,
    ): void {
        Cache::put(
            $this->cacheKey($context, $sessionId, $componentId, $token),
            $selection->toArray(),
            now()->addMinutes(30),
        );

        if ($previousToken !== null && $previousToken !== '') {
            Cache::forget($this->cacheKey($context, $sessionId, $componentId, $previousToken));
        }
    }

    public function get(
        InstitutionMapsSelectionContext $context,
        string $sessionId,
        string $componentId,
        ?string $token,
    ): ?VerifiedInstitutionMapsSelection {
        if ($token === null || $token === '') {
            return null;
        }

        $selection = Cache::get($this->cacheKey($context, $sessionId, $componentId, $token));

        return is_array($selection)
            ? VerifiedInstitutionMapsSelection::fromCache($selection)
            : null;
    }

    public function forget(
        InstitutionMapsSelectionContext $context,
        string $sessionId,
        string $componentId,
        ?string $token,
    ): void {
        Cache::forget($this->cacheKey($context, $sessionId, $componentId, $token ?? ''));
    }

    private function cacheKey(
        InstitutionMapsSelectionContext $context,
        string $sessionId,
        string $componentId,
        string $token,
    ): string {
        return $context->cachePrefix().hash('sha256', $sessionId.'|'.$componentId.'|'.$token);
    }
}
