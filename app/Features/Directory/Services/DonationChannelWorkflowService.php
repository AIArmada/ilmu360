<?php

declare(strict_types=1);

namespace App\Features\Directory\Services;

use App\Features\Directory\Actions\DonationChannels\DeleteDonationChannelAction;
use App\Features\Directory\Actions\DonationChannels\DemoteDefaultDonationChannelsAction;
use App\Features\Directory\Actions\DonationChannels\DetachDonationChannelVerifierAction;
use App\Features\Directory\Actions\DonationChannels\RestoreDonationChannelVerifierAction;
use App\Features\Directory\Actions\DonationChannels\SaveDonationChannelAction;
use App\Features\Directory\Actions\DonationChannels\TransitionDonationChannelStatusAction;
use App\Features\Directory\Api\DonationChannelWorkflow;
use App\Features\Directory\Support\DirectoryModel;
use App\Models\DonationChannel;
use App\Shared\Directory\Enums\DonationChannelStatus;
use LogicException;

final readonly class DonationChannelWorkflowService implements DonationChannelWorkflow
{
    public function __construct(
        private TransitionDonationChannelStatusAction $transitionStatus,
        private DemoteDefaultDonationChannelsAction $demoteDefault,
        private SaveDonationChannelAction $saveChannel,
        private DetachDonationChannelVerifierAction $detachVerifier,
        private RestoreDonationChannelVerifierAction $restoreVerifier,
        private DeleteDonationChannelAction $deleteChannel,
    ) {}

    public function save(array $data, ?string $channelId = null): string
    {
        $channel = $channelId !== null ? $this->findChannel($channelId) : null;

        $saved = $this->saveChannel->handle($data, $channel);

        return (string) $saved->getKey();
    }

    public function transition(string $channelId, string $status, ?string $verifierId = null): void
    {
        $channel = $this->findChannel($channelId);

        if (! $channel instanceof DonationChannel) {
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
        $channel = $this->findChannel($channelId);

        if ($channel instanceof DonationChannel) {
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

    public function delete(array $channelIds): int
    {
        $deleted = 0;

        foreach (DirectoryModel::query('donation_channel')->whereKey($channelIds)->get() as $channel) {
            if (! $channel instanceof DonationChannel) {
                throw new LogicException('The registered donation channel model must extend the Directory donation channel model.');
            }

            $this->deleteChannel->handle($channel);
            $deleted++;
        }

        return $deleted;
    }

    private function findChannel(string $channelId): ?DonationChannel
    {
        $channel = DirectoryModel::query('donation_channel')->find($channelId);

        if ($channel === null) {
            return null;
        }

        if (! $channel instanceof DonationChannel) {
            throw new LogicException('The registered donation channel model must extend the Directory donation channel model.');
        }

        return $channel;
    }
}
