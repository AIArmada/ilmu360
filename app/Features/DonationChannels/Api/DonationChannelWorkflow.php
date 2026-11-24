<?php

declare(strict_types=1);

namespace App\Features\DonationChannels\Api;

/**
 * Donation-channel lifecycle boundary for model hooks.
 */
interface DonationChannelWorkflow
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function save(array $data, ?string $channelId = null): string;

    public function transition(string $channelId, string $status, ?string $verifierId = null): void;

    /**
     * Sync the institution channel set; null leaves the set untouched and
     * validates the existing set against the owner's current country.
     */
    public function syncInstitution(string $institutionId, mixed $payload): void;

    public function demoteDefault(string $channelId): void;

    /** @return list<string> */
    public function verifiedIdsForAccount(string $accountId): array;

    public function detachVerifier(string $accountId): void;

    /**
     * @param  list<string>  $channelIds
     */
    public function restoreVerifier(string $accountId, array $channelIds): void;

    /**
     * Delete donation channels through the owning feature; returns the deleted count.
     *
     * @param  list<string>  $channelIds
     */
    public function delete(array $channelIds): int;
}
