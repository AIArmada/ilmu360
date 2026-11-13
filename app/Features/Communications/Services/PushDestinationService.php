<?php

declare(strict_types=1);

namespace App\Features\Communications\Services;

use AIArmada\Communications\Models\CommunicationDestination;
use App\Features\Communications\Actions\DeletePushDestinationAction;
use App\Features\Communications\Actions\RegisterPushDestinationAction;
use App\Features\Communications\Actions\RemovePushDestinationAction;
use App\Features\Communications\Actions\ResolvePushDestinationsAction;
use App\Features\Communications\Actions\UpdatePushDestinationAction;
use App\Features\Communications\Api\PushDestinationDetails;
use App\Features\Communications\Api\PushDestinationManagement;
use App\Features\Communications\Api\PushDestinationSnapshot;
use Carbon\CarbonImmutable;
use DateTimeInterface;

final class PushDestinationService implements PushDestinationManagement
{
    public function __construct(
        private readonly RegisterPushDestinationAction $registerDestination,
        private readonly UpdatePushDestinationAction $updateDestination,
        private readonly DeletePushDestinationAction $deleteDestination,
        private readonly RemovePushDestinationAction $removeDestination,
        private readonly ResolvePushDestinationsAction $resolveDestinations,
    ) {}

    public function register(string $accountId, string $installationId, PushDestinationDetails $details): PushDestinationSnapshot
    {
        return $this->snapshot($this->registerDestination->handle($accountId, $installationId, $details));
    }

    public function update(string $accountId, string $installationId, PushDestinationDetails $details): ?PushDestinationSnapshot
    {
        $destination = $this->updateDestination->handle($accountId, $installationId, $details);

        return $destination instanceof CommunicationDestination ? $this->snapshot($destination) : null;
    }

    public function deleteByInstallation(string $accountId, string $installationId): void
    {
        $this->deleteDestination->handle($accountId, $installationId);
    }

    public function removeById(string $accountId, string $destinationId): bool
    {
        return $this->removeDestination->handle($accountId, $destinationId);
    }

    /**
     * @return list<PushDestinationSnapshot>
     */
    public function forNotification(string $accountId): array
    {
        return $this->snapshots($this->resolveDestinations->handle($accountId, false));
    }

    /**
     * @return list<PushDestinationSnapshot>
     */
    public function forFallbackDelivery(string $accountId): array
    {
        return $this->snapshots($this->resolveDestinations->handle($accountId, true));
    }

    /**
     * @return list<PushDestinationSnapshot>
     */
    private function snapshots(iterable $destinations): array
    {
        $snapshots = [];

        foreach ($destinations as $destination) {
            if ($destination instanceof CommunicationDestination) {
                $snapshots[] = $this->snapshot($destination);
            }
        }

        return $snapshots;
    }

    private function snapshot(CommunicationDestination $destination): PushDestinationSnapshot
    {
        return new PushDestinationSnapshot(
            id: (string) $destination->getKey(),
            installationId: (string) $destination->address,
            fcmToken: is_string($destination->external_id) ? $destination->external_id : null,
            platform: (string) ($destination->platform ?? ''),
            appVersion: (string) ($destination->app_version ?? ''),
            deviceLabel: (string) ($destination->device_label ?? ''),
            locale: (string) ($destination->locale ?? ''),
            timezone: (string) ($destination->timezone ?? ''),
            lastSeenAt: $this->dateTimeToIso($destination->last_seen_at),
            verifiedAt: $this->dateTimeToIso($destination->verified_at),
        );
    }

    private function dateTimeToIso(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->toIso8601String();
        }

        if (is_string($value) && $value !== '') {
            return CarbonImmutable::parse($value)->toIso8601String();
        }

        return null;
    }
}
