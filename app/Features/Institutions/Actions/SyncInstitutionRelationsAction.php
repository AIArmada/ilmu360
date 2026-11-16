<?php

declare(strict_types=1);

namespace App\Features\Institutions\Actions;

use App\Platform\Directory\EntityRelationSync;
use App\Features\DonationChannels\Api\DonationChannelWorkflow;
use Illuminate\Database\Eloquent\Model;

/**
 * Synchronize an institution's address, contacts, social profiles and
 * donation channels from a contribution or admin payload.
 */
final readonly class SyncInstitutionRelationsAction
{
    public function __construct(
        private EntityRelationSync $relations,
        private DonationChannelWorkflow $donationChannels,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(Model $institution, array $payload): void
    {
        if (array_key_exists('address', $payload)) {
            $this->relations->syncAddress($institution, $payload['address'], allowCountryOnly: true);
        }

        if (array_key_exists('contactMethods', $payload)) {
            $this->relations->syncContactMethods($institution, $payload['contactMethods']);
        }

        if (array_key_exists('social_media', $payload)) {
            $this->relations->syncSocialMedia($institution, $payload['social_media']);
        }

        if (array_key_exists('donation_channels', $payload)) {
            $this->donationChannels->syncInstitution((string) $institution->getKey(), $payload['donation_channels']);
        }
    }
}
