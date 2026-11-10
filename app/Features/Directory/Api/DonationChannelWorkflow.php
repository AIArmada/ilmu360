<?php

declare(strict_types=1);

namespace App\Features\Directory\Api;

/**
 * Donation-channel lifecycle boundary for legacy model hooks.
 */
interface DonationChannelWorkflow
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function save(array $data, ?string $channelId = null): string;

    public function transition(string $channelId, string $status, ?string $verifierId = null): void;

    public function demoteDefault(string $channelId): void;

    public function detachVerifier(string $accountId): void;

    /**
     * @param  list<string>  $channelIds
     */
    public function restoreVerifier(string $accountId, array $channelIds): void;
}
