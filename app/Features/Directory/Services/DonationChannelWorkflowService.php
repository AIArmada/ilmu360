<?php

declare(strict_types=1);

namespace App\Features\Directory\Services;

use App\Features\Directory\Actions\DonationChannels\DemoteDefaultDonationChannelsAction;
use App\Features\Directory\Actions\DonationChannels\DetachDonationChannelVerifierAction;
use App\Features\Directory\Actions\DonationChannels\RestoreDonationChannelVerifierAction;
use App\Features\Directory\Actions\DonationChannels\SaveDonationChannelAction;
use App\Features\Directory\Actions\DonationChannels\TransitionDonationChannelStatusAction;
use App\Features\Directory\Api\DonationChannelWorkflow;
use App\Features\Directory\Support\DirectoryModel;
use App\Shared\Directory\Enums\DonationChannelStatus;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final readonly class DonationChannelWorkflowService implements DonationChannelWorkflow
{
    public function __construct(
        private TransitionDonationChannelStatusAction $transitionStatus,
        private DemoteDefaultDonationChannelsAction $demoteDefault,
        private SaveDonationChannelAction $saveChannel,
        private DetachDonationChannelVerifierAction $detachVerifier,
        private RestoreDonationChannelVerifierAction $restoreVerifier,
    ) {}

    public function save(array $data, ?string $channelId = null): string
    {
        $channel = $channelId !== null
            ? DirectoryModel::query('donation_channel')->find($channelId)
            : null;

        $saved = $this->saveChannel->handle($data, $channel instanceof Model ? $channel : null);

        return (string) $saved->getKey();
    }

    public function transition(string $channelId, string $status, ?string $verifierId = null): void
    {
        $channel = DirectoryModel::query('donation_channel')->find($channelId);

        if (! $channel instanceof Model) {
            throw new LogicException('A new donation channel must be saved before it can transition.');
        }

        if ($verifierId !== null && $channel->getAttribute('verified_by') === null) {
            $channel->setAttribute('verified_by', $verifierId);
        }

        $resolved = DonationChannelStatus::tryFrom($status);

        if (! $resolved instanceof DonationChannelStatus) {
            throw new LogicException("Unknown donation channel status [{$status}].");
        }

        $this->transitionStatus->handle($channel, $resolved);
    }

    public function demoteDefault(string $channelId): void
    {
        $channel = DirectoryModel::query('donation_channel')->find($channelId);

        if ($channel instanceof Model) {
            $this->demoteDefault->handle($channel);
        }
    }

    public function detachVerifier(string $accountId): void
    {
        $this->detachVerifier->handle($accountId);
    }

    public function restoreVerifier(string $accountId, array $channelIds): void
    {
        $this->restoreVerifier->handle($accountId, $channelIds);
    }
}
