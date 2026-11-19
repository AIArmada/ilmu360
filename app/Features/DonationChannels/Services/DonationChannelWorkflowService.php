<?php

declare(strict_types=1);

namespace App\Features\DonationChannels\Services;

use App\Features\DonationChannels\Actions\DeleteDonationChannelAction;
use App\Features\DonationChannels\Actions\DemoteDefaultDonationChannelsAction;
use App\Features\DonationChannels\Actions\DetachDonationChannelVerifierAction;
use App\Features\DonationChannels\Actions\RestoreDonationChannelVerifierAction;
use App\Features\DonationChannels\Actions\SaveDonationChannelAction;
use App\Features\DonationChannels\Actions\SyncInstitutionDonationChannelsAction;
use App\Features\DonationChannels\Actions\TransitionDonationChannelStatusAction;
use App\Features\DonationChannels\Api\DonationChannelWorkflow;
use App\Features\DonationChannels\Api\Enums\DonationChannelStatus;
use App\Features\DonationChannels\Models\DonationChannel;
use LogicException;

final readonly class DonationChannelWorkflowService implements DonationChannelWorkflow
{
    public function __construct(
        private TransitionDonationChannelStatusAction $transitionStatus,
        private DemoteDefaultDonationChannelsAction $demoteDefault,
        private SaveDonationChannelAction $saveChannel,
        private SyncInstitutionDonationChannelsAction $syncInstitutionChannels,
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

    public function syncInstitution(string $institutionId, mixed $payload): void
    {
        $this->syncInstitutionChannels->handle($institutionId, $payload);
    }

    public function verifiedIdsForAccount(string $accountId): array
    {
        return DonationChannel::query()
            ->where('verified_by', $accountId)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
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

        foreach (DonationChannel::query()->whereKey($channelIds)->get() as $channel) {
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
        $channel = DonationChannel::query()->find($channelId);

        if ($channel === null) {
            return null;
        }

        if (! $channel instanceof DonationChannel) {
            throw new LogicException('The registered donation channel model must extend the Directory donation channel model.');
        }

        return $channel;
    }
}
