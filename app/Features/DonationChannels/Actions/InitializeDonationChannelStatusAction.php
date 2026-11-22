<?php

declare(strict_types=1);

namespace App\Features\DonationChannels\Actions;

use App\Features\DonationChannels\Api\Enums\DonationChannelStatus;
use App\Features\DonationChannels\Models\DonationChannel;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/** Normalize initial status and its lifecycle audit fields before persistence. */
final readonly class InitializeDonationChannelStatusAction
{
    public function handle(DonationChannel $channel): void
    {
        if (($channel->getAttributes()['status'] ?? null) === null) {
            $channel->setAttribute('status', DonationChannelStatus::Pending);
        }

        $status = $this->resolveStatus($channel->getAttributes()['status'] ?? null);
        $now = CarbonImmutable::now();
        $timestampAttribute = match ($status) {
            DonationChannelStatus::Verified => 'verified_at',
            DonationChannelStatus::Rejected => 'rejected_at',
            DonationChannelStatus::Inactive => 'inactive_at',
            default => null,
        };

        if ($timestampAttribute !== null && $channel->getAttribute($timestampAttribute) === null) {
            $channel->setAttribute($timestampAttribute, $now);
        }

        $channel->last_state_change_at ??= $now;

        if ($status === DonationChannelStatus::Verified) {
            $channel->verified_by ??= auth()->id();
        }
    }

    private function resolveStatus(mixed $status): DonationChannelStatus
    {
        if ($status instanceof DonationChannelStatus) {
            return $status;
        }

        $resolved = is_string($status) ? DonationChannelStatus::tryFrom($status) : null;

        if (! $resolved instanceof DonationChannelStatus) {
            throw new InvalidArgumentException(sprintf('The donation channel status [%s] is invalid.', is_scalar($status) ? (string) $status : gettype($status)));
        }

        return $resolved;
    }
}
