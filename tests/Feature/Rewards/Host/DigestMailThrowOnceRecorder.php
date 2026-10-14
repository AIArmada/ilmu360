<?php

declare(strict_types=1);

namespace Tests\Feature\Rewards\Host;

use AIArmada\Communications\Contracts\CommunicationRecorder;
use AIArmada\Communications\Data\CommunicationContextData;
use AIArmada\Communications\Models\Communication;
use AIArmada\Communications\Services\CommunicationRecorderService;
use RuntimeException;

final class DigestMailThrowOnceRecorder implements CommunicationRecorder
{
    private bool $thrown = false;

    public function __construct(
        private readonly CommunicationRecorderService $inner,
    ) {}

    public function createCommunication(CommunicationContextData $context): Communication
    {
        return $this->inner->createCommunication($context);
    }

    public function markSending(string $communicationId, string $deliveryId): void
    {
        $this->inner->markSending($communicationId, $deliveryId);
    }

    public function markSent(string $communicationId, string $deliveryId, array $result): void
    {
        if (! $this->thrown) {
            $this->thrown = true;

            throw new RuntimeException('simulated recording crash after provider acceptance');
        }

        $this->inner->markSent($communicationId, $deliveryId, $result);
    }

    public function markFailed(string $communicationId, string $deliveryId, string $failureMessage): void
    {
        $this->inner->markFailed($communicationId, $deliveryId, $failureMessage);
    }

    public function cancelCommunication(string $communicationId): void
    {
        $this->inner->cancelCommunication($communicationId);
    }
}
