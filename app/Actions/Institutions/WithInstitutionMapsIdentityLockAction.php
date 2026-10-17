<?php

declare(strict_types=1);

namespace App\Actions\Institutions;

use App\Forms\SharedFormSchema;
use App\Support\Location\GooglePlaceIdentity;
use Closure;
use Illuminate\Support\Facades\Cache;
use Lorisleiva\Actions\Concerns\AsAction;

final class WithInstitutionMapsIdentityLockAction
{
    use AsAction;

    /**
     * Serialize an institution create operation against the same canonical Maps identity.
     *
     * @template TResult
     *
     * @param  array<string, mixed>  $state
     * @param  Closure(): TResult  $operation
     * @return TResult
     */
    public function handle(array $state, Closure $operation): mixed
    {
        $identity = $this->identity($state);

        if ($identity === null) {
            return $operation();
        }

        $lockKey = 'contribution-create:institution-maps:'.hash('sha256', $identity);

        return Cache::lock($lockKey, 300)->block(10, $operation);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function identity(array $state): ?string
    {
        $address = is_array($state['address'] ?? null)
            ? SharedFormSchema::prepareAddressPersistenceData($state['address'])
            : [];

        $cid = GooglePlaceIdentity::canonicalCid($address['google_cid'] ?? null)
            ?? GooglePlaceIdentity::cidFromFeatureId($address['google_feature_id'] ?? null);

        if ($cid === null && is_string($address['google_maps_url'] ?? null)) {
            $cid = GooglePlaceIdentity::cidsFromGoogleMapsUrl($address['google_maps_url'])[0] ?? null;
        }

        $placeId = is_string($address['google_place_id'] ?? null)
            ? trim($address['google_place_id'])
            : '';

        return $cid !== null ? 'cid:'.$cid : ($placeId !== '' ? 'place:'.$placeId : null);
    }
}
