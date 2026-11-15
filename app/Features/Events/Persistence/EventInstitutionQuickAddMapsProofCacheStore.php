<?php

declare(strict_types=1);

namespace App\Features\Events\Persistence;

use App\Features\Events\Api\EventInstitutionQuickAddMapsProof;
use App\Features\Events\Api\EventInstitutionQuickAddMapsProofStore;
use Illuminate\Support\Facades\Cache;

final readonly class EventInstitutionQuickAddMapsProofCacheStore implements EventInstitutionQuickAddMapsProofStore
{
    private const string CACHE_PREFIX = 'event-institution-quick-add:verified-maps:';

    public function get(string $sessionId, string $componentId, string $token): ?EventInstitutionQuickAddMapsProof
    {
        $record = Cache::get($this->cacheKey($sessionId, $componentId, $token));

        return is_array($record)
            ? EventInstitutionQuickAddMapsProof::fromRecord($record)
            : null;
    }

    public function put(
        string $sessionId,
        string $componentId,
        string $token,
        EventInstitutionQuickAddMapsProof $proof,
    ): void {
        Cache::put(
            $this->cacheKey($sessionId, $componentId, $token),
            $proof->toArray(),
            now()->addMinutes(30),
        );
    }

    public function forget(string $sessionId, string $componentId, string $token): void
    {
        Cache::forget($this->cacheKey($sessionId, $componentId, $token));
    }

    private function cacheKey(string $sessionId, string $componentId, string $token): string
    {
        return self::CACHE_PREFIX.hash('sha256', $sessionId.'|'.$componentId.'|'.$token);
    }
}
