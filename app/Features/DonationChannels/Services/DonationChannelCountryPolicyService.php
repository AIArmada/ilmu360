<?php

declare(strict_types=1);

namespace App\Features\DonationChannels\Services;

use App\Features\DonationChannels\Api\DonationChannelCountryPolicy;
use App\Features\DonationChannels\Api\DonationChannelCountryProfiles;
use App\Features\DonationChannels\Api\DonationChannelDirectory;
use Illuminate\Validation\ValidationException;

final readonly class DonationChannelCountryPolicyService implements DonationChannelCountryPolicy
{
    public function __construct(
        private DonationChannelCountryProfiles $profiles,
        private DonationChannelDirectory $channels,
    ) {}

    public function assertOwnerChannelsAllowedForCountryId(string $ownerType, string $ownerId, ?string $countryId): void
    {
        $profile = $this->profiles->forCountryId($countryId);

        foreach ($this->channels->forOwner($ownerType, $ownerId) as $channel) {
            $bankAllowed = $channel->method !== 'bank_account'
                || ($channel->bank_code !== null && $profile->banks->retains($channel->bank_code));

            if (! $profile->supports($channel->method) || ! $bankAllowed) {
                throw ValidationException::withMessages([
                    'donation_channels' => __('Remove the donation channels that the selected country does not support before changing the country.'),
                ]);
            }
        }
    }
}
