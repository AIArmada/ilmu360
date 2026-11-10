<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\DonationChannels;

use App\Features\Directory\Support\DirectoryModel;
use App\Shared\Directory\Enums\DonationChannelStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Transition a persisted donation channel and record lifecycle timestamps.
 */
final readonly class TransitionDonationChannelStatusAction
{
    public function handle(Model $channel, DonationChannelStatus $status): Model
    {
        DirectoryModel::assert($channel, 'donation_channel');

        if (! $channel->exists) {
            throw new LogicException('A new donation channel must be saved before it can transition.');
        }

        $currentStatus = $channel->getAttribute('status');

        if (! $currentStatus instanceof DonationChannelStatus) {
            throw new LogicException('A persisted donation channel must have a valid status before it can transition.');
        }

        if ($status === DonationChannelStatus::Verified) {
            $channel->setAttribute('verified_by', $channel->getAttribute('verified_by') ?? auth()->id());
        }

        if ($currentStatus === $status) {
            $now = CarbonImmutable::now();
            $this->applyStatusTimestamp($channel, $status, $now);
            $channel->setAttribute('last_state_change_at', $channel->getAttribute('last_state_change_at') ?? $now);

            if ($channel->isDirty()) {
                $channel->save();
            }

            return $channel;
        }

        $now = CarbonImmutable::now();
        $channel->setAttribute('status', $status);
        $this->applyStatusTimestamp($channel, $status, $now, true);
        $channel->setAttribute('last_state_change_at', $now);
        $channel->save();

        return $channel;
    }

    private function applyStatusTimestamp(Model $channel, DonationChannelStatus $status, CarbonImmutable $at, bool $overwrite = false): void
    {
        $attribute = match ($status) {
            DonationChannelStatus::Verified => 'verified_at',
            DonationChannelStatus::Rejected => 'rejected_at',
            DonationChannelStatus::Inactive => 'inactive_at',
            default => null,
        };

        if ($attribute === null) {
            return;
        }

        if ($overwrite || $channel->getAttribute($attribute) === null) {
            $channel->setAttribute($attribute, $at);
        }
    }
}
