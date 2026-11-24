<?php

declare(strict_types=1);

namespace App\Features\DonationChannels\Queries;

use App\Features\DonationChannels\Api\Data\DonationChannelSummary;
use App\Features\DonationChannels\Api\DonationChannelDirectory;
use App\Features\DonationChannels\Models\DonationChannel;
use App\Platform\Models\MorphModelResolver;
use Illuminate\Database\Eloquent\Builder;

final readonly class EloquentDonationChannelDirectory implements DonationChannelDirectory
{
    public function forOwner(string $ownerType, string $ownerId): array
    {
        return $this->queryForOwner($ownerType, $ownerId)
            ->with('media')
            ->orderByDesc('is_default')
            ->orderBy('created_at')
            ->get()
            ->map(fn (DonationChannel $channel): DonationChannelSummary => $this->summary($channel))
            ->all();
    }

    public function findForOwner(string $ownerType, string $ownerId, string $channelId): ?DonationChannelSummary
    {
        $channel = $this->queryForOwner($ownerType, $ownerId)
            ->with('media')
            ->whereKey($channelId)
            ->first();

        return $channel instanceof DonationChannel ? $this->summary($channel) : null;
    }

    /**
     * @return Builder<DonationChannel>
     */
    private function queryForOwner(string $ownerType, string $ownerId): Builder
    {
        $modelClass = MorphModelResolver::tryResolve($ownerType);
        $types = array_values(array_unique(array_filter([$ownerType, $modelClass])));

        return DonationChannel::query()
            ->whereIn('donatable_type', $types)
            ->where('donatable_id', $ownerId);
    }

    private function summary(DonationChannel $channel): DonationChannelSummary
    {
        return new DonationChannelSummary(
            id: (string) $channel->getKey(),
            label: $channel->getAttribute('label'),
            method: (string) $channel->getAttribute('method'),
            method_display: (string) $channel->getAttribute('method_display'),
            recipient: (string) $channel->getAttribute('recipient'),
            payment_details: (string) $channel->getAttribute('payment_details'),
            bank_code: $channel->getAttribute('bank_code'),
            bank_name: $channel->getAttribute('bank_name'),
            account_number: $channel->getAttribute('account_number'),
            iban: $channel->getAttribute('iban'),
            swift_bic: $channel->getAttribute('swift_bic'),
            routing_code: $channel->getAttribute('routing_code'),
            currency: $channel->getAttribute('currency'),
            duitnow_type: $channel->getAttribute('duitnow_type'),
            duitnow_value: $channel->getAttribute('duitnow_value'),
            ewallet_provider: $channel->getAttribute('ewallet_provider'),
            ewallet_handle: $channel->getAttribute('ewallet_handle'),
            is_default: (bool) $channel->getAttribute('is_default'),
            status: $channel->status,
            qr_url: $channel->getFirstMediaUrl('qr', 'thumb') ?: $channel->getFirstMediaUrl('qr') ?: null,
            qr_full_url: $channel->getFirstMediaUrl('qr') ?: null,
        );
    }
}
