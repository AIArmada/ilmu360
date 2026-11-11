<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\Venues;

use App\Platform\Directory\EntityRelationSync;
use Illuminate\Database\Eloquent\Model;

/**
 * Synchronize a venue's address, contact methods and social profiles from an
 * admin or quick-add payload.
 */
final readonly class SyncVenueRelationsAction
{
    public function __construct(
        private EntityRelationSync $relations,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(Model $venue, array $payload): void
    {
        if (array_key_exists('address', $payload)) {
            $this->relations->syncAddress($venue, $payload['address'], allowCountryOnly: true);
        }

        if (array_key_exists('contactMethods', $payload)) {
            $this->relations->syncContactMethods($venue, $payload['contactMethods']);
        }

        if (array_key_exists('social_media', $payload)) {
            $this->relations->syncSocialMedia($venue, $payload['social_media']);
        }
    }
}
